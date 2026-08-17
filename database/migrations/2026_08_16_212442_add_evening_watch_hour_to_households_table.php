<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hour the Arena starts reading an open quest as "at risk".
 *
 * Explicitly *not* a deadline. The household day rolls at `day_boundary_hour`
 * (4am by default) and nothing expires before it — a quest open at 9pm is not
 * late. This is a display threshold and nothing else: past it, a kid whose
 * quest is still open gets the candle, and the urgency ramps over a three-hour
 * window and then holds.
 *
 * It needs its own column rather than being derived from `day_boundary_hour`
 * because they answer different questions. The boundary is when the day ends;
 * this is when a family starts looking at the clock, and 7pm suits a house
 * whose day rolls at 4am exactly as well as one that rolls at 2am.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->unsignedTinyInteger('evening_watch_hour')->default(19)->after('day_boundary_hour');
        });
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn('evening_watch_hour');
        });
    }
};
