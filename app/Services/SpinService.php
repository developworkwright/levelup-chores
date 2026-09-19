<?php

namespace App\Services;

use App\Enums\PetKnack;
use App\Enums\PetStage;
use App\Models\Chore;
use App\Models\Profile;
use App\Models\Spin;
use Illuminate\Support\Collection;
use RuntimeException;

class SpinService
{
    /** 35% chance of a 3x boost, otherwise 2x — matches the design prototype's spin odds. */
    private const TRIPLE_CHANCE = 0.35;

    /**
     * A charged wheel's odds: 15% 4x, 45% 3x, 40% 2x.
     *
     * The charge doesn't just bolt a 4x onto the plain table — it lifts the 3x
     * with it, so an OP spin can never be a worse bet than the free spin the
     * ticket was spent on. Expected multiplier 2.75x against the plain 2.35x.
     */
    private const OP_QUAD_CHANCE = 0.15;

    private const OP_TRIPLE_CHANCE = 0.45;

    /** Keeps the wheel legible and its segments tappable/readable at any household size. */
    public const MAX_WHEEL_CHORES = 10;

    public function __construct(private BadgeService $badges) {}

    public function today(Profile $profile): ?Spin
    {
        $today = HouseholdClock::for($profile->household)->today();

        return Spin::where('profile_id', $profile->id)
            ->whereDate('spin_date', $today)
            ->first();
    }

    public function hasSpunToday(Profile $profile): bool
    {
        return $this->today($profile) !== null;
    }

    /** Whether an OP charge is sitting on the wheel, waiting for a spin. */
    public function isCharged(Profile $profile): bool
    {
        return $profile->op_spin_armed_at !== null;
    }

    /**
     * Arms the next spin with the OP odds. Returns false when a charge is
     * already waiting — charges don't stack, and the caller uses that to keep
     * the perk in the kid's pocket rather than spending it on nothing.
     */
    public function charge(Profile $profile): bool
    {
        if ($this->isCharged($profile)) {
            return false;
        }

        $profile->update(['op_spin_armed_at' => now()]);

        return true;
    }

    /**
     * Drops today's spin so the wheel is available again. Returns the spin
     * that was cleared, or null if there wasn't one — callers use that to
     * avoid charging for a respin that had nothing to undo.
     *
     * An OP charge is **not** handed back with the spin. The charge buys the
     * roll, and the roll happened; a respin that returned it would let a kid
     * re-roll the 4x table until it paid. The wheel warns before it comes to
     * that — see the respin confirm on the Home page.
     *
     * That rule is for a kid undoing their own spin. A parent undoing it goes
     * through {@see resetByParent()}, which does refund.
     */
    public function clearToday(Profile $profile): ?Spin
    {
        $spin = $this->today($profile);

        $spin?->delete();

        return $spin;
    }

    /**
     * A reset from the parent console (or `wheel:reset-spin`), which **does**
     * hand an OP charge back.
     *
     * The opposite of the respin perk above, on purpose. The perk keeps the
     * charge because the kid chose to re-roll, and returning it would let them
     * re-roll the 4x table until it paid. A parent reset is not a re-roll a
     * kid bought — it is a spin being undone, usually because it landed on
     * something that was never really available — and taking a ticket-bought
     * charge with it makes the parent's fix cost the kid money they earned.
     *
     * Only ever one charge on a wheel: a kid who has already armed the next
     * spin keeps that one rather than ending up holding two, which
     * {@see charge()} refuses on its own.
     */
    public function resetByParent(Profile $profile): ?Spin
    {
        $spin = $this->clearToday($profile);

        if ($spin?->was_op) {
            $this->charge($profile);
        }

        return $spin;
    }

    /**
     * The chores eligible to appear on the bonus wheel today — age
     * appropriate, not barred from the wheel by a parent, and not already
     * claimed by anyone in the household — capped to MAX_WHEEL_CHORES.
     *
     * Above the cap, a random subset is used instead of the full list, but
     * it's picked with a per-profile-per-day deterministic shuffle (not a
     * fresh random pick each call) and always forces in whichever chore was
     * actually landed on, if a spin already happened today. That's what
     * lets this same method drive both the live spin (before a result
     * exists) and every later page render (after one does) without the
     * wheel ever showing a different set of chores than what it can — or
     * already did — land on.
     *
     * @return Collection<int, Chore>
     */
    public function eligibleChoresFor(Profile $profile): Collection
    {
        // Resolved lazily (not constructor-injected) to avoid a circular
        // dependency — ChoreService itself depends on SpinService.
        $chores = app(ChoreService::class);

        $spinToday = $this->today($profile);

        $eligible = $profile->household->chores
            ->filter(fn (Chore $chore) => $chore->isAppropriateFor($profile))
            // Cooldowns are household-wide, so a chore a sibling already
            // claimed can no longer be earned — landing a 3x boost on it
            // would be a prize that pays nothing. A parent can bar a chore
            // from the wheel outright for the same reason: an opportunistic
            // job like "put the groceries away" is only real on the days
            // there are groceries.
            //
            // Whatever today's spin already landed on stays regardless of
            // either: the wheel has to keep showing the result it gave, even
            // once that chore is spent or a parent has since excluded it —
            // mount() and spin() both locate the wheel's rotation by finding
            // that chore's index in this collection.
            ->filter(fn (Chore $chore) => ($spinToday && $chore->id === $spinToday->chore_id)
                || ($chore->wheel_eligible && $chores->stateFor($profile, $chore) === 'ready'))
            ->sortBy('id')
            ->values();

        if ($eligible->count() <= self::MAX_WHEEL_CHORES) {
            return $eligible;
        }

        $today = HouseholdClock::for($profile->household)->today()->toDateString();
        $seed = "{$profile->id}-{$today}";

        $mustInclude = $spinToday ? $eligible->firstWhere('id', $spinToday->chore_id) : null;

        $pool = $mustInclude
            ? $eligible->reject(fn (Chore $chore) => $chore->id === $mustInclude->id)
            : $eligible;

        $picked = $pool
            ->sortBy(fn (Chore $chore) => crc32("{$seed}-{$chore->id}"))
            ->take(self::MAX_WHEEL_CHORES - ($mustInclude ? 1 : 0));

        return ($mustInclude ? $picked->push($mustInclude) : $picked)
            ->sortBy('id')
            ->values();
    }

