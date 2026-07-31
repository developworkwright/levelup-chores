<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Services\HouseholdClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HouseholdClockTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_late_night_moment_still_counts_as_the_previous_day(): void
    {
        $household = Household::factory()->create(['timezone' => 'UTC', 'day_boundary_hour' => 4]);

        Carbon::setTestNow(Carbon::parse('2026-03-10 01:30:00', 'UTC'));

        $today = HouseholdClock::for($household)->today();

        $this->assertSame('2026-03-09', $today->toDateString());
    }

    public function test_a_morning_moment_past_the_boundary_counts_as_today(): void
    {
        $household = Household::factory()->create(['timezone' => 'UTC', 'day_boundary_hour' => 4]);

        Carbon::setTestNow(Carbon::parse('2026-03-10 05:00:00', 'UTC'));

        $today = HouseholdClock::for($household)->today();

        $this->assertSame('2026-03-10', $today->toDateString());
    }

    public function test_start_of_returns_the_boundary_hour_instant(): void
    {
        $household = Household::factory()->create(['timezone' => 'UTC', 'day_boundary_hour' => 4]);

        $start = HouseholdClock::for($household)->startOf(Carbon::parse('2026-03-10 00:00:00', 'UTC'));

        $this->assertSame('2026-03-10 04:00:00', $start->format('Y-m-d H:i:s'));
    }

    public function test_start_of_resolves_to_utc_for_a_non_utc_household(): void
    {
        // Timestamps are stored in UTC, and Eloquent binds a Carbon using its
        // own timezone — so returning household-local time here would compare
        // 04:00 Eastern against 04:00 UTC and throw every cooldown out by the
        // offset. Every clock test used to run on UTC households, which is
        // exactly why that went unnoticed.
        $household = Household::factory()->create([
            'timezone' => 'America/New_York',
            'day_boundary_hour' => 4,
        ]);

        $start = HouseholdClock::for($household)->startOf(
            Carbon::parse('2026-07-30 00:00:00', 'America/New_York')
        );

        // 04:00 EDT is 08:00 UTC.
        $this->assertSame('2026-07-30 08:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $start->timezoneName);
    }
}
