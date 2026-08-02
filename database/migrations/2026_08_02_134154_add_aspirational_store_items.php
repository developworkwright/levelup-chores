<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two rewards priced above every kid's balance on purpose. The Loot Shop now
 * groups items into price bands and pins a saving-up goal at the top, and both
 * fall flat if everything on the shelves is already affordable — there has to
 * be something worth saving for.
 *
 * Applied per household so existing installs get them, not just fresh seeds.
 */
return new class extends Migration
{
    /** @var array<int, array{name: string, description: string, cost: int, color_tag: string}> */
    private const ITEMS = [
        [
            'name' => 'Movie night out',
            'description' => 'A trip to the cinema, your pick of film.',
            'cost' => 800,
            'color_tag' => 'cyan',
        ],
        [
            'name' => 'Lego set',
            'description' => 'A Lego set of your choice.',
            'cost' => 2000,
            'color_tag' => 'violet',
        ],
    ];

    public function up(): void
    {
        foreach (DB::table('households')->pluck('id') as $householdId) {
            foreach (self::ITEMS as $item) {
                // Guarded by name so re-running against a household that
                // already has one of these can't duplicate the shelf.
                $exists = DB::table('store_items')
                    ->where('household_id', $householdId)
                    ->where('name', $item['name'])
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('store_items')->insert([
                    'household_id' => $householdId,
                    ...$item,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('store_items')
            ->whereIn('name', array_column(self::ITEMS, 'name'))
            ->delete();
    }
};
