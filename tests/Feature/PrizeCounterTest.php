<?php

namespace Tests\Feature;

use App\Enums\CandyOrderStatus;
use App\Enums\PrizeSlot;
use App\Enums\TokenKind;
use App\Exceptions\InsufficientTokensException;
use App\Models\Candy;
use App\Models\CandyOrder;
use App\Models\Household;
use App\Models\PetPrize;
use App\Models\Profile;
use App\Models\TokenEntry;
use App\Services\PrizeCounterService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use RuntimeException;
use Tests\TestCase;

/**
 * The arcade's prize counter: pet snacks, toys and beds bought with tokens and
 * put out on the pet's floor, and real sweets that wait on a grown-up.
 */
class PrizeCounterTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
    }

    private function counter(): PrizeCounterService
    {
        return app(PrizeCounterService::class);
    }

    private function kid(int $tokens = 0, string $name = 'Nova'): Profile
    {
        $kid = Profile::factory()->for($this->household)->create(['name' => $name]);

        if ($tokens > 0) {
            app(TokenService::class)->record($kid, TokenKind::Adjustment, $tokens, 'Seed');
        }

        return $kid->fresh();
    }

    private function parent(): Profile
    {
        return Profile::factory()->for($this->household)->parent()->create(['name' => 'Mum']);
    }

    public function test_buying_a_toy_spends_the_tokens_and_puts_it_out(): void
    {
        $kid = $this->kid(100);

        $this->counter()->buy($kid, PrizeSlot::Toy, 'frisbee');

        $this->assertSame(25, $kid->fresh()->arcade_tokens);
        $this->assertSame('frisbee', $kid->fresh()->pet_toy);
        $this->assertTrue($this->counter()->owns($kid, PrizeSlot::Toy, 'frisbee'));
        $this->assertSame(TokenKind::Prize, TokenEntry::latest('id')->first()->kind);
    }

    public function test_a_prize_cannot_be_bought_twice(): void
    {
        $kid = $this->kid(100);
        $this->counter()->buy($kid, PrizeSlot::Snack, 'burger');

        $this->expectException(RuntimeException::class);
        $this->counter()->buy($kid->fresh(), PrizeSlot::Snack, 'burger');
    }

    public function test_a_prize_the_kid_cannot_afford_is_refused_and_nothing_is_kept(): void
    {
        $kid = $this->kid(10);

        try {
            $this->counter()->buy($kid, PrizeSlot::Bed, 'throne');
            $this->fail('A 150-token bed was sold for 10.');
        } catch (InsufficientTokensException $e) {
            $this->assertSame(140, $e->shortfall);
        }

        // The ownership row is written first, and has to roll back with the spend.
        $this->assertSame(0, PetPrize::count());
        $this->assertSame(10, $kid->fresh()->arcade_tokens);
        $this->assertNull($kid->fresh()->pet_bed);
    }

    public function test_the_meat_block_is_everybodys_and_is_the_snack_until_another_is_out(): void
    {
        $kid = $this->kid();

        $this->assertTrue($this->counter()->owns($kid, PrizeSlot::Snack, 'meat'));
        $this->assertSame(['snack' => 'meat', 'toy' => null, 'bed' => null], $this->counter()->gearFor($kid));

        $this->expectException(RuntimeException::class);
        $this->counter()->buy($kid, PrizeSlot::Snack, 'meat');
    }

    public function test_only_something_owned_can_be_put_out_and_swapping_is_free(): void
    {
        $kid = $this->kid(60);

        $this->assertFalse($this->counter()->putOut($kid, PrizeSlot::Snack, 'sushi'));

        $this->counter()->buy($kid, PrizeSlot::Snack, 'pizza');
        $this->assertTrue($this->counter()->putOut($kid->fresh(), PrizeSlot::Snack, 'meat'));

        $this->assertSame('meat', $kid->fresh()->pet_snack);
        $this->assertSame(40, $kid->fresh()->arcade_tokens);
    }

    public function test_a_toy_or_bed_can_be_put_away_but_the_snack_cannot(): void
    {
        $kid = $this->kid(100);
        $this->counter()->buy($kid, PrizeSlot::Toy, 'ball');

        $this->counter()->putAway($kid->fresh(), PrizeSlot::Toy);
        $this->counter()->putAway($kid->fresh(), PrizeSlot::Snack);

        $this->assertNull($kid->fresh()->pet_toy);
        $this->assertSame('meat', $this->counter()->gearFor($kid->fresh())['snack']);
    }

    public function test_grown_ups_cannot_buy_prizes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->counter()->buy($this->parent(), PrizeSlot::Toy, 'ball');
    }

    public function test_the_kids_pet_layer_wears_the_gear(): void
    {
        $kid = $this->kid(200);
        $this->counter()->buy($kid, PrizeSlot::Toy, 'rocket');
        $this->counter()->buy($kid->fresh(), PrizeSlot::Snack, 'taco');

        // The pet layer only draws when there is a pet, so the attributes are
        // asked of the gear the shell reads rather than of a rendered page.
        $this->assertSame(['snack' => 'taco', 'toy' => 'rocket', 'bed' => null], $this->counter()->gearFor($kid->fresh()));
    }

    public function test_buying_sweets_spends_the_tokens_takes_one_from_the_cupboard_and_queues_it(): void
    {
        $kid = $this->kid(50);
        $candy = Candy::factory()->for($this->household)->create(['name' => 'Freddo', 'tokens' => 20, 'stock' => 2]);

        $order = $this->counter()->buyCandy($kid, $candy);

        $this->assertSame(30, $kid->fresh()->arcade_tokens);
        $this->assertSame(1, $candy->fresh()->stock);
        $this->assertSame(CandyOrderStatus::Waiting, $order->status);
        $this->assertSame('Freddo', $order->name);
        $this->assertSame(1, $this->counter()->waitingCountFor($kid));
        $this->assertCount(1, $this->counter()->queueFor($this->household));
    }

    public function test_the_last_one_in_the_cupboard_can_only_be_bought_once(): void
    {
        $candy = Candy::factory()->for($this->household)->create(['stock' => 1, 'tokens' => 5]);

        $this->counter()->buyCandy($this->kid(50, 'Nova'), $candy);

        $this->expectExceptionMessage('None left in the cupboard.');
        $this->counter()->buyCandy($this->kid(50, 'Rook'), $candy);
    }

    public function test_sweets_off_the_counter_or_from_another_house_are_not_for_sale(): void
    {
        $kid = $this->kid(100);
        $retired = Candy::factory()->for($this->household)->retired()->create();
        $theirs = Candy::factory()->create();

        foreach ([$retired, $theirs] as $candy) {
            try {
                $this->counter()->buyCandy($kid, $candy);
                $this->fail('Sold a sweet that is not on this counter.');
            } catch (RuntimeException) {
                // Refused, which is right.
            }
        }

        $this->assertSame(100, $kid->fresh()->arcade_tokens);
        $this->assertSame(0, CandyOrder::count());
    }

    public function test_a_refund_gives_back_the_tokens_and_the_sweet(): void
    {
        $kid = $this->kid(50);
        $candy = Candy::factory()->for($this->household)->create(['tokens' => 20, 'stock' => 1]);
        $order = $this->counter()->buyCandy($kid, $candy);

        $this->assertTrue($this->counter()->refund($order, $this->parent()));

        $this->assertSame(50, $kid->fresh()->arcade_tokens);
        $this->assertSame(1, $candy->fresh()->stock);
        $this->assertSame(CandyOrderStatus::Refunded, $order->fresh()->status);

        // A refund is not the machine paying, so it never eats into the cap.
        $this->assertSame(0, app(TokenService::class)->earnedToday($kid));

        // And only once.
        $this->assertFalse($this->counter()->refund($order->fresh(), $this->parent()));
    }

    public function test_handed_over_sweets_leave_the_queue_and_cannot_then_be_refunded(): void
    {
        $kid = $this->kid(50);
        $order = $this->counter()->buyCandy($kid, Candy::factory()->for($this->household)->create(['tokens' => 20]));

        $this->assertTrue($this->counter()->handOver($order, $this->parent()));
        $this->assertCount(0, $this->counter()->queueFor($this->household));
        $this->assertFalse($this->counter()->refund($order->fresh(), $this->parent()));
        $this->assertSame(30, $kid->fresh()->arcade_tokens);
    }

    public function test_the_counter_tab_shows_the_shelves_and_the_balance(): void
    {
        $kid = $this->kid(34);
        Candy::factory()->for($this->household)->create(['name' => 'Ice lolly']);

        Auth::guard('profile')->login($kid);

        Volt::test('arcade')
            ->call('showTab', 'prizes')
            ->assertSee('PRIZES')
            ->assertSee('SNACKS')
            ->assertSee('BEDS')
            ->assertSee('Ice lolly')
            ->assertSee('34 tokens. What&#039;ll it be?', false)
            ->assertSee('What your pet has out');
    }

    public function test_the_page_buys_a_prize_and_tells_the_pet_layer(): void
    {
        $kid = $this->kid(20);
        Auth::guard('profile')->login($kid);

        Volt::test('arcade')
            ->call('showTab', 'prizes')
            ->call('buyPrize', 'snack', 'burger')
            ->assertDispatched('fq-pet-gear', gear: ['snack' => 'burger', 'toy' => null, 'bed' => null])
            ->assertSet('counterNote', 'Burger is out. Look down!');

        $this->assertSame(5, $kid->fresh()->arcade_tokens);
    }

    public function test_the_page_says_how_far_short_a_kid_is(): void
    {
        $kid = $this->kid(5);
        Auth::guard('profile')->login($kid);

        Volt::test('arcade')
            ->call('showTab', 'prizes')
            ->call('buyPrize', 'toy', 'ball')
            ->assertSet('counterNote', '35 short. Go play.');
    }

    public function test_the_page_swaps_a_ticket_and_queues_sweets(): void
    {
        $kid = $this->kid(60);
        $candy = Candy::factory()->for($this->household)->create(['name' => 'Haribo bag', 'tokens' => 45]);
        Auth::guard('profile')->login($kid);

        Volt::test('arcade')
            ->call('showTab', 'prizes')
            ->call('buyTicket')
            ->call('buyCandy', $candy->id)
            ->assertSet('counterNote', 'Haribo bag is on its way. A grown-up will bring it.');

        $this->assertSame(5, $kid->fresh()->arcade_tokens);
        $this->assertSame(1, $kid->fresh()->bonus_tickets);
    }
}
