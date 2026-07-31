<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wording mirrors the conditions in BadgeService — if a threshold moves
     * there, the copy here needs to move with it.
     *
     * @var array<string, string>
     */
    private const DESCRIPTIONS = [
        'first_quest' => 'Clear your very first daily quest.',
        'streak_3' => 'Clear your daily quest three days in a row.',
        'streak_7' => 'Clear your daily quest seven days in a row.',
        'streak_14' => 'Keep a streak alive for fourteen days straight.',
        'big_spender' => 'Spend 1,000 points in the Loot Shop.',
        'big_saver' => 'Hold 500 points at once without spending them.',
        'wheel_winner' => 'Land a 3x multiplier on the Bonus Wheel.',
        'busy_bee' => 'Get four chores approved in a single day.',
        'perfect_board' => 'Clear your quest and every other chore on your board in one day.',
        'early_bird' => 'Finish a chore before 7am.',
        'night_owl' => 'Finish a chore at 10pm or later.',
        'team_effort' => 'Reach the family goal together — everyone earns this one.',
        'speed_runner' => 'Clear your main quest within five minutes of opening the chest.',
    ];

    public function up(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $table->string('description')->nullable()->after('name');
        });

        foreach (self::DESCRIPTIONS as $key => $description) {
            DB::table('badges')->where('key', $key)->update(['description' => $description]);
        }
    }

    public function down(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
