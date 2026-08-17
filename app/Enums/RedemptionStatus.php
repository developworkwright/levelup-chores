<?php

namespace App\Enums;

enum RedemptionStatus: string
{
    case Pending = 'pending';
    case Fulfilled = 'fulfilled';

    /** Turned down by a parent. The points went back — see StoreService::reject(). */
    case Rejected = 'rejected';
}
