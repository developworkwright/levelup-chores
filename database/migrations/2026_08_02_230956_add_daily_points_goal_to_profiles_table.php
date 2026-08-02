<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many points a kid is aiming to earn each day on the way to their saving
 * goal. Nullable rather than defaulted: "not picked one yet" is a real state
 * the Goal Planner answers with a suggestion, and a zero would read as a
 * target of nothing at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->unsignedInteger('daily_points_goal')
                ->nullable()
                ->after('saving_for_store_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('daily_points_goal');
        });
    }
};
