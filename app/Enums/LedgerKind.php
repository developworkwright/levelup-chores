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

    /**
     * Points handed back for a redemption a parent turned down.
     *
     * Deliberately its own kind rather than an `Adjustment`. An adjustment is
     * a grown-up deciding to move a number; this is the app undoing something
     * it charged for, and a parent scanning the ledger needs to be able to
     * tell those apart. It also has to *net out* of the amount spent — see
     * BadgeService's big_spender — which an adjustment would not.
     */
    case Refund = 'refund';
}
