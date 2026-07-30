<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The mystery chore is now picked automatically each day (daily_mysteries
     * table) rather than a parent flagging one chore permanently — these
     * per-chore columns no longer mean anything.
     */
    public function up(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            $table->dropColumn(['is_mystery', 'mystery_bonus_points']);
        });
    }

    public function down(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            $table->boolean('is_mystery')->default(false)->after('quest_eligible');
            $table->unsignedInteger('mystery_bonus_points')->default(500)->after('is_mystery');
        });
    }
};
