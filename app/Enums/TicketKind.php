<?php

namespace App\Enums;

enum TicketKind: string
{
    case LevelUp = 'level_up';
    case Badge = 'badge';
    case Purchase = 'purchase';
    case Adjustment = 'adjustment';

    /** Tickets moving between two kids in the same household (a sibling trade). */
    case Trade = 'trade';

    /** Paid for naming three things you're grateful for. */
    case Gratitude = 'gratitude';

    /** Paid out to the whole household when a monster goes down. */
    case BossDefeat = 'boss_defeat';

    public function label(): string
    {
        return match ($this) {
            self::LevelUp => 'Level up',
            self::Badge => 'Badge',
            self::Purchase => 'Purchase',
            self::Adjustment => 'Adjustment',
            self::Trade => 'Trade',
            self::Gratitude => 'Gratitude',
            self::BossDefeat => 'Boss defeated',
        };
    }
}
