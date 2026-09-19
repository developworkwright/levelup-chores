<?php

namespace App\Enums;

/**
 * Why a kid's arcade token balance moved. See App\Services\TokenService.
 *
 * Only the first two count against the daily cap. The cap is on what the
 * machines pay out in a day; a week's win, a refund or a grown-up's nudge is
 * not the machine paying, and a day's ceiling must not eat any of them.
 */
enum TokenKind: string
{
    /** A run on a ranked game: one for having a go, one per new rung. */
    case Run = 'run';

    /** Opening the toy, once a day. It keeps no score, so it pays flat. */
    case Toy = 'toy';

    /** Topping a game's board for a finished week. */
    case WeeklyPrize = 'weekly_prize';

    /** Swapped at the counter for a bonus ticket. */
    case Ticket = 'ticket';

    /** A snack, toy or bed bought for the pet. */
    case Prize = 'prize';

    /** Real sweets, waiting on a grown-up. */
    case Candy = 'candy';

    /** Sweets a grown-up turned down, given back. */
    case Refund = 'refund';

    case Adjustment = 'adjustment';

    /** Whether this kind is the machine paying out, which is what the cap limits. */
    public function countsTowardCap(): bool
    {
        return $this === self::Run || $this === self::Toy;
    }

    /**
     * The kinds the cap limits, as their stored values.
     *
     * @return list<string>
     */
    public static function cappedValues(): array
    {
        return array_values(array_map(
            fn (self $kind) => $kind->value,
            array_filter(self::cases(), fn (self $kind) => $kind->countsTowardCap()),
        ));
    }
}
