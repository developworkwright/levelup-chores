<?php

namespace App\Services;

use App\Models\Chore;
use App\Models\Profile;
use App\Models\Spin;
use Illuminate\Support\Collection;
use RuntimeException;

class SpinService
{
    /** 35% chance of a 3x boost, otherwise 2x — matches the design prototype's spin odds. */
    private const TRIPLE_CHANCE = 0.35;

    /** Keeps the wheel legible and its segments tappable/readable at any household size. */
    public const MAX_WHEEL_CHORES = 10;

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

    /**
     * Drops today's spin so the wheel is available again. Returns the spin
     * that was cleared, or null if there wasn't one — callers use that to
     * avoid charging for a respin that had nothing to undo.
     */
    public function clearToday(Profile $profile): ?Spin
    {
        $spin = $this->today($profile);

        $spin?->delete();

        return $spin;
    }

    /**
     * The chores eligible to appear on the bonus wheel today — age
     * appropriate, not today's daily quest, and not already claimed by
     * anyone in the household — capped to MAX_WHEEL_CHORES.
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
        $questChoreId = $chores->questFor($profile)->chore_id;

        $spinToday = $this->today($profile);

        $eligible = $profile->household->chores
            ->filter(fn (Chore $chore) => $chore->isAppropriateFor($profile))
            ->reject(fn (Chore $chore) => $chore->id === $questChoreId)
            // Cooldowns are household-wide, so a chore a sibling already
            // claimed can no longer be earned — landing a 3x boost on it
            // would be a prize that pays nothing. Whatever today's spin
            // already landed on stays regardless: the wheel has to keep
            // showing the result it gave, even once that chore is spent.
            ->filter(fn (Chore $chore) => ($spinToday && $chore->id === $spinToday->chore_id)
                || $chores->stateFor($profile, $chore) === 'ready')
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

        $chore = $eligible->random();
        $multiplier = (mt_rand() / mt_getrandmax()) < self::TRIPLE_CHANCE ? 3 : 2;

        return Spin::create([
            'profile_id' => $profile->id,
            'spin_date' => HouseholdClock::for($profile->household)->today(),
            'chore_id' => $chore->id,
            'multiplier' => $multiplier,
        ]);
    }

    public function multiplierFor(Profile $profile, Chore $chore): int
    {
        $spin = $this->today($profile);

        return ($spin && $spin->chore_id === $chore->id) ? $spin->multiplier : 1;
    }
}
