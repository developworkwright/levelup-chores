<?php

namespace App\Enums;

enum RedemptionStatus: string
{
    case Pending = 'pending';
    case Fulfilled = 'fulfilled';
}
