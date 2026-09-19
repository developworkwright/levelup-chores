<?php

namespace App\Enums;

/**
 * A rarer pet's own trick, on top of its style. Commons have none.
 *
 * Which knacks a pet can have is its tier's call (rarity()), and how strong
 * one is is its age's: a baby has not learned it yet, a young pet does it at
 * half strength, and a grown one does it properly — see allowance() and
 * describe(). Half strength is a weaker version of the trick wherever one
 * makes sense (a young Sniffer leaves five chores in the running, a grown one
 * three), and the same trick less often only where it doesn't.
 *
 * A knack is used where it matters, on the page it matters on: the pet goes
 * to the thing and offers it, and the kid taps the offer. Guard Dog, Night
 * Owl and Lucky Tail go off on their own (automatic()), and the rest of the
 * always-on ones never need using at all (allowance() is null).
 *
 * Uses are counted per kid and per knack, over a rolling window, in
 * `pet_knack_uses` — see App\Services\KnackService.
 */
enum PetKnack: string
{
    case CoinSniffer = 'coin_sniffer';
    case BigPockets = 'big_pockets';
    case Fetch = 'fetch';
    case Sniffer = 'sniffer';
    case PawNudge = 'paw_nudge';
    case SecondLook = 'second_look';
    case LuckyTail = 'lucky_tail';
    case GoodLuckCharm = 'good_luck_charm';
    case Digger = 'digger';
    case GuardDog = 'guard_dog';
    case NightOwl = 'night_owl';
    case Sidekick = 'sidekick';

    public function label(): string
    {
        return match ($this) {
            self::CoinSniffer => 'Coin Sniffer',
            self::BigPockets => 'Big Pockets',
            self::Fetch => 'Fetch',
            self::Sniffer => 'Sniffer',
            self::PawNudge => 'Paw Nudge',
            self::SecondLook => 'Second Look',
            self::LuckyTail => 'Lucky Tail',
            self::GoodLuckCharm => 'Good Luck Charm',
            self::Digger => 'Digger',
            self::GuardDog => 'Guard Dog',
            self::NightOwl => 'Night Owl',
            self::Sidekick => 'Sidekick',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::CoinSniffer => 'fa-coins',
            self::BigPockets => 'fa-sack-dollar',
            self::Fetch => 'fa-rotate-left',
            self::Sniffer => 'fa-magnifying-glass',
            self::PawNudge => 'fa-paw',
            self::SecondLook => 'fa-arrows-rotate',
            self::LuckyTail => 'fa-bolt',
            self::GoodLuckCharm => 'fa-star',
            self::Digger => 'fa-cube',
            self::GuardDog => 'fa-shield-dog',
            self::NightOwl => 'fa-moon',
            self::Sidekick => 'fa-hand-fist',
        };
    }

    /**
     * The tier a knack belongs to. Rare ones are small everyday help, Epic
     * ones are worth tickets in the Bonus Shop, and Legendary ones rescue a
     * streak or a run — the most a pet can do for a kid.
     */
    public function rarity(): PetRarity
    {
        return match ($this) {
            self::CoinSniffer, self::BigPockets, self::Fetch, self::Sniffer => PetRarity::Rare,
            self::PawNudge, self::SecondLook, self::LuckyTail, self::GoodLuckCharm, self::Digger => PetRarity::Epic,
            self::GuardDog, self::NightOwl, self::Sidekick => PetRarity::Legendary,
        };
    }

    /**
     * How many times it can be used, over how many days, at this age — or
     * null for a knack that is simply always on, and for a baby, which has
     * not learned it yet (see unlocked()).
     *
     * A rolling window, not a calendar one: a use comes back that many days
     * after it was spent, so "next one Thursday" is always a real answer and
     * nothing has to reset on a schedule.
     *
     * @return array{uses: int, days: int}|null
     */
    public function allowance(PetStage $stage): ?array
    {
        if (! self::unlocked($stage)) {
            return null;
        }

        $grown = $stage === PetStage::Adult;

        return match ($this) {
            self::CoinSniffer, self::BigPockets, self::Sidekick => null,
            self::Fetch, self::SecondLook, self::Digger => ['uses' => 1, 'days' => $grown ? 7 : 14],
            self::Sniffer => ['uses' => $grown ? 2 : 1, 'days' => 7],
            self::PawNudge => ['uses' => 2, 'days' => 7],
            self::LuckyTail, self::GoodLuckCharm => ['uses' => 1, 'days' => 7],
            self::GuardDog, self::NightOwl => ['uses' => 1, 'days' => $grown ? 30 : 60],
        };
    }

