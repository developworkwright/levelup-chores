<?php

use App\Enums\PerkEffect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Quest Charm: somewhere to record what it did, and its catalogue row.
 *
 * Three columns because the charm resolves in two places, not one.
 * `charmed_at` says a charm was spent on this day's quest. `charm_effect` is
 * what it did to the hand, rolled when the chest opens. `charm_payout_percent`
 * is the second roll, settled when the quest is handed in — that split is the
 * whole design: a charm that only rolled up front would be a coin flip a kid
 * watches themselves lose.
 *
 * All three are nullable and mean "no charm" together. A null `charm_effect`
 * on a charmed quest is the legitimate mid-state between casting it and
 * opening the chest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_quests', function (Blueprint $table) {
            $table->timestamp('charmed_at')->nullable()->after('offered_chore_ids');
            $table->string('charm_effect', 32)->nullable()->after('charmed_at');
            $table->unsignedTinyInteger('charm_payout_percent')->nullable()->after('charm_effect');
        });

        $defaults = PerkEffect::QuestCharm->defaults();

        // Households that already exist get the perk too — PerkEffect::defaults()
        // only covers ones created from here on, via the factory and seeder.
        $rows = DB::table('households')->pluck('id')->map(fn (int $id) => [
            'household_id' => $id,
            'effect' => PerkEffect::QuestCharm->value,
            'name' => $defaults['name'],
            'description' => $defaults['description'],
            'cost' => $defaults['cost'],
            'glyph' => $defaults['glyph'],
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if ($rows !== []) {
            DB::table('bonus_perks')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::table('daily_quests', function (Blueprint $table) {
            $table->dropColumn(['charmed_at', 'charm_effect', 'charm_payout_percent']);
        });

        DB::table('bonus_perks')->where('effect', PerkEffect::QuestCharm->value)->delete();
    }
};
