<?php

namespace App\Services;

use App\Enums\PerkEffect;
use App\Exceptions\PerkUnavailableException;
use App\Models\OwnedPerk;
use App\Models\Profile;
use Illuminate\Support\Collection;

/**
 * Perks a kid owns but hasn't spent yet.
 *
 * Buying no longer fires the effect — it puts a perk in the pocket. That moves
 * every "can this be used right now" check from purchase time to use time,
 * which is the point: holding a Streak Restore before you need it is the whole
 * reason it's worth buying.
 */
class PerkInventoryService
{
    public function __construct(
        private SpinService $spins,
        private ChoreService $chores,
        private BadgeService $badges,
    ) {}

    public function grant(Profile $profile, PerkEffect $effect, string $source): OwnedPerk
    {
        return OwnedPerk::create([
            'profile_id' => $profile->id,
            'effect' => $effect,
            'source' => $source,
            'acquired_at' => now(),
        ]);
    }

    /** @return Collection<int, OwnedPerk> */
    public function unusedFor(Profile $profile): Collection
    {
        return OwnedPerk::where('profile_id', $profile->id)
            ->unused()
            ->orderBy('acquired_at')
            ->get();
    }

    /** How many of a given perk the kid is holding. */
    public function countOf(Profile $profile, PerkEffect $effect): int
    {
        return OwnedPerk::where('profile_id', $profile->id)
            ->unused()
            ->where('effect', $effect)
            ->count();
    }

    public function holds(Profile $profile, PerkEffect $effect): bool
    {
        return $this->countOf($profile, $effect) > 0;
    }

    /**
     * Spends the oldest unused copy of a perk and applies it.
     *
     * @throws PerkUnavailableException
     */
    public function use(Profile $profile, PerkEffect $effect): string
    {
        $owned = OwnedPerk::where('profile_id', $profile->id)
            ->unused()
            ->where('effect', $effect)
            ->oldest('acquired_at')
            ->first();

        if (! $owned) {
            throw new PerkUnavailableException("You don't have that perk to use.");
        }

        if ($reason = $this->blockedReason($profile, $effect)) {
            throw new PerkUnavailableException($reason);
        }

        // Apply first, mark spent second. A perk that couldn't be applied
        // stays in the pocket.
        $outcome = $this->apply($profile, $effect);

        $owned->consumed_at = now();
        $owned->save();

        // Spending a perk is what "Gadgeteer" counts, and a Streak Restore is
        // the only thing that ever unlocks "Comeback Kid".
        $this->badges->evaluate($profile);

        return $outcome;
    }

    /** Why this perk can't be used at this moment, or null when it can. */
    public function blockedReason(Profile $profile, PerkEffect $effect): ?string
    {
        return match ($effect) {
            PerkEffect::WheelRespin => $this->spins->hasSpunToday($profile)
                ? null
                : 'Spin the wheel first',
            PerkEffect::QuestReroll => $this->chores->isQuestDoneToday($profile)
                ? "Today's quest is already cleared"
                : null,
            PerkEffect::StreakRestore => $this->chores->repairableStreakDate($profile)
                ? null
                : 'No broken streak to fix',
            PerkEffect::MysteryHint => $this->mysteryHintReason($profile),
        };
    }

    private function apply(Profile $profile, PerkEffect $effect): string
    {
        return match ($effect) {
            PerkEffect::WheelRespin => $this->applyWheelRespin($profile),
            PerkEffect::QuestReroll => $this->applyQuestReroll($profile),
            PerkEffect::StreakRestore => $this->applyStreakRestore($profile),
            PerkEffect::MysteryHint => $this->applyMysteryHint($profile),
        };
    }

    private function applyWheelRespin(Profile $profile): string
    {
        if (! $this->spins->clearToday($profile)) {
            throw new PerkUnavailableException('You have not spun yet today.');
        }

        return 'Wheel reset — take another spin!';
    }

    private function applyQuestReroll(Profile $profile): string
    {
        $quest = $this->chores->rerollQuest($profile);

        if (! $quest) {
            throw new PerkUnavailableException('There is no other quest to swap to.');
        }

        return 'New quest in the chest — open it to see!';
    }

    private function applyStreakRestore(Profile $profile): string
    {
        if (! $this->chores->repairStreak($profile)) {
            throw new PerkUnavailableException('There is no broken streak to repair.');
        }

        return "Streak saved — back to {$profile->refresh()->streak} days!";
    }

    private function applyMysteryHint(Profile $profile): string
    {
        $hint = $this->chores->buyMysteryHint($profile);

        if (! $hint) {
            throw new PerkUnavailableException('There is no hint available today.');
        }

        return "Hint unlocked: {$hint}";
    }

    private function mysteryHintReason(Profile $profile): ?string
    {
        if ($this->chores->hasBoughtMysteryHint($profile)) {
            return 'Already unlocked today';
        }

        $chore = $this->chores->mysteryChoreFor($profile->household);

        if (! $chore || blank($chore->hint)) {
            return 'No hint available today';
        }

        // A clue is worthless once the race is over.
        return $this->chores->claimantFor($chore) ? 'Already found today' : null;
    }
}
