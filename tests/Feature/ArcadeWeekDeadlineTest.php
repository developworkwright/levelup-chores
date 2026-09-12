<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Profile;
use App\Services\ArcadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Saying when the week ends.
 *
 * The board has always closed on Sunday night and never said so in a way
 * anybody could act on, which made the last day of a week feel exactly like the
 * first — the least exciting possible shape for something about to pay out.
 */
class ArcadeWeekDeadlineTest extends TestCase
{
    use RefreshDatabase;

    private function arcade(): ArcadeService
    {
        return app(ArcadeService::class);
    }

    public function test_the_countdown_is_coarse_early_in_the_week(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-08 09:00:00')); // Tuesday

        $this->assertStringContainsString('days left', $this->arcade()->weekCountdown());
        $this->assertFalse($this->arcade()->weekIsClosing());
    }

    public function test_it_counts_hours_on_the_last_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-13 09:00:00')); // Sunday morning

        $this->assertStringContainsString('h left', $this->arcade()->weekCountdown());
        $this->assertTrue($this->arcade()->weekIsClosing());
    }

    public function test_it_counts_minutes_in_the_last_hour(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-13 23:20:00'));

        $this->assertStringContainsString('min left', $this->arcade()->weekCountdown());
        $this->assertTrue($this->arcade()->weekIsClosing());
    }

    /**
     * The deadline has to agree with the bucket a run lands in, or the page
     * counts down to a week the next score will not be posted into.
     */
    public function test_the_deadline_closes_the_week_runs_are_posted_to(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-13 23:59:00'));

        $arcade = $this->arcade();

        $this->assertSame(
            $arcade->currentWeek(),
            $arcade->currentWeek($arcade->weekEndsAt()),
        );

        // And the far side of it belongs to the next week.
        $this->assertNotSame(
            $arcade->currentWeek(),
            $arcade->currentWeek($arcade->weekEndsAt()->addSecond()),
        );
    }

    public function test_the_board_prints_the_countdown(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-08 09:00:00'));

        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Rowan']);

        Auth::guard('profile')->login($kid);

        Volt::test('arcade')
            ->assertSee($this->arcade()->weekCountdown())
            ->assertDontSee('ends Sun');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
