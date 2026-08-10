<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the single family goal and the one boss that wore it.
 *
 * Everything here has a replacement that has been carrying the real load since
 * the arena shipped: the goal columns became `monsters` rows, `goal_contribution`
 * became a sum over `monster_hits`, the seen markers became `monsters_seen`, and
 * the pending celebration became `pending_monster_kills`. Nothing reads any of
 * it any more, and two sources of truth for the same fight is exactly the drift
 * this design set out to avoid.
 *
 * The one-time data migrations that read these columns still run correctly on a
 * fresh database — they come earlier in the sequence, do their work, and only
 * then does this take the columns away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn([
                'goal_name',
                'goal_target',
                'goal_now',
                'boss_key',
                'boss_started_at',
                'boss_battle',
            ]);
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn([
                'goal_contribution',
                'pending_goal_celebration',
                'pending_goal_finisher',
                'pending_boss_name',
                'boss_damage_seen',
                'boss_battle_seen',
            ]);
        });

        // Its rows were copied onto `monsters` as beaten tier 3 entries by the
        // seed migration, so the trophy shelf survives this.
        Schema::dropIfExists('boss_defeats');
    }

    /**
     * Puts the shape back, but not the data — which is the honest limit of a
     * rollback here. The numbers these held now live in `monsters` and
     * `monster_hits`, and reconstructing a single goal out of three is a
     * guess rather than a reversal.
     */
    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->string('goal_name')->nullable();
            $table->unsignedInteger('goal_target')->default(0);
            $table->unsignedInteger('goal_now')->default(0);
            $table->string('boss_key')->nullable();
            $table->timestamp('boss_started_at')->nullable();
            $table->unsignedInteger('boss_battle')->default(1);
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->unsignedInteger('goal_contribution')->default(0);
            $table->string('pending_goal_celebration')->nullable();
            $table->string('pending_goal_finisher')->nullable();
            $table->string('pending_boss_name')->nullable();
            $table->unsignedInteger('boss_damage_seen')->nullable();
            $table->unsignedInteger('boss_battle_seen')->nullable();
        });
    }
};
