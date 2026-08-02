<?php

namespace App\Enums;

enum ChoreCadence: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';

    /** No cooldown — always claimable again immediately, for chores that can happen more than once a day. */
    case Unlimited = 'unlimited';

    /**
     * Up for grabs exactly once, first come first served. Claiming it takes it
     * off the board for the whole household — not until tomorrow, but until a
     * parent reactivates it.
     */
    case Once = 'once';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Unlimited => 'Unlimited',
            self::Once => 'One-time',
        };
    }

    /** How the cadence reads on a parent's chore row. */
    public function summary(): string
    {
        return match ($this) {
            self::Daily => 'Daily · Cooldown 24h',
            self::Weekly => 'Weekly · Cooldown 7d',
            self::Unlimited => 'Unlimited · No cooldown',
            self::Once => 'One-time · First come, first served',
        };
    }

    /** How the cadence reads on a kid's chore card. */
    public function kidLabel(): string
    {
        return match ($this) {
            self::Daily => 'Once a day',
            self::Weekly => 'Once a week',
            self::Unlimited => 'Anytime · No limit',
            self::Once => 'First come, first served',
        };
    }

    /** The next case for the parent's cycle button, wrapping past the last one. */
    public function next(): self
    {
        $cases = self::cases();

        return $cases[(array_search($this, $cases, true) + 1) % count($cases)];
    }
}
