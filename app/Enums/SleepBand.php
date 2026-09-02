<?php

namespace App\Enums;

/**
 * How long last night was, bucketed.
 *
 * The hours card takes a number and lands it in one of these. The band is
 * always derived from the minutes and never stored, so the two can't drift and
 * a household that later moves the six-hour line doesn't leave old rows
 * disagreeing with the enum.
 *
 * Same rule as {@see SleepOutcome}: none of the three is a failure the app
 * reacts to. A short night still pays, a poor one costs nothing that was
 * already earned, and only the run stops. Sleep is the one thing on this card a
 * kid has the least control over — a card that scolded them for a bad night
 * would be punishing them for lying awake.
 */
enum SleepBand: string
{
    case Full = 'full';

    case Short = 'short';

    case Poor = 'poor';

    /** Eight hours. The night the run is actually about. */
    public const FULL_MINUTES = 480;

    /** Six. Below this the run stops, though nothing is taken away. */
    public const SHORT_MINUTES = 360;

    /** Answers are taken to the half hour, which is as precise as anyone is. */
    public const STEP_MINUTES = 30;

    /** The most a kid can log — past this it isn't a night, it's a typo. */
    public const MAX_MINUTES = 840;

    /** Where the stepper starts a kid who hasn't answered yet. */
    public const DEFAULT_MINUTES = 480;

    public static function fromMinutes(int $minutes): self
    {
        return match (true) {
            $minutes >= self::FULL_MINUTES => self::Full,
            $minutes >= self::SHORT_MINUTES => self::Short,
            default => self::Poor,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Full => 'A full night',
            self::Short => 'A short night',
            self::Poor => 'A rough night',
        };
    }

    /** The short form, for the log a parent reads. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Full => 'Full night',
            self::Short => 'Short night',
            self::Poor => 'Rough night',
        };
    }

    /** The band's own range, said the way the buttons say it. */
    public function range(): string
    {
        return match ($this) {
            self::Full => '8 hours or more',
            self::Short => '6 to 8 hours',
            self::Poor => 'Under 6 hours',
        };
    }

    /** What the card says back. Warm for all three, on purpose. */
    public function response(): string
    {
        return match ($this) {
            self::Full => 'A full night. That is the one that counts.',
            self::Short => 'Not a full night, but it still counts for something.',
            self::Poor => 'A rough one. Nothing lost — tonight is a new go.',
        };
    }

    public function glyph(): string
    {
        return match ($this) {
            self::Full => '★',
            self::Short => '☾',
            self::Poor => '☁',
        };
    }

    public function cssVar(): string
    {
        return match ($this) {
            self::Full => 'var(--fq-lime)',
            self::Short => 'var(--fq-cyan)',
            self::Poor => 'var(--fq-text-4)',
        };
    }

    /** Whether this band advances the run and the night count. */
    public function counts(): bool
    {
        return $this === self::Full;
    }

    /**
     * What this band pays when a household hasn't said otherwise, in points at
     * that household's own rate.
     *
     * In half-dollars rather than the whole ones {@see SleepOutcome} uses,
     * because a short night is worth fifty cents and there is no honest way to
     * say that in integer dollars.
     */
    public function defaultPoints(int $pointsPerDollar): int
    {
        $halfDollars = match ($this) {
            self::Full => 2,
            self::Short => 1,
            self::Poor => 0,
        };

        return intdiv($halfDollars * $pointsPerDollar, 2);
    }

    /** The household column holding this band's rate. */
    public function pointsColumn(): string
    {
        return 'sleep_hours_points_'.$this->value;
    }

    /** "7h 30m" — how every part of the card says a length. */
    public static function say(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? "{$hours}h" : "{$hours}h {$rest}m";
    }
}
