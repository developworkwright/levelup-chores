<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `kind` is an enum column, so points moving between two siblings needs the
 * new 'transfer' value adding before any of it can be written. Deliberately
 * not reusing 'spend': that drives the Loot Shop's big_spender threshold.
 */
return new class extends Migration
{
    private const KINDS = ['earn', 'spend', 'cash_in', 'cash_out', 'adjustment'];

    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->enum('kind', [...self::KINDS, 'transfer'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->enum('kind', self::KINDS)->change();
        });
    }
};
