<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turning down a redemption, and putting the points back.
 *
 * Points come out of a balance the moment a kid asks — that is what stops them
 * over-redeeming — so a request a parent turns down has already been paid for.
 * An accidental tap therefore costs real money until somebody notices, and the
 * only fix available was a hand adjustment, which lands in the ledger looking
 * like a parent gave points away.
 *
 * Its own columns rather than reusing `fulfilled_at` / `fulfilled_by`: a
 * rejection is not a fulfilment with a flag on it, and a parent asking "who
 * turned this down and when" is a different question from "who handed it
 * over".
 */
return new class extends Migration
{
    public function up(): void
    {
        // `status` was created as an enum of exactly ['pending','fulfilled'],
        // which on both engines is a constraint and not just documentation —
        // SQLite writes a CHECK, MySQL an ENUM — so a third state has to be
        // let in before anything can be written with it. Rewritten as a plain
        // string rather than a widened enum: RedemptionStatus is the list, and
        // keeping a second copy of it in the schema means the next state added
        // fails at runtime in exactly this way.
        Schema::table('redemptions', function (Blueprint $table) {
            $table->string('status', 32)->default('pending')->change();
        });

        // Same story one table over: `ledger_entries.kind` is an enum too, and
        // it has already been widened once by hand (see the `transfer`
        // migration) — which is the pattern this stops repeating. The refund
        // is a new kind and would be rejected by the constraint otherwise.
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->string('kind', 32)->change();
        });

        Schema::table('redemptions', function (Blueprint $table) {
            $table->timestamp('rejected_at')->nullable()->after('fulfilled_by_profile_id');
            $table->unsignedBigInteger('rejected_by_profile_id')->nullable()->index()->after('rejected_at');
            // Optional and short. "You already have one of those" is worth
            // saying; a form that demands a reason before a parent can undo a
            // misclick is not.
            $table->string('reject_reason', 160)->nullable()->after('rejected_by_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('redemptions', function (Blueprint $table) {
            $table->dropColumn(['rejected_at', 'rejected_by_profile_id', 'reject_reason']);
        });

        // Rows the enums can't hold have to go before they are put back, or
        // the rollback fails on the very data this migration made possible.
        DB::table('redemptions')->where('status', 'rejected')->update(['status' => 'pending']);
        DB::table('ledger_entries')->where('kind', 'refund')->delete();

        Schema::table('redemptions', function (Blueprint $table) {
            $table->enum('status', ['pending', 'fulfilled'])->default('pending')->change();
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->enum('kind', ['earn', 'spend', 'cash_in', 'cash_out', 'adjustment', 'transfer'])->change();
        });
    }
};
