<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The daily quest becomes a hand of cards to choose from rather than a chore
 * handed down.
 *
 * `offered_chore_ids` is the hand dealt for the day, low points to high.
 * `chore_id` stays non-null throughout — before a pick it holds the first card
 * as a placeholder, so every existing consumer of `$quest->chore` keeps
 * working — and the pick overwrites it. `revealed_at` keeps its exact old
 * meaning of "the kid knows what their quest is", which is what gates the
 * board and what times the speed_runner badge; it is now stamped by the pick
 * instead of by the chest.
 *
 * `dealt_at` is the new half: the chest has been opened and the cards are on
 * the table, but nothing is chosen yet. It has to be persisted rather than
 * held client-side, or a refresh between the open and the pick would shut the
 * chest again and replay an animation the kid already watched.
 *
 * Rows written before today are left with a null hand rather than backfilled.
 * `DailyQuest::offeredChoreIds()` reads that as a one-card hand of whatever
 * they were assigned, which is exactly what those days were — and writing a
 * JSON array here would need engine-specific SQL to say so (tests run on
 * SQLite, production on MySQL). `dealt_at` does carry over from `revealed_at`,
 * so a quest already open on the day this ships doesn't slam shut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_quests', function (Blueprint $table) {
            $table->json('offered_chore_ids')->nullable()->after('chore_id');
            $table->timestamp('dealt_at')->nullable()->after('quest_date');
        });

        DB::table('daily_quests')
            ->whereNotNull('revealed_at')
            ->update(['dealt_at' => DB::raw('revealed_at')]);
    }

    public function down(): void
    {
        Schema::table('daily_quests', function (Blueprint $table) {
            $table->dropColumn(['offered_chore_ids', 'dealt_at']);
        });
    }
};
