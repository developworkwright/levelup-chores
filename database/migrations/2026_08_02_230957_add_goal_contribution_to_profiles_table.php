<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What this kid has personally fed into the household's current family goal —
 * the per-profile counterpart of `households.goal_now`, written in the same
 * place and zeroed alongside it when a parent starts a new goal.
 *
 * Stored rather than derived from completions, because the family goal has no
 * start date to count from: resetting progress begins a fresh goal, and the
 * points banked toward the old one must not follow it over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->unsignedInteger('goal_contribution')
                ->default(0)
                ->after('daily_points_goal');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('goal_contribution');
        });
    }
};
