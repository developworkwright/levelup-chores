<?php

use App\Services\HouseholdClock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hour the house calls it a night — and what the kids' streak countdown
 * actually counts down to.
 *
 * The third evening time on this table, and they are three different questions:
 * `day_boundary_hour` is when the day ends for the data, `evening_watch_hour`
 * is when the Arena starts drawing an open quest as at risk, and this is when
 * the family says the day is over. Only the first is a real deadline — a
 * streak survives past bedtime and dies at the rollover. The countdown points
 * here anyway, because "get it done before bed" is the rule a kid can act on
 * and 4am is not a time anybody is going to do a chore.
 *
 * Stored as an 'H:i' string rather than an hour, unlike its two neighbours:
 * bedtime is the one of the three a house is likely to put on the half hour.
 * It is read through {@see HouseholdClock::atTime()}, which
 * already parses that shape, already returns null for anything that isn't one,
 * and already knows that a time before the day boundary belongs to the small
 * hours at the *end* of the day.
 *
 * Nullable so a household can switch it off: with no bedtime the countdown
 * falls back to the rollover, which is what it counted to before this existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->string('bedtime', 5)->nullable()->default('21:00')->after('evening_watch_hour');
        });
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn('bedtime');
        });
    }
};
