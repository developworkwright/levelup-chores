<?php

namespace App\Enums;

enum LedgerKind: string
{
    case Earn = 'earn';
    case Spend = 'spend';
    case CashIn = 'cash_in';
    case CashOut = 'cash_out';
    case Adjustment = 'adjustment';

    /**
     * Points moving between two kids in the same household (a sibling offer).
     * Deliberately not `Spend`/`Earn`: those drive Loot Shop badge thresholds,
     * and paying your brother is not shopping.
     */
    case Transfer = 'transfer';
}
