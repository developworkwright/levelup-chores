<?php

namespace Tests\Feature;

use App\Enums\ArcadeGame;
use App\Models\Household;
use App\Models\Profile;
use App\Notifications\ArcadeLastCall;
use App\Services\ArcadeService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The Sunday-evening last call.
 *
 * The weekly prize has always been paid on a deadline nobody could see, and the
 * board's own countdown only reaches a kid already looking at it. This is the
 * half that reaches one who isn't — timed off bedtime rather than off the
 * technical end of the week, because a push saying the boards close at midnight
 * is a push telling a child to be awake at midnight.
 */
class ArcadeLastCallTest extends TestCase
{
    use RefreshDatabase;

    private function arcade(): ArcadeService
    {
        return app(ArcadeService::class);
    }

    /** Sunday evening, in a household whose clock matches the app's. */
    private function sundayEvening(string $time = '2026-09-13 17:00:00'): void
    {
        Carbon::setTestNow(Carbon::parse($time));
    }

    private function household(?string $bedtime = '20:00'): Household
    {
        return Household::factory()->create([
            'timezone' => 'UTC',
            'bedtime' => $bedtime,
        ]);
    }

    public function test_the_hour_is_a_few_hours_before_bedtime(): void
    {
        $this->assertSame(17, $this->arcade()->lastCallHourFor($this->household('20:00')));
        $this->assertSame(16, $this->arcade()->lastCallHourFor($this->household('19:00')));
    }

    /**
     * The whole point is that it lands with enough evening left to play, so a
     * very late bedtime must not drag the last call into the night.
     */
    public function test_a_late_bedtime_does_not_push_the_last_call_into_the_night(): void
    {
        $this->assertSame(19, $this->arcade()->lastCallHourFor($this->household('23:30')));
    }

    /** And a very early one must not drag it into the school day. */
    public function test_an_early_bedtime_does_not_pull_it_into_the_afternoon(): void
    {
        $this->assertSame(15, $this->arcade()->lastCallHourFor($this->household('17:00')));
    }

    public function test_a_household_with_no_bedtime_gets_a_sensible_default(): void
    {
        $this->assertSame(17, $this->arcade()->lastCallHourFor($this->household(null)));
    }

    public function test_it_is_due_in_the_target_hour_on_the_last_day(): void
    {
        $this->sundayEvening();

        $this->assertTrue($this->arcade()->isLastCallDue($this->household('20:00')));
    }

    public function test_it_is_not_due_at_the_wrong_hour(): void
    {
        $this->sundayEvening('2026-09-13 12:00:00');

        $this->assertFalse($this->arcade()->isLastCallDue($this->household('20:00')));
    }

    public function test_it_is_not_due_earlier_in_the_week(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09 17:00:00')); // Wednesday

        $this->assertFalse($this->arcade()->isLastCallDue($this->household('20:00')));
    }

    /**
     * The hour is read off the household's own clock, so a house in another
     * timezone gets this in its own early evening rather than the server's.
     */
    public function test_the_hour_is_the_households_own(): void
    {
        // 17:00 UTC is 12:00 in New York, which is not that household's hour.
        $this->sundayEvening('2026-09-13 17:00:00');

        $newYork = Household::factory()->create([
            'timezone' => 'America/New_York',
            'bedtime' => '20:00',
        ]);

        $this->assertFalse($this->arcade()->isLastCallDue($newYork));

        // 21:00 UTC is 17:00 there, which is.
        Carbon::setTestNow(Carbon::parse('2026-09-13 21:00:00'));

        $this->assertTrue($this->arcade()->isLastCallDue($newYork));
    }

    public function test_it_tells_a_leader_what_they_are_defending(): void
    {
        $this->sundayEvening();

        $household = $this->household();
        $rowan = Profile::factory()->for($household)->create(['name' => 'Rowan']);

        foreach (ArcadeGame::ranked() as $game) {
            $this->arcade()->post($rowan, $game, 50);
        }

        $message = $this->arcade()->lastCallFor($rowan);

        $this->assertNotNull($message);
        $this->assertStringContainsString('You are top of', $message['body']);
    }

