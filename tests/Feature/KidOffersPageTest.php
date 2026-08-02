<?php

namespace Tests\Feature;

use App\Enums\SiblingOfferKind;
use App\Enums\SiblingOfferStatus;
use App\Models\Household;
use App\Models\Profile;
use App\Models\SiblingOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class KidOffersPageTest extends TestCase
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

        Volt::test('kid.offers')
            ->call('toggleCompose')
            ->assertSee('Send it to')
            ->assertSee('Sam')
            ->assertDontSee('Mum');
    }

    public function test_an_only_child_is_told_why_the_tab_is_empty(): void
    {
        $household = Household::factory()->create();
        $this->loginKid($household, ['points' => 500]);

        Volt::test('kid.offers')->assertDontSee('Make a trade with a sibling');
    }

    public function test_sending_a_trade_holds_the_points_and_closes_the_form(): void
    {
        $household = Household::factory()->create();
        $sam = Profile::factory()->for($household)->create(['name' => 'Sam']);
        $alex = $this->loginKid($household, ['name' => 'Alex', 'points' => 500]);

        Volt::test('kid.offers')
            ->call('toggleCompose')
            ->set('offerDescription', 'Play a game for 30 minutes')
            ->set('offerPoints', '100')
            ->call('sendOffer', $sam->id)
            ->assertSee('Sent to Sam')
            ->assertDontSee('Send it to')
            ->assertSee('Waiting on an answer');

        $this->assertSame(400, $alex->refresh()->points);
    }

    public function test_a_trade_you_cannot_afford_is_refused_with_the_shortfall(): void
    {
        $household = Household::factory()->create();
        $sam = Profile::factory()->for($household)->create(['name' => 'Sam']);
        $alex = $this->loginKid($household, ['points' => 60]);

        Volt::test('kid.offers')
            ->call('toggleCompose')
            ->set('offerDescription', 'Play a game')
            ->set('offerPoints', '100')
            ->call('sendOffer', $sam->id)
            ->assertSee('need 40 more');

        $this->assertSame(60, $alex->refresh()->points);
        $this->assertSame(0, SiblingOffer::count());
    }

    public function test_a_trade_with_nothing_typed_in_it_is_refused(): void
    {
        $household = Household::factory()->create();
        $sam = Profile::factory()->for($household)->create(['name' => 'Sam']);
        $this->loginKid($household, ['points' => 500]);

        Volt::test('kid.offers')
            ->call('toggleCompose')
            ->set('offerDescription', '   ')
            ->set('offerPoints', '100')
            ->call('sendOffer', $sam->id)
            ->assertSee('Say what the trade is first');

        $this->assertSame(0, SiblingOffer::count());
    }

    public function test_nothing_incoming_means_no_trades_section(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create();
        $this->loginKid($household, ['points' => 100]);

        // An empty shelf reads as a loading bug, same as the price bands.
        Volt::test('kid.offers')->assertDontSee('Trades for you');
    }

    public function test_an_incoming_trade_renders_and_accepting_it_moves_the_points(): void
    {
        $household = Household::factory()->create();
        $alex = Profile::factory()->for($household)->create(['name' => 'Alex', 'points' => 400]);
        $sam = $this->loginKid($household, ['name' => 'Sam', 'points' => 0]);

        $offer = SiblingOffer::factory()->create([
            'household_id' => $household->id,
            'from_profile_id' => $alex->id,
            'to_profile_id' => $sam->id,
            'description' => 'Play a game for 30 minutes',
            'points' => 100,
        ]);

        Volt::test('kid.offers')
            ->assertSee('Trades for you')
            ->assertSee('Play a game for 30 minutes')
            ->assertSee('100 pts')
            ->call('acceptOffer', $offer->id)
            ->assertDontSee('Trades for you');

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

        Volt::test('kid.offers')
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
            'points' => 100,
        ]);

        Volt::test('kid.offers')->call('declineOffer', $offer->id)->assertSee('Turned it down');

        $this->assertSame(500, $alex->refresh()->points);
        $this->assertSame(0, $sam->refresh()->points);
    }

    public function test_a_trade_you_would_have_to_pay_for_but_cannot_afford_offers_no_accept_button(): void
    {
        $household = Household::factory()->create();
        $alex = Profile::factory()->for($household)->create(['name' => 'Alex']);
        $this->loginKid($household, ['name' => 'Sam', 'points' => 60]);

        // "They pay me" — Sam is the payer here, so the button is priced.
        SiblingOffer::factory()->earning()->create([
            'household_id' => $household->id,
            'from_profile_id' => $alex->id,
            'to_profile_id' => Profile::where('name', 'Sam')->value('id'),
            'points' => 100,
        ]);

        Volt::test('kid.offers')
            ->assertSee('Need 40')
            ->assertDontSee('Trade!');
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
            'points' => 100,
        ]);
        $alex->decrement('points', 100);

        Volt::test('kid.offers')->call('cancelOffer', $offer->id)->assertSee('Took it back');

        $this->assertSame(500, $alex->refresh()->points);
    }

    public function test_opening_the_tab_sweeps_and_refunds_a_lapsed_trade(): void
    {
        $household = Household::factory()->create();
        $sam = Profile::factory()->for($household)->create(['name' => 'Sam']);
        $alex = $this->loginKid($household, ['name' => 'Alex', 'points' => 400]);

        // The app has no scheduler — the Loot Shop is what settles these.
        $offer = SiblingOffer::factory()->expired()->create([
            'household_id' => $household->id,
            'from_profile_id' => $alex->id,
            'to_profile_id' => $sam->id,
            'points' => 100,
        ]);

        Volt::test('kid.offers')->assertOk()->assertDontSee('Waiting on an answer');

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

        Volt::test('kid.offers')->assertSee('2 sibling trades waiting on you');
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
            'kind' => SiblingOfferKind::Paying,
        ]);

        Volt::test('kid.offers')->assertDontSee('waiting on you');
    }
}
