<?php

namespace App\Services;

use App\Enums\LedgerKind;
use App\Enums\ProfileRole;
use App\Enums\RedemptionStatus;
use App\Exceptions\InsufficientPointsException;
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
}
