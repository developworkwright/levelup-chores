<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a Quest Charm actually paid on this piece of work, frozen at the claim.
 *
 * It is already inside `points_awarded`; this records it separately for the one
 * question `points_awarded` cannot answer — *has the charm on this chore been
 * paid to this kid today?*
 *
 * Without it the charm pays every time a chore is submitted, and
 * `ChoreCadence::Unlimited` is the cadence with no cooldown to stop that: a
 * charmed unlimited chore paid half again on the first tap and on the tenth.
 * `chore_completions.help_wanted` exists for exactly the same reason one column
 * along — a flagged unlimited chore was a ticket printer until it did.
 *
 * Frozen rather than derived, like the two flags beside it: a charm lapses at
 * the household rollover, and what a kid was promised when they chose the job
 * must not change when it does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chore_completions', function (Blueprint $table) {
            $table->unsignedInteger('charm_bonus')->default(0)->after('help_wanted');
        });
    }

    public function down(): void
    {
        Schema::table('chore_completions', function (Blueprint $table) {
            $table->dropColumn('charm_bonus');
        });
    }
};
