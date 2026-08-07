<?php

namespace App\Enums;

/**
 * The monsters the family goal can wear.
 *
 * A skin is art and copy only — every number behind the battle is the goal's
 * (`goal_target` as max health, `goal_now` as damage dealt). Adding a case here
 * means adding a matching `resources/views/components/boss/{value}.blade.php`;
 * `BossSkinCatalogTest` fails loudly if the two get out of step.
 */
enum BossSkin: string
{
    case Gnash = 'gnash';
    case Sockmoth = 'sockmoth';
    case Crumbler = 'crumbler';
    case Tangleboy = 'tangleboy';
    case MoldKing = 'mold-king';
    case Dustwyrm = 'dustwyrm';

    public function label(): string
    {
        return match ($this) {
            self::Gnash => 'Gnash',
            self::Sockmoth => 'The Sockmoth',
            self::Crumbler => 'Crumbler',
            self::Tangleboy => 'Tangleboy',
            self::MoldKing => 'The Mold King',
            self::Dustwyrm => 'Dustwyrm',
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
        };
    }

    /**
     * Body colours. Deliberately outside the app's purple/gold tokens — the
     * monster should read as an intruder in the room, not as more furniture.
     *
     * @return array{body: string, shade: string, glow: string, teeth: string, eye: string}
     */
    public function palette(): array
    {
        return match ($this) {
            self::Gnash => [
                'body' => '#d81b7a', 'shade' => '#8a0f4c', 'glow' => '#ff5fb0',
                'teeth' => '#fff6fb', 'eye' => '#ffe14d',
            ],
            self::Sockmoth => [
                'body' => '#8e8ba6', 'shade' => '#4c4a63', 'glow' => '#c3c0dd',
                'teeth' => '#f2f0ff', 'eye' => '#ff8ac7',
            ],
            self::Crumbler => [
                'body' => '#a9752f', 'shade' => '#5e3f14', 'glow' => '#e0a558',
                'teeth' => '#fff3dc', 'eye' => '#9cff5e',
            ],
            self::Tangleboy => [
                'body' => '#2f3d55', 'shade' => '#161d2b', 'glow' => '#5cc8ff',
                'teeth' => '#e8f7ff', 'eye' => '#5cc8ff',
            ],
            self::MoldKing => [
                'body' => '#5f8f3a', 'shade' => '#2c4a17', 'glow' => '#9cff5e',
                'teeth' => '#f4ffe8', 'eye' => '#ffc93d',
            ],
            self::Dustwyrm => [
                'body' => '#7a7288', 'shade' => '#3d3847', 'glow' => '#b9aed4',
                'teeth' => '#fbf7ff', 'eye' => '#ff6b6b',
            ],
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

    /** The blade component drawing this skin. */
    public function component(): string
    {
        return 'boss.'.$this->value;
    }

    public static function default(): self
    {
        return self::Gnash;
    }
}
