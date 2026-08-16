<?php

namespace App\Enums;

/**
 * The pictures a kid earns in their own sky, one per seven nights.
 *
 * Deliberately gentle, and deliberately nothing like the monsters — the boss
 * art is meant to be a bit frightening, which is the last register you want on
 * a card about being scared at bedtime.
 *
 * The order is fixed: constellation N is the same picture for every kid, so a
 * sky can be drawn from a single number rather than a stored list. Each carries
 * its own star positions, in a 0-100 box the sky scales into place.
 */
enum Constellation: string
{
    case LittleBear = 'little-bear';
    case Kite = 'kite';
    case Lantern = 'lantern';
    case Turtle = 'turtle';
    case Sailboat = 'sailboat';
    case Crown = 'crown';
    case Whale = 'whale';
    case Butterfly = 'butterfly';
    case Rocket = 'rocket';
    case Fox = 'fox';
    case Teapot = 'teapot';
    case Dragon = 'dragon';

    public function label(): string
    {
        return match ($this) {
            self::LittleBear => 'The Little Bear',
            self::Kite => 'The Kite',
            self::Lantern => 'The Lantern',
            self::Turtle => 'The Turtle',
            self::Sailboat => 'The Sailboat',
            self::Crown => 'The Crown',
            self::Whale => 'The Whale',
            self::Butterfly => 'The Butterfly',
            self::Rocket => 'The Rocket',
            self::Fox => 'The Fox',
            self::Teapot => 'The Teapot',
            self::Dragon => 'The Dragon',
        };
    }

    /**
     * Seven stars, one per night, as x/y percentages. Drawn in order, so a
     * half-finished constellation is the first few points of the finished one
     * rather than a different shape — which is what lets the card show tonight's
     * star landing in the place it will always occupy.
     *
     * @return array<int, array{0: float, 1: float}>
     */
    public function stars(): array
    {
        return match ($this) {
            self::LittleBear => [[18, 72], [34, 60], [50, 64], [62, 48], [74, 34], [58, 26], [44, 36]],
            self::Kite => [[50, 12], [72, 38], [50, 64], [28, 38], [50, 38], [56, 80], [46, 92]],
            self::Lantern => [[50, 10], [32, 26], [32, 62], [50, 78], [68, 62], [68, 26], [50, 92]],
            self::Turtle => [[24, 54], [38, 34], [60, 30], [76, 46], [66, 68], [42, 72], [50, 50]],
            self::Sailboat => [[50, 8], [50, 46], [26, 52], [74, 52], [20, 70], [80, 70], [50, 84]],
            self::Crown => [[16, 66], [28, 34], [40, 58], [52, 26], [64, 58], [76, 34], [84, 66]],
            self::Whale => [[16, 56], [34, 42], [58, 40], [78, 52], [88, 34], [64, 66], [36, 66]],
            self::Butterfly => [[50, 20], [50, 76], [26, 34], [22, 62], [74, 34], [78, 62], [50, 48]],
            self::Rocket => [[50, 8], [38, 34], [62, 34], [38, 66], [62, 66], [30, 84], [70, 84]],
            self::Fox => [[24, 30], [36, 54], [64, 54], [76, 30], [50, 70], [50, 44], [50, 88]],
            self::Teapot => [[26, 36], [26, 68], [58, 68], [58, 36], [72, 46], [16, 50], [42, 24]],
            self::Dragon => [[12, 68], [28, 52], [44, 60], [58, 44], [72, 52], [84, 34], [66, 24]],
        };
    }

    /** How many nights one picture takes. */
    public const NIGHTS = 7;

    /**
     * The constellation a kid's Nth completed picture is, counting from one.
     * Cycles once they have them all — twelve pictures is nearly six months of
     * perfect nights, and running out is a nicer problem than stopping.
     */
    public static function number(int $completed): self
    {
        $cases = self::cases();

        return $cases[($completed - 1) % count($cases)];
    }

    /** How many whole pictures a given number of nights has finished. */
    public static function completedFrom(int $nights): int
    {
        return intdiv($nights, self::NIGHTS);
    }

    /** How many stars are lit in the picture currently being drawn. */
    public static function starsInProgress(int $nights): int
    {
        return $nights % self::NIGHTS;
    }
}
