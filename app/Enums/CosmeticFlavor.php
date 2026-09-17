<?php

namespace App\Enums;

/**
 * The flavor sets — the house favourites, so a whole look can be dressed to one
 * idea. An item with no flavor is a house basic, which is a chip too.
 *
 * Mirrors `FQCosmetics.TAGS`, less its "Everything" chip, which is a filter
 * rather than a set.
 */
enum CosmeticFlavor: string
{
    case Monster = 'monster';
    case Orange = 'orange';
    case Pink = 'pink';
    case Animal = 'animal';
    case House = 'house';

    public function label(): string
    {
        return match ($this) {
            self::Monster => 'Monsters',
            self::Orange => 'Orange & black',
            self::Pink => 'Pink & white',
            self::Animal => 'Animals',
            self::House => 'House basics',
        };
    }

    /** The chip's ink when it is picked. */
    public function ink(): string
    {
        return match ($this) {
            self::Monster => '#e8e2d2',
            self::Orange => '#ff7a18',
            self::Pink => '#ff5fa2',
            self::Animal => '#e0a243',
            self::House => '#8c7bab',
        };
    }

    /** The chip's rim when it is picked. */
    public function border(): string
    {
        return match ($this) {
            self::Monster => '#c2182f',
            self::Orange => '#ff7a18',
            self::Pink => '#ff5fa2',
            self::Animal => '#e0a243',
            self::House => '#3a2360',
        };
    }
}
