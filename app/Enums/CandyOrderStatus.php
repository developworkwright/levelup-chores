<?php

namespace App\Enums;

enum CandyOrderStatus: string
{
    /** Paid for, in a grown-up's queue. */
    case Waiting = 'waiting';

    case HandedOver = 'handed_over';

    /** Turned down, and the tokens given back. */
    case Refunded = 'refunded';
}
