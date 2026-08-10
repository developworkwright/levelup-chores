<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catch-up replay and the kill announcements, both now per monster.
 *
 * Their single-boss ancestors — `boss_damage_seen`, `boss_battle_seen`,
 * `pending_goal_celebration` and friends — are left in place. Anything already
 * queued in them still has a card waiting for it, and they come out with the
 * rest of the old family-goal columns once nothing reads them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            // How much damage this kid has watched land on each monster:
            // {"12": 400, "13": 0}, keyed by monster id and pruned to whatever
            // is standing. A map rather than columns because the number of
            // monsters is a product decision, not a schema one.
            //
            // A monster missing from the map has never been watched, which
            // replays nothing — nobody should meet a five-stage recap of a
            // fight they have been following all week.
            $table->json('monsters_seen')->nullable()->after('boss_battle_seen');

            // Kills waiting to be announced. A list, because one chore can take
            // two monsters down at once and a kid away for a week can come back
            // to several — a single slot would silently drop all but the last.
            $table->json('pending_monster_kills')->nullable()->after('monsters_seen');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['monsters_seen', 'pending_monster_kills']);
        });
    }
};
