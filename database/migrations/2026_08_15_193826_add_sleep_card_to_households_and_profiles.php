<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The own-bed card: a morning check-in for the kids who need encouragement
 * sleeping in their own bed.
 *
 * Off everywhere by default, and twice over — a household switch and a per-kid
 * one. Age is deliberately *not* the gate: a nine-year-old who doesn't need it
 * shouldn't be asked every morning, and an eleven-year-old who does shouldn't
 * be locked out. A parent decides, per kid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->boolean('sleep_card_enabled')->default(false)->after('spin_enabled');
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->boolean('sleep_card_enabled')->default(false)->after('daily_points_goal');

            // Three numbers, each with exactly one job.
            //
            // `nights` only ever goes up and is what the rewards are paid on,
            // so a bad night costs nothing that was already earned. `run` is
            // the consecutive count and does reset — that is the number a kid
            // doesn't want to break, and without it both `best_run` and the
            // Night Saver perk would have nothing to act on. `best_run` is the
            // high-water mark, which is the part that can never be taken away.
            $table->unsignedInteger('sleep_nights')->default(0)->after('sleep_card_enabled');
            $table->unsignedInteger('sleep_run')->default(0)->after('sleep_nights');
            $table->unsignedInteger('sleep_best_run')->default(0)->after('sleep_run');

            // Payout high-water marks, for the same reason
            // `tickets_granted_through_level` and
            // `streak_milestone_paid_through` exist: a parent can nudge these
            // counters by hand from the console, and without a mark, nudging
            // one down and back up would pay the same constellation twice.
            $table->unsignedInteger('sleep_constellations_paid')->default(0)->after('sleep_best_run');
            $table->unsignedInteger('sleep_run_paid_through')->default(0)->after('sleep_constellations_paid');

            // The run milestone waiting to be opened, exactly as
            // `pending_streak_chest` works — the tickets are already banked,
            // this is only the reveal.
            $table->unsignedInteger('pending_sleep_chest')->nullable()->after('sleep_run_paid_through');
        });
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn('sleep_card_enabled');
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn([
                'sleep_card_enabled',
                'sleep_nights',
                'sleep_run',
                'sleep_best_run',
                'sleep_constellations_paid',
                'sleep_run_paid_through',
                'pending_sleep_chest',
            ]);
        });
    }
};
