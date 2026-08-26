<?php

namespace App\Enums;

/**
 * Where a blow came from.
 *
 * Kept because only one of the two belongs to anybody: an `Adjust` is a parent
 * moving the bar by hand, and must never count toward a kid's share of the
 * kill.
 */
enum MonsterHitKind: string
{
    case Hit = 'hit';
    case Adjust = 'adjust';

    public function label(): string
    {
        return match ($this) {
            self::Hit => 'Hit',
            self::Adjust => 'Adjusted',
        };
    }

    /** Whether this kind counts toward the kid's name on the leaderboard. */
    public function isEarned(): bool
    {
        return $this !== self::Adjust;
    }
}