    public function test_it_tells_a_chaser_the_number_to_beat(): void
    {
        $this->sundayEvening();

        $household = $this->household();
        $rowan = Profile::factory()->for($household)->create(['name' => 'Rowan']);
        $wren = Profile::factory()->for($household)->create(['name' => 'Wren']);

        foreach (ArcadeGame::ranked() as $game) {
            $this->arcade()->post($rowan, $game, 50);
        }

        $message = $this->arcade()->lastCallFor($wren);

        $this->assertNotNull($message);
        $this->assertStringContainsString('Rowan leads', $message['body']);
        $this->assertStringContainsString('beat 51', $message['body']);
    }

    /**
     * A board nobody has touched is the one a kid who is behind on everything
     * can actually take, so it wins the pick outright.
     */
    public function test_an_untouched_board_is_offered_ahead_of_a_chase(): void
    {
        $this->sundayEvening();

        $household = $this->household();
        $rowan = Profile::factory()->for($household)->create(['name' => 'Rowan']);
        $wren = Profile::factory()->for($household)->create(['name' => 'Wren']);

        // Only the first ranked game gets played, leaving the rest open.
        $this->arcade()->post($rowan, ArcadeGame::ranked()[0], 50);

        $message = $this->arcade()->lastCallFor($wren);

        $this->assertNotNull($message);
        $this->assertStringContainsString('Nobody has played', $message['body']);
        $this->assertStringContainsString('one run takes it', $message['body']);
    }

    /**
     * The deadline a kid can act on is bedtime. Saying the boards close at
     * midnight is telling them to be awake at midnight.
     */
    public function test_the_message_points_at_bedtime_not_midnight(): void
    {
        $this->sundayEvening();

        $household = $this->household('20:00');
        $rowan = Profile::factory()->for($household)->create(['name' => 'Rowan']);

        $this->arcade()->post($rowan, ArcadeGame::ranked()[0], 50);

        $message = $this->arcade()->lastCallFor($rowan);

        $this->assertStringContainsString('until bedtime', $message['body']);
        $this->assertStringNotContainsString('midnight', $message['body']);
    }

    public function test_it_sends_to_kids_and_not_to_grown_ups(): void
    {
        Notification::fake();
        $this->sundayEvening();

        $household = $this->household();
        $rowan = Profile::factory()->for($household)->create(['name' => 'Rowan']);
        $dad = Profile::factory()->for($household)->parent()->create(['name' => 'Dad']);

        $this->arcade()->post($rowan, ArcadeGame::ranked()[0], 50);

        $this->assertSame(1, $this->arcade()->sendLastCall($household));

        Notification::assertSentTo($rowan, ArcadeLastCall::class);
        Notification::assertNotSentTo($dad, ArcadeLastCall::class);
    }

    public function test_it_sends_at_most_once_a_week(): void
    {
        Notification::fake();
        $this->sundayEvening();

        $household = $this->household();
        $rowan = Profile::factory()->for($household)->create(['name' => 'Rowan']);

        $this->arcade()->post($rowan, ArcadeGame::ranked()[0], 50);

        $this->assertSame(1, $this->arcade()->sendLastCall($household));
        $this->assertSame(0, $this->arcade()->sendLastCall($household));

        Notification::assertSentToTimes($rowan, ArcadeLastCall::class, 1);
    }

    public function test_it_sends_nothing_outside_the_target_hour(): void
    {
        Notification::fake();
        $this->sundayEvening('2026-09-13 12:00:00');

        $household = $this->household();
        Profile::factory()->for($household)->create(['name' => 'Rowan']);

        $this->assertSame(0, $this->arcade()->sendLastCall($household));

        Notification::assertNothingSent();
    }

    public function test_force_overrides_both_gates(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-09-09 03:00:00')); // Wednesday, 3am

        $household = $this->household();
        $rowan = Profile::factory()->for($household)->create(['name' => 'Rowan']);

        $this->assertSame(1, $this->arcade()->sendLastCall($household, force: true));
        $this->assertSame(1, $this->arcade()->sendLastCall($household, force: true));

        Notification::assertSentToTimes($rowan, ArcadeLastCall::class, 2);
    }

    public function test_the_command_sends_the_last_call(): void
    {
        Notification::fake();
        $this->sundayEvening();

        $household = $this->household();
        $rowan = Profile::factory()->for($household)->create(['name' => 'Rowan']);

        $this->arcade()->post($rowan, ArcadeGame::ranked()[0], 50);

        $this->artisan('arcade:last-call')->assertSuccessful();

        Notification::assertSentTo($rowan, ArcadeLastCall::class);
    }

