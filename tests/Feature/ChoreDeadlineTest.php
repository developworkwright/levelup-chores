<?php

namespace Tests\Feature;

use App\Enums\ChoreCadence;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Notifications\ChoreClosingSoon;
use App\Services\ChoreService;
use App\Services\HouseholdClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * A parent can put a chore on the clock — "beat me to it before dinner" — after
 * which it closes for the rest of the household day and the job is theirs. The
 * deadline lifts on its own overnight rather than needing anyone to clear it.
 */
class ChoreDeadlineTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): ChoreService
    {
        return app(ChoreService::class);
    }

    /**
     * Boards exclude whichever chore became today's quest, so fixtures need one
     * quest-eligible chore to absorb the assignment.
     */
    private function household(array $attributes = []): Household
    {
        $household = Household::factory()->create($attributes);

        Chore::factory()->for($household)->create([
            'name' => 'The quest',
            'quest_eligible' => true,
        ]);

        return $household;
    }

    private function chore(Household $household, ?Carbon $expiresAt, string $name = 'Feed the animals'): Chore
    {
        return Chore::factory()->for($household)->create([
            'name' => $name,
            'quest_eligible' => false,
            'expires_at' => $expiresAt,
        ]);
    }

    public function test_a_chore_stays_claimable_until_its_deadline(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $chore = $this->chore($household, now()->addHour());

        $this->assertSame('ready', $this->service()->stateFor($kid, $chore));
        $this->assertNotNull($this->service()->deadlineFor($chore));
    }

    public function test_it_closes_once_the_deadline_passes(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $chore = $this->chore($household, now()->addHour());

        Carbon::setTestNow(now()->addHours(2));

        $this->assertSame('expired', $this->service()->stateFor($kid, $chore->refresh()));
        // Nothing to count down to any more — the badge has to give way to the
        // closed marker rather than tick into negative numbers.
        $this->assertNull($this->service()->deadlineFor($chore));
    }

    public function test_the_deadline_lifts_the_next_household_day(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $chore = $this->chore($household, now()->addHour());

        Carbon::setTestNow(now()->addDays(2));

        // A deadline is "before I do it myself tonight", not a standing curfew,
        // so a stale stamp must not keep a daily chore off the board for good.
        $this->assertSame('ready', $this->service()->stateFor($kid->fresh(), $chore->refresh()));
    }

    public function test_a_claim_that_beat_the_clock_still_stands(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $chore = $this->chore($household, now()->addHour());

        $this->service()->claim($kid, $chore);

        Carbon::setTestNow(now()->addHours(2));

        // They won the race. Flipping their pending claim to "time's up" would
        // read as the work being thrown away after the fact.
        $this->assertSame('pending', $this->service()->stateFor($kid->fresh(), $chore->refresh()));
    }

    public function test_an_unlimited_chore_closes_too(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();

        $chore = Chore::factory()->for($household)->create([
            'name' => 'Fold laundry',
            'cadence' => ChoreCadence::Unlimited,
            'quest_eligible' => false,
            'expires_at' => now()->addHour(),
        ]);

        Carbon::setTestNow(now()->addHours(2));

        // Unlimited means no cooldown, not immune to a parent's deadline.
        $this->assertSame('expired', $this->service()->stateFor($kid->fresh(), $chore->refresh()));
    }

    public function test_a_closed_chore_is_never_handed_out_as_a_quest(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();

        $safe = Chore::factory()->for($household)->create(['name' => 'Safe quest', 'quest_eligible' => true]);
        Chore::factory()->for($household)->create([
            'name' => 'Closed already',
            'quest_eligible' => true,
            'expires_at' => now()->subHour(),
        ]);

        // A quest nobody can clear costs a streak night — the same dead end a
        // spent one-time chore would create.
        $this->assertSame($safe->id, $this->service()->questFor($kid)->chore_id);
    }

    public function test_a_quest_chore_that_closes_is_rerolled(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();

        $doomed = Chore::factory()->for($household)->create([
            'name' => 'Closes at five',
            'quest_eligible' => true,
        ]);

        $this->assertSame($doomed->id, $this->service()->questFor($kid)->chore_id);

        $spare = Chore::factory()->for($household)->create(['name' => 'Spare quest', 'quest_eligible' => true]);

        $this->service()->setDeadline($doomed, now()->addHour());

        Carbon::setTestNow(now()->addHours(2));

        $this->assertSame($spare->id, $this->service()->questFor($kid->fresh())->chore_id);
    }

    public function test_a_closed_chore_is_never_the_mystery_chore(): void
    {
        $household = Household::factory()->create();

        $safe = Chore::factory()->for($household)->create(['name' => 'Safe mystery']);
        Chore::factory()->for($household)->create([
            'name' => 'Closed already',
            'expires_at' => now()->subHour(),
        ]);

        // Hiding the bonus behind a chore nobody can claim means nobody wins it.
        $this->assertSame($safe->id, $this->service()->mysteryChoreFor($household)?->id);
    }

    public function test_a_kid_cannot_claim_a_closed_chore_and_is_told_why(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $chore = $this->chore($household, now()->addHour());

        Carbon::setTestNow(now()->addHours(2));

        Auth::guard('profile')->login($kid->fresh());

        Volt::test('kid.quests')
            ->call('claimChore', $chore->id)
            ->assertSee("Time's up on Feed the animals");

        $this->assertSame(0, $chore->completions()->count());
    }

    public function test_the_kid_board_counts_down_to_the_deadline(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $chore = $this->chore($household, now()->addHour());

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->assertSee('Feed the animals')
            ->assertSee('Closes in');
    }

    public function test_a_parent_can_put_a_chore_on_the_clock(): void
    {
        Notification::fake();

        $household = $this->household();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        $chore = $this->chore($household, null);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')->call('setDeadline', $chore->id, '17:00');

        $expected = HouseholdClock::for($household)->atTime('17:00');

        $this->assertNotNull($chore->refresh()->expires_at);
        $this->assertTrue($expected->equalTo($chore->expires_at));

        // A countdown nobody is told about is just a chore quietly vanishing.
        Notification::assertSentTo($kid, ChoreClosingSoon::class);
        Notification::assertNotSentTo($parent, ChoreClosingSoon::class);
    }

    public function test_a_parent_can_lift_a_deadline(): void
    {
        $household = $this->household();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = $this->chore($household, now()->addHour());

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')->call('clearDeadline', $chore->id);

        $this->assertNull($chore->refresh()->expires_at);
    }

    public function test_clearing_the_time_field_lifts_the_deadline(): void
    {
        $household = $this->household();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = $this->chore($household, now()->addHour());

        Auth::guard('profile')->login($parent);

        // Emptying the input is the same gesture as pressing Clear — a browser
        // that submits a blank time must not resolve it to midnight.
        Volt::test('parent.chores')->call('setDeadline', $chore->id, '');

        $this->assertNull($chore->refresh()->expires_at);
    }

    public function test_a_parent_cannot_put_another_households_chore_on_the_clock(): void
    {
        $parent = Profile::factory()->parent()->for($this->household())->create();
        $foreign = Chore::factory()->for(Household::factory())->create();

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')->call('setDeadline', $foreign->id, '17:00');

        $this->assertNull($foreign->refresh()->expires_at);
    }

    public function test_a_time_before_the_day_boundary_lands_tonight_not_this_morning(): void
    {
        $household = Household::factory()->create([
            'timezone' => 'America/Chicago',
            'day_boundary_hour' => 4,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-02 18:00', 'America/Chicago'));

        $at = HouseholdClock::for($household)->atTime('02:00');

        // 2am on a 4am boundary is the tail end of today's household day, not
        // sixteen hours ago — a deadline set for it must still be ahead.
        $this->assertTrue($at->isFuture());
        $this->assertSame('2026-08-03 02:00', $at->copy()->setTimezone('America/Chicago')->format('Y-m-d H:i'));
    }
}
