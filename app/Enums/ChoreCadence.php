<?php

namespace App\Enums;

enum ChoreCadence: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';

    /** No cooldown — always claimable again immediately, for chores that can happen more than once a day. */
    case Unlimited = 'unlimited';
}
