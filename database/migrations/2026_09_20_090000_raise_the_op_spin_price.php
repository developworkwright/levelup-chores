<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The OP Spin was a single ticket — the cheapest thing in the shop. Pets made
 * that a problem: a Lucky Tail's Power Treat is priced one ticket under the
 * perk it matches and floored at one, so at a cost of 1 the treat cost exactly
 * as much as simply buying the spin, and a young pet's treat bought strictly
 * less (no 4x). Five puts the pet back in front.
 *
 * Only households still on the old default are moved. A grown-up who has
 * priced it themselves — anything other than 1 — keeps their own number, the
 * same rule every other perk-pricing migration here follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bonus_perks')
            ->where('effect', 'op_spin')
            ->where('cost', 1)
            ->update(['cost' => 5]);
    }

    public function down(): void
    {
        DB::table('bonus_perks')
            ->where('effect', 'op_spin')
            ->where('cost', 5)
            ->update(['cost' => 1]);
    }
};
