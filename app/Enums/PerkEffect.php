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
    case NameMonster = 'name_monster';
    case NightSaver = 'night_saver';
    case QuestCharm = 'quest_charm';

    /**
     * How using this perk should celebrate — one of the styles in
     * `fqCelebrations` (`shapeStyle()`), which also picks the sound.
     *
     * Money is the default over there and it is wrong for every perk: nothing
     * is bought at the moment one is *used*, the tickets went days ago, and
     * coins raining down for naming a monster is the app cheering about a
     * transaction that didn't happen.
     */
    public function celebrationStyle(): string
    {
        return match ($this) {
            // Soft, and the only celebrations in the app that are meant to be —
            // they match the perks' own ♡ and the relief of a rescued run.
            self::StreakRestore, self::NightSaver => 'heart',
            // A clue is a small bright thing, not a party.
            self::MysteryHint => 'star',
            // The charm is cast now and lands when the chest opens, so this is
            // the sparkle of it taking hold rather than the payoff.
            self::QuestCharm => 'star',
            default => 'confetti',
        };
    }

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
            self::NameMonster => [
                'name' => 'Name a Monster',
                'description' => 'Give one of the monsters a name of your own. It keeps it until the day it goes down.',
                'cost' => 4,
                'glyph' => '✎',
            ],
            // Cheap on purpose. The tickets to buy it come from the run it
            // protects, so pricing it high would mean a kid who has a bad night
            // early — before any milestone has paid — can never afford the
            // thing that exists to help them.
            self::NightSaver => [
                'name' => 'Night Saver',
                'description' => 'Had a night out of your own bed? Buy it back and keep your run going.',
                'cost' => 2,
                'glyph' => '☾',
            ],
            // Priced with Wheel Respin and Quest Reroll rather than with the
            // rescues above it: like them it is a good day made better, not a
            // bad one bought back, and a kid should be able to afford one most
            // days they feel like a gamble.
            self::QuestCharm => [
                'name' => 'Quest Charm',
                'description' => 'Charm the quest chest before you open it. More cards go bold, or the bold bonus grows — and if nothing shows on the cards, the charm pays out when you hand the quest in.',
                'cost' => 3,
                'glyph' => '✧',
            ],
        };
    }
}
