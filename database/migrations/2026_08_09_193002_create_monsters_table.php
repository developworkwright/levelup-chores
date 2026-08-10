<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per monster the family has ever faced, living or dead.
 *
 * This is the family goal itself now, not a skin over it: three monsters stand
 * at once, one per tier, and each carries the reward its own defeat pays out.
 * `households.goal_name/goal_target/goal_now` collapse into these rows — see
 * the seed migration that moves them.
 *
 * There is deliberately **no damage column**. Health is summed from
 * `monster_hits`, which is the same table the per-monster leaderboard is
 * grouped from, so the bar and the names underneath it cannot disagree. A
 * family lands a handful of hits a day; the sum is cheaper than keeping a
 * counter honest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monsters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id')->index();

            // 1, 2 or 3. Small and quick through to the long game, and the
            // direction overkill spills: a killing blow on tier 1 rolls its
            // excess onto tier 2.
            $table->unsignedTinyInteger('tier');

            // Which monster this is at its tier, counting from one. Identity,
            // and what keeps a re-spawned tier 1 from being confused with the
            // one before it.
            $table->unsignedInteger('battle')->default(1);

            // The artwork. Stored per monster rather than per household now
            // that three are standing — they must not all wear the same face.
            $table->string('skin');

            // What beating it actually buys: "Ice cream outing", "Weekend
            // Airbnb". This is the old goal_name, one per tier.
            $table->string('reward_name');

            // What that reward costs in real money, so the parent screen can
            // show dollars-per-point across the three tiers and catch a tier
            // that has been priced out of line with the others. Nullable:
            // plenty of rewards don't have a price tag.
            $table->unsignedInteger('reward_cost_cents')->nullable();

            $table->unsignedInteger('max_health');

            // The chore this monster flinches at, for double damage. Rotated
            // weekly, and swappable by a parent when the draw picks something
            // unreasonable for the week — the swap overrides the pick without
            // touching `weak_rotated_on`, so the next rotation carries on as
            // normal.
            $table->unsignedBigInteger('weak_chore_id')->nullable()->index();
            $table->date('weak_rotated_on')->nullable();

            $table->timestamp('started_at');

            // Null while it is still standing. Authoritative on its own: a
            // monster stays beaten even if a parent later nudges its damage
            // down, because the celebration has fired and the reward is owed.
            $table->timestamp('defeated_at')->nullable();
            $table->unsignedBigInteger('finisher_profile_id')->nullable()->index();

            // The leaderboard frozen at the kill: [['name' => 'Nova', ...], ...].
            // A live monster's is summed from monster_hits instead; this exists
            // because the trophy shelf must keep reading correctly years later,
            // long after the hits behind it stop being interesting.
            $table->json('contributions')->nullable();

            $table->timestamps();

            // The arena's own query: the live monsters for a household.
            $table->index(['household_id', 'tier', 'defeated_at']);

            // One monster per battle per tier, which is also the guard against
            // banking the same kill twice.
            $table->unique(['household_id', 'tier', 'battle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monsters');
    }
};
