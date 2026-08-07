<?php

use App\Enums\BossSkin;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            // Which monster the family goal is currently wearing. Nullable so
            // BossService can lazily assign it the same way the daily quest and
            // the mystery chore assign themselves, rather than leaning on a
            // database default the factory would have to duplicate.
            $table->string('boss_key')->nullable()->after('goal_now');

            // Scopes the hit feed. Every approved chore since this moment is a
            // blow landed on the boss currently standing.
            $table->timestamp('boss_started_at')->nullable()->after('boss_key');

            // Which battle this is, counting from one. Identity, deliberately
            // not derived from `boss_started_at`: two battles starting in the
            // same second is unlikely rather than impossible, and a clock
            // reading that can tie is not an identifier.
            $table->unsignedInteger('boss_battle')->default(1)->after('boss_started_at');
        });

        Schema::table('profiles', function (Blueprint $table) {
            // Who landed the killing blow, held beside pending_goal_celebration
            // so the reveal can name them. Its own column rather than packed
            // into that string: two facts, and the shell reads them separately.
            $table->string('pending_goal_finisher')->nullable()->after('pending_goal_celebration');

            // The monster's name at the moment it died. Stored for the same
            // reason the goal's name is: a parent who starts the next goal
            // before a kid logs in rotates the skin, and reading it live would
            // pin the kill on whichever monster happened to be standing later.
            $table->string('pending_boss_name')->nullable()->after('pending_goal_finisher');

            // Where this kid last left the fight. The arena replays every stage
            // between this number and the boss's real health, so damage dealt
            // while they were away is watched rather than merely discovered.
            //
            // Null means "has never looked": the first visit seeds it without
            // replaying, so nobody meets a five-stage recap of a monster they
            // have been watching all week. Same rule as badges_seen_at.
            $table->unsignedInteger('boss_damage_seen')->nullable()->after('pending_boss_name');

            // Which battle that number belongs to. A new monster makes the
            // stored damage meaningless, and comparing battle numbers is how
            // the replay knows to start the new one from nothing seen.
            $table->unsignedInteger('boss_battle_seen')->nullable()->after('boss_damage_seen');
        });

        // Households mid-goal keep their progress and start on the first
        // monster, with the battle dated now — backdating it would sweep every
        // chore they have ever done into the new boss's hit feed.
        DB::table('households')->whereNull('boss_key')->update([
            'boss_key' => BossSkin::default()->value,
            'boss_started_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn(['boss_key', 'boss_started_at', 'boss_battle']);
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn([
                'pending_goal_finisher',
                'pending_boss_name',
                'boss_damage_seen',
                'boss_battle_seen',
            ]);
        });
    }
};
