<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientTicketsException;
use App\Exceptions\LuckyBlockEmptyException;
use App\Models\GratitudeEntry;
use App\Models\Household;
use App\Models\LuckyHit;
use App\Models\LuckyPrize;
use App\Models\Profile;
use App\Services\HouseholdClock;
use App\Services\LuckyBlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class LuckyBlockTest extends TestCase
{
    use RefreshDatabase;

    private function block(): LuckyBlockService
    {
        return app(LuckyBlockService::class);
    }

    private function loginKid(Household $household, array $attributes = []): Profile
    {
        $kid = Profile::factory()->for($household)->create($attributes);
        Auth::guard('profile')->login($kid);

        return $kid;
    }

    private function loginParent(Household $household): Profile
    {
        $parent = Profile::factory()->for($household)->parent()->create();
        Auth::guard('profile')->login($parent);

        return $parent;
    }

    /** Clears the seeded pool so a test can state its own. */
    private function emptyPool(Household $household): void
    {
        LuckyPrize::where('household_id', $household->id)->delete();
    }

    public function test_a_new_household_starts_with_the_opening_pool(): void
    {
        $household = Household::factory()->create();

        $this->assertCount(
            count(LuckyPrize::defaults()),
            LuckyPrize::where('household_id', $household->id)->get(),
        );
    }

    public function test_a_hit_costs_three_tickets_and_returns_a_prize_from_the_pool(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 5]);

        $hit = $this->block()->hit($kid);

        $this->assertSame(2, $kid->fresh()->bonus_tickets);
        $this->assertSame(LuckyBlockService::TICKET_COST, $hit->tickets_spent);
        $this->assertTrue(
            LuckyPrize::where('household_id', $household->id)->where('name', $hit->prize_name)->exists(),
        );
    }

    public function test_the_prize_name_is_stamped_at_the_draw_and_survives_a_rename(): void
    {
        // What a kid was told they won is not something a later edit gets to
        // rewrite — the approvals queue has to name the thing that was
        // promised.
        $household = Household::factory()->create();
        $this->emptyPool($household);
        $prize = LuckyPrize::factory()->for($household)->create(['name' => 'Pizza night']);

        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 3]);
        $hit = $this->block()->hit($kid);

        $prize->update(['name' => 'Something else entirely']);

        $this->assertSame('Pizza night', $hit->fresh()->prize_name);
    }

    public function test_it_refuses_a_hit_below_three_tickets_and_spends_nothing(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 2]);

        try {
            $this->block()->hit($kid);
            $this->fail('A hit was allowed on two tickets.');
        } catch (InsufficientTicketsException $e) {
            $this->assertSame(1, $e->shortfall);
        }

        $this->assertSame(2, $kid->fresh()->bonus_tickets);
        $this->assertSame(0, LuckyHit::count());
    }

    public function test_an_empty_pool_refuses_the_hit_without_taking_the_tickets(): void
    {
        $household = Household::factory()->create();
        $this->emptyPool($household);
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 9]);

        $this->expectException(LuckyBlockEmptyException::class);

        try {
            $this->block()->hit($kid);
        } finally {
            // The transaction has to have rolled the spend back with it.
            $this->assertSame(9, $kid->fresh()->bonus_tickets);
            $this->assertSame(0, LuckyHit::count());
        }
    }

    public function test_prizes_scoped_to_a_sibling_stay_out_of_this_kids_pool(): void
    {
        $household = Household::factory()->create();
        $this->emptyPool($household);

        $sam = Profile::factory()->for($household)->create(['name' => 'Sam']);
        $alex = Profile::factory()->for($household)->create(['name' => 'Alex']);

        LuckyPrize::factory()->for($household)->create(['name' => 'House wide']);
        LuckyPrize::factory()->forKid($sam)->create(['name' => 'Just for Sam']);
        LuckyPrize::factory()->forKid($alex)->create(['name' => 'Just for Alex']);

        $this->assertEqualsCanonicalizing(
            ['House wide', 'Just for Sam'],
            $this->block()->poolFor($sam)->pluck('name')->all(),
        );
    }

    public function test_a_switched_off_prize_is_never_drawn(): void
    {
        $household = Household::factory()->create();
        $this->emptyPool($household);

        LuckyPrize::factory()->for($household)->create(['name' => 'The only live one']);
        LuckyPrize::factory()->for($household)->inactive()->create(['name' => 'Parked']);

        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 3]);

        $this->assertSame(['The only live one'], $this->block()->poolFor($kid)->pluck('name')->all());
        $this->assertSame('The only live one', $this->block()->hit($kid)->prize_name);
    }

    public function test_a_won_prize_leaves_the_pool_until_it_is_handed_over(): void
    {
        $household = Household::factory()->create(['lucky_hold_won' => true]);
        $this->emptyPool($household);

        LuckyPrize::factory()->for($household)->create(['name' => 'First']);
        LuckyPrize::factory()->for($household)->create(['name' => 'Second']);

        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 6]);

        $first = $this->block()->hit($kid);

        $this->assertSame(
            [$first->prize_name === 'First' ? 'Second' : 'First'],
            $this->block()->drawableFor($kid)->pluck('name')->all(),
        );

        // Ticking it off puts it back.
        $this->block()->fulfill($first, $this->loginParent($household));

        $this->assertCount(2, $this->block()->drawableFor($kid));
    }

    public function test_holding_won_prizes_never_leaves_the_block_dead(): void
    {
        // A kid with one prize in their pool and one unredeemed win would
        // otherwise be holding tickets they cannot spend. A repeat beats a
        // dead button.
        $household = Household::factory()->create(['lucky_hold_won' => true]);
        $this->emptyPool($household);
        LuckyPrize::factory()->for($household)->create(['name' => 'The only one']);

        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 6]);

        $this->block()->hit($kid);

        $this->assertSame('The only one', $this->block()->hit($kid)->prize_name);
        $this->assertSame(0, $kid->fresh()->bonus_tickets);
    }

    public function test_the_hold_rule_can_be_switched_off(): void
    {
        $household = Household::factory()->create(['lucky_hold_won' => false]);
        $this->emptyPool($household);
        LuckyPrize::factory()->for($household)->create(['name' => 'First']);
        LuckyPrize::factory()->for($household)->create(['name' => 'Second']);

        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 3]);
        $this->block()->hit($kid);

        $this->assertCount(2, $this->block()->drawableFor($kid));
    }

    public function test_ticking_a_win_off_twice_does_nothing_the_second_time(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 3]);
        $parent = $this->loginParent($household);

        $hit = $this->block()->hit($kid);

        $this->assertTrue($this->block()->fulfill($hit, $parent));
        $this->assertFalse($this->block()->fulfill($hit->fresh(), $parent));
    }

    public function test_the_shop_shows_the_block_and_hitting_it_reveals_a_prize(): void
    {
        $household = Household::factory()->create();
        $this->emptyPool($household);
        LuckyPrize::factory()->for($household)->create(['name' => 'Pick the film']);

        $this->loginKid($household, ['bonus_tickets' => 3]);

        Volt::test('kid.loot')
            ->assertSee('Lucky Block')
            ->assertSee('Hit it')
            ->assertSee('Pick the film')
            ->call('hitLuckyBlock')
            ->assertSee('You got')
            ->assertSee('Sent to a grown-up to tick off.')
            ->call('dismissLuckyBlock')
            ->assertDontSee('You got')
            // The three tickets went with the hit, so the block they come back
            // to is the one they can't afford.
            ->assertSee('Need 3 tickets');
    }

    public function test_the_shop_offers_no_button_below_three_tickets(): void
    {
        $household = Household::factory()->create();
        $this->loginKid($household, ['bonus_tickets' => 2]);

        Volt::test('kid.loot')
            ->assertSee('Need 3 tickets')
            ->assertDontSee('Hit it');
    }

    public function test_the_shop_hides_the_block_entirely_when_the_pool_is_empty(): void
    {
        $household = Household::factory()->create();
        $this->emptyPool($household);
        $this->loginKid($household, ['bonus_tickets' => 9]);

        Volt::test('kid.loot')
            ->assertDontSee('Lucky Block')
            ->assertDontSee('Hit it');
    }

    public function test_the_journal_shortcut_only_shows_when_today_is_unwritten(): void
    {
        $household = Household::factory()->create();
        $kid = $this->loginKid($household, ['bonus_tickets' => 3]);

        Volt::test('kid.loot')->assertSee('Journal &middot; not done today', false);

        GratitudeEntry::create([
            'household_id' => $household->id,
            'profile_id' => $kid->id,
            'entry_date' => HouseholdClock::for($household)->today(),
            'items' => ['one', 'two', 'three'],
        ]);

        Volt::test('kid.loot')
            ->assertSee('Journal &middot; done today', false)
            ->assertDontSee('Journal &middot; not done today', false);
    }

    public function test_home_points_at_the_block_only_from_two_tickets_up(): void
    {
        $household = Household::factory()->create();

        $this->loginKid($household, ['bonus_tickets' => 1]);
        Volt::test('kid.home')->assertDontSee('Lucky Block');

        Auth::guard('profile')->logout();
        $this->loginKid($household, ['bonus_tickets' => 2]);
        Volt::test('kid.home')->assertSee('One more ticket for a Lucky Block');

        Auth::guard('profile')->logout();
        $this->loginKid($household, ['bonus_tickets' => 3]);
        Volt::test('kid.home')->assertSee("You've got enough for a Lucky Block", false);
    }

    public function test_home_says_nothing_when_the_pool_is_empty_however_many_tickets(): void
    {
        $household = Household::factory()->create();
        $this->emptyPool($household);
        $this->loginKid($household, ['bonus_tickets' => 12]);

        Volt::test('kid.home')->assertDontSee('Lucky Block');
    }

    public function test_a_win_lands_in_the_approvals_queue_and_can_be_ticked_off(): void
    {
        $household = Household::factory()->create();
        $this->emptyPool($household);
        LuckyPrize::factory()->for($household)->create(['name' => 'Front seat']);

        $kid = Profile::factory()->for($household)->create(['name' => 'Sam', 'bonus_tickets' => 3]);
        $hit = $this->block()->hit($kid);

        $this->loginParent($household);

        Volt::test('parent.approvals')
            ->assertSee('Lucky Block Wins')
            ->assertSee('Front seat')
            ->call('tickOffLucky', $hit->id)
            ->assertDontSee('Lucky Block Wins');

        $this->assertNotNull($hit->fresh()->fulfilled_at);
    }

    public function test_the_parent_screen_adds_scopes_and_switches_prizes_off(): void
    {
        $household = Household::factory()->create();
        $this->emptyPool($household);
        $sam = Profile::factory()->for($household)->create(['name' => 'Sam']);
        $this->loginParent($household);

        $page = Volt::test('parent.lucky')
            ->set('newPrizeName', 'Dad does your Saturday job')
            ->set('newPrizeScope', (string) $sam->id)
            ->call('addPrize');

        $prize = LuckyPrize::where('household_id', $household->id)->firstOrFail();

        $this->assertSame('Dad does your Saturday job', $prize->name);
        $this->assertSame($sam->id, $prize->profile_id);
        $this->assertTrue($prize->active);

        $page->call('togglePrize', $prize->id);

        $this->assertFalse($prize->fresh()->active);
    }

    public function test_the_parent_screen_warns_when_the_pool_is_thin(): void
    {
        $household = Household::factory()->create();
        $this->emptyPool($household);
        LuckyPrize::factory()->count(3)->for($household)->create();
        $this->loginParent($household);

        Volt::test('parent.lucky')
            ->assertSee('Thin pool')
            ->assertSee('3 active');
    }

    public function test_a_full_pool_draws_no_warning(): void
    {
        $household = Household::factory()->create();
        $this->loginParent($household);

        Volt::test('parent.lucky')
            ->assertSee('10 active')
            ->assertDontSee('Thin pool');
    }

    public function test_the_hold_rule_toggles_from_the_parent_screen(): void
    {
        $household = Household::factory()->create();
        $this->loginParent($household);

        // Off out of the box — the block rolls against the whole pool until a
        // household decides repeats are bothering them.
        $this->assertFalse((bool) $household->fresh()->lucky_hold_won);

        Volt::test('parent.lucky')->call('toggleHoldWon');

        $this->assertTrue((bool) $household->fresh()->lucky_hold_won);
    }

    public function test_reordering_moves_a_prize_without_touching_the_odds(): void
    {
        $household = Household::factory()->create();
        $this->emptyPool($household);
        $first = LuckyPrize::factory()->for($household)->create(['name' => 'First', 'position' => 0]);
        $second = LuckyPrize::factory()->for($household)->create(['name' => 'Second', 'position' => 1]);

        $kid = Profile::factory()->for($household)->create();
        $this->loginParent($household);

        Volt::test('parent.lucky')->call('movePrize', $second->id, -1);

        $this->assertSame(
            ['Second', 'First'],
            $this->block()->poolFor($kid)->pluck('name')->all(),
        );

        // Both are still equally likely — order is presentation only.
        $this->assertCount(2, $this->block()->drawableFor($kid));
    }

    public function test_a_parent_cannot_touch_another_households_pool(): void
    {
        $mine = Household::factory()->create();
        $theirs = Household::factory()->create();
        $theirPrize = LuckyPrize::factory()->for($theirs)->create(['name' => 'Not yours']);

        $this->loginParent($mine);

        Volt::test('parent.lucky')->call('removePrize', $theirPrize->id);

        $this->assertDatabaseHas('lucky_prizes', ['id' => $theirPrize->id]);
    }

    public function test_a_kid_cannot_open_the_parent_screen(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();

        $this->actingAs($kid, 'profile')
            ->get(route('parent.lucky'))
            ->assertForbidden();
    }
}
