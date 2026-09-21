<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hours card asks *when* as well as how long.
 *
 * A full night is now eight hours that also cover midnight to 6am, and a night
 * asleep for less than four of those six hours pays nothing at all. Eight hours
 * from 3am to 11am is still eight hours and still isn't sleeping when the house
 * does; the card is for the habit rather than the arithmetic, and the four-hour
 * floor is what tells a night from a nap at bedtime or a sleep that ran on
 * through the day.
 *
 * Neither is a failure. A long night in the wrong hours drops a band, a night
 * that never met the window doesn't pay, and nothing already earned is taken
 * back either way. Only the run stops, exactly as before.
 *
 * ## Minutes since noon, not clock times
 *
 * Both columns are minutes since **noon on the evening the night began**: 11pm
 * is 660, midnight 720, 6am 1080. Clock times would sort wrongly across the
 * wrap — 11pm is an earlier bedtime than 1am but a bigger number — and every
 * comparison would need a special case. This way both questions the card asks
 * — how long, and how much of midnight-to-6am — are subtractions. See
 * App\Services\NightWindow.
 *
 * `minutes` stays, derived from the two on write rather than answered
 * directly. It is what the bands and the weekly average are read from, and
 * keeping it means every row already logged still reads the same.
 *
 * ## Nights already answered
 *
 * Nullable, and left null on every existing row rather than guessed at. A null
 * pair reads as *covering* the window (see SleepNight::band()) — the rule
 * arrived after those nights were slept, and a migration that demoted them
 * would take a kid's run away for a question nobody asked them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sleep_nights', function (Blueprint $table) {
            // Held to the half hour, the same step the card's steppers move in.
            $table->unsignedSmallInteger('asleep_minute')->nullable()->after('minutes');
            $table->unsignedSmallInteger('awake_minute')->nullable()->after('asleep_minute');
        });
    }

    public function down(): void
    {
        Schema::table('sleep_nights', function (Blueprint $table) {
            $table->dropColumn(['asleep_minute', 'awake_minute']);
        });
    }
};
