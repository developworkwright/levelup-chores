<?php

use App\Services\SleepService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a finished constellation pays, so a parent can taper it as the habit
 * settles — the point of paying for this at all is to get it started, and
 * habits don't need paying forever.
 *
 * Stored in **points**, not dollars: points are what the ledger actually moves,
 * and a dollar amount would round badly against `points_per_dollar` the moment
 * a parent wanted anything other than a whole number of them. The console shows
 * the dollar equivalent beside it.
 *
 * Zero is a legitimate setting rather than an off switch — the end of a taper
 * is the picture being its own reward, and the card carries on regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->unsignedInteger('sleep_constellation_points')->nullable()->after('sleep_card_enabled');
        });

        // Seed at the old constant so nothing changes for anyone mid-taper.
        DB::table('households')->whereNull('sleep_constellation_points')->update([
            'sleep_constellation_points' => DB::raw(
                SleepService::CONSTELLATION_DOLLARS.' * points_per_dollar'
            ),
        ]);
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn('sleep_constellation_points');
        });
    }
};
