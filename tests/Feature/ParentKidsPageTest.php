<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Models\Spin;
use App\Services\ChoreService;
use App\Services\HouseholdClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ParentKidsPageTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsParent(Household $household): Profile
    {
        $parent = Profile::factory()->parent()->for($household)->create();
        Auth::guard('profile')->login($parent);

        return $parent;
    }

    public function test_it_names_todays_mystery_chore(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['name' => 'Scrub the tub']);
        $this->actingAsParent($household);

        Volt::test('parent.kids')
            ->assertSee("Today's Mystery Chore", false)
            ->assertSee($chore->name)
            ->assertSee('UP FOR GRABS');
    }

    public function test_it_shows_who_claimed_the_mystery_chore(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $chore = Chore::factory()->for($household)->create(['name' => 'Scrub the tub']);
        $this->actingAsParent($household);

        app(ChoreService::class)->claim($kid, $chore);

        Volt::test('parent.kids')
            ->assertSee('FOUND BY NOVA')
            ->assertDontSee('UP FOR GRABS');
    }

    public function test_it_copes_with_no_eligible_mystery_chore(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create();
        // Unlimited-cadence chores are never mystery candidates, but are still
        // assignable as a quest — so the page has something to render.
        Chore::factory()->for($household)->create(['cadence' => 'unlimited']);
        $this->actingAsParent($household);

        Volt::test('parent.kids')
            ->assertSee('Nothing eligible today')
            ->assertSuccessful();
    }

    public function test_it_shows_the_chore_and_multiplier_a_kid_spun(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $spunChore = Chore::factory()->for($household)->create(['name' => 'Fold the laundry']);
        $this->actingAsParent($household);

        Spin::create([
            'profile_id' => $kid->id,
            'spin_date' => HouseholdClock::for($household)->today(),
            'chore_id' => $spunChore->id,
            'multiplier' => 3,
        ]);

        Volt::test('parent.kids')
            ->assertSee('Bonus Wheel')
            ->assertSee('Fold the laundry')
            ->assertSee('3x');
    }

    public function test_it_says_when_a_kid_has_not_spun_yet(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create();
        $this->actingAsParent($household);

        Volt::test('parent.kids')->assertSee("Hasn't spun today", false);
    }

    public function test_a_kid_cannot_reach_the_parent_console(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        Volt::test('parent.kids')->assertForbidden();
    }
}
