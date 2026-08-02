<?php

namespace App\Enums;

enum SiblingOfferStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting',
            self::Accepted => 'Traded',
            self::Declined => 'Turned down',
            self::Cancelled => 'Taken back',
            self::Expired => 'Ran out of time',
        };
    }
}
