<?php

namespace Tests\Feature;

use App\Enums\PerkEffect;
use App\Enums\TicketKind;
use App\Models\BonusTicketEntry;
use App\Models\Chore;
use App\Models\DailyChest;
use App\Models\DailyMystery;
use App\Models\Household;
use App\Models\OwnedPerk;
use App\Models\Profile;
use App\Models\Spin;
use App\Services\ChestService;
use App\Services\ChoreService;
use App\Services\HouseholdClock;
use App\Services\PerkInventoryService;
use App\Services\SpinService;
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

    /**
     * Resetting from here is a parent undoing a spin, not a kid re-rolling
     * one, so a ticket-bought OP charge goes back in the pocket — and the card
     * says so before it is pressed.
     */
    public function test_resetting_an_op_spin_hands_the_charge_back(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova']);
        Chore::factory()->for($household)->count(6)->create();
        $this->actingAsParent($household);

        $spins = app(SpinService::class);
        $spins->charge($kid);
        $spins->spin($kid->refresh());

        Volt::test('parent.kids')
            ->assertSee('OP spin', escape: false)
            ->call('resetSpin', $kid->id)
            ->assertSee("Hasn't spun today", escape: false)
            ->assertDontSee('OP spin', escape: false);

        $this->assertTrue($spins->isCharged($kid->refresh()));
    }

    public function test_resetting_a_plain_spin_charges_nothing(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova']);
        Chore::factory()->for($household)->count(6)->create();
        $this->actingAsParent($household);

        app(SpinService::class)->spin($kid);

        Volt::test('parent.kids')
            ->assertDontSee('OP spin', escape: false)
            ->call('resetSpin', $kid->id)
            ->assertSee("Hasn't spun today", escape: false);

        $this->assertFalse(app(SpinService::class)->isCharged($kid->refresh()));
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

    /**
     * The card on each kid used to report the daily quest: which card was
     * dealt, whether the chest was open, and a button to re-deal the hand. All
     * of it went with the quest. What a parent needs answering is the same
     * question underneath it — has this kid done anything today — so the card
     * reports the day.
     */
    public function test_the_day_card_says_nothing_is_in_yet(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create();
        $this->actingAsParent($household);

        Volt::test('parent.kids')
            ->assertSee('Nothing handed in')
            ->assertSee('0 signed off');
    }

    public function test_the_day_card_names_the_last_chore_and_what_it_waits_on(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['name' => 'Sweep the porch']);
        $this->actingAsParent($household);

        app(ChoreService::class)->claim($kid, $chore);

        Volt::test('parent.kids')
            ->assertSee('Sweep the porch')
            ->assertSee('1 waiting on you')
            ->assertDontSee('Nothing handed in');
    }

    public function test_the_day_card_counts_what_has_been_signed_off(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create();
        $parent = $this->actingAsParent($household);

        $chores = app(ChoreService::class);
        $chores->approve($chores->claim($kid, $chore), $parent);

        Volt::test('parent.kids')
            ->assertSee('All signed off')
            ->assertSee('1 signed off');
    }

    public function test_an_unopened_daily_chest_says_so(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create();
        $this->actingAsParent($household);

        Volt::test('parent.kids')
            ->assertSee('Daily Chest')
            ->assertSee('Not opened today')
            ->assertSee('WAITING');
    }

    public function test_an_opened_daily_chest_names_what_it_paid(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create();
        $this->actingAsParent($household);

        // Forced rather than rolled: the loot table is weighted, and a test
        // that reruns the roll until it likes the answer is a slow test.
        $chest = DailyChest::create([
            'profile_id' => $kid->id,
            'chest_date' => HouseholdClock::for($household)->today(),
            'reward_kind' => ChestService::KIND_POINTS,
            'reward_amount' => 150,
            'quest_was_done' => true,
        ]);

        Volt::test('parent.kids')
            ->assertSee(app(ChestService::class)->describe($chest))
            ->assertSee('a chore was done first')
            ->assertSee('CLAIMED')
            ->assertDontSee('Not opened today');
    }

    public function test_a_chest_opened_on_a_worse_roll_says_nothing_was_done(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create();
        $this->actingAsParent($household);

        DailyChest::create([
            'profile_id' => $kid->id,
            'chest_date' => HouseholdClock::for($household)->today(),
            'reward_kind' => ChestService::KIND_TICKETS,
            'reward_amount' => 1,
            'quest_was_done' => false,
        ]);

        Volt::test('parent.kids')
            ->assertSee('1 ticket')
            ->assertSee('nothing done yet');
    }

    public function test_yesterdays_chest_does_not_count_as_todays(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create();
        $this->actingAsParent($household);

        DailyChest::create([
            'profile_id' => $kid->id,
            'chest_date' => HouseholdClock::for($household)->today()->subDay(),
            'reward_kind' => ChestService::KIND_POINTS,
            'reward_amount' => 50,
        ]);

        Volt::test('parent.kids')->assertSee('Not opened today');
    }

    public function test_a_parent_can_hand_a_kid_a_quest_charm(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova', 'bonus_tickets' => 0]);
        Chore::factory()->for($household)->count(3)->create();
        $this->actingAsParent($household);

        Volt::test('parent.kids')
            ->call('giveQuestCharm', $kid->id)
            ->assertSee('Nova is holding 1 charm');

        $perk = OwnedPerk::where('profile_id', $kid->id)->sole();

        $this->assertSame(PerkEffect::QuestCharm, $perk->effect);
        $this->assertSame(OwnedPerk::SOURCE_GIFT, $perk->source);
        // A gift: the kid pays nothing for it.
        $this->assertSame(0, $kid->refresh()->bonus_tickets);
    }

    public function test_the_day_card_counts_charms_still_in_the_pocket(): void
    {
        // What a parent needs before handing another one over.
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(3)->create();
        $this->actingAsParent($household);

        $perks = app(PerkInventoryService::class);
        $perks->grant($kid, PerkEffect::QuestCharm, OwnedPerk::SOURCE_GIFT);
        $perks->grant($kid, PerkEffect::QuestCharm, OwnedPerk::SOURCE_GIFT);

        Volt::test('parent.kids')->assertSee('2 charms in pocket');
    }

    public function test_a_parent_cannot_hand_a_charm_to_another_households_kid(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create();

        $otherHousehold = Household::factory()->create();
        $foreign = Profile::factory()->for($otherHousehold)->create();

        $this->actingAsParent($household);
        Volt::test('parent.kids')->call('giveQuestCharm', $foreign->id);

        $this->assertSame(0, OwnedPerk::where('profile_id', $foreign->id)->count());
    }

    public function test_a_kid_cannot_reach_the_parent_console(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        Volt::test('parent.kids')->assertForbidden();
    }

    /**
     * The house clock, which had no control in the console at all until a
     * deadline set for 8:55 closed at 9:55.
     *
     * The column defaults to America/Chicago and could only be changed from an
     * artisan command, so a house in another zone had every wall-clock time in
     * the app quietly shifted — and the app hid it, because it redisplays a
     * stored time through the same zone it stored it with.
     */
    public function test_a_parent_can_set_the_household_timezone(): void
    {
        $household = Household::factory()->create(['timezone' => 'America/Chicago']);
        $this->actingAsParent($household);

        Volt::test('parent.kids')
            ->set('timezone', 'America/New_York')
            ->call('saveTimezone')
            ->assertSee('the house clock now reads');

        $this->assertSame('America/New_York', $household->fresh()->timezone);
    }

    public function test_a_timezone_the_app_does_not_know_is_refused(): void
    {
        $household = Household::factory()->create(['timezone' => 'America/Chicago']);
        $this->actingAsParent($household);

        // It arrives from a browser, and a bad value would put every page in
        // the console into a DateTime exception rather than merely being wrong.
        Volt::test('parent.kids')
            ->set('timezone', 'Middle/Earth')
            ->call('saveTimezone')
            ->assertSee('not a timezone I know')
            // Snapped back, so the control never sits showing a zone the house
            // is not actually on.
            ->assertSet('timezone', 'America/Chicago');

        $this->assertSame('America/Chicago', $household->fresh()->timezone);
    }

    public function test_the_page_says_what_the_house_clock_reads_right_now(): void
    {
        // The one number a parent can check against the clock on their own
        // wall — which is the entire reason this control is worth a card.
        $household = Household::factory()->create(['timezone' => 'America/New_York']);
        $this->actingAsParent($household);

        Volt::test('parent.kids')
            ->assertSee('The house clock')
            ->assertSee(HouseholdClock::for($household)->now()->format('g:i A T'));
    }
}
