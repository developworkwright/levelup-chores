<?php

namespace App\Services;

use App\Enums\SleepBand;

/**
 * The shape of a night, as the hours card now asks it.
 *
 * ## Minutes since noon, not clock times
 *
 * A night starts in one day and ends in the next, so clock times sort wrongly:
 * 11pm is an earlier bedtime than 1am but a bigger number, and every comparison
 * has to special-case the wrap. Both columns on `sleep_nights` — and everything
 * here — are instead minutes since **noon on the evening the night began**.
 * 11pm is 660, midnight is 720, 6am is 1080, 7am is 1140, and "how long did
 * they sleep" is a subtraction rather than a special case.
 *
 * ## The core hours
 *
 * Eight hours is no longer enough on its own: a full night has to be asleep
 * right across midnight to 6am as well. Eight hours from 3am to 11am is still
 * eight hours, and still isn't sleeping when the house sleeps — the card is for
 * the habit, not the arithmetic.
 *
 * And there is a floor underneath that: a night has to be asleep for at least
 * four of those six hours to pay anything at all. That line is what tells a
 * night from a nap, and it is there because of the two answers that were
 * actually being given — a doze at bedtime, and a sleep that ran through the
 * following day. Both look like nights by length alone.
 *
 * Nothing here is a punishment, in keeping with the rest of the card: a long
 * night in the wrong hours drops a band, a night that never really met the
 * window simply doesn't pay, and neither takes back anything already earned.
 * Only the run stops. See {@see SleepBand::fromNight()}.
 */
final class NightWindow
{
    /** Midnight — the start of the hours a full night has to cover. */
    public const CORE_START = 720;

    /** Six in the morning — the end of them. */
    public const CORE_END = 1080;

    /** How long the window is: six hours. */
    public const CORE_LENGTH = self::CORE_END - self::CORE_START;

    /**
     * How much of the window a night has to be asleep for to pay anything at
     * all: four hours of it.
     *
     * This is the line that tells a night from a nap. The two answers that
     * prompted it were a doze at bedtime and a sleep that ran through the
     * following day — both of them long enough to look like nights, and
     * neither of them sleep between midnight and six. Covering the window
     * *entirely* is still what a full night means; this is only the floor
     * below which the card stops paying.
     */
    public const PAYING_OVERLAP = 240;

    /** A whole day — the furthest apart a bedtime and a waking can be. */
    public const DAY = 1440;

    /**
     * Where the two steppers open — 11pm to 7am. Eight hours that cover the
     * window, so the default answer is the night the card is asking for rather
     * than one a kid has to climb to.
     */
    public const DEFAULT_ASLEEP = 660;

    public const DEFAULT_AWAKE = 1140;

    /**
     * A bedtime, as any time of day to the minute.
     *
     * The card used to hold bedtimes between 6pm and 4am on the half hour, and
     * a kid who fell asleep outside that had no way to say so. Any clock time
     * is an answer now; the window rules decide what the night was worth.
     */
    public static function asleepAt(int $minute): int
    {
        return self::wrap($minute);
    }

    /**
     * A waking: the first time after the bedtime that the clock reads it.
     *
     * Only the waking's clock time is taken from what is sent — which day it
     * lands on falls out of the bedtime. So 7am after an 11pm bedtime is the
     * next morning, and 2pm after a 4am one is that afternoon, which is why a
     * waking can be more than a day's worth of minutes past noon. The same
     * clock time as the bedtime is a night of nothing.
     */
    public static function awakeAt(int $asleep, int $minute): int
    {
        $asleep = self::wrap($asleep);

        return $asleep + self::wrap($minute - $asleep);
    }

    /**
     * How long the night was. Clamped at {@see SleepBand::MAX_MINUTES} — past
     * that it isn't a night, it's a stale form, and the same clamp the stepper
     * has always had applies rather than losing the kid their answer.
     */
    public static function lengthOf(int $asleep, int $awake): int
    {
        return max(0, min(SleepBand::MAX_MINUTES, $awake - $asleep));
    }

    /**
     * How many minutes of the night fell between midnight and 6am.
     *
     * The number both halves of the rule are read from: all six hours of it
     * makes a night eligible to be a full one, four of them is the floor for
     * being paid at all, and less than that is a nap however long it ran. See
     * {@see SleepBand::fromNight()}.
     */
    public static function overlapOf(int $asleep, int $awake): int
    {
        return max(0, min($awake, self::CORE_END) - max($asleep, self::CORE_START));
    }

    /** Whether the night was asleep for the whole of midnight to 6am. */
    public static function covers(int $asleep, int $awake): bool
    {
        return self::overlapOf($asleep, $awake) === self::CORE_LENGTH;
    }

    /** "11:00 pm" — how every part of the card says a time. */
    public static function say(int $minute): string
    {
        $clock = ($minute + self::CORE_START) % 1440;
        $hour = intdiv($clock, 60);
        $suffix = $hour < 12 ? 'am' : 'pm';

        return sprintf('%d:%02d %s', $hour % 12 === 0 ? 12 : $hour % 12, $clock % 60, $suffix);
    }

    /** Any number of minutes, as a time of day since noon. */
    private static function wrap(int $minute): int
    {
        return (($minute % self::DAY) + self::DAY) % self::DAY;
    }
}
