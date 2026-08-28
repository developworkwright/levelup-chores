<?php

namespace App\Enums;

/**
 * The faces a kid can put on a quote.
 *
 * Two rules hold this list together, and both are worth keeping when it grows:
 *
 * **None of them is negative.** There is no thumbs-down and there shouldn't be:
 * these are things the kids' own siblings said out loud, and a button for "that
 * wasn't funny" would make the Quote Wall a place you can lose.
 *
 * **None of them crowns anything.** 👑 and ⭐ were considered and left out for
 * that reason alone — quotes are never ranked (see the quotes migration), and a
 * crown under one of them would put the winner back on the page in picture form
 * whatever the schema says.
 *
 * The row wraps on a phone at this length, which is the practical ceiling.
 */
enum ReactionKind: string
{
    /** The default and the one that gets used. */
    case Laugh = 'laugh';

    /** "I'm dead." Sibling shorthand for the funniest tier. */
    case Dead = 'dead';

    /** Laughing hard enough to leak. Distinct from Laugh by degree, which is the point. */
    case Crying = 'crying';

    /** For the ones that are less funny than genuinely baffling. */
    case Mind = 'mind';

    /** No notes. */
    case Fire = 'fire';

    /** Full marks. */
    case Hundred = 'hundred';

    /** Greatest of all time — and a goat, which is funny either way. */
    case Goat = 'goat';

    /** The soft one — for the accidentally lovely rather than the daft. */
    case Heart = 'heart';

    public function emoji(): string
    {
        return match ($this) {
            self::Laugh => '😂',
            self::Dead => '💀',
            self::Crying => '😭',
            self::Mind => '🤯',
            self::Fire => '🔥',
            self::Hundred => '💯',
            self::Goat => '🐐',
            self::Heart => '❤️',
        };
    }

    /** Read out by screen readers, and the button's tooltip when nobody has tapped it. */
    public function label(): string
    {
        return match ($this) {
            self::Laugh => 'Funny',
            self::Dead => 'I am dead',
            self::Crying => 'Crying laughing',
            self::Mind => 'Mind blown',
            self::Fire => 'Fire',
            self::Hundred => 'Full marks',
            self::Goat => 'Greatest of all time',
            self::Heart => 'Love it',
        };
    }

    /** Null for anything that isn't one of the four, so a hand-typed value can't throw. */
    public static function tryFromValue(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
