<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration, not just schema — inserts the new achievements directly so
 * they show up after a plain `php artisan migrate` on an already-seeded
 * environment (e.g. Laravel Cloud), without needing a re-seed. Keys are
 * distinct from the original four seeded badges, so this is safe to run
 * alongside DatabaseSeeder on a fresh install too.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('badges')->insertOrIgnore([
            ['key' => 'streak_14', 'name' => 'Fortnight Streak', 'glyph' => 'F', 'color' => 'cyan', 'hidden' => false, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'busy_bee', 'name' => 'Busy Bee', 'glyph' => 'B', 'color' => 'lime', 'hidden' => false, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'big_saver', 'name' => 'Big Saver', 'glyph' => 'S', 'color' => 'violet', 'hidden' => false, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'wheel_winner', 'name' => 'Wheel Winner', 'glyph' => 'W', 'color' => 'gold', 'hidden' => false, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'perfect_board', 'name' => 'Perfect Board', 'glyph' => 'P', 'color' => 'lime', 'hidden' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'early_bird', 'name' => 'Early Bird', 'glyph' => 'E', 'color' => 'coral', 'hidden' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'night_owl', 'name' => 'Night Owl', 'glyph' => 'N', 'color' => 'violet', 'hidden' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'team_effort', 'name' => 'Team Effort', 'glyph' => 'T', 'color' => 'gold', 'hidden' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'speed_runner', 'name' => 'Speed Runner', 'glyph' => 'R', 'color' => 'magenta', 'hidden' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        DB::table('badges')->whereIn('key', [
            'streak_14', 'busy_bee', 'big_saver', 'wheel_winner',
            'perfect_board', 'early_bird', 'night_owl', 'team_effort', 'speed_runner',
        ])->delete();
    }
};
