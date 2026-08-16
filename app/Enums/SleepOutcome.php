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
}
