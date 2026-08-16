<?php

use App\Services\SleepService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a single night in their own bed pays.
 *
 * A constellation is a week away, and a week is a very long time to a
 * five-year-old — the picture alone gave them nothing for tonight, which is the
 * night that is actually hard. This is the reward for pressing the button.
 *
 * Deliberately generous to start and tapered later, which is why it is a
 * setting and not a constant: the money is there to get the habit going, and
 * once each night is easier the dial comes down. Same reasoning as the
 * constellation payout, and both are on the console beside each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->unsignedInteger('sleep_night_points')->nullable()->after('sleep_constellation_points');
        });

        DB::table('households')->whereNull('sleep_night_points')->update([
            'sleep_night_points' => DB::raw(SleepService::NIGHT_DOLLARS.' * points_per_dollar'),
        ]);
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn('sleep_night_points');
        });
    }
};
