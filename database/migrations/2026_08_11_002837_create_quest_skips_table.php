<?php

use App\Enums\PerkEffect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Day Off perk: storage for it, and its catalogue row.
 *
 * Shaped like `streak_repairs`, which it sits beside in the streak's reckoning
 * — both answer the same question, "does this day count even though the quest
 * wasn't done", and `questApprovedOn()` reads them together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quest_skips', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('profile_id')->index();
            $table->date('skip_date');
            $table->timestamp('created_at');

            // One skip per kid per day. A second on the same day would be
            // paying twice for a thing already true, and the guard is cheaper
            // here than trusting every path that might write one.
            $table->unique(['profile_id', 'skip_date']);
        });

        // Households that already exist get the perk too — PerkEffect::defaults()
        // only covers ones created from here on, via the seeder and the factory.
        $defaults = PerkEffect::QuestSkip->defaults();

        $rows = DB::table('households')->pluck('id')->map(fn (int $id) => [
            'household_id' => $id,
            'effect' => PerkEffect::QuestSkip->value,
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
        Schema::dropIfExists('quest_skips');

        DB::table('bonus_perks')->where('effect', PerkEffect::QuestSkip->value)->delete();
    }
};
