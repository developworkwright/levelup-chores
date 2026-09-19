<?php

namespace App\Enums;

/**
 * How a pet helps in the arcade. Every pet has one, whatever its rarity.
 *
 * A style names a kind of help, not an effect: each game decides what Steady,
 * Big, Quick and Lucky do in it — a Steady pet steadies one wobbly drop in
 * Stack the Mess, a Quick one makes the bean hop in Windy Walkies go a lane
 * further. That is what lets there be a ton of games without any one pet
 * owning all of them: each game gives each style something of about the same
 * worth, and which one suits a game best differs from game to game.
 *
 * The same at every rarity, on purpose — see PetRarity.
 */
enum PetStyle: string
{
    case Steady = 'steady';
    case Big = 'big';
    case Quick = 'quick';
    case Lucky = 'lucky';

    public function label(): string
    {
        return match ($this) {
            self::Steady => 'Steady',
            self::Big => 'Big',
            self::Quick => 'Quick',
            self::Lucky => 'Lucky',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Steady => 'fa-shield-halved',
            self::Big => 'fa-up-right-and-down-left-from-center',
            self::Quick => 'fa-bolt',
            self::Lucky => 'fa-clover',
        };
    }

    /** What the style is about, in a kid's words. Each game says what it does there. */
    public function blurb(): string
    {
        return match ($this) {
            self::Steady => 'Keeps things from going wrong',
            self::Big => 'Makes things bigger and easier to hit',
            self::Quick => 'Gets you further, faster',
            self::Lucky => 'Gives you a second chance',
        };
    }

    /**
     * The style a pet made before styles existed starts with: spread across
     * the four by id, so a house's old pets do not all come out the same. A
     * grown-up can change it.
     */
    public static function startingFor(int $cosmeticId): self
    {
        return self::cases()[$cosmeticId % count(self::cases())];
    }
}
