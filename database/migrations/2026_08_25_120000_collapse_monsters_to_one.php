<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three monsters back down to one.
 *
 * The tiers were a real choice — a small reward and a long one running at the
 * same time, and the kids picking which of them each finished chore hit. What
 * it cost was a tap on every single claim, which is more than the choice was
 * worth. One monster, and the work lands where it obviously should.
 *
 * The level 3 monster is the survivor: it is the one the family has actually
 * been feeding, and its damage is the progress nobody wants to lose. The lower
 * tiers are **deleted rather than shelved** — marking them beaten would put a
 * trophy on the shelf for a fight nobody won and promise a reward nobody
 * earned. Their hits go with them, which costs the arena a little damage; the
 * points those chores paid were always the kids' own and are untouched in the
 * ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Standing lower-tier monsters first — a beaten one is a real trophy
        // and keeps its place on the shelf.
        $doomed = DB::table('monsters')
            ->where('tier', '<', 3)
            ->whereNull('defeated_at')
            ->pluck('id');

        if ($doomed->isNotEmpty()) {
            DB::table('monster_hits')->whereIn('monster_id', $doomed)->delete();
            DB::table('monsters')->whereIn('id', $doomed)->delete();
        }

        // Nothing spills any more, so the kind that named it has gone. Rewritten
        // rather than dropped: a spill was a kid's own blow landing one tier up,
        // and it still counts toward their share of the kill.
        DB::table('monster_hits')->where('kind', 'spill')->update(['kind' => 'hit']);

        // `battle` numbered monsters within a tier. With the tier gone it has to
        // number them within the household, or two beaten monsters from
        // different tiers collide on the unique index below.
        foreach (DB::table('monsters')->distinct()->pluck('household_id') as $householdId) {
            $rows = DB::table('monsters')
                ->where('household_id', $householdId)
                ->orderBy('started_at')
                ->orderBy('id')
                ->pluck('id');

            foreach ($rows as $index => $id) {
                DB::table('monsters')->where('id', $id)->update(['battle' => $index + 1]);
            }
        }

        Schema::table('monsters', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'tier', 'defeated_at']);
            $table->dropUnique(['household_id', 'tier', 'battle']);
        });

        Schema::table('monsters', function (Blueprint $table) {
            $table->dropColumn('tier');

            // The arena's own query: the monster standing for a household.
            $table->index(['household_id', 'defeated_at']);
            $table->unique(['household_id', 'battle']);
        });

        Schema::table('chore_completions', function (Blueprint $table) {
            // Nothing to aim at any more. `struck_weak_point` stays — the weak
            // chore survives the collapse, and it is still frozen at submit.
            $table->dropColumn('target_tier');
        });

        Schema::table('profiles', function (Blueprint $table) {
            // The picker's remembered preference. There is no picker.
            $table->dropColumn('last_monster_tier');
        });
    }

    /**
     * Puts the columns back, with everything left on tier 3.
     *
     * The deleted monsters are gone for good — that is what makes this a
     * one-way door in practice, and the reason it is worth being sure before
     * running it.
     */
    public function down(): void
    {
        Schema::table('monsters', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'defeated_at']);
            $table->dropUnique(['household_id', 'battle']);
            $table->unsignedTinyInteger('tier')->default(3)->after('household_id');
        });

        Schema::table('monsters', function (Blueprint $table) {
            $table->index(['household_id', 'tier', 'defeated_at']);
            $table->unique(['household_id', 'tier', 'battle']);
        });

        Schema::table('chore_completions', function (Blueprint $table) {
            $table->unsignedTinyInteger('target_tier')->nullable()->after('points_awarded');
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->unsignedTinyInteger('last_monster_tier')->nullable();
        });
    }
};
