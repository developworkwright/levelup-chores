<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who topped a finished week, and whether it paid.
 *
 * The prize is three bonus tickets to the tallest tower of the week, and this
 * table exists for one reason: to make that happen exactly once. There is no
 * scheduler behind it — the payout is settled lazily, by whoever opens the
 * arcade next — so without a row to point at, two kids loading the page at the
 * same moment on a Monday morning both find an unpaid week and both pay it.
 * The unique key is what stops that, in the one place it cannot be raced.
 *
 * It records the parent wins too, and that is not an oversight. A grown-up who
 * tops the week wins nothing — that was the rule the moment they were let onto
 * the board — so their week mints no ticket entry at all. Were "has this week
 * paid?" answered by looking for the ticket entry, a week a parent won would
 * look unpaid forever and be re-settled on every page load. So the question is
 * "has this week been *settled*", which is what a row here means, and
 * `tickets` records what that settlement was worth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arcade_week_prizes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            // ISO year-week, matching arcade_scores.week — e.g. "2026-W35".
            $table->string('week', 8);
            // Who won it. Nullable so a week can be settled as "nobody played"
            // rather than left open and re-checked forever.
            $table->unsignedBigInteger('profile_id')->nullable()->index();
            $table->unsignedSmallInteger('score')->nullable();
            // Zero for a week a parent won, or one nobody played.
            $table->unsignedSmallInteger('tickets')->default(0);
            $table->timestamp('created_at');

            // The whole point of the table.
            $table->unique(['household_id', 'week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arcade_week_prizes');
    }
};
