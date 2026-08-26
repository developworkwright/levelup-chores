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
        private MonsterService $monsters,
        private SleepService $sleep,
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
     * `$input` is for the one perk that needs the kid to say something as well
     * as tap: naming a monster wants a target and a name. Every other effect
     * ignores it, which is why it is an optional bag rather than a signature
     * every caller has to satisfy.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws PerkUnavailableException
     */
    public function use(Profile $profile, PerkEffect $effect, array $input = []): string
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
        $outcome = $this->apply($profile, $effect, $input);

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
            PerkEffect::StreakRestore => $this->streakRestoreReason($profile),
            PerkEffect::MysteryHint => $this->mysteryHintReason($profile),
            PerkEffect::NameMonster => $this->monsters->nameable($profile->household) === null
                ? 'Nothing left to name'
                : null,
            PerkEffect::NightSaver => $this->sleep->saveReason($profile),
            PerkEffect::QuestCharm => $this->questCharmReason($profile),
        };
    }

    /**
     * A charm has to be cast at a chest that is still shut.
     *
     * Charming cards the kid has already read isn't a gamble, it's shopping —
     * they would only ever buy it on a hand worth improving. The wording says
     * "before you open it" rather than "too late" because on most days the
     * refusal is a timing mistake they can avoid tomorrow.
     */
    private function questCharmReason(Profile $profile): ?string
    {
        $quest = $this->chores->questFor($profile);

        return match (true) {
            $quest->completed_at !== null => "Today's quest is already cleared",
            $quest->isCharmed() => "Today's quest is already charmed",
            $quest->dealt_at !== null => 'Charm the chest before you open it',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function apply(Profile $profile, PerkEffect $effect, array $input = []): string
    {
        return match ($effect) {
            PerkEffect::WheelRespin => $this->applyWheelRespin($profile),
            PerkEffect::QuestReroll => $this->applyQuestReroll($profile),
            PerkEffect::StreakRestore => $this->applyStreakRestore($profile),
            PerkEffect::MysteryHint => $this->applyMysteryHint($profile),
            PerkEffect::NameMonster => $this->applyNameMonster($profile, $input),
            PerkEffect::NightSaver => $this->applyNightSaver($profile),
            PerkEffect::QuestCharm => $this->applyQuestCharm($profile),
        };
    }

    private function applyQuestCharm(Profile $profile): string
    {
        if (! $this->chores->charmQuest($profile)) {
            throw new PerkUnavailableException('There is no chest left to charm today.');
        }

        // Deliberately promises nothing specific. What the charm did isn't
        // decided until the lid comes up, and naming an outcome here would
        // spend the reveal a beat before the animation that carries it.
        return 'The chest is charmed — open it and see.';
    }

    private function applyNightSaver(Profile $profile): string
    {
        // blockedReason() has already said there is a night to buy back, and a
        // perk that fails to apply is not consumed — so a false here would mean
        // the two disagreed.
        if (! $this->sleep->saveNight($profile)) {
            throw new PerkUnavailableException('There is no night to buy back.');
        }

        return 'Night bought back — your run is safe.';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function applyNameMonster(Profile $profile, array $input): string
    {
        try {
            $name = $this->monsters->nameMonster(
                $profile->household,
                (string) ($input['name'] ?? ''),
            );
        } catch (\RuntimeException $e) {
            // Rethrown as the shop's own exception so the perk stays in the
            // pocket and the kid gets the sentence rather than a stack trace.
            throw new PerkUnavailableException($e->getMessage());
        }

        return "Say hello to {$name}.";
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

    /**
     * A restore only saves a chain that's still hanging. Clearing today's
     * quest starts a new one and closes the window, and saying so is worth a
     * separate message — "no broken streak to fix" reads as a bug to a kid
     * looking at a streak they know they just broke.
     */
    private function streakRestoreReason(Profile $profile): ?string
    {
        if ($this->chores->repairableStreakDate($profile)) {
            return null;
        }

        return $this->chores->isQuestDoneToday($profile)
            ? "Too late — today's quest is already done"
            : 'No broken streak to fix';
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
        if ($this->chores->mysteryFinderFor($profile->household)) {
            return 'Already found today';
        }

        // A sibling's pending claim locks the chore for the whole household, so
        // the clue is spent even before a parent gets to it. The kid's *own*
        // claim deliberately doesn't count: a label that flipped on their own
        // tap would let them submit the board one chore at a time and read off
        // which one carries the bonus — the same hole that moving the award to
        // approval closed everywhere else.
        $claimant = $this->chores->claimantFor($chore);

        return $claimant && $claimant->profile_id !== $profile->id
            ? 'Someone got there first'
            : null;
    }
}
