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
}
