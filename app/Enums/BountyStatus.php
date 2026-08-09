<?php

namespace App\Enums;

enum BountyStatus: string
{
    /** On the board, nobody has taken it. */
    case Open = 'open';

    /** A sibling has taken it; the work has not been reported done yet. */
    case Claimed = 'claimed';

    /** The worker says it is finished, waiting on the payer to agree. */
    case Done = 'done';

    /** Settled between two kids. */
    case Paid = 'paid';

    /** A parent took an offer of work, turning it into a one-time chore. */
    case Hired = 'hired';

    case Cancelled = 'cancelled';

    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Up for grabs',
            self::Claimed => 'Taken',
            self::Done => 'Waiting on the payer',
            self::Paid => 'Settled',
            self::Hired => 'Hired by a parent',
            self::Cancelled => 'Taken back',
            self::Expired => 'Ran out of time',
        };
    }

    /** Still moving. Everything else is history. */
    public function isLive(): bool
    {
        return in_array($this, [self::Open, self::Claimed, self::Done], true);
    }
}
