<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Day Off perk: storage for it, and its catalogue row.
 *
 * Both are undone further down the stack — see the migration that folds Day Off
 * into Streak Restore. This one is left in place, and rewritten to name the
 * effect as the literal `'quest_skip'` rather than through `PerkEffect`, which
 * no longer has the case: a migration that reaches into today's enums stops
 * being a record of what the schema was and starts being a way for a fresh
 * `migrate` to fatal.
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

        // Households that already exist get the perk too — the seeder and the
        // factory only cover ones created from here on.
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

    public function down(): void
    {
        Schema::dropIfExists('quest_skips');

        DB::table('bonus_perks')->where('effect', 'quest_skip')->delete();
    }
};
