<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The four original badges only ever existed in DatabaseSeeder, so any
 * environment migrated without `--seed` was missing them — and BadgeService
 * silently no-ops when it can't find a badge by key, so those achievements
 * just never fired. This moves them alongside the other nine so migrations
 * own the full set.
 *
 * insertOrIgnore keeps it safe on already-seeded databases: rows that exist
 * are left exactly as they are, duplicates are impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('badges')->insertOrIgnore([
            ['key' => 'first_quest', 'name' => 'First Quest', 'description' => 'Clear your very first daily quest.', 'glyph' => 'Q', 'color' => 'lime', 'hidden' => false, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'streak_3', 'name' => '3-Day Streak', 'description' => 'Clear your daily quest three days in a row.', 'glyph' => '3', 'color' => 'coral', 'hidden' => false, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'streak_7', 'name' => '7-Day Streak', 'description' => 'Clear your daily quest seven days in a row.', 'glyph' => '7', 'color' => 'gold', 'hidden' => false, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'big_spender', 'name' => 'Big Spender', 'description' => 'Spend 1,000 points in the Loot Shop.', 'glyph' => '$', 'color' => 'magenta', 'hidden' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        DB::table('badges')->whereIn('key', [
            'first_quest', 'streak_3', 'streak_7', 'big_spender',
        ])->delete();
    }
};
