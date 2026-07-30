<?php

namespace App\Enums;

enum LedgerKind: string
{
    case Earn = 'earn';
    case Spend = 'spend';
    case CashIn = 'cash_in';
    case CashOut = 'cash_out';
    case Adjustment = 'adjustment';
}
