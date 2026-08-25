<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Models\SiblingOffer;
use App\Models\StoreItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class KidLootShopTest extends TestCase
{
    use RefreshDatabase;

    private function loginKid(Household $household, array $attributes = []): Profile
    {
        $kid = Profile::factory()->for($household)->create($attributes);
        Auth::guard('profile')->login($kid);

        return $kid;
    }

    public function test_the_shop_stays_out_of_the_sibling_trade_business(): void
    {
        // Trades live on their own tab: a catalogue of things to buy from a
        // parent and a negotiation with a sibling are unrelated errands, and
        // the trade notification deep-links to /kid/trades, not here.
        $household = Household::factory()->create();
        $alex = Profile::factory()->for($household)->create(['name' => 'Alex', 'points' => 400]);
        $sam = $this->loginKid($household, ['name' => 'Sam', 'points' => 0]);

        SiblingOffer::factory()->create([
            'household_id' => $household->id,
            'from_profile_id' => $alex->id,
            'to_profile_id' => $sam->id,
            'description' => 'Play a game for 30 minutes',
        ]);

        Volt::test('kid.loot')
            ->assertOk()
            ->assertDontSee('Trades for you')
            ->assertDontSee('Make a trade with a sibling')
            ->assertDontSee('Play a game for 30 minutes');
    }

    public function test_it_groups_the_catalog_into_price_bands(): void
    {
        $household = Household::factory()->create();
        StoreItem::factory()->for($household)->create(['name' => 'Cheap Thrill', 'cost' => 100]);
        StoreItem::factory()->for($household)->create(['name' => 'Middle Road', 'cost' => 200]);
        StoreItem::factory()->for($household)->create(['name' => 'Grand Prize', 'cost' => 1500]);

        $this->loginKid($household, ['points' => 0]);

        Volt::test('kid.loot')
            ->call('setView', 'price')
            ->assertSee('Treat yourself')
            ->assertSee('Under 150 pts')
            ->assertSee('Worth a few days')
            ->assertSee('150 – 999 pts')
            ->assertSee('Big ticket')
            ->assertSee('1000 pts and up');
    }

    public function test_a_band_with_nothing_in_it_is_not_rendered(): void
    {
        $household = Household::factory()->create();
        StoreItem::factory()->for($household)->create(['cost' => 100]);

        $this->loginKid($household, ['points' => 0]);

        // An empty shelf is worse than no shelf — it reads as a loading bug.
        Volt::test('kid.loot')
            ->call('setView', 'price')
            ->assertSee('Treat yourself')
            ->assertDontSee('Worth a few days')
            ->assertDontSee('Big ticket');
    }

    public function test_the_band_boundaries_are_inclusive_below_and_exclusive_above(): void
    {
        $household = Household::factory()->create();
        StoreItem::factory()->for($household)->create(['cost' => 149]);
        StoreItem::factory()->for($household)->create(['cost' => 150]);
        StoreItem::factory()->for($household)->create(['cost' => 999]);
        StoreItem::factory()->for($household)->create(['cost' => 1000]);

        $this->loginKid($household, ['points' => 0]);

        // 150 and 1000 are the shelf floors, so all three bands exist and the
        // edges land above rather than below.
        Volt::test('kid.loot')
            ->call('setView', 'price')
            ->assertSee('Treat yourself')
            ->assertSee('Worth a few days')
            ->assertSee('Big ticket');
    }

    public function test_the_shelf_headings_follow_the_thresholds(): void
    {
        // The headings are derived, so this guards the derivation rather than
        // the specific numbers — a moved threshold should relabel itself.
        $household = Household::factory()->create();
        StoreItem::factory()->for($household)->create(['cost' => 999]);

        $this->loginKid($household, ['points' => 0]);

        Volt::test('kid.loot')
            ->call('setView', 'price')
            ->assertSee('150 – 999 pts')
            ->assertDontSee('150 – 499 pts')
            ->assertDontSee('500 pts and up');
    }

    public function test_saving_for_an_item_pins_it_as_the_goal(): void
    {
        $household = Household::factory()->create();
        $item = StoreItem::factory()->for($household)->create(['name' => 'Grand Prize', 'cost' => 900]);
        $kid = $this->loginKid($household, ['points' => 100]);

        Volt::test('kid.loot')
            ->assertDontSee('Saving up for')
            ->call('saveFor', $item->id)
            ->assertSee('Saving up for')
            ->assertSee('Saving for this');

        $this->assertSame($item->id, $kid->refresh()->saving_for_store_item_id);
    }

    public function test_a_kid_cannot_save_for_another_households_reward(): void
    {
        $household = Household::factory()->create();
        $theirs = StoreItem::factory()->for(Household::factory()->create())->create();
        $kid = $this->loginKid($household, ['points' => 100]);

        Volt::test('kid.loot')->call('saveFor', $theirs->id);

        $this->assertNull($kid->refresh()->saving_for_store_item_id);
    }

    public function test_the_goal_card_prices_the_gap_in_chores(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->count(2)->create(['points' => 100]);
        $item = StoreItem::factory()->for($household)->create(['cost' => 900]);

        $this->loginKid($household, ['points' => 100, 'saving_for_store_item_id' => $item->id]);

        // 800 short at an average of 100 a chore.
        Volt::test('kid.loot')->assertSee('8 more chores');
    }

    public function test_one_chore_to_go_reads_in_the_singular(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['points' => 100]);
        $item = StoreItem::factory()->for($household)->create(['cost' => 200]);

        $this->loginKid($household, ['points' => 100, 'saving_for_store_item_id' => $item->id]);

        Volt::test('kid.loot')
            ->assertSee('1 more chore')
            ->assertDontSee('1 more chores');
    }

    public function test_the_estimate_assumes_at_least_25_points_a_chore(): void
    {
        // A household of near-worthless chores would otherwise turn a modest
        // goal into a demoralising four-figure estimate.
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['points' => 1]);
        $item = StoreItem::factory()->for($household)->create(['cost' => 500]);

        $this->loginKid($household, ['points' => 0, 'saving_for_store_item_id' => $item->id]);

        Volt::test('kid.loot')
            ->assertSee('20 more chores')
            ->assertDontSee('500 more chores');
    }

    public function test_an_affordable_goal_offers_the_cash_out_instead_of_an_estimate(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['points' => 100]);
        $item = StoreItem::factory()->for($household)->create(['cost' => 150]);

        $this->loginKid($household, ['points' => 400, 'saving_for_store_item_id' => $item->id]);

        Volt::test('kid.loot')
            ->assertSee('Cash it out')
            ->assertDontSee('more chores');
    }

    public function test_an_over_funded_goal_clamps_the_balance_it_displays(): void
    {
        $household = Household::factory()->create();
        $item = StoreItem::factory()->for($household)->create(['cost' => 150]);

        $this->loginKid($household, ['points' => 1240, 'saving_for_store_item_id' => $item->id]);

        // "1240 / 150" reads as a bug rather than a win.
        Volt::test('kid.loot')
            ->assertSee('150 / 150 PTS')
            ->assertDontSee('1240 / 150');
    }

    public function test_the_picker_only_opens_when_asked(): void
    {
        $household = Household::factory()->create();
        $item = StoreItem::factory()->for($household)->create(['cost' => 900]);

        $this->loginKid($household, ['points' => 0, 'saving_for_store_item_id' => $item->id]);

        Volt::test('kid.loot')
            ->assertDontSee('Pick something to save for')
            ->call('togglePicker')
            ->assertSee('Pick something to save for')
            ->call('togglePicker')
            ->assertDontSee('Pick something to save for');
    }

    public function test_picking_from_the_picker_closes_it(): void
    {
        $household = Household::factory()->create();
        $current = StoreItem::factory()->for($household)->create(['cost' => 900]);
        $next = StoreItem::factory()->for($household)->create(['name' => 'Something Else', 'cost' => 700]);

        $kid = $this->loginKid($household, ['points' => 0, 'saving_for_store_item_id' => $current->id]);

        Volt::test('kid.loot')
            ->call('togglePicker')
            ->call('saveFor', $next->id)
            ->assertDontSee('Pick something to save for');

        $this->assertSame($next->id, $kid->refresh()->saving_for_store_item_id);
    }

    public function test_searching_narrows_the_shelves_to_matching_rewards(): void
    {
        $household = Household::factory()->create();
        StoreItem::factory()->for($household)->create(['name' => 'Lego set', 'cost' => 1500]);
        StoreItem::factory()->for($household)->create(['name' => 'Ice cream run', 'cost' => 100]);

        $this->loginKid($household, ['points' => 0]);

        Volt::test('kid.loot')
            ->call('setView', 'price')
            ->set('search', 'lego')
            ->assertSee('Lego set')
            ->assertDontSee('Ice cream run')
            // The shelf its only match sat on goes with it.
            ->assertSee('Big ticket')
            ->assertDontSee('Treat yourself');
    }

    public function test_the_search_reaches_into_descriptions(): void
    {
        // The name is often a nickname; the description is where the thing
        // itself is written down.
        $household = Household::factory()->create();
        StoreItem::factory()->for($household)->create([
            'name' => 'Sweet Friday',
            'description' => 'A trip out for ice cream.',
            'cost' => 300,
        ]);

        $this->loginKid($household, ['points' => 0]);

        Volt::test('kid.loot')
            ->set('search', 'ice cream')
            ->assertSee('Sweet Friday');
    }

    public function test_a_search_that_matches_nothing_says_so(): void
    {
        $household = Household::factory()->create();
        StoreItem::factory()->for($household)->create(['name' => 'Lego set', 'cost' => 1500]);

        $this->loginKid($household, ['points' => 0]);

        Volt::test('kid.loot')
            ->set('search', 'trampoline')
            ->assertSee('Nothing in the shop matches')
            ->assertDontSee('Lego set');
    }

    public function test_clearing_the_search_puts_the_whole_catalog_back(): void
    {
        $household = Household::factory()->create();
        StoreItem::factory()->for($household)->create(['name' => 'Lego set', 'cost' => 1500]);
        StoreItem::factory()->for($household)->create(['name' => 'Ice cream run', 'cost' => 100]);

        $this->loginKid($household, ['points' => 0]);

        Volt::test('kid.loot')
            ->set('search', 'lego')
            ->assertDontSee('Ice cream run')
            ->call('clearSearch')
            ->assertSee('Ice cream run')
            ->assertSee('Lego set');
    }

    public function test_an_active_search_does_not_shorten_the_goal_picker(): void
    {
        // The picker answers "what am I aiming at next?" — a search typed to
        // find something else has no business hiding the rest of the catalog.
        $household = Household::factory()->create();
        $current = StoreItem::factory()->for($household)->create(['name' => 'Lego set', 'cost' => 1500]);
        StoreItem::factory()->for($household)->create(['name' => 'Ice cream run', 'cost' => 100]);

        $this->loginKid($household, ['points' => 0, 'saving_for_store_item_id' => $current->id]);

        Volt::test('kid.loot')
            ->set('search', 'lego')
            ->call('togglePicker')
            ->assertSee('Ice cream run');
    }

    public function test_the_search_stays_inside_the_household(): void
    {
        $household = Household::factory()->create();
        StoreItem::factory()->for(Household::factory()->create())->create(['name' => 'Lego set', 'cost' => 1500]);

        $this->loginKid($household, ['points' => 0]);

        Volt::test('kid.loot')
            ->set('search', 'lego')
            ->assertDontSee('Lego set')
            ->assertSee('Nothing in the shop matches');
    }

    public function test_retiring_the_reward_a_kid_was_saving_for_just_clears_the_goal(): void
    {
        $household = Household::factory()->create();
        $item = StoreItem::factory()->for($household)->create(['cost' => 900]);
        $kid = $this->loginKid($household, ['points' => 0, 'saving_for_store_item_id' => $item->id]);

        // nullOnDelete, not cascade — a parent tidying the shop must not take
        // the kid's profile with it.
        $item->delete();

        $this->assertTrue($kid->exists());
        $this->assertNull($kid->refresh()->saving_for_store_item_id);

        Volt::test('kid.loot')->assertOk()->assertDontSee('Saving up for');
    }
}
