<?php

namespace App\Services;

use App\Enums\LedgerKind;
use App\Enums\ProfileRole;
use App\Enums\RedemptionStatus;
use App\Exceptions\InsufficientPointsException;
use App\Exceptions\LevelTooLowException;
use App\Models\Profile;
use App\Models\Redemption;
use App\Models\StoreItem;
use App\Notifications\ParentApprovalNeeded;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class StoreService
{
    public function __construct(
        private LedgerService $ledger,
        private BadgeService $badges,
    ) {}

    /**
     * Points deduct immediately on request (so a kid can't over-redeem past
     * their balance); "fulfilled" is just the parent's bookkeeping step.
     */
    public function redeem(Profile $profile, StoreItem $item): Redemption
    {
        // Level before points: a kid who can't have the thing yet shouldn't be
        // told they're only short of points for it.
        if ($item->isLockedFor($profile)) {
            throw new LevelTooLowException($item->min_level);
        }

        if ($profile->points < $item->cost) {
            throw new InsufficientPointsException($item->cost - $profile->points);
        }

        $redemption = DB::transaction(function () use ($profile, $item) {
            $this->ledger->record(
                $profile->household,
                $profile,
                LedgerKind::Spend,
                -$item->cost,
                "{$profile->name} — {$item->name}",
                $item,
            );

            return Redemption::create([
                'store_item_id' => $item->id,
                'profile_id' => $profile->id,
                'cost_snapshot' => $item->cost,
                'status' => RedemptionStatus::Pending,
                'requested_at' => now(),
            ]);
        });

        $this->badges->evaluate($profile);

        $parents = Profile::where('household_id', $profile->household_id)
            ->where('role', ProfileRole::Parent)
            ->get();

        // Best-effort: the points are already deducted and the redemption
        // recorded, so a failed notification must not fail the request.
        try {
            Notification::send($parents, new ParentApprovalNeeded(
                'Reward requested',
                "{$profile->name} wants to redeem {$item->name}.",
            ));
        } catch (Throwable $e) {
            Log::error('Parent approval notification failed for redemption.', [
                'redemption_id' => $redemption->id,
                'exception' => $e,
            ]);
        }

        return $redemption;
    }

    public function fulfill(Redemption $redemption, Profile $approver): void
    {
        $redemption->status = RedemptionStatus::Fulfilled;
        $redemption->fulfilled_at = now();
        $redemption->fulfilled_by_profile_id = $approver->id;
        $redemption->save();
    }

    /**
     * Turns a redemption down and puts the points back.
     *
     * The refund is the whole point. Points leave a balance the instant a kid
     * asks — that is what stops them redeeming past what they have — so a
     * request nobody intended to grant has already been paid for, and an
     * accidental tap costs real money until somebody notices.
     *
     * Recorded as {@see LedgerKind::Refund} rather than an adjustment, so it
     * reads as the app giving back what it took rather than a parent handing
     * points over, and so it nets out of the amount spent.
     *
     * Refuses anything that isn't still pending: a fulfilled reward has been
     * handed over, and refunding it would pay the kid for keeping it. Returns
     * false so the caller can say so.
     */
    public function reject(Redemption $redemption, Profile $decider, ?string $reason = null): bool
    {
        if ($redemption->status !== RedemptionStatus::Pending) {
            return false;
        }

        $reason = $reason === null ? null : (trim($reason) ?: null);

        DB::transaction(function () use ($redemption, $decider, $reason) {
            $redemption->status = RedemptionStatus::Rejected;
            $redemption->rejected_at = now();
            $redemption->rejected_by_profile_id = $decider->id;
            $redemption->reject_reason = $reason;
            $redemption->save();

            // The reason rides in the description because that is the only
            // place it reaches the kid: nothing on their side lists their own
            // requests, so the refund line in their stats is where they find
            // out this didn't happen and why.
            $description = "{$redemption->profile->name} — {$redemption->storeItem->name} refunded"
                .($reason === null ? '' : " ({$reason})");

            // The snapshot, not the item's price today — a parent editing the
            // cost between the request and the rejection must not change what
            // the kid gets back.
            $this->ledger->record(
                $redemption->profile->household,
                $redemption->profile,
                LedgerKind::Refund,
                $redemption->cost_snapshot,
                $description,
                $redemption->storeItem,
            );
        });

        // The refund can drop a kid back under a spending threshold, so the
        // badges have to be looked at again rather than left where the
        // redemption put them.
        $this->badges->evaluate($redemption->profile->refresh());

        return true;
    }
}
