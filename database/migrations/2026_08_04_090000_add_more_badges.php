<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A second wave of achievements, covering the corners of the app the original
 * thirteen never reached: lifetime volume, points earned, levels, the Bonus
 * Wheel, daily chests, the Loot Shop, sibling trades and perks.
 *
 * Data migration rather than a seeder, for the same reason as the first wave —
 * BadgeService silently no-ops on a key with no row behind it, so these have to
 * exist after a plain `php artisan migrate`. Wording mirrors the conditions in
 * BadgeService; if a threshold moves there, the copy here moves with it.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{key: string, name: string, description: string, xp_reward: int, glyph: string, color: string, hidden: bool}>
     */
    private const BADGES = [
        ['key' => 'chores_10', 'name' => 'Getting Started', 'description' => 'Get ten chores approved.', 'xp_reward' => 50, 'glyph' => 'G', 'color' => 'lime', 'hidden' => false],
        ['key' => 'chores_50', 'name' => 'Half Century', 'description' => 'Get fifty chores approved.', 'xp_reward' => 150, 'glyph' => 'H', 'color' => 'cyan', 'hidden' => false],
        ['key' => 'chores_100', 'name' => 'Centurion', 'description' => 'Get a hundred chores approved.', 'xp_reward' => 250, 'glyph' => 'C', 'color' => 'gold', 'hidden' => false],
        ['key' => 'chores_365', 'name' => 'Chore Legend', 'description' => 'Get 365 chores approved — a whole year of them.', 'xp_reward' => 400, 'glyph' => '∞', 'color' => 'magenta', 'hidden' => false],

        ['key' => 'quest_10', 'name' => 'Daily Driver', 'description' => 'Clear ten daily quests.', 'xp_reward' => 100, 'glyph' => 'D', 'color' => 'violet', 'hidden' => false],
        ['key' => 'quest_50', 'name' => 'Quest Master', 'description' => 'Clear fifty daily quests.', 'xp_reward' => 250, 'glyph' => 'M', 'color' => 'cyan', 'hidden' => false],

        ['key' => 'streak_30', 'name' => 'Unstoppable', 'description' => 'Keep a streak alive for thirty days straight.', 'xp_reward' => 400, 'glyph' => 'U', 'color' => 'gold', 'hidden' => false],

        ['key' => 'earner_1000', 'name' => 'Point Collector', 'description' => 'Earn 1,000 points from chores and bonuses.', 'xp_reward' => 100, 'glyph' => '◇', 'color' => 'lime', 'hidden' => false],
        ['key' => 'earner_5000', 'name' => 'Point Hoarder', 'description' => 'Earn 5,000 points from chores and bonuses.', 'xp_reward' => 250, 'glyph' => '◈', 'color' => 'violet', 'hidden' => false],
        ['key' => 'earner_20000', 'name' => 'Point Tycoon', 'description' => 'Earn 20,000 points from chores and bonuses.', 'xp_reward' => 400, 'glyph' => '◆', 'color' => 'gold', 'hidden' => false],

        ['key' => 'level_10', 'name' => 'Double Digits', 'description' => 'Reach level 10.', 'xp_reward' => 250, 'glyph' => 'X', 'color' => 'cyan', 'hidden' => false],
        ['key' => 'level_25', 'name' => 'Zenith', 'description' => 'Reach level 25.', 'xp_reward' => 400, 'glyph' => 'Z', 'color' => 'magenta', 'hidden' => false],

        ['key' => 'spin_25', 'name' => 'Spin Doctor', 'description' => 'Take twenty-five turns on the Bonus Wheel.', 'xp_reward' => 150, 'glyph' => '↻', 'color' => 'lime', 'hidden' => false],
        ['key' => 'triple_threat', 'name' => 'Triple Threat', 'description' => 'Land three 3x multipliers on the Bonus Wheel.', 'xp_reward' => 250, 'glyph' => '×', 'color' => 'gold', 'hidden' => true],

        ['key' => 'chest_7', 'name' => 'Chest Popper', 'description' => 'Open seven daily bonus chests.', 'xp_reward' => 100, 'glyph' => 'K', 'color' => 'coral', 'hidden' => false],
        ['key' => 'chest_30', 'name' => 'Treasure Vault', 'description' => 'Open thirty daily bonus chests.', 'xp_reward' => 250, 'glyph' => 'V', 'color' => 'gold', 'hidden' => false],

        ['key' => 'first_reward', 'name' => 'Treat Yourself', 'description' => 'Claim your first Loot Shop reward.', 'xp_reward' => 50, 'glyph' => 'Y', 'color' => 'magenta', 'hidden' => false],
        ['key' => 'big_ticket', 'name' => 'Big Ticket', 'description' => 'Claim a reward costing 500 points or more.', 'xp_reward' => 200, 'glyph' => '★', 'color' => 'violet', 'hidden' => false],

        ['key' => 'dealmaker', 'name' => 'Dealmaker', 'description' => 'Settle your first trade with a sibling.', 'xp_reward' => 100, 'glyph' => '⇄', 'color' => 'cyan', 'hidden' => false],
        ['key' => 'trade_10', 'name' => 'Ace Negotiator', 'description' => 'Settle ten trades with a sibling.', 'xp_reward' => 250, 'glyph' => 'A', 'color' => 'lime', 'hidden' => false],

        ['key' => 'gadgeteer', 'name' => 'Gadgeteer', 'description' => 'Use five perks out of your pocket.', 'xp_reward' => 150, 'glyph' => '⚙', 'color' => 'violet', 'hidden' => false],

        ['key' => 'comeback_kid', 'name' => 'Comeback Kid', 'description' => 'Buy back a missed day with a Streak Restore.', 'xp_reward' => 100, 'glyph' => '♥', 'color' => 'coral', 'hidden' => true],
        ['key' => 'weekend_warrior', 'name' => 'Weekend Warrior', 'description' => 'Get a chore approved on both days of the same weekend.', 'xp_reward' => 150, 'glyph' => '☀', 'color' => 'coral', 'hidden' => true],
        ['key' => 'overachiever', 'name' => 'Overachiever', 'description' => 'Get eight chores approved in a single day.', 'xp_reward' => 300, 'glyph' => 'O', 'color' => 'magenta', 'hidden' => true],
        ['key' => 'all_rounder', 'name' => 'All-Rounder', 'description' => 'Do every chore on your board at least once.', 'xp_reward' => 250, 'glyph' => '◎', 'color' => 'cyan', 'hidden' => true],
    ];

    public function up(): void
    {
        $now = now();

        DB::table('badges')->insertOrIgnore(array_map(
            fn (array $badge) => $badge + ['created_at' => $now, 'updated_at' => $now],
            self::BADGES,
        ));
    }

    public function down(): void
    {
        DB::table('badges')->whereIn('key', array_column(self::BADGES, 'key'))->delete();
    }
};