    public function test_the_command_is_quiet_when_there_is_nothing_to_do(): void
    {
        Notification::fake();
        $this->sundayEvening('2026-09-13 12:00:00');

        $household = $this->household();
        Profile::factory()->for($household)->create(['name' => 'Rowan']);

        $this->artisan('arcade:last-call')
            ->doesntExpectOutputToContain('sent to')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_command_can_target_one_household(): void
    {
        Notification::fake();
        $this->sundayEvening();

        $mine = $this->household();
        $rowan = Profile::factory()->for($mine)->create(['name' => 'Rowan']);

        $theirs = $this->household();
        $wren = Profile::factory()->for($theirs)->create(['name' => 'Wren']);

        $this->arcade()->post($rowan, ArcadeGame::ranked()[0], 50);
        $this->arcade()->post($wren, ArcadeGame::ranked()[0], 50);

        $this->artisan('arcade:last-call', ['--household' => $mine->id])->assertSuccessful();

        Notification::assertSentTo($rowan, ArcadeLastCall::class);
        Notification::assertNotSentTo($wren, ArcadeLastCall::class);
    }

    /**
     * The host is scale-to-zero, so nothing may depend on a per-minute
     * scheduler. If a schedule entry ever reappears, this is the test that
     * should make somebody justify it.
     */
    public function test_nothing_is_scheduled(): void
    {
        $this->assertSame([], app(Schedule::class)->events());
    }

    public function test_a_kid_opening_a_page_in_the_window_sends_the_last_call(): void
    {
        Notification::fake();
        $this->sundayEvening();

        $household = $this->household();
        $rowan = Profile::factory()->for($household)->create(['name' => 'Rowan']);

        $this->arcade()->post($rowan, ArcadeGame::ranked()[0], 50);

        $this->actingAs($rowan, 'profile')->get(route('kid.home'))->assertOk();

        Notification::assertSentTo($rowan, ArcadeLastCall::class);
    }

    /**
     * A parent clearing the approval queue after Sunday dinner is the likeliest
     * visitor of all, and the one whose visit should reach a kid who has
     * stopped coming.
     */
    public function test_a_parents_visit_sends_the_last_call_to_the_kids(): void
    {
        Notification::fake();
        $this->sundayEvening();

        $household = $this->household();
        $rowan = Profile::factory()->for($household)->create(['name' => 'Rowan']);
        $dad = Profile::factory()->for($household)->parent()->create(['name' => 'Dad']);

        $this->arcade()->post($rowan, ArcadeGame::ranked()[0], 50);

        $this->actingAs($dad, 'profile')->get(route('parent.home'))->assertOk();

        Notification::assertSentTo($rowan, ArcadeLastCall::class);
        Notification::assertNotSentTo($dad, ArcadeLastCall::class);
    }

    public function test_a_visit_outside_the_window_sends_nothing(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-09-09 17:00:00')); // Wednesday

        $household = $this->household();
        $rowan = Profile::factory()->for($household)->create(['name' => 'Rowan']);

        $this->actingAs($rowan, 'profile')->get(route('kid.home'))->assertOk();

        Notification::assertNothingSent();
    }

    /**
     * Every page a kid opens that evening passes through the middleware, and
     * only the first may buzz.
     */
    public function test_repeated_visits_buzz_once(): void
    {
        Notification::fake();
        $this->sundayEvening();

        $household = $this->household();
        $rowan = Profile::factory()->for($household)->create(['name' => 'Rowan']);

        $this->arcade()->post($rowan, ArcadeGame::ranked()[0], 50);

        $this->actingAs($rowan, 'profile')->get(route('kid.home'))->assertOk();
        $this->actingAs($rowan, 'profile')->get(route('kid.arcade'))->assertOk();
        $this->actingAs($rowan, 'profile')->get(route('kid.home'))->assertOk();

        Notification::assertSentToTimes($rowan, ArcadeLastCall::class, 1);
    }

    /**
     * The window check has to be free of database work — it runs on every
     * authenticated request, and for most of the week its whole job is to say
     * "no" without asking anything.
     */
    public function test_the_window_check_costs_no_queries(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09 17:00:00'));

        $arcade = $this->arcade();
        $queries = 0;

        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->assertFalse($arcade->lastCallWindowIsOpen());
        $this->assertSame(0, $queries);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
