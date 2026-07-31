<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The perk catalogue, mirroring store_items. Cost, name and wording are data
 * a parent can retune without a deploy — only the behaviour behind `effect`
 * has to be code, which is why that column is seeded here and not editable.
 *
 * Kept separate from store_items on purpose: points and tickets will want
 * columns that don't apply to the other, and one table carrying both would
 * fill up with fields that are null half the time.
 */
return new class extends Migration
{
    /** @var array<int, array{effect: string, name: string, description: string, cost: int, glyph: string}> */
    private const PERKS = [
        [
            'effect' => 'wheel_respin',
            'name' => 'Wheel Respin',
            'description' => "Clear today's spin and take another turn on the Bonus Wheel.",
            'cost' => 3,
            'glyph' => '↻',
        ],
        [
            'effect' => 'quest_reroll',
            'name' => 'Quest Reroll',
            'description' => "Swap today's main quest for a different chore.",
            'cost' => 3,
            'glyph' => '⇄',
        ],
        [
            'effect' => 'streak_restore',
            'name' => 'Streak Restore',
            'description' => 'Buy back the day you missed and keep your streak alive.',
            'cost' => 5,
            'glyph' => '♡',
        ],
        [
            'effect' => 'mystery_hint',
            'name' => 'Mystery Hint',
            'description' => "Get a clue about which chore is today's Mystery Chore.",
            'cost' => 6,
            'glyph' => '?',
        ],
    ];

    public function up(): void
    {
        Schema::create('bonus_perks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('effect');
            $table->string('name');
            $table->string('description');
            $table->unsignedInteger('cost');
            $table->string('glyph', 4);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['household_id', 'effect']);
        });

        foreach (DB::table('households')->pluck('id') as $householdId) {
            $this->seedFor($householdId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_perks');
    }

    private function seedFor(int $householdId): void
    {
        $now = now();

        DB::table('bonus_perks')->insertOrIgnore(array_map(
            fn (array $perk) => [...$perk, 'household_id' => $householdId, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            self::PERKS,
        ));
    }
};
