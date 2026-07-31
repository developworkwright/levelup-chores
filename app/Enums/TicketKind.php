<?php

namespace App\Enums;

enum TicketKind: string
{
    case LevelUp = 'level_up';
    case Badge = 'badge';
    case Purchase = 'purchase';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::LevelUp => 'Level up',
            self::Badge => 'Badge',
            self::Purchase => 'Purchase',
            self::Adjustment => 'Adjustment',
        };
    }
}
