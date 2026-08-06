<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a kid is up to on celebrations they haven't been shown yet.
 *
 * This lived in the session, which meant it lasted exactly as long as a login
 * did: a family goal finished while a kid was signed out, or a badge a parent
 * approved overnight, was seeded silently on their next visit and the moment
 * was gone. Tickets still landed — they just never got told why.
 *
 * All three are deliberately nullable with no backfill. A null marker seeds
 * itself on the kid's next page load without celebrating, which is what stops
 * a full trophy case from firing every card it holds the day this ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            // Badges are a list, so the marker is a watermark: anything with a
            // later earned_at on the pivot is still owed a card.
            $table->timestamp('badges_seen_at')->nullable()->after('pending_streak_chest');

            $table->unsignedInteger('level_seen')->nullable()->after('badges_seen_at');

            // Holds the name of the goal that was reached, not a flag — a
            // parent who resets and renames the goal before a kid logs in must
            // not have the new one announced as finished.
            $table->string('pending_goal_celebration')->nullable()->after('level_seen');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['badges_seen_at', 'level_seen', 'pending_goal_celebration']);
        });
    }
};
