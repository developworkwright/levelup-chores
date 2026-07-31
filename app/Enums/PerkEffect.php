<?php

namespace App\Enums;

/**
 * The set of perk behaviours that actually exist in code. A bonus_perks row
 * points at one of these; everything else about a perk — its price, name and
 * wording — is editable data on that row.
 *
 * Adding a case here means writing the effect in BonusShopService to match.
 */
enum PerkEffect: string
{
    case WheelRespin = 'wheel_respin';
    case QuestReroll = 'quest_reroll';
    case StreakRestore = 'streak_restore';
    case MysteryHint = 'mystery_hint';

    /**
     * Starting catalogue values for a household that doesn't have this perk
     * yet. Once seeded, the row is the source of truth — not this.
     *
     * @return array{name: string, description: string, cost: int, glyph: string}
     */
    public function defaults(): array
    {
        return match ($this) {
            self::WheelRespin => [
                'name' => 'Wheel Respin',
                'description' => "Clear today's spin and take another turn on the Bonus Wheel.",
                'cost' => 3,
                'glyph' => '↻',
            ],
            self::QuestReroll => [
                'name' => 'Quest Reroll',
                'description' => "Swap today's main quest for a different chore.",
                'cost' => 3,
                'glyph' => '⇄',
            ],
            self::StreakRestore => [
                'name' => 'Streak Restore',
                'description' => 'Buy back the day you missed and keep your streak alive.',
                'cost' => 5,
                'glyph' => '♡',
            ],
            self::MysteryHint => [
                'name' => 'Mystery Hint',
                'description' => "Get a clue about which chore is today's Mystery Chore.",
                'cost' => 6,
                'glyph' => '?',
            ],
        };
    }
}
