<?php

namespace Tests\Feature;

use App\Enums\CandyOrderStatus;
use App\Enums\TokenKind;
use App\Models\Candy;
use App\Models\CandyOrder;
use App\Models\Household;
use App\Models\Profile;
use App\Services\PrizeCounterService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Parent console → Candy: stocking the counter's sweets shelf, and handing
 * over or refunding what the kids bought. 2f in the arcade tokens handoff.
 */
class ParentCandyTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $parent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->parent = Profile::factory()->for($this->household)->parent()->create(['name' => 'Mum']);

        Auth::guard('profile')->login($this->parent);
    }

    private function order(string $kidName = 'Colton', int $tokens = 20): CandyOrder
    {
        $kid = Profile::factory()->for($this->household)->create(['name' => $kidName]);
        app(TokenService::class)->record($kid, TokenKind::Adjustment, 100, 'Seed');

        $candy = Candy::factory()->for($this->household)->create(['name' => 'Freddo', 'tokens' => $tokens]);

        return app(PrizeCounterService::class)->buyCandy($kid->fresh(), $candy);
    }

    public function test_a_parent_puts_a_sweet_on_the_counter(): void
    {
        Volt::test('parent.candy')
            ->set('name', 'Haribo bag')
            ->set('tokens', '45')
            ->set('stock', '2')
            ->call('$set', 'hue', 190)
            ->call('addCandy')
            ->assertHasNoErrors()
            ->assertSee('Haribo bag is on the counter.');

        $candy = Candy::sole();

        $this->assertSame([$this->household->id, 'Haribo bag', 45, 2, 190], [$candy->household_id, $candy->name, $candy->tokens, $candy->stock, $candy->hue]);
    }

    public function test_a_sweet_needs_a_name_and_a_price(): void
    {
        Volt::test('parent.candy')
            ->set('name', '')
            ->set('tokens', '0')
            ->call('addCandy')
            ->assertHasErrors(['name', 'tokens']);

        $this->assertSame(0, Candy::count());
    }

    public function test_the_queue_hands_over_and_refunds(): void
    {
        $handed = $this->order('Colton');
        $refunded = $this->order('Westin', 30);

        Volt::test('parent.candy')
            ->assertSee('Colton &middot; Freddo', false)
            ->call('handOver', $handed->id)
            ->call('refund', $refunded->id)
            ->assertSee('30 tokens back to Westin.');

        $this->assertSame(CandyOrderStatus::HandedOver, $handed->fresh()->status);
        $this->assertSame(CandyOrderStatus::Refunded, $refunded->fresh()->status);
        $this->assertSame(100, $refunded->profile->fresh()->arcade_tokens);
    }

    public function test_an_old_order_is_called_overdue(): void
    {
        $order = $this->order();
        $order->forceFill(['created_at' => now()->subDays(3)])->save();

        Volt::test('parent.candy')->assertSee('overdue');
    }

    public function test_another_households_candy_and_orders_are_out_of_reach(): void
    {
        $theirCandy = Candy::factory()->create(['stock' => 3]);
        $theirKid = Profile::factory()->for($theirCandy->household)->create();
        app(TokenService::class)->record($theirKid, TokenKind::Adjustment, 100, 'Seed');
        $theirOrder = app(PrizeCounterService::class)->buyCandy($theirKid->fresh(), $theirCandy);

        Volt::test('parent.candy')
            ->assertDontSee($theirCandy->name.' ·')
            ->call('restock', $theirCandy->id, 1)
            ->call('retire', $theirCandy->id)
            ->call('refund', $theirOrder->id);

        $this->assertSame(2, $theirCandy->fresh()->stock);
        $this->assertNull($theirCandy->fresh()->retired_at);
        $this->assertSame(CandyOrderStatus::Waiting, $theirOrder->fresh()->status);
    }

    public function test_restocking_and_taking_a_sweet_off_the_counter(): void
    {
        $candy = Candy::factory()->for($this->household)->create(['stock' => 1]);

        Volt::test('parent.candy')
            ->call('restock', $candy->id, 1)
            ->call('restock', $candy->id, -1)
            ->call('restock', $candy->id, -1)
            ->call('restock', $candy->id, -1)
            ->call('retire', $candy->id);

        $this->assertSame(0, $candy->fresh()->stock);
        $this->assertNotNull($candy->fresh()->retired_at);
        $this->assertCount(0, app(PrizeCounterService::class)->candiesFor($this->household));
    }

    public function test_the_sheet_counts_sweets_waiting(): void
    {
        $this->order();

        Volt::test('parent.home')->assertSee(route('parent.candy'), false);
        $this->assertStringContainsString('1 thing waiting on you', Volt::test('parent.music')->html());
    }

    public function test_kids_cannot_open_it(): void
    {
        $kid = Profile::factory()->for($this->household)->create();
        Auth::guard('profile')->login($kid);

        $this->get(route('parent.candy'))->assertForbidden();
    }
}
