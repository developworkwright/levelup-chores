<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Badges now pay XP when they unlock. Values are tiered by how hard the badge
 * is to earn — roughly 2,200 XP across the full set, or about eleven levels at
 * Profile::XP_PER_LEVEL.
 */
return new class extends Migration
{
    /** @var array<string, int> */
    private const REWARDS = [
        'first_quest' => 50,
        'streak_3' => 100,
        'wheel_winner' => 100,
        'early_bird' => 100,
        'night_owl' => 100,
        'big_saver' => 150,
        'big_spender' => 150,
        'busy_bee' => 150,
        'speed_runner' => 150,
        'streak_7' => 200,
        'team_effort' => 250,
        'perfect_board' => 300,
        'streak_14' => 400,
    ];

    public function up(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $table->unsignedInteger('xp_reward')->default(0)->after('description');
        });

        foreach (self::REWARDS as $key => $xp) {
            DB::table('badges')->where('key', $key)->update(['xp_reward' => $xp]);
        }
    }

    public function down(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $table->dropColumn('xp_reward');
        });
    }
};
