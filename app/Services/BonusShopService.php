<?php

namespace App\Services;

use App\Enums\PerkEffect;
use App\Exceptions\InsufficientTicketsException;
use App\Exceptions\PerkUnavailableException;
use App\Models\BonusPerk;
use App\Models\Profile;
use Illuminate\Support\Collection;

/**
 * Buying a perk applies it immediately — there's no inventory to hold and no
 * parent approval step. That's the line between this and the Loot Shop: loot
 * is a real-world reward a parent hands over, a perk is a rule bending itself.
 *
 * Pricing and wording live on the bonus_perks row so they can be retuned
 * without a deploy; only the behaviour behind PerkEffect is code.
 */
class BonusShopService
{
    public function __construct(
        private TicketService $tickets,
        private SpinService $spins,
        private ChoreService $chores,
    ) {}

    /**
     * Every enabled perk with whether it can be bought right now and why not.
     *
     * @return Collection<int, array{perk: BonusPerk, affordable: bool, usable: bool, reason: ?string}>
     */
    public function catalogFor(Profile $profile): Collection
    {
        return BonusPerk::where('household_id', $profile->household_id)
            ->enabled()
            ->orderBy('cost')
            ->get()
            ->map(function (BonusPerk $perk) use ($profile) {
                $reason = $this->unusableReason($profile, $perk->effect);

                return [
                    'perk' => $perk,
                    'affordable' => $profile->bonus_tickets >= $perk->cost,
                    'usable' => $reason === null,
                    'reason' => $reason,
                ];
            });
    }

    public function perkFor(Profile $profile, PerkEffect $effect): ?BonusPerk
    {
        return BonusPerk::where('household_id', $profile->household_id)
            ->enabled()
            ->where('effect', $effect)
            ->first();
    }

    /**
     * @throws InsufficientTicketsException
     * @throws PerkUnavailableException
     */
    public function purchase(Profile $profile, BonusPerk $perk): string
    {
        if (! $perk->enabled || $perk->household_id !== $profile->household_id) {
            throw new PerkUnavailableException('That perk is not available.');
        }

        if ($reason = $this->unusableReason($profile, $perk->effect)) {
            throw new PerkUnavailableException($reason);
        }

        if ($profile->bonus_tickets < $perk->cost) {
            throw new InsufficientTicketsException($perk->cost - $profile->bonus_tickets);
        }

        // Apply first, charge second. If applying fails the kid keeps their
        // tickets, which is the right way round to get this wrong.
        $outcome = $this->apply($profile, $perk->effect);

        $this->tickets->spend($profile, $perk->cost, $perk->name, $perk);

        return $outcome;
    }

    /** Human-readable result, used for the celebration message. */
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

        return "New quest: {$quest->chore->name}";
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

    private function unusableReason(Profile $profile, PerkEffect $effect): ?string
    {
        return match ($effect) {
            PerkEffect::WheelRespin => $this->spins->hasSpunToday($profile)
                ? null
                : 'Spin the wheel first',
            PerkEffect::QuestReroll => $this->chores->isQuestDoneToday($profile)
                ? 'Today\'s quest is already cleared'
                : null,
            PerkEffect::StreakRestore => $this->chores->repairableStreakDate($profile)
                ? null
                : 'No broken streak to fix',
            PerkEffect::MysteryHint => $this->mysteryHintReason($profile),
        };
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
        return $this->chores->mysteryClaimant($chore) ? 'Already found today' : null;
    }
}
