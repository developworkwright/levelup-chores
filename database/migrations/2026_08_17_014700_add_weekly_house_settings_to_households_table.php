<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Arena's week: one shared target, and whatever a parent has put up for
 * hitting it.
 *
 * `weekly_prize` is free text and stays that way. The app does not pay it, does
 * not track whether it was paid, and has no idea what "Friday movie pick + $5"
 * costs — it is settled by hand, which is the point. Anything that tried to
 * model it would be modelling a family's own arrangement badly.
 *
 * `weekly_chore_target` is nullable rather than defaulted: a household that has
 * not set one has no house bar, which reads better than a bar against a number
 * nobody chose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->unsignedSmallInteger('weekly_chore_target')->nullable()->after('evening_watch_hour');
            $table->string('weekly_prize')->nullable()->after('weekly_chore_target');
            $table->string('weekly_prize_note')->nullable()->after('weekly_prize');
        });
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn(['weekly_chore_target', 'weekly_prize', 'weekly_prize_note']);
        });
    }
};
