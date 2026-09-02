<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The hours card: the second kind of bedtime card, and the one a kid graduates
 * onto once sleeping in their own bed has stopped being the hard part.
 *
 * ## Why the counters are a second set rather than the same ones
 *
 * The two cards ask different questions, so their numbers are not the same
 * number. A twenty-night own-bed run says nothing about how many hours anyone
 * slept, and carrying it across would start the new card on a score it hadn't
 * earned. Zeroing the old ones instead would be worse: the sky is drawn from
 * `sleep_nights`, so a kid would lose every constellation they had finished on
 * the morning they moved up — the app punishing them for graduating.
 *
 * So the own-bed counters freeze exactly where they stopped and stay on the
 * page as a finished thing, and the hours card counts its own nights from zero.
 * `sleep_constellations_paid` is deliberately not duplicated: the hours card
 * has no picture, and the sky belongs to the card that drew it.
 *
 * ## Minutes, not hours
 *
 * `minutes` avoids a decimal column and the float comparison that comes with
 * it — 7.5 hours is 450, and "did they clear eight" is an integer test. The
 * band (full / short / poor) is derived from it on read rather than stored, so
 * moving the six-hour line later doesn't leave rows disagreeing with the enum.
 *
 * `outcome` becomes nullable because an hours row has no outcome and an
 * own-bed row has no minutes. One table either way: a kid's sleep history
 * should read in one list across the graduation, not stop and restart.
 */
return new class extends Migration
{
    /**
     * Starting rates for the three bands, as multiples of `points_per_dollar`.
     * A short night is half a dollar, which is why these are expressed as a
     * numerator over two rather than the whole-dollar figures the own-bed
     * outcomes use.
     *
     * Written out rather than read from SleepBand: a migration records what
     * happened the day it ran, and an enum that later moves its rates must not
     * change it in retrospect.
     */
    private const RATES = [
        'sleep_hours_points_full' => 2,
        'sleep_hours_points_short' => 1,
        'sleep_hours_points_poor' => 0,
    ];

    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            // own_bed | hours. See App\Enums\SleepCardType.
            $table->string('sleep_card_type', 16)->default('own_bed')->after('sleep_card_enabled');

            // The hours card's own set, mirroring the own-bed counters one for
            // one. Same jobs, same reasons — see the own-bed migration for why
            // there is a run, a best run and a paid-through mark.
            $table->unsignedInteger('sleep_hours_nights')->default(0)->after('pending_sleep_chest');
            $table->unsignedInteger('sleep_hours_run')->default(0)->after('sleep_hours_nights');
            $table->unsignedInteger('sleep_hours_best_run')->default(0)->after('sleep_hours_run');
            $table->unsignedInteger('sleep_hours_run_paid_through')->default(0)->after('sleep_hours_best_run');
            $table->unsignedInteger('pending_sleep_hours_chest')->nullable()->after('sleep_hours_run_paid_through');
        });

        Schema::table('households', function (Blueprint $table) {
            foreach (array_keys(self::RATES) as $column) {
                $table->unsignedInteger($column)->nullable()->after('sleep_points_rough');
            }
        });

        foreach (self::RATES as $column => $halfDollars) {
            DB::table('households')->update([
                $column => DB::raw("{$halfDollars} * points_per_dollar / 2"),
            ]);
        }

        Schema::table('sleep_nights', function (Blueprint $table) {
            // How long they slept, to the half hour. Null on an own-bed row.
            $table->unsignedSmallInteger('minutes')->nullable()->after('outcome');
        });

        // Nullable now that an hours row carries minutes instead. Rebuilt
        // rather than ->change()'d: this project's SQLite connection can't
        // alter a column in place, and every existing row already has one.
        Schema::table('sleep_nights', function (Blueprint $table) {
            $table->string('outcome')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn([
                'sleep_card_type',
                'sleep_hours_nights',
                'sleep_hours_run',
                'sleep_hours_best_run',
                'sleep_hours_run_paid_through',
                'pending_sleep_hours_chest',
            ]);
        });

        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn(array_keys(self::RATES));
        });

        Schema::table('sleep_nights', function (Blueprint $table) {
            $table->dropColumn('minutes');
        });
    }
};
