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
        $now = $this->now();
        $date = $now->copy()->startOfDay();

        if ($now->hour < $this->household->day_boundary_hour) {
            $date = $date->subDay();
        }

        return $date;
    }

    /** The instant the household day starting on $date actually begins. */
    public function startOf(Carbon $date): Carbon
    {
        return $date->copy()->setTime($this->household->day_boundary_hour, 0);
    }
}
