<?php

namespace App\Enums;

/**
 * How rare a pet is, set by the grown-up who made it.
 *
 * Rarity never changes what a pet does in the arcade: every pet has a style
 * (PetStyle), and styles are the same strength at every tier, so a Legendary
 * cannot win a game a Common would have lost. What a rarer pet has is a knack
 * (PetKnack) — and the tier says which knacks it can have, rather than making
 * one knack stronger, so each knack is balanced once.
 *
 * Kids see the tier before they buy: a rim on the Locker tile, and the
 * pattern on an egg (see eggSvg() in resources/js/pets.js).
 */
enum PetRarity: string
{
    case Common = 'common';
    case Rare = 'rare';
    case Epic = 'epic';
    case Legendary = 'legendary';

    public function label(): string
    {
        return match ($this) {
            self::Common => 'Common',
            self::Rare => 'Rare',
            self::Epic => 'Epic',
            self::Legendary => 'Legendary',
        };
    }

    /** The rim and the chip colour, wherever a pet's tier shows. */
    public function color(): string
    {
        return match ($this) {
            self::Common => '#8c7bab',
            self::Rare => '#54c8ff',
            self::Epic => '#c77dff',
            self::Legendary => '#ffc93d',
        };
    }

    /**
     * What an egg of this tier costs, in tickets. The kid can see the tier on
     * the egg, so the price is what a rarer pet costs — there is no luck in
     * which tier comes out of which egg.
     */
    public function eggPrice(): int
    {
        return match ($this) {
            self::Common => 15,
            self::Rare => 20,
            self::Epic => 30,
            self::Legendary => 40,
        };
    }

    /** The shell pattern an egg of this tier wears — see eggSvg() in pets.js. */
    public function eggPattern(): string
    {
        return match ($this) {
            self::Common => 'plain',
            self::Rare => 'speckled',
            self::Epic => 'striped',
            self::Legendary => 'gold',
        };
    }

    /** Whether a pet of this tier has a knack at all. Commons have a style only. */
    public function hasKnack(): bool
    {
        return $this !== self::Common;
    }

    /**
     * The knacks a pet of this tier can have.
     *
     * @return array<int, PetKnack>
     */
    public function knacks(): array
    {
        return array_values(array_filter(PetKnack::cases(), fn (PetKnack $knack) => $knack->rarity() === $this));
    }
}
