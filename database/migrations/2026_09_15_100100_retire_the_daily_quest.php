<?php

use App\Enums\PerkEffect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the daily quest: the chest, the hand of cards, the bold card, the
 * pick and everything keyed to `daily_quests`.
 *
 * It was a second board sitting on top of the board. A kid opened a chest,
 * chose one of three cards, and the other two — plus, before the pick, all
 * three — were cut out of the side-quest list underneath, so the page that
 * exists to show a kid what there is to do was hiding some of it. Everything
 * the quest actually paid for is still paid: any approved chore earns the
 * streak day, any chore boosts the chest, and the wheel lands wherever it
 * lands. What goes is the layer explaining which chore was special today.
 *
 * Four kinds of leftover, each moved rather than dropped:
 *
 * - **Held Quest Rerolls become Quest Charms.** There is no quest to reroll,
 *   and the charm is the perk that survived — see below. Consumed rows convert
 *   too: `owned_perks.effect` casts to PerkEffect, so a value the enum no
 *   longer has throws the moment anything counts them. Same reasoning as the
 *   Day Off fold.
 * - **A chest reward waiting to be opened** is the same problem in
 *   `daily_chests.reward_effect`, and gets the same swap.
 * - **The Quest Reroll catalogue row goes**, or BonusPerkCatalogTest fails:
 *   cases and rows have to match exactly.
 * - **The Quest Charm's description is rewritten**, because the thing it
 *   describes no longer exists. Only where a parent hasn't already reworded it:
 *   the catalogue row is theirs to edit, and overwriting a house's own wording
 *   would be this migration reaching past its own business.
 *
 * `chores.quest_eligible` goes with it. It only ever meant "may be dealt as a
 * quest card", and there are no cards; a charm can land on any open chore.
 *
 * The quest rows themselves are dropped rather than converted. Nothing reads
 * them any more — the streak walks `chore_completions`, and the stats page's
 * longest-run figure now walks the same table — so keeping the table would be
 * keeping a record only this migration knows how to read.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('owned_perks')
            ->where('effect', 'quest_reroll')
            ->update(['effect' => PerkEffect::QuestCharm->value]);

        DB::table('daily_chests')
            ->where('reward_effect', 'quest_reroll')
            ->update(['reward_effect' => PerkEffect::QuestCharm->value]);

        DB::table('bonus_perks')->where('effect', 'quest_reroll')->delete();

        DB::table('bonus_perks')
            ->where('effect', PerkEffect::QuestCharm->value)
            ->where('description', self::OLD_CHARM_DESCRIPTION)
            ->update(['description' => PerkEffect::QuestCharm->defaults()['description']]);

        Schema::dropIfExists('daily_quests');

        Schema::table('chores', function (Blueprint $table) {
            $table->dropColumn('quest_eligible');
        });
    }

    /**
     * Rebuilds the table, the column and the catalogue row — but every kid's
     * quest history is gone, and a Quest Charm that used to be a Reroll stays a
     * charm. Both are the price of the drop, and neither is load-bearing: no
     * live rule reads a past quest, and the two perks cost the same.
     */
    public function down(): void
    {
        Schema::create('daily_quests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chore_id')->constrained()->cascadeOnDelete();
            $table->json('offered_chore_ids')->nullable();
            $table->timestamp('charmed_at')->nullable();
            $table->string('charm_effect', 32)->nullable();
            $table->unsignedTinyInteger('charm_payout_percent')->nullable();
            $table->timestamp('revealed_at')->nullable();
            $table->date('quest_date');
            $table->timestamp('dealt_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'quest_date']);
        });

        Schema::table('chores', function (Blueprint $table) {
            $table->boolean('quest_eligible')->default(true)->after('min_age');
        });

        $rows = DB::table('households')->pluck('id')->map(fn (int $id) => [
            'household_id' => $id,
            'effect' => 'quest_reroll',
            'name' => 'Quest Reroll',
            'description' => "Swap today's main quest for a different chore.",
            'cost' => 3,
            'glyph' => '⇄',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if ($rows !== []) {
            DB::table('bonus_perks')->insert($rows);
        }
    }

    /**
     * What the charm's catalogue row said while it was a bet on the chest.
     * Matched exactly, so a household that reworded it keeps their wording.
     */
    private const OLD_CHARM_DESCRIPTION = 'Charm the quest chest before you open it. More cards go bold, or the bold bonus grows — and if nothing shows on the cards, the charm pays out when you hand the quest in.';
};
