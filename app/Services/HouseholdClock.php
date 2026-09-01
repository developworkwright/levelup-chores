<?php

namespace App\Services;

use App\Models\Household;
use Illuminate\Support\Carbon;

/**
 * The household's "day" rolls over at day_boundary_hour (default 4am), not
 * midnight, so a chore claimed at 1am still counts for the previous day.
 */
class HouseholdClock
{
    private function __construct(private Household $household) {}

    public static function for(Household $household): self
    {
        return new self($household);
    }

    public function now(): Carbon
    {
        return Carbon::now($this->household->timezone);
    }

    public function today(): Carbon
    {
        return $this->dayFor($this->now());
    }

    /**
     * The household day a given instant belongs to. A chore finished at 1am
     * counts for the previous day, so approving it later must resolve to the
     * same date the kid was working against.
     */
    public function dayFor(Carbon $moment): Carbon
    {
        $local = $moment->copy()->setTimezone($this->household->timezone);
        $date = $local->copy()->startOfDay();

        if ($local->hour < $this->household->day_boundary_hour) {
            $date = $date->subDay();
        }

        return $date;
    }

    /**
     * The instant the household day starting on $date actually begins.
     *
     * Returned in UTC deliberately. Every caller uses this as a query bound
     * against timestamp columns, which are stored in the app timezone (UTC),
     * and Eloquent binds a Carbon using its own timezone — so a household-local
     * Carbon would compare 04:00 Eastern against 04:00 UTC as if they were the
     * same moment, putting every cooldown out by the household's UTC offset.
     */
    public function startOf(Carbon $date): Carbon
    {
        return $date->copy()
            ->setTime($this->household->day_boundary_hour, 0)
            ->utc();
    }

    /**
     * The instant a wall-clock time like "17:00" lands on during the household
     * day currently in progress, in UTC — for the same reason startOf() returns
     * UTC, since this is compared against timestamp columns too.
     *
     * A time earlier than the day boundary belongs to the small hours at the
     * *end* of the day rather than the start: on a 4am boundary, "2:00" means
     * tonight, not this morning, which is already behind us.
     *
     * Returns null for anything that isn't an H:i time, so a hand-typed or
     * cleared field falls through to the caller rather than becoming midnight.
     */
    /**
     * The instant tonight's evening watch begins, in UTC, or null when the
     * household hasn't got a usable one.
     *
     * Not a deadline — nothing expires here. It is the hour past which the
     * Arena starts drawing an open quest as at risk. Resolved through
     * atTime(), so a watch hour set earlier than the day boundary lands in the
     * small hours at the *end* of the day, like every other wall-clock time.
     *
     * **Nullable, and it has to be.** atTime() returns null for anything that
     * isn't an H:i time, and `evening_watch_hour` reaches it as one: a model
     * built in memory has no value for it at all (the column's default is
     * applied by the database, not read back), and the column is an
     * unsignedTinyInteger, so a hand-edited 200 is a legal row and an illegal
     * hour. Declaring this non-nullable turned both of those into a TypeError
     * on every kid page load instead of a household that simply never shows
     * anyone as at risk.
     */
    public function eveningWatch(): ?Carbon
    {
        $hour = $this->household->evening_watch_hour;

        return $hour === null ? null : $this->atTime($hour.':00');
    }

    /**
     * The instant tonight's bedtime lands on, in UTC, or null when the
     * household hasn't set one.
     *
     * Also not a deadline — nothing expires here either. It is what the kids'
     * streak countdown counts down to, because it is the time they can act on;
     * the run itself survives until the rollover. See
     * {@see StreakService::streakWindowFor()}.
     *
     * Nullable for the same reasons eveningWatch() is, plus one more: the
     * column is nullable on purpose, so a house can switch bedtime off and get
     * the rollover back as the target.
     */
    public function bedtime(): ?Carbon
    {
        $bedtime = $this->household->bedtime;

        return $bedtime === null ? null : $this->atTime($bedtime);
    }

    public function atTime(string $time): ?Carbon
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $parts)) {
            return null;
        }

        [, $hour, $minute] = $parts;

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        $moment = $this->today()->setTime((int) $hour, (int) $minute);

        if ((int) $hour < $this->household->day_boundary_hour) {
            $moment = $moment->addDay();
        }

        return $moment->utc();
    }
}
