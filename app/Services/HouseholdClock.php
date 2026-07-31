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
}
