<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SetHouseholdCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_the_timezone_and_boundary_hour(): void
    {
        $household = Household::factory()->create([
            'timezone' => 'America/Chicago',
            'day_boundary_hour' => 4,
        ]);

        $this->artisan('household:set', [
            '--timezone' => 'America/New_York',
            '--day-boundary-hour' => 6,
        ])->assertSuccessful();

        $household->refresh();
        $this->assertSame('America/New_York', $household->timezone);
        $this->assertSame(6, $household->day_boundary_hour);
    }

    public function test_it_leaves_untouched_settings_alone(): void
    {
        $household = Household::factory()->create([
            'name' => 'Home Base',
            'timezone' => 'America/Chicago',
            'day_boundary_hour' => 4,
            'points_per_dollar' => 100,
        ]);

        $this->artisan('household:set', ['--day-boundary-hour' => 5])->assertSuccessful();

        $household->refresh();
        $this->assertSame('Home Base', $household->name);
        $this->assertSame('America/Chicago', $household->timezone);
        $this->assertSame(100, $household->points_per_dollar);
    }

    public function test_it_shows_settings_without_changing_anything(): void
    {
        $household = Household::factory()->create([
            'timezone' => 'America/New_York',
            'day_boundary_hour' => 4,
        ]);

        $this->artisan('household:set')
            ->expectsOutputToContain('America/New_York')
            ->assertSuccessful();

        $household->refresh();
        $this->assertSame('America/New_York', $household->timezone);
        $this->assertSame(4, $household->day_boundary_hour);
    }

    /**
     * Re-reads both models before asking. The cooldown window comes from the
     * chore's household, so a chore loaded before `household:set` ran would
     * still be carrying the old boundary hour on its cached relation.
     */
    private function stateNow(Profile $kid, Chore $chore): string
    {
        return app(ChoreService::class)->stateFor(
            Profile::findOrFail($kid->id),
            Chore::findOrFail($chore->id),
        );
    }

    public function test_it_warns_when_the_boundary_moves_later(): void
    {
        // A later boundary starts today later, so a chore already done this
        // morning falls into yesterday and comes off cooldown.
        Household::factory()->create(['day_boundary_hour' => 4]);

        $this->artisan('household:set', ['--day-boundary-hour' => 8])
            ->expectsOutputToContain('can come off cooldown')
            ->assertSuccessful();
    }

    public function test_it_does_not_warn_when_the_boundary_moves_earlier(): void
    {
        Household::factory()->create(['day_boundary_hour' => 8]);

        $this->artisan('household:set', ['--day-boundary-hour' => 4])
            ->doesntExpectOutputToContain('can come off cooldown')
            ->assertSuccessful();
    }

    public function test_raising_the_boundary_releases_this_mornings_cooldown(): void
    {
        // The behaviour the warning exists for. Asserted directly so the
        // direction of that warning can't quietly get flipped again.
        $household = Household::factory()->create([
            'timezone' => 'America/New_York',
            'day_boundary_hour' => 4,
        ]);
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['cadence' => 'daily']);

        Carbon::setTestNow(Carbon::parse('2026-07-30 06:00', 'America/New_York'));
        $completion = app(ChoreService::class)->claim($kid, $chore);
        app(ChoreService::class)->approve($completion, $parent);

        Carbon::setTestNow(Carbon::parse('2026-07-30 21:00', 'America/New_York'));
        $this->assertSame('done', $this->stateNow($kid, $chore));

        $this->artisan('household:set', ['--day-boundary-hour' => 8])->assertSuccessful();

        // 06:00 now falls before the 08:00 boundary, so it counts as
        // yesterday and today's cooldown is clear again.
        $this->assertSame('ready', $this->stateNow($kid, $chore));

        Carbon::setTestNow();
    }

    public function test_it_rejects_an_invalid_timezone(): void
    {
        $household = Household::factory()->create(['timezone' => 'America/Chicago']);

        $this->artisan('household:set', ['--timezone' => 'EST'])->assertFailed();

        $this->assertSame('America/Chicago', $household->refresh()->timezone);
    }

    public function test_it_rejects_an_out_of_range_boundary_hour(): void
    {
        $household = Household::factory()->create(['day_boundary_hour' => 4]);

        $this->artisan('household:set', ['--day-boundary-hour' => 24])->assertFailed();

        $this->assertSame(4, $household->refresh()->day_boundary_hour);
    }

    public function test_it_toggles_the_boolean_settings(): void
    {
        $household = Household::factory()->create(['spin_enabled' => true]);

        $this->artisan('household:set', ['--spin-enabled' => 'no'])->assertSuccessful();

        $this->assertFalse($household->refresh()->spin_enabled);
    }

    public function test_it_rejects_a_non_boolean_toggle(): void
    {
        $household = Household::factory()->create(['spin_enabled' => true]);

        $this->artisan('household:set', ['--spin-enabled' => 'maybe'])->assertFailed();

        $this->assertTrue($household->refresh()->spin_enabled);
    }

    public function test_it_rejects_a_points_per_dollar_below_one(): void
    {
        $household = Household::factory()->create(['points_per_dollar' => 100]);

        $this->artisan('household:set', ['--points-per-dollar' => 0])->assertFailed();

        $this->assertSame(100, $household->refresh()->points_per_dollar);
    }

    public function test_it_rejects_an_empty_name(): void
    {
        $household = Household::factory()->create(['name' => 'Home Base']);

        $this->artisan('household:set', ['--name' => '   '])->assertFailed();

        $this->assertSame('Home Base', $household->refresh()->name);
    }

    public function test_it_fails_when_no_household_exists(): void
    {
        $this->artisan('household:set', ['--day-boundary-hour' => 5])->assertFailed();
    }
}
