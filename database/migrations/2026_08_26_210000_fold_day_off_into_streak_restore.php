<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the Day Off perk and folds everything it left behind into Streak
 * Restore, which is now the only way to buy a day back.
 *
 * The perk existed to open a board the main quest had locked. That gate is
 * gone, so all a Day Off would still do is keep a streak — which is exactly
 * what a Streak Restore does, only after the fact instead of in advance.
 *
 * Four kinds of leftover, each moved rather than dropped:
 *
 * - **Held perks become Streak Restores.** A kid who spent eight tickets keeps
 *   something they can use. Consumed rows move too: `owned_perks.effect` casts
 *   to PerkEffect, and a value the enum no longer has throws the moment the
 *   Stats page counts it.
 * - **A chest reward waiting to be opened** is the same problem in
 *   `daily_chests.reward_effect`, and gets the same swap.
 * - **The catalogue row goes**, or BonusPerkCatalogTest fails: cases and rows
 *   have to match exactly.
 * - **Days already bought become streak repairs.** `questApprovedOn()` read
 *   `quest_skips` beside `streak_repairs` and treated them identically, so the
 *   rows convert one-for-one and every live run keeps the length it has. Drop
 *   the table without this and a kid loses their streak overnight for a day
 *   they paid for.
 *
 * One knock-on, accepted rather than worked around: the `comeback_kid` badge
 * unlocks on any streak repair existing, so a kid who only ever bought days off
 * may find it waiting for them. A gifted badge is a much smaller wrong than a
 * shortened run.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('owned_perks')->where('effect', 'quest_skip')->update(['effect' => 'streak_restore']);
        DB::table('daily_chests')->where('reward_effect', 'quest_skip')->update(['reward_effect' => 'streak_restore']);
        DB::table('bonus_perks')->where('effect', 'quest_skip')->delete();

        // insertOrIgnore, not insert: a day that was both bought off and later
        // repaired would collide with the unique index, and either row means
        // the same thing to the streak.
        $repairs = DB::table('quest_skips')
            ->get()
            ->map(fn (object $skip) => [
                'profile_id' => $skip->profile_id,
                'repaired_date' => $skip->skip_date,
                'created_at' => $skip->created_at,
            ])
            ->all();

        if ($repairs !== []) {
            DB::table('streak_repairs')->insertOrIgnore($repairs);
        }

        Schema::dropIfExists('quest_skips');
    }

    /**
     * Rebuilds the table and the catalogue row, but cannot tell a converted
     * repair from one a kid bought with a Streak Restore — so the days stay
     * where this migration put them. They count for the streak either way,
     * which is the only thing either row was ever read for.
     */
    public function down(): void
    {
        Schema::create('quest_skips', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('profile_id')->index();
            $table->date('skip_date');
            $table->timestamp('created_at');

            $table->unique(['profile_id', 'skip_date']);
        });

        $rows = DB::table('households')->pluck('id')->map(fn (int $id) => [
            'household_id' => $id,
            'effect' => 'quest_skip',
            'name' => 'Day Off',
            'description' => "Skip today's main quest — the board opens and your streak survives. Once a week, and you earn nothing for it.",
            'cost' => 8,
            'glyph' => '»',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if ($rows !== []) {
            DB::table('bonus_perks')->insert($rows);
        }
    }
};