    /**
     * The Bonus Shop perk that does what this knack does, if there is one —
     * what a Power Treat for it is priced against (KnackService::treatPrice()),
     * one ticket under, so a pet with the knack is always the cheaper way.
     */
    public function matchingPerk(): ?PerkEffect
    {
        return match ($this) {
            self::Fetch, self::SecondLook, self::PawNudge => PerkEffect::WheelRespin,
            self::LuckyTail => PerkEffect::OpSpin,
            self::GoodLuckCharm => PerkEffect::QuestCharm,
            self::Sniffer => PerkEffect::MysteryHint,
            self::GuardDog => PerkEffect::StreakRestore,
            self::NightOwl => PerkEffect::NightSaver,
            default => null,
        };
    }

    /** Whether it is always on — nothing to spend, and a treat doubles it instead. */
    public function alwaysOn(): bool
    {
        return in_array($this, [self::CoinSniffer, self::BigPockets, self::Sidekick], true);
    }

    /** Whether a pet this age can do its knack at all. A baby is still learning. */
    public static function unlocked(PetStage $stage): bool
    {
        return $stage !== PetStage::Baby;
    }

    /**
     * Whether it goes off by itself rather than being offered. The rescues,
     * because a kid who missed a day is not around to tap anything, and
     * "you had it and forgot" is a bad thing to hand a kid; and Lucky Tail,
     * which charges the week's first spin before there is anything to tap.
     */
    public function automatic(): bool
    {
        return in_array($this, [self::GuardDog, self::NightOwl, self::LuckyTail], true);
    }

    /** What it does at this age, in a kid's words. A baby's says when it learns. */
    public function describe(PetStage $stage): string
    {
        if (! self::unlocked($stage)) {
            return 'Still learning this one — it can do it once it grows up a bit.';
        }

        $grown = $stage === PetStage::Adult;

        return match ($this) {
            self::CoinSniffer => $grown
                ? 'Sniffs out a bonus token every time you reach a new rung in a game.'
                : 'Sniffs out a bonus token on every other new rung in a game.',
            self::BigPockets => $grown
                ? 'You can earn 10 more arcade tokens a day.'
                : 'You can earn 5 more arcade tokens a day.',
            self::Fetch => 'Landed a 2x on the Bonus Wheel? It fetches you another go at the boost — same chore.',
            self::Sniffer => $grown
                ? 'Sniffs the quest board and narrows the Mystery Chore down to 3.'
                : 'Sniffs the quest board and narrows the Mystery Chore down to 5.',
            self::PawNudge => $grown
                ? 'Bats the Bonus Wheel one chore over — you pick which way.'
                : 'Bats the Bonus Wheel one chore over — whichever way it likes!',
            self::SecondLook => 'Spins the Bonus Wheel again, chore and boost.',
            self::LuckyTail => $grown
                ? 'Charges your first spin of the week — a shot at 4x.'
                : 'Gives your first spin of the week a better shot at 3x.',
            self::GoodLuckCharm => $grown
                ? 'Casts a Quest Charm over your board.'
                : 'Makes one chore on your board pay half again.',
            self::Digger => 'Digs you a free hit on the Lucky Block.',
            self::GuardDog => 'Guards your streak the day you miss.',
            self::NightOwl => 'Saves your bedtime run after a night out of your own bed.',
            self::Sidekick => $grown
                ? 'Jumps in on the monster — your chores hit 10% harder.'
                : 'Jumps in on the monster — your chores hit 5% harder.',
        };
    }
}
