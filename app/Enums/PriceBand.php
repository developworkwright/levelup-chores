<?php

namespace App\Enums;

/**
 * "What can I do for about $2?" — the board's price shelves.
 *
 * A six-year-old asks for jobs in dollars, and the board answers in points, so
 * the bands are declared in **dollars** and resolved against the household's
 * own `points_per_dollar`. Declaring the point ranges outright would be right
 * for the default rate of 100 and quietly wrong for every household that
 * changed it — a `$1–2` button offering 100–199 points is `$2–4` at a rate of
 * 50.
 *
 * Bounds are min-inclusive, max-exclusive, so the four bands tile the range
 * with no chore falling between two of them.
 *
 * **There is no "under $1" band.** A household board starts at $1 and tops out
 * near $10, so the shelf below would always be empty and the one at the top
 * usually is — that one renders anyway. An empty band that sometimes fills is
 * a promise; hiding it costs the eldest kid the one place he goes looking for
 * the occasional big one-time job.
 */
enum PriceBand: int
{
    case QuickOnes = 0;
    case ARealJob = 1;
    case HalfADay = 2;
    case RareOnes = 3;

    /** The button's big line. */
    public function label(): string
    {
        return match ($this) {
            self::QuickOnes => '$1–2',
            self::ARealJob => '$2–5',
            self::HalfADay => '$5–10',
            self::RareOnes => '$10+',
        };
    }

    /** The button's small line — what the money buys you in work. */
    public function sub(): string
    {
        return match ($this) {
            self::QuickOnes => 'Quick ones',
            self::ARealJob => 'A real job',
            self::HalfADay => 'Half a day',
            self::RareOnes => 'Rare ones',
        };
    }

    /** Dollars, inclusive. */
    public function fromDollars(): int
    {
        return match ($this) {
            self::QuickOnes => 1,
            self::ARealJob => 2,
            self::HalfADay => 5,
            self::RareOnes => 10,
        };
    }

    /** Dollars, exclusive. Null on the open-ended top band. */
    public function toDollars(): ?int
    {
        return match ($this) {
            self::QuickOnes => 2,
            self::ARealJob => 5,
            self::HalfADay => 10,
            self::RareOnes => null,
        };
    }

    public function contains(int $points, int $pointsPerDollar): bool
    {
        if ($points < $this->fromDollars() * $pointsPerDollar) {
            return false;
        }

        $ceiling = $this->toDollars();

        return $ceiling === null || $points < $ceiling * $pointsPerDollar;
    }
}
