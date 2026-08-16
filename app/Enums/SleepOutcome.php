<?php

namespace App\Enums;

/**
 * How last night went.
 *
 * Three answers, and all three are answers. Only the first lights a star, but
 * none of them is a failure the app reacts to — a kid who woke at 3am
 * frightened is not failing a task, and a card that says so in the morning
 * would be the exact opposite of encouraging. Nothing is ever taken away for
 * picking the second or third: the run simply doesn't advance.
 */
enum SleepOutcome: string
{
    case OwnBed = 'own_bed';

    case Visited = 'visited';

    case Rough = 'rough';

    public function label(): string
    {
        return match ($this) {
            self::OwnBed => 'All night in my own bed',
            self::Visited => 'I came in for a cuddle',
            self::Rough => 'It was a rough night',
        };
    }

    /** The short form, for the log a parent reads. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::OwnBed => 'Own bed',
            self::Visited => 'Came in',
            self::Rough => 'Rough night',
        };
    }

    /** What the card says back. Warm for all three, on purpose. */
    public function response(): string
    {
        return match ($this) {
            self::OwnBed => 'A whole night in your own bed. That is a star.',
            self::Visited => 'Thanks for telling me. There is always tonight.',
            self::Rough => 'Rough nights happen to everyone. Nothing lost.',
        };
    }

    public function glyph(): string
    {
        return match ($this) {
            self::OwnBed => '★',
            self::Visited => '☾',
            self::Rough => '☁',
        };
    }

    public function cssVar(): string
    {
        return match ($this) {
            self::OwnBed => 'var(--fq-lime)',
            self::Visited => 'var(--fq-cyan)',
            self::Rough => 'var(--fq-text-4)',
        };
    }

    /** Whether this night advances the run and the star count. */
    public function countsAsOwnBed(): bool
    {
        return $this === self::OwnBed;
    }

    /**
     * What answering this way pays when a household hasn't said otherwise, in
     * dollars at that household's own rate.
     *
     * A cuddle pays too, and less. A kid who got most of the way there has done
     * something worth more than nothing, and the ladder is what makes the top
     * rung worth reaching for — a single flat reward for the perfect night
     * leaves a hard night paying exactly the same as never trying.
     *
     * Nothing here changes the run or the stars: only {@see self::OwnBed}
     * advances those, whatever any of them pay.
     */
    public function defaultDollars(): int
    {
        return match ($this) {
            self::OwnBed => 2,
            self::Visited => 1,
            self::Rough => 0,
        };
    }

    /** The household column holding this outcome's rate. */
    public function pointsColumn(): string
    {
        return 'sleep_points_'.$this->value;
    }
}
