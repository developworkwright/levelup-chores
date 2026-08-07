<?php

namespace Tests\Feature;

use App\Enums\TicketKind;
use App\Models\BonusTicketEntry;
use App\Models\Chore;
use App\Models\DailyMystery;
use App\Models\Household;
use App\Models\Profile;
use App\Models\Spin;
use App\Services\ChoreService;
use App\Services\HouseholdClock;
use App\Services\TicketService;
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

    public function test_a_claimed_mystery_chore_is_shown_as_waiting_on_the_parent(): void
    {
        // Nobody has won it yet — the bonus is settled by the approval, so the
        // label has to say what's actually outstanding rather than call it.
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $chore = Chore::factory()->for($household)->create(['name' => 'Scrub the tub']);
        $this->actingAsParent($household);

        app(ChoreService::class)->claim($kid, $chore);

        Volt::test('parent.kids')
            ->assertSee('NOVA — NEEDS APPROVAL', escape: false)
            ->assertDontSee('FOUND BY NOVA')
            ->assertDontSee('UP FOR GRABS');
    }

    public function test_a_mystery_chore_signed_off_without_a_winner_says_so(): void
    {
        // claimantFor() counts an *approved* claim inside the cooldown too, so
        // reading it as "needs approval" told a parent to go and sign off work
        // they'd already signed off. This is where any mystery decided before
        // the bonus moved to approval lands.
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $settled = Chore::factory()->for($household)->create(['name' => 'Scrub the tub']);
        $parent = $this->actingAsParent($household);

        $chores = app(ChoreService::class);
        $chores->approve($chores->claim($kid, $settled), $parent);

        DailyMystery::where('household_id', $household->id)->firstOrFail()->forceFill([
            'chore_id' => $settled->id,
            'found_by_profile_id' => null,
            'found_at' => null,
        ])->save();

        Volt::test('parent.kids')
            ->assertSee('NOBODY WON IT')
            ->assertDontSee('NEEDS APPROVAL')
            ->assertDontSee('FOUND BY NOVA')
            ->assertDontSee('UP FOR GRABS');
    }

    public function test_it_shows_who_won_the_mystery_chore_once_it_is_approved(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $chore = Chore::factory()->for($household)->create(['name' => 'Scrub the tub']);
        $parent = $this->actingAsParent($household);

        $chores = app(ChoreService::class);
        $chores->approve($chores->claim($kid, $chore), $parent);

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

    public function test_it_shows_ticket_balances_and_levels(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create([
            'name' => 'Nova',
            'bonus_tickets' => 7,
            'xp' => Profile::XP_PER_LEVEL * 2,
        ]);
        Chore::factory()->for($household)->create();
        $this->actingAsParent($household);

        Volt::test('parent.kids')
            ->assertSee('TICKETS · LVL 3', false)
            ->assertSee('7');
    }

    public function test_a_parent_can_grant_and_deduct_tickets(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 3]);
        Chore::factory()->for($household)->create();
        $this->actingAsParent($household);

        Volt::test('parent.kids')
            ->call('adjustTickets', $kid->id, 5)
            ->call('adjustTickets', $kid->id, -1);

        $this->assertSame(7, $kid->refresh()->bonus_tickets);
        $this->assertSame(2, BonusTicketEntry::where('profile_id', $kid->id)->count());
    }

    public function test_a_parent_cannot_adjust_another_households_kid(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create();
        $foreign = Profile::factory()->for(Household::factory())->create(['bonus_tickets' => 0]);
        $this->actingAsParent($household);

        Volt::test('parent.kids')->call('adjustTickets', $foreign->id, 50);

        $this->assertSame(0, $foreign->refresh()->bonus_tickets);
    }

    public function test_ticket_activity_lives_on_the_activity_tab_not_here(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova', 'bonus_tickets' => 0]);
        Chore::factory()->for($household)->create();
        $this->actingAsParent($household);

        app(TicketService::class)->record($kid, TicketKind::LevelUp, 1, 'Reached level 4');

        Volt::test('parent.kids')->assertDontSee('Reached level 4');
    }

    public function test_a_parent_can_swap_the_mystery_chore(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(4)->create();
        $this->actingAsParent($household);

        $before = app(ChoreService::class)->mysteryChoreFor($household);

        Volt::test('parent.kids')->call('rerollMystery');

        $after = app(ChoreService::class)->mysteryChoreFor($household->refresh());

        $this->assertNotSame($before->id, $after->id);
    }

    public function test_the_mystery_cannot_be_swapped_once_someone_has_found_it(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(4)->create();
        $this->actingAsParent($household);

        $mystery = app(ChoreService::class)->mysteryChoreFor($household);
        app(ChoreService::class)->claim($kid, $mystery);

        Volt::test('parent.kids')
            ->call('rerollMystery')
            ->assertSee('already found it');

        // Moving the finish line after someone crossed it would rob the winner.
        $this->assertSame($mystery->id, app(ChoreService::class)->mysteryChoreFor($household)->id);
    }

    public function test_swapping_the_mystery_respects_the_fairness_rules(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create();
        $eligible = Chore::factory()->for($household)->create(['name' => 'Plain and eligible']);
        Chore::factory()->for($household)->create(['name' => 'Age gated', 'min_age' => 10]);
        Chore::factory()->for($household)->create(['name' => 'Unlimited', 'cadence' => 'unlimited']);
        $this->actingAsParent($household);

        $before = app(ChoreService::class)->mysteryChoreFor($household);

        Volt::test('parent.kids')->call('rerollMystery');

        // Only one other chore qualifies, so a swap either lands on it or is
        // refused — it must never reach for the gated or unlimited ones.
        $after = app(ChoreService::class)->mysteryChoreFor($household);
        $this->assertContains($after->id, [$before->id, $eligible->id]);
    }

    public function test_a_parent_can_swap_a_kids_quest_for_free(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 4]);
        Chore::factory()->for($household)->count(4)->create();
        $this->actingAsParent($household);

        $before = app(ChoreService::class)->questFor($kid)->chore_id;

        Volt::test('parent.kids')->call('rerollQuest', $kid->id);

        $after = app(ChoreService::class)->questFor($kid->refresh())->chore_id;

        $this->assertNotSame($before, $after);
        // Same logic the kid's perk uses, but the parent isn't charged for it.
        $this->assertSame(4, $kid->bonus_tickets);
    }

    public function test_swapping_an_already_cleared_quest_reports_back(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(3)->create();
        $this->actingAsParent($household);

        app(ChoreService::class)->claimQuest($kid);
        $before = app(ChoreService::class)->questFor($kid)->chore_id;

        Volt::test('parent.kids')
            ->call('rerollQuest', $kid->id)
            ->assertSee('Nothing to swap');

        $this->assertSame($before, app(ChoreService::class)->questFor($kid->refresh())->chore_id);
    }

    public function test_a_parent_cannot_swap_another_households_quest(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create();

        $otherHousehold = Household::factory()->create();
        $foreign = Profile::factory()->for($otherHousehold)->create();
        Chore::factory()->for($otherHousehold)->count(3)->create();

        $before = app(ChoreService::class)->questFor($foreign)->chore_id;

        $this->actingAsParent($household);
        Volt::test('parent.kids')->call('rerollQuest', $foreign->id);

        $this->assertSame($before, app(ChoreService::class)->questFor($foreign->refresh())->chore_id);
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