    public function spin(Profile $profile): Spin
    {
        if (! $profile->household->spin_enabled) {
            throw new RuntimeException('Spinning is disabled for this household.');
        }

        if ($this->hasSpunToday($profile)) {
            throw new RuntimeException('Already spun today.');
        }

        $eligible = $this->eligibleChoresFor($profile);

        if ($eligible->isEmpty()) {
            throw new RuntimeException('No chores available to spin for.');
        }

        $charged = $this->isCharged($profile);

        // A pet's Lucky Tail charges the spin by itself — the week's first,
        // and only when the kid has not charged it already, so it never lands
        // on top of a ticket they spent. Grown, the full OP table; young, a
        // better shot at 3x. See KnackService::luckyTailReady().
        $knacks = app(KnackService::class);
        $tail = $charged ? null : $knacks->luckyTailReady($profile);

        $chore = $eligible->random();
        $multiplier = $this->rollMultiplier($charged || $tail === PetStage::Adult, $tail === PetStage::Young);

        // Spent by the spin, not by the result. What the wheel landed on is
        // already decided by the time the charge clears, so there is nothing
        // left for it to improve.
        if ($charged) {
            $profile->update(['op_spin_armed_at' => null]);
        }

        $spin = Spin::create([
            'profile_id' => $profile->id,
            'spin_date' => HouseholdClock::for($profile->household)->today(),
            'chore_id' => $chore->id,
            'multiplier' => $multiplier,
            'was_op' => $charged || $tail === PetStage::Adult,
        ]);

        if ($tail !== null) {
            $knacks->use($profile, PetKnack::LuckyTail, ['spin_id' => $spin->id]);
        }

        // The wheel badges would otherwise wait for the next chore approval to
        // notice a spin that already happened.
        $this->badges->evaluate($profile);

        return $spin;
    }

    /**
     * Rolls today's boost again, on the same chore — what a pet's Fetch does
     * with a 2x. The plain table: an OP charge was spent by the first roll,
     * and a fetch is another go at the boost, not another charge. It can come
     * back 2x again, which is part of it.
     *
     * @return int the new multiplier
     */
    public function rerollBoost(Spin $spin): int
    {
        $spin->update(['multiplier' => $this->rollMultiplier(false)]);

        return $spin->multiplier;
    }

    /**
     * 2x, 3x or 4x — one roll read against whichever table the spin was paid
     * for. 4x exists only on the charged table, which is the whole of what the
     * ticket buys.
     */
    private function rollMultiplier(bool $charged, bool $lucky = false): int
    {
        $roll = mt_rand() / mt_getrandmax();

        // A young pet's Lucky Tail: the charged table's chance of a 3x or
        // better, all of it as 3x — no 4x until it is grown.
        if ($lucky && ! $charged) {
            return $roll < self::OP_QUAD_CHANCE + self::OP_TRIPLE_CHANCE ? 3 : 2;
        }

        if (! $charged) {
            return $roll < self::TRIPLE_CHANCE ? 3 : 2;
        }

        return match (true) {
            $roll < self::OP_QUAD_CHANCE => 4,
            $roll < self::OP_QUAD_CHANCE + self::OP_TRIPLE_CHANCE => 3,
            default => 2,
        };
    }

    /**
     * Moves today's boost to another chore on the wheel, keeping what it
     * rolled — a pet's Paw Nudge. See KnackService::nudge().
     */
    public function moveBoostTo(Spin $spin, Chore $chore): void
    {
        $spin->update(['chore_id' => $chore->id]);
    }

    public function multiplierFor(Profile $profile, Chore $chore): int
    {
        $spin = $this->today($profile);

        return ($spin && $spin->chore_id === $chore->id) ? $spin->multiplier : 1;
    }
}
