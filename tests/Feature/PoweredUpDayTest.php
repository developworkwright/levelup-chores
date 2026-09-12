<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChoreService;
use App\Services\StreakService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * One chore a day switches the day's extras on.
 *
 * The reward loop had come unhooked from the work loop — a kid could spin, open
 * chests, earn tickets and play all week without submitting a single chore.
 * This hooks it back up the only way that is safe to point at a kid who has
 * already drifted: by turning things *on*, never off. Every test here that
 * looks like a gate is really asserting the absence of one.
 *
 * The visible half matters as much as the mechanic. The daily chest has boosted
 * itself on this exact rule for months and nobody noticed, because a better
 * roll you cannot see is not a reward.
 */
class PoweredUpDayTest extends TestCase
{
    use RefreshDatabase;

    private function submitChore(Profile $kid, ?Carbon $when = null, string $status = 'pending'): ChoreCompletion
    {
        $chore = Chore::factory()->for($kid->household)->create();

        return ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $kid->id,
            'status' => $status,
            'points_awarded' => 100,
            'submitted_at' => $when ?? now(),
            'decided_at' => $status === 'pending' ? null : ($when ?? now()),
        ]);
    }

    private function streaks(): StreakService
    {
        return app(StreakService::class);
    }

    private function chores(): ChoreService
    {
        return app(ChoreService::class);
    }

    public function test_a_chore_today_powers_the_day_up(): void
    {
        $kid = Profile::factory()->create();

        $this->assertFalse($this->streaks()->hasWorkedToday($kid));

        $this->submitChore($kid);

        $this->assertTrue($this->streaks()->hasWorkedToday($kid));
    }

    public function test_work_waiting_on_a_grown_up_still_powers_the_day_up(): void
    {
        $kid = Profile::factory()->create();

        $this->submitChore($kid, status: 'pending');

        $this->assertTrue($this->streaks()->hasWorkedToday($kid));
    }

    public function test_a_parent_is_always_powered_up(): void
    {
        $parent = Profile::factory()->parent()->create();

        $this->assertTrue($this->streaks()->hasWorkedToday($parent));
    }

    public function test_yesterdays_work_buys_a_bigger_hand_today(): void
    {
        $kid = Profile::factory()->create();

        $this->assertSame(ChoreService::HAND_SIZE, $this->chores()->handSizeFor($kid));

        $this->submitChore($kid, now()->subDay());

        $this->assertSame(
            ChoreService::HAND_SIZE + ChoreService::HAND_BONUS_CARDS,
            $this->chores()->handSizeFor($kid),
        );
    }

    /**
     * The hand is dealt in the morning, before today's work exists — so today's
     * chore must not be what pays for today's cards, or the reward would be
     * unreachable on the day it is offered.
     */
    public function test_todays_work_does_not_change_todays_hand(): void
    {
        $kid = Profile::factory()->create();

        $this->submitChore($kid);

        $this->assertSame(ChoreService::HAND_SIZE, $this->chores()->handSizeFor($kid));
    }

    public function test_the_bigger_hand_actually_deals_more_cards(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['age' => 12]);

        Chore::factory()->for($household)->count(12)->create(['quest_eligible' => true]);

        $this->submitChore($kid, now()->subDay());

        $hand = $this->chores()->offeredChoresFor($kid);

        $this->assertCount(ChoreService::HAND_SIZE + ChoreService::HAND_BONUS_CARDS, $hand);
    }

    public function test_a_kid_who_did_nothing_yesterday_gets_the_normal_hand(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['age' => 12]);

        Chore::factory()->for($household)->count(12)->create(['quest_eligible' => true]);

        $this->assertCount(ChoreService::HAND_SIZE, $this->chores()->offeredChoresFor($kid));
    }

    public function test_home_names_the_extras_when_they_are_off(): void
    {
        $kid = Profile::factory()->create();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')
            ->assertSee('Not powered up yet')
            ->assertSee('Do one chore today')
            ->assertSee('extra quest cards tomorrow');
    }

    public function test_home_says_so_when_they_are_on(): void
    {
        $kid = Profile::factory()->create();

        $this->submitChore($kid);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')
            ->assertSee('Powered up')
            ->assertDontSee('Not powered up yet');
    }

    /**
     * The bug this was rebuilt for: powering up was a *state*, so the first
     * person to try it did a chore and did not find out until they had logged
     * out and back in. It has to arrive, not wait to be noticed.
     */
    public function test_the_days_first_chore_announces_itself(): void
    {
        $kid = Profile::factory()->create();

        $this->submitChore($kid);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')->assertSee('POWERED UP!');

        $this->assertTrue($kid->fresh()->powered_up_on->isToday());
    }

    public function test_it_announces_once_and_not_on_the_next_chore(): void
    {
        $kid = Profile::factory()->create();

        $this->submitChore($kid);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')->assertSee('POWERED UP!');

        $this->submitChore($kid);

        Volt::test('kid.home')->assertDontSee('POWERED UP!');
    }

    public function test_it_announces_again_the_next_day(): void
    {
        $kid = Profile::factory()->create();

        $this->submitChore($kid, now()->subDay());
        $kid->forceFill(['powered_up_on' => now()->subDay()])->save();

        $this->submitChore($kid);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')->assertSee('POWERED UP!');
    }

    public function test_a_day_with_no_chore_announces_nothing(): void
    {
        $kid = Profile::factory()->create();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')->assertDontSee('POWERED UP!');

        $this->assertNull($kid->fresh()->powered_up_on);
    }

    public function test_the_token_and_the_sky_come_alive_when_powered_up(): void
    {
        $kid = Profile::factory()->create();

        $this->submitChore($kid);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')
            ->assertSee('fq-powered-token')
            ->assertSee('fq-shooting-star');
    }

    public function test_the_token_and_the_sky_are_still_when_it_is_not(): void
    {
        $kid = Profile::factory()->create();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')
            ->assertDontSee('fq-powered-token')
            ->assertDontSee('fq-shooting-star');
    }

    /**
     * The guard rail. Nothing on this strip may ever be phrased as something
     * taken away, because the kid reading it is the one who has done nothing
     * today and a locked door tells them to go away.
     */
    public function test_the_strip_never_says_anything_is_locked(): void
    {
        // Rendered on its own rather than through the page: the shell's push
        // toggle says "blocked", which contains the word being looked for and
        // has nothing to do with this.
        $html = $this->blade(
            '<x-powered-up-strip :powered-up="false" :hand-size="3" :bonus-cards="2" />'
        );

        foreach (['lock', 'Lock', 'earn it', 'can\'t', 'cannot'] as $forbidden) {
            $html->assertDontSee($forbidden, false);
        }
    }
}
