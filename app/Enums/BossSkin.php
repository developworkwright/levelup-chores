<?php

namespace App\Enums;

/**
 * The monsters the family goal can wear.
 *
 * A skin is art and copy only — every number behind a fight belongs to the
 * `Monster` wearing it (`max_health`, and the damage summed from its hits).
 *
 * **The artwork lives in `resources/js/monsters.js`**, shipped verbatim from the
 * design bundle, and so do the palettes. This enum holds only what the server
 * needs to say out loud: the key it stores on a household, and the name and
 * tagline it renders into HTML, stamps onto a defeat, and puts in a celebration
 * a kid reads days later. Nothing here draws anything.
 *
 * Cases, order, names and taglines must match `FQMonsters.SKINS` exactly —
 * order because it is the rotation, the rest because both sides are read by
 * kids on the same screen. `BossSkinCatalogTest` parses the JavaScript and
 * fails if the two drift.
 */
enum BossSkin: string
{
    case Gnash = 'gnash';
    case Sockmoth = 'sockmoth';
    case Crumbler = 'crumbler';
    case Tangleboy = 'tangleboy';
    case MoldKing = 'mold-king';
    case Dustwyrm = 'dustwyrm';
    case Static = 'static';
    case Chorus = 'chorus';
    case Drip = 'drip';
    case Wallcrack = 'wallcrack';
    case Rattle = 'rattle';
    case Hushpuppet = 'hushpuppet';
    case Longlegs = 'longlegs';
    case Leaner = 'leaner';
    case Balloonhead = 'balloonhead';

    public function label(): string
    {
        return match ($this) {
            self::Gnash => 'Gnash',
            self::Sockmoth => 'The Sockmoth',
            self::Crumbler => 'Crumbler',
            self::Tangleboy => 'Tangleboy',
            self::MoldKing => 'The Mold King',
            self::Dustwyrm => 'Dustwyrm',
            self::Static => 'Static',
            self::Chorus => 'The Chorus',
            self::Drip => 'Drip',
            self::Wallcrack => 'Wallcrack',
            self::Rattle => 'Rattle',
            self::Hushpuppet => 'Hushpuppet',
            self::Longlegs => 'Longlegs',
            self::Leaner => 'The Leaner',
            self::Balloonhead => 'Balloonhead',
        };
    }

    /** One line of "where it lives", which is also the hint about what beats it. */
    public function tagline(): string
    {
        return match ($this) {
            self::Gnash => 'Lives in the toy box. Eats anything left on the floor.',
            self::Sockmoth => 'Made of every sock that never came back.',
            self::Crumbler => 'Scraped itself together from under the sofa.',
            self::Tangleboy => 'A knot of chargers that learned to walk.',
            self::MoldKing => 'Crowned himself at the back of the fridge.',
            self::Dustwyrm => 'Long, grey, and full of things you dropped.',
            self::Static => 'Came in on a channel nobody subscribes to.',
            self::Chorus => 'One voice per mouth, and it knows your name.',
            self::Drip => 'Left the tap running for eleven years.',
            self::Wallcrack => 'Not a shadow. The wall is not that thick.',
            self::Rattle => 'Plays its own ribs when the house goes quiet.',
            self::Hushpuppet => 'Somebody else is holding the strings.',
            self::Longlegs => 'Hangs in the corner. Counts everyone who walks under.',
            self::Leaner => 'Stands at the end of the hall with its head on wrong.',
            self::Balloonhead => 'Left over from a party nobody remembers.',
        };
    }

    /**
     * The next monster in the rotation, so a family that beats one meets
     * somebody new rather than the same face with the bar refilled.
     */
    public function next(): self
    {
        $cases = self::cases();
        $at = array_search($this, $cases, true);

        return $cases[($at + 1) % count($cases)];
    }

    public static function default(): self
    {
        return self::Gnash;
    }

    /**
     * Recovers a skin from the name stamped on a queued defeat.
     *
     * The celebration stores the label rather than the key, and it has to: a
     * kid can be days late to it, by which time the household has rotated to
     * the next monster, so asking the household who the boss is would name the
     * wrong one. The label is what the kid will be shown, and this turns it
     * back into the artwork that goes with it.
     *
     * Null for a defeat queued before the boss battle existed, and for anything
     * whose name no longer matches a case — a renamed monster loses its picture
     * rather than the whole celebration.
     */
    public static function fromLabel(?string $label): ?self
    {
        if ($label === null) {
            return null;
        }

        foreach (self::cases() as $case) {
            if ($case->label() === $label) {
                return $case;
            }
        }

        return null;
    }
}
