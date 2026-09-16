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

    /**
     * Powered Up used to buy two extra quest cards the following morning as
     * well as the better chest. The cards went with the quest, so the chest is
     * the whole of it — which is why yesterday's work buys nothing today.
     */
    public function test_yesterdays_work_does_not_power_up_today(): void
    {
        $kid = Profile::factory()->create();

        $this->submitChore($kid, now()->subDay());

        $this->assertFalse($this->streaks()->hasWorkedToday($kid->refresh()));
    }

    /**
     * The Home card that spelled the extras out is gone. What is left is a bolt
     * beside the kid's name in the header — on every page, not just Home — dim
     * until the day's first chore and lit after it.
     */
    public function test_the_bolt_beside_the_name_is_dim_before_a_chore(): void
    {
        $kid = Profile::factory()->create();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')
            ->assertSee('data-powered="off"', false)
            ->assertSee('Not powered up yet')
            // The card's own wording must not have survived somewhere.
            ->assertDontSee('Any chore counts, and it happens');
    }

    public function test_the_bolt_lights_once_a_chore_is_in(): void
    {
        $kid = Profile::factory()->create();

        $this->submitChore($kid);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')
            ->assertSee('data-powered="on"', false)
            ->assertSee('Powered up today');
    }

    /** The header draws it, so it is on every kid page, not only Home. */
    public function test_the_bolt_is_in_the_header_on_other_pages_too(): void
    {
        $kid = Profile::factory()->create();

        $this->submitChore($kid);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.journal')->assertSee('data-powered="on"', false);
    }

    /**
     * The bug this was rebuilt for: powering up was a *state*, so the first
     * person to try it did a chore and did not find out until they had logged
     * out and back in. It has to arrive, not wait to be noticed.
     */
    public function test_the_days_first_chore_announces_itself(): void
    {
        // Pinned to the middle of a household day. `powered_up_on` is stamped
        // with HouseholdClock::today() and read back with isToday(), which is
        // the app timezone — so between UTC midnight and the household's 4am
        // boundary the two genuinely disagree and this failed on the clock
        // rather than on anything it tests.
        $this->travelTo(Carbon::parse('2026-05-04 12:00', 'America/Chicago'));

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
        // Pinned for the same reason as above.
        $this->travelTo(Carbon::parse('2026-05-04 12:00', 'America/Chicago'));

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
     * The guard rail, moved onto the bolt. Nothing about the dim state may ever
     * be phrased as something taken away, because the kid reading it is the one
     * who has done nothing today and a locked door tells them to go away.
     */
    public function test_the_dim_bolt_never_says_anything_is_locked(): void
    {
        $kid = Profile::factory()->create();

        Auth::guard('profile')->login($kid);

        $html = Volt::test('kid.home')->html();

        // Just the bolt's own tag — the rest of the shell has a push toggle
        // that says "blocked", which contains the word being looked for.
        preg_match('/<i[^>]*data-powered="off"[^>]*>/s', $html, $bolt);

        $this->assertNotEmpty($bolt, 'The dim bolt is drawn.');

        foreach (['lock', 'Lock', 'earn it', 'can\'t', 'cannot'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $bolt[0]);
        }
    }
}
