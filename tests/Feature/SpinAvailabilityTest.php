<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Models\Spin;
use App\Services\ChoreService;
use App\Services\HouseholdClock;
use App\Services\SpinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use RuntimeException;
use Tests\TestCase;

/**
 * Cooldowns are household-wide, so a chore a sibling already claimed cannot
 * be earned again today. Landing a 3x boost on one would hand a kid a prize
 * that pays nothing — the wheel has to draw from what's actually winnable.
 */
class SpinAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function spins(): SpinService
    {
        return app(SpinService::class);
    }

    private function chores(): ChoreService
    {
        return app(ChoreService::class);
    }

    /** One quest-eligible chore absorbs the daily quest, which the wheel always excludes. */
    private function household(): Household
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['name' => 'The quest', 'quest_eligible' => true]);

        return $household;
    }

    public function test_a_chore_a_sibling_claimed_leaves_the_wheel(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();

        $taken = Chore::factory()->for($household)->create(['quest_eligible' => false, 'cadence' => 'daily']);
        $free = Chore::factory()->for($household)->create(['quest_eligible' => false, 'cadence' => 'daily']);

        $this->chores()->claim($sibling, $taken);

        $ids = $this->spins()->eligibleChoresFor($kid)->pluck('id');

        $this->assertContains($free->id, $ids);
        $this->assertNotContains($taken->id, $ids);
    }

    public function test_a_chore_the_kid_already_did_leaves_the_wheel(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();

        $done = Chore::factory()->for($household)->create(['quest_eligible' => false, 'cadence' => 'daily']);
        Chore::factory()->for($household)->create(['quest_eligible' => false, 'cadence' => 'daily']);

        $this->chores()->claim($kid, $done);

        // Their own pending claim is just as unwinnable as a sibling's.
        $this->assertNotContains($done->id, $this->spins()->eligibleChoresFor($kid)->pluck('id'));
    }

    public function test_an_unlimited_chore_stays_on_the_wheel(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();

        $shared = Chore::factory()->for($household)->create(['quest_eligible' => false, 'cadence' => 'unlimited']);

        $this->chores()->claim($sibling, $shared);

        // No cooldown, so it's still winnable no matter who did it.
        $this->assertContains($shared->id, $this->spins()->eligibleChoresFor($kid)->pluck('id'));
    }

    public function test_a_rejected_claim_puts_the_chore_back_on_the_wheel(): void
    {
        $household = $this->household();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();

        $chore = Chore::factory()->for($household)->create(['quest_eligible' => false, 'cadence' => 'daily']);

        $completion = $this->chores()->claim($sibling, $chore);
        $this->assertNotContains($chore->id, $this->spins()->eligibleChoresFor($kid)->pluck('id'));

        $this->chores()->sendBack($completion, $parent);

        $this->assertContains($chore->id, $this->spins()->eligibleChoresFor($kid)->pluck('id'));
    }

    public function test_the_spin_never_lands_on_a_claimed_chore(): void
    {
        $household = $this->household();
        $sibling = Profile::factory()->for($household)->create();

        $taken = Chore::factory()->for($household)->create(['quest_eligible' => false, 'cadence' => 'daily']);
        $free = Chore::factory()->for($household)->create(['quest_eligible' => false, 'cadence' => 'daily']);

        $this->chores()->claim($sibling, $taken);

        // The draw is random, so assert across enough kids that a leak would show.
        for ($i = 0; $i < 15; $i++) {
            $kid = Profile::factory()->for($household)->create();

            $this->assertSame($free->id, $this->spins()->spin($kid)->chore_id);
        }
    }

    public function test_the_chore_already_landed_on_stays_on_the_wheel(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();

        $chore = Chore::factory()->for($household)->create(['quest_eligible' => false, 'cadence' => 'daily']);
        Chore::factory()->for($household)->create(['quest_eligible' => false, 'cadence' => 'daily']);

        $spin = $this->spins()->spin($kid);

        // Claiming what the wheel landed on must not erase the result — the
        // wheel still has to render the segment it stopped on.
        $this->chores()->claim($kid, Chore::findOrFail($spin->chore_id));

        $this->assertContains($spin->chore_id, $this->spins()->eligibleChoresFor($kid)->pluck('id'));
    }

    public function test_the_boost_survives_the_chore_being_claimed(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();

        $chore = Chore::factory()->for($household)->create(['quest_eligible' => false, 'cadence' => 'daily']);
        // Dated off the household clock, not now()->toDateString(): the app
        // timezone is UTC but the household's day rolls at 4am Chicago, so a
        // plain UTC date puts this row on a different day than the service
        // looks up — and the test fails for a few hours every evening.
        Spin::create([
            'profile_id' => $kid->id,
            'spin_date' => HouseholdClock::for($household)->today()->toDateString(),
            'chore_id' => $chore->id,
            'multiplier' => 3,
        ]);

        $this->assertSame(3, $this->spins()->multiplierFor($kid, $chore));
    }

    public function test_spinning_with_nothing_available_throws(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();

        $only = Chore::factory()->for($household)->create(['quest_eligible' => false, 'cadence' => 'daily']);
        $this->chores()->claim($sibling, $only);

        $this->expectException(RuntimeException::class);

        $this->spins()->spin($kid);
    }

    public function test_the_wheel_page_does_not_blow_up_when_nothing_is_available(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();

        $only = Chore::factory()->for($household)->create(['quest_eligible' => false, 'cadence' => 'daily']);
        $this->chores()->claim($sibling, $only);

        Auth::guard('profile')->login($kid);

        // SpinService throws on an empty pool and the page never catches it,
        // so the guard has to sit in front of the call.
        Volt::test('kid.wheel')->call('spin')->assertOk();

        $this->assertFalse($this->spins()->hasSpunToday($kid));
    }

    public function test_a_chore_a_parent_barred_from_the_wheel_never_appears_on_it(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();

        $barred = Chore::factory()->for($household)->create([
            'name' => 'Put the groceries away',
            'quest_eligible' => false,
            'wheel_eligible' => false,
        ]);
        $free = Chore::factory()->for($household)->create(['quest_eligible' => false]);

        $ids = $this->spins()->eligibleChoresFor($kid)->pluck('id');

        $this->assertContains($free->id, $ids);
        $this->assertNotContains($barred->id, $ids);
    }

    public function test_barring_a_chore_from_the_wheel_still_leaves_it_claimable(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();

        // The two exclusions are about where a chore can be *offered*, not
        // whether it can be done — it stays on the board at its usual points.
        $barred = Chore::factory()->for($household)->create(['wheel_eligible' => false]);

        $this->assertSame('ready', $this->chores()->stateFor($kid, $barred));
        $this->chores()->claim($kid, $barred);

        $this->assertSame('pending', $this->chores()->stateFor($kid, $barred));
    }

    public function test_a_chore_barred_after_the_spin_landed_on_it_stays_on_the_wheel(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();

        Chore::factory()->for($household)->create(['quest_eligible' => false]);
        Chore::factory()->for($household)->create(['quest_eligible' => false]);

        $spin = $this->spins()->spin($kid);

        // mount() and spin() find the wheel's rotation by locating this chore's
        // index in the collection — dropping it points the wheel at segment 0.
        Chore::whereKey($spin->chore_id)->update(['wheel_eligible' => false]);

        $this->assertContains($spin->chore_id, $this->spins()->eligibleChoresFor($kid)->pluck('id'));
    }

    public function test_spinning_with_every_chore_barred_throws(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();

        Chore::factory()->for($household)->create(['quest_eligible' => false, 'wheel_eligible' => false]);

        $this->expectException(RuntimeException::class);

        $this->spins()->spin($kid);
    }
}
