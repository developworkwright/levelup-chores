<?php

namespace App\Services;

use App\Enums\LedgerKind;
use App\Enums\ProfileRole;
use App\Enums\RedemptionStatus;
use App\Exceptions\InsufficientPointsException;
use App\Exceptions\LevelTooLowException;
use App\Models\LootFavorite;
use App\Models\Profile;
use App\Models\Redemption;
use App\Models\StoreItem;
use App\Notifications\LootRestocked;
use App\Notifications\ParentApprovalNeeded;
use App\Notifications\RedemptionDecided;
use Illuminate\Database\UniqueConstraintViolationException;
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

    /**
     * How many rewards have landed since this kid last opened the shop.
     *
     * The number the Spend rail button wears. It is the whole answer to "they
     * never see the new things": a badge on the tab is seen before the shop
     * is, which is the only place a kid who doesn't read the shelves will
     * notice anything has changed.
     */
    public function newCountFor(Profile $profile): int
    {
        return StoreItem::where('household_id', $profile->household_id)
            ->when(
                $profile->loot_seen_at !== null,
                fn ($query) => $query->where('created_at', '>', $profile->loot_seen_at),
            )
            ->count();
    }

    /**
     * Marks the shop as looked at.
     *
     * Called on render, *after* the page has worked out what was new — the
     * same ordering MonsterService::markSeen() needs, and for the same reason:
     * marking first would erase the very gap the page exists to show.
     */
    public function markShopSeen(Profile $profile): void
    {
        $profile->loot_seen_at = now();
        $profile->save();
    }

    /**
     * How many times this kid has actually had each reward, keyed by item id.
     *
     * The half of "favorites" worth deriving rather than storing. A star says
     * what a kid is dreaming about; a repeat purchase says what actually moves
     * them, and it needs nothing taught and no taps.
     *
     * Counts fulfilled redemptions only. A pending request is a wish that has
     * not been granted yet, and a rejected one is a wish that was turned down
     * — neither is evidence of anything.
     *
     * @return array<int, int>
     */
    public function boughtBeforeFor(Profile $profile): array
    {
        return Redemption::where('profile_id', $profile->id)
            ->where('status', RedemptionStatus::Fulfilled)
            ->selectRaw('store_item_id, count(*) as total')
            ->groupBy('store_item_id')
            ->pluck('total', 'store_item_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /** Star or unstar a reward. Returns whether it is starred afterwards. */
    public function toggleFavorite(Profile $profile, StoreItem $item): bool
    {
        if ($item->household_id !== $profile->household_id) {
            return false;
        }

        $existing = LootFavorite::where('profile_id', $profile->id)
            ->where('store_item_id', $item->id)
            ->first();

        if ($existing) {
            $existing->delete();

            return false;
        }

        try {
            LootFavorite::create([
                'profile_id' => $profile->id,
                'store_item_id' => $item->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A double tap that beat its own lookup. It is starred either way,
            // which is what the caller asked for.
            return true;
        }

        return true;
    }

    /** @return array<int, true> Item ids this kid has starred. */
    public function favoriteIdsFor(Profile $profile): array
    {
        return LootFavorite::where('profile_id', $profile->id)
            ->pluck('store_item_id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    public function fulfill(Redemption $redemption, Profile $approver): void
    {
        $redemption->status = RedemptionStatus::Fulfilled;
        $redemption->fulfilled_at = now();
        $redemption->fulfilled_by_profile_id = $approver->id;
        $redemption->save();

        // The one moment in a cash-out the kid isn't in the room for. They
        // spent the points days ago and have been waiting on a grown-up ever
        // since; nothing on their side lists the request, so without this the
        // reward being ready is something they have to happen to ask about.
        try {
            $redemption->profile->notify(new RedemptionDecided(
                'Ready for you!',
                "{$redemption->storeItem->name} is yours — go and collect it.",
            ));
        } catch (Throwable $e) {
            Log::error('Redemption notification failed for fulfilment.', [
                'redemption_id' => $redemption->id,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Tells the kids a reward has landed on the shelves.
     *
     * Deliberately every kid, whatever their level — the same rule the count
     * badge on the Spend tab follows. A locked reward is a thing to climb
     * towards, and skipping it here would leave a badge on the tab with
     * nothing behind it. The gate rides in the body when there is one, though:
     * now that a parent sets it on the form that fires this, a push can
     * announce something a kid can't buy yet, and finding that out by opening
     * the shop is the part that stings.
     *
     * Called by the parent Loot page rather than from an item's own creation,
     * because a reward can be seeded, imported or fixed up without that being
     * news — this is for a parent deliberately putting something out.
     */
    public function announceNewItem(StoreItem $item): void
    {
        $kids = Profile::where('household_id', $item->household_id)
            ->where('role', ProfileRole::Kid)
            ->get();

        try {
            $body = "{$item->name} — {$item->cost} points."
                .($item->min_level > 0 ? " Unlocks at level {$item->min_level}." : '');

            Notification::send($kids, new LootRestocked('New in the shop!', $body));
        } catch (Throwable $e) {
            Log::error('Loot restocked notification failed.', [
                'store_item_id' => $item->id,
                'exception' => $e,
            ]);
        }
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

        // The reason travels with it. It already rides in the ledger
        // description, but that is a line in a list a kid has to go and find —
        // this is the app telling them what happened and why, unprompted.
        try {
            $redemption->profile->notify(new RedemptionDecided(
                'Not this time',
                "{$redemption->storeItem->name} was turned down"
                    .($reason === null ? '' : " — {$reason}")
                    .". Your {$redemption->cost_snapshot} points are back.",
            ));
        } catch (Throwable $e) {
            Log::error('Redemption notification failed for rejection.', [
                'redemption_id' => $redemption->id,
                'exception' => $e,
            ]);
        }

        return true;
    }
}
