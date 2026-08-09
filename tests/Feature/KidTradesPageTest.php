<?php

namespace Tests\Feature;

use App\Enums\SiblingOfferStatus;
use App\Enums\TradeAsset;
use App\Models\Bounty;
use App\Models\Household;
use App\Models\Profile;
use App\Models\SiblingOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class KidTradesPageTest extends TestCase
{
    use RefreshDatabase;

    private function loginKid(Household $household, array $attributes = []): Profile
    {
        $kid = Profile::factory()->for($household)->create($attributes);
        Auth::guard('profile')->login($kid);

        return $kid;
    }

    public function test_the_trade_form_lists_siblings_but_not_the_viewer_or_a_parent(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create(['name' => 'Sam']);
        Profile::factory()->parent()->for($household)->create(['name' => 'Mum']);
        $this->loginKid($household, ['name' => 'Alex', 'points' => 500]);

        Volt::test('kid.trades')
            ->call('choose', 'swap')
            // The sibling buttons are the submit, so the verb is on them.
            ->assertSee('Send to Sam')
            ->assertDontSee('Mum');
    }

    public function test_an_only_child_is_told_why_the_tab_is_empty(): void
    {
        $household = Household::factory()->create();
        $this->loginKid($household, ['points' => 500]);

        Volt::test('kid.trades')->assertDontSee('Make a trade with a sibling');
    }

    public function test_sending_a_trade_holds_the_points_and_closes_the_form(): void
    {
        $household = Household::factory()->create();
        $sam = Profile::factory()->for($household)->create(['name' => 'Sam']);
        $alex = $this->loginKid($household, ['name' => 'Alex', 'points' => 500]);

        Volt::test('kid.trades')
            ->call('choose', 'swap')
            ->set('giveAmount', '100')
            ->set('getAmount', '2')
            ->call('sendSwap', $sam->id)
            ->assertSee('Sent to Sam')
            ->assertDontSee('no other button needed')
            ->assertSee('Yours, still waiting');

        $this->assertSame(400, $alex->refresh()->points);
    }

    public function test_a_trade_you_cannot_afford_is_refused_with_the_shortfall(): void
    {
        $household = Household::factory()->create();
        $sam = Profile::factory()->for($household)->create(['name' => 'Sam']);
        $alex = $this->loginKid($household, ['points' => 60]);

        Volt::test('kid.trades')
            ->call('choose', 'swap')
            ->set('giveAmount', '100')
            ->set('getAmount', '2')
            ->call('sendSwap', $sam->id)
            ->assertSee('need 40 more');

        $this->assertSame(60, $alex->refresh()->points);
        $this->assertSame(0, SiblingOffer::count());
    }

    public function test_a_job_with_nothing_typed_in_it_is_refused(): void
    {
        $household = Household::factory()->create();
        $sam = Profile::factory()->for($household)->create(['name' => 'Sam']);
        $this->loginKid($household, ['points' => 500]);

        // A swap needs no words — the two amounts say everything. A job is the
        // opposite: the typed line *is* the deal, so it is the one thing that
        // cannot be left blank.
        Volt::test('kid.trades')
            ->call('choose', 'wanted')
            ->set('jobDescription', '   ')
            ->set('jobAmount', 100)
            ->call('postJob', $sam->id)
            ->assertSee('Say what the job is first');

        $this->assertSame(0, Bounty::count());
    }

    public function test_clearing_the_amount_box_does_not_blow_the_page_up(): void
    {
        $household = Household::factory()->create();
        $sam = Profile::factory()->for($household)->create(['name' => 'Sam']);
        $this->loginKid($household, ['points' => 500]);

        // A number input the kid empties posts back "", which a typed int
        // property takes as a type error rather than as an empty box. Both
        // amounts are strings for that reason, and the refusal has to be the
        // service's range message rather than a 500.
        Volt::test('kid.trades')
            ->call('choose', 'wanted')
            ->set('jobDescription', 'Make my bed')
            ->set('jobAmount', '')
            ->call('postJob', $sam->id)
            ->assertOk()
            ->assertSee('Price it between');

        $this->assertSame(0, Bounty::count());
    }

    public function test_a_nonsense_deal_kind_is_refused_rather_than_thrown(): void
    {
        $household = Household::factory()->create();
        $sam = Profile::factory()->for($household)->create(['name' => 'Sam']);
        $this->loginKid($household, ['points' => 500]);

        // `mode` is a public property, so it arrives as whatever was sent.
        // Enum::from() on a bad value throws a ValueError, which is an Error
        // rather than an Exception and would sail past the page's handler.
        Volt::test('kid.trades')
            ->set('mode', 'nonsense')
            ->set('jobDescription', 'Make my bed')
            ->call('postJob', $sam->id)
            ->assertOk()
            ->assertSee('Pick what kind of job it is first');

        $this->assertSame(0, Bounty::count());
    }

    public function test_a_swap_cannot_be_posted_as_a_job(): void
    {
        $household = Household::factory()->create();
        $sam = Profile::factory()->for($household)->create(['name' => 'Sam']);
        $this->loginKid($household, ['points' => 500]);

        // "swap" is a valid mode for the form but not a kind of job, and the
        // two share one set of buttons.
        Volt::test('kid.trades')
            ->set('mode', 'swap')
            ->set('jobDescription', 'Make my bed')
            ->call('postJob', $sam->id)
            ->assertOk()
            ->assertSee('Pick what kind of job it is first');

        $this->assertSame(0, Bounty::count());
    }

    public function test_nothing_incoming_means_no_trades_section(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create();
        $this->loginKid($household, ['points' => 100]);

        // An empty shelf reads as a loading bug, same as the price bands.
        Volt::test('kid.trades')->assertDontSee('Waiting on you');
    }

    public function test_an_incoming_trade_renders_and_accepting_it_moves_the_points(): void
    {
        $household = Household::factory()->create();
        $alex = Profile::factory()->for($household)->create(['name' => 'Alex', 'points' => 400]);
        // A ticket to swap back with: a trade is two currencies now, so the
        // recipient always has a side of their own to hand over.
        $sam = $this->loginKid($household, ['name' => 'Sam', 'points' => 0, 'bonus_tickets' => 2]);

        $offer = SiblingOffer::factory()->create([
            'household_id' => $household->id,
            'from_profile_id' => $alex->id,
            'to_profile_id' => $sam->id,
            'give_amount' => 100,
        ]);

        Volt::test('kid.trades')
            ->assertSee('Waiting on you')
            ->assertSee('100 pts')
            ->assertSee('1 ticket')
            ->call('acceptOffer', $offer->id)
            ->assertDontSee('Waiting on you');

        $this->assertSame(100, $sam->refresh()->points);
        $this->assertSame(SiblingOfferStatus::Accepted, $offer->refresh()->status);
    }

    public function test_an_incoming_trade_counts_down_rather_than_describing_a_moment(): void
    {
        $household = Household::factory()->create();
        $alex = Profile::factory()->for($household)->create(['name' => 'Alex', 'points' => 400]);
        $sam = $this->loginKid($household, ['name' => 'Sam', 'points' => 0]);

        SiblingOffer::factory()->create([
            'household_id' => $household->id,
            'from_profile_id' => $alex->id,
            'to_profile_id' => $sam->id,
            // Half an hour of slack: on the boundary the render is already a
            // few milliseconds past 23h and truncates down to 22.
            'expires_at' => now()->addHours(23)->addMinutes(30),
        ]);

        Volt::test('kid.trades')
            ->assertSee('23 hours left')
            ->assertDontSee('23 hours from now');
    }

    public function test_declining_an_incoming_trade_refunds_the_sender(): void
    {
        $household = Household::factory()->create();
        $alex = Profile::factory()->for($household)->create(['name' => 'Alex', 'points' => 400]);
        $sam = $this->loginKid($household, ['name' => 'Sam', 'points' => 0]);

        $offer = SiblingOffer::factory()->create([
            'household_id' => $household->id,
            'from_profile_id' => $alex->id,
            'to_profile_id' => $sam->id,
            'give_amount' => 100,
        ]);

        Volt::test('kid.trades')->call('declineOffer', $offer->id)->assertSee('Turned it down');

        $this->assertSame(500, $alex->refresh()->points);
        $this->assertSame(0, $sam->refresh()->points);
    }

    public function test_a_trade_you_would_have_to_pay_for_but_cannot_afford_offers_no_accept_button(): void
    {
        $household = Household::factory()->create();
        $alex = Profile::factory()->for($household)->create(['name' => 'Alex']);
        $this->loginKid($household, ['name' => 'Sam', 'points' => 60]);

        // "They pay me" — Sam is the payer here, so the button is priced.
        SiblingOffer::factory()->earning(100)->create([
            'household_id' => $household->id,
            'from_profile_id' => $alex->id,
            'to_profile_id' => Profile::where('name', 'Sam')->value('id'),
        ]);

        Volt::test('kid.trades')
            ->assertSee('Need 40')
            ->assertDontSee('Swap!');
    }

    public function test_taking_back_your_own_trade_refunds_you(): void
    {
        $household = Household::factory()->create();
        $sam = Profile::factory()->for($household)->create(['name' => 'Sam']);
        $alex = $this->loginKid($household, ['name' => 'Alex', 'points' => 500]);

        $offer = SiblingOffer::factory()->create([
            'household_id' => $household->id,
            'from_profile_id' => $alex->id,
            'to_profile_id' => $sam->id,
            'give_amount' => 100,
        ]);
        $alex->decrement('points', 100);

        Volt::test('kid.trades')->call('cancelOffer', $offer->id)->assertSee('Took it back');

        $this->assertSame(500, $alex->refresh()->points);
    }

    public function test_opening_the_tab_sweeps_and_refunds_a_lapsed_trade(): void
    {
        $household = Household::factory()->create();
        $sam = Profile::factory()->for($household)->create(['name' => 'Sam']);
        $alex = $this->loginKid($household, ['name' => 'Alex', 'points' => 400]);

        // The app has no scheduler — opening this page is what settles these.
        $offer = SiblingOffer::factory()->expired()->create([
            'household_id' => $household->id,
            'from_profile_id' => $alex->id,
            'to_profile_id' => $sam->id,
            'give_amount' => 100,
        ]);

        Volt::test('kid.trades')->assertOk()->assertDontSee('Yours, still waiting');

        $this->assertSame(500, $alex->refresh()->points);
        $this->assertSame(SiblingOfferStatus::Expired, $offer->refresh()->status);
    }

    public function test_the_trades_tab_carries_a_count_of_trades_waiting_on_you(): void
    {
        $household = Household::factory()->create();
        $alex = Profile::factory()->for($household)->create(['name' => 'Alex', 'points' => 400]);
        $sam = $this->loginKid($household, ['name' => 'Sam']);

        SiblingOffer::factory()->count(2)->create([
            'household_id' => $household->id,
            'from_profile_id' => $alex->id,
            'to_profile_id' => $sam->id,
        ]);

        // Wording is generic now that the same badge also carries jobs from the
        // bounty board — a world's count is the sum of its pages'.
        Volt::test('kid.trades')->assertSee('2 things waiting on you');
    }

    public function test_a_trade_you_sent_does_not_badge_your_own_tab(): void
    {
        $household = Household::factory()->create();
        $sam = Profile::factory()->for($household)->create(['name' => 'Sam']);
        $alex = $this->loginKid($household, ['name' => 'Alex', 'points' => 400]);

        SiblingOffer::factory()->create([
            'household_id' => $household->id,
            'from_profile_id' => $alex->id,
            'to_profile_id' => $sam->id,
        ]);

        Volt::test('kid.trades')->assertDontSee('waiting on you');
    }

    public function test_a_kid_can_compose_a_straight_points_for_tickets_swap(): void
    {
        $household = Household::factory()->create();
        $sam = Profile::factory()->for($household)->create(['name' => 'Sam']);
        $alex = $this->loginKid($household, ['name' => 'Alex', 'points' => 500]);

        Volt::test('kid.trades')
            ->call('choose', 'swap')
            ->call('setGetAsset', TradeAsset::Tickets->value)
            ->set('giveAmount', '100')
            ->set('getAmount', '2')
            // No favour on either side, so there is nothing to type.
            ->assertDontSee('Play a game with me for 30 minutes')
            ->call('sendSwap', $sam->id)
            ->assertSee('Sent to Sam')
            ->assertSee('100 pts for 2 tickets');

        $this->assertSame(400, $alex->refresh()->points);
    }

    public function test_picking_the_same_currency_on_both_sides_moves_the_other_one(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create(['name' => 'Sam']);
        $this->loginKid($household, ['name' => 'Alex', 'points' => 500]);

        // Points for points is never what a kid meant, and letting the form
        // reach a state the service only rejects is worse than moving a picker.
        Volt::test('kid.trades')
            ->call('choose', 'swap')
            ->call('setGetAsset', TradeAsset::Tickets->value)
            ->call('setGiveAsset', TradeAsset::Tickets->value)
            ->assertSet('giveAsset', TradeAsset::Tickets->value)
            // Only two currencies left to land on now that a favour is not a
            // side a trade can carry.
            ->assertSet('getAsset', TradeAsset::Points->value);
    }

    public function test_a_trade_asking_for_tickets_the_viewer_does_not_have_offers_no_accept_button(): void
    {
        $household = Household::factory()->create();
        $alex = Profile::factory()->for($household)->create(['name' => 'Alex', 'points' => 500]);
        $this->loginKid($household, ['name' => 'Sam', 'bonus_tickets' => 1]);

        SiblingOffer::factory()->swap(points: 100, tickets: 3)->create([
            'household_id' => $household->id,
            'from_profile_id' => $alex->id,
            'to_profile_id' => Profile::where('name', 'Sam')->value('id'),
        ]);

        Volt::test('kid.trades')
            ->assertSee('Need 2 tickets')
            ->assertDontSee('Swap!');
    }
}
