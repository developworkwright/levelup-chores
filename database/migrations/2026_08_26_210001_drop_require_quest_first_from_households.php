<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Side quests no longer wait on the main quest.
 *
 * The gate was there so nobody could hoover up the easy chores and leave the
 * ugly ones — but that job is done better by the things that pay: the quest
 * chest's charm and the bonus wheel both make the main quest worth choosing,
 * without making it the price of touching the board at all. In practice the
 * gate was the thing stopping kids browsing, which is the behaviour the board
 * exists to encourage.
 *
 * The column goes rather than being defaulted to false: a switch nothing can
 * turn on is a rule that only looks like it still exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn('require_quest_first');
        });
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->boolean('require_quest_first')->default(true);
        });
    }
};
