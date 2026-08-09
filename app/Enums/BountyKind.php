<?php

namespace App\Enums;

/**
 * Which way round a bounty runs.
 *
 * The two are mirror images, and the whole service is written against that:
 * every bounty has a worker and a payer, and the kind is the only thing that
 * says which end of the deal the poster is standing at. Everything downstream —
 * who escrows, who marks it done, who confirms — falls out of those two.
 */
enum BountyKind: string
{
    /** "I'll pay 100 pts if someone makes my bed." The poster pays. */
    case Wanted = 'wanted';

    /** "I'll wash the car for 200 pts." The poster does the work. */
    case Offered = 'offered';

    public function label(): string
    {
        return match ($this) {
            self::Wanted => 'Job wanted',
            self::Offered => 'Job offered',
        };
    }

    /** How the board headlines it. */
    public function headline(): string
    {
        return match ($this) {
            self::Wanted => 'Wants this done',
            self::Offered => 'Will do this',
        };
    }

    /** What the taker is signing up for. */
    public function takeLabel(): string
    {
        return match ($this) {
            self::Wanted => 'I\'ll do it',
            self::Offered => 'Hire them',
        };
    }

    /** Whether the kid who posted it is the one who pays out. */
    public function posterPays(): bool
    {
        return $this === self::Wanted;
    }

    /**
     * Whether a parent can take this on. Only an offer of work — a parent
     * cannot answer "someone please make my bed" by paying a kid to do it,
     * which is just a chore, and chores already exist.
     */
    public function hireable(): bool
    {
        return $this === self::Offered;
    }
}
