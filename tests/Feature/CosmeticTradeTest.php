<?php

namespace Tests\Feature;

use App\Enums\CosmeticSlot;
use App\Enums\TicketKind;
use App\Enums\TradeAsset;
use App\Exceptions\OfferUnavailableException;
use App\Models\BonusTicketEntry;
use App\Models\Cosmetic;
use App\Models\Household;
use App\Models\OwnedCosmetic;
use App\Models\Profile;
use App\Models\SiblingOffer;
use App\Services\CosmeticService;
use App\Services\SiblingOfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Limited items changing hands.
 *
 * A limited is on sale for one week, ever. After that week the only way to get
 * one is from the sibling who bought it, which is the whole reason a limited is
 * worth chasing — and the whole reason this exists.
 */
class CosmeticTradeTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $colton;

    private Profile $westin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-16 12:00', 'America/Chicago'));

        $this->household = Household::factory()->create();
        $this->colton = Profile::factory()->for($this->household)->create(['name' => 'Colton', 'bonus_tickets' => 30, 'points' => 500]);
        $this->westin = Profile::factory()->for($this->household)->create(['name' => 'Westin', 'bonus_tickets' => 30, 'points' => 500]);
    }

    /** What a kid's trades moved, ignoring anything else that pays tickets. */
    private function tradeTickets(Profile $kid): int
    {
        return (int) BonusTicketEntry::where('profile_id', $kid->id)
            ->where('kind', TicketKind::Trade)
            ->sum('amount');
    }

    private function trades(): SiblingOfferService
    {
        return app(SiblingOfferService::class);
    }

    private function item(string $slot, string $recipe): Cosmetic
    {
        return Cosmetic::where('household_id', $this->household->id)->where('slot', $slot)->where('recipe', $recipe)->firstOrFail();
    }

    /** Buys a limited for a kid — the crown and the skull are both limiteds. */
    private function give(Profile $kid, string $slot, string $recipe): Cosmetic
    {
        $item = $this->item($slot, $recipe);
        app(CosmeticService::class)->buy($kid, $item);
        app()->forgetScopedInstances();

        return $item;
    }

    public function test_only_limiteds_a_kid_owns_can_be_put_up(): void
    {
        $crown = $this->give($this->colton, 'frame', 'crown');

        $tradable = app(CosmeticService::class)->tradableFor($this->colton);

        $this->assertSame(['crown'], $tradable->pluck('recipe')->all());

        // A shelf item they own is not tradable: the shop still sells it.
        $this->give($this->colton, 'frame', 'double');
        app()->forgetScopedInstances();

        $this->assertSame(['crown'], app(CosmeticService::class)->tradableFor($this->colton)->pluck('recipe')->all());
        $this->assertTrue($crown->isLimited());
    }

    public function test_an_item_is_swapped_for_tickets_and_changes_owner(): void
    {
        $crown = $this->give($this->colton, 'frame', 'crown');

        $offer = $this->trades()->offer(
            $this->colton,
            $this->westin,
            TradeAsset::Cosmetic,
            0,
            TradeAsset::Tickets,
            6,
            giveCosmetic: $crown,
        );

        // Nothing is held: Colton keeps wearing it until somebody says yes.
        $this->assertSame($crown->id, $this->colton->fresh()->worn_frame_id);
        $this->assertSame(30 - $crown->cost, $this->colton->fresh()->bonus_tickets);

        $this->trades()->accept($offer->fresh(), $this->westin);
        app()->forgetScopedInstances();

        $this->assertDatabaseHas('owned_cosmetics', ['profile_id' => $this->westin->id, 'cosmetic_id' => $crown->id]);
        $this->assertDatabaseMissing('owned_cosmetics', ['profile_id' => $this->colton->id, 'cosmetic_id' => $crown->id]);
        // One row, one owner — a traded limited is no less rare than a bought one.
        $this->assertSame(1, OwnedCosmetic::where('cosmetic_id', $crown->id)->count());

        // And the kid who gave it away is no longer wearing what they gave away.
        $this->assertNull($this->colton->fresh()->worn_frame_id);

        // The trade's own movement, rather than the balances: accepting also
        // re-evaluates badges, and a badge pays a ticket of its own.
        $this->assertSame(6, $this->tradeTickets($this->colton));
        $this->assertSame(-6, $this->tradeTickets($this->westin));
    }

    public function test_a_kid_can_ask_for_a_siblings_item_and_pay_points(): void
    {
        $skull = $this->give($this->westin, 'avatar', 'skull');

        $offer = $this->trades()->offer(
            $this->colton,
            $this->westin,
            TradeAsset::Points,
            200,
            TradeAsset::Cosmetic,
            0,
            getCosmetic: $skull,
        );

        // The points side is escrowed as usual.
        $this->assertSame(300, $this->colton->fresh()->points);

        $this->trades()->accept($offer->fresh(), $this->westin);
        app()->forgetScopedInstances();

        $this->assertDatabaseHas('owned_cosmetics', ['profile_id' => $this->colton->id, 'cosmetic_id' => $skull->id]);
        $this->assertSame(700, $this->westin->fresh()->points);
        $this->assertNull($this->westin->fresh()->worn_avatar_id);
    }

    public function test_two_items_can_be_swapped_for_each_other(): void
    {
        $crown = $this->give($this->colton, 'frame', 'crown');
        $skull = $this->give($this->westin, 'avatar', 'skull');

        $offer = $this->trades()->offer(
            $this->colton,
            $this->westin,
            TradeAsset::Cosmetic,
            0,
            TradeAsset::Cosmetic,
            0,
            giveCosmetic: $crown,
            getCosmetic: $skull,
        );

        $this->trades()->accept($offer->fresh(), $this->westin);

        $this->assertDatabaseHas('owned_cosmetics', ['profile_id' => $this->westin->id, 'cosmetic_id' => $crown->id]);
        $this->assertDatabaseHas('owned_cosmetics', ['profile_id' => $this->colton->id, 'cosmetic_id' => $skull->id]);
    }

    public function test_a_kid_cannot_put_up_something_they_do_not_own(): void
    {
        $skull = $this->give($this->westin, 'avatar', 'skull');

        $this->expectException(InvalidArgumentException::class);

        $this->trades()->offer($this->colton, $this->westin, TradeAsset::Cosmetic, 0, TradeAsset::Tickets, 3, giveCosmetic: $skull);
    }

    public function test_a_kid_cannot_ask_for_something_the_sibling_has_not_got(): void
    {
        $crown = $this->give($this->colton, 'frame', 'crown');

        $this->expectException(InvalidArgumentException::class);

        // Colton asking Westin for the crown Colton himself owns.
        $this->trades()->offer($this->colton, $this->westin, TradeAsset::Tickets, 3, TradeAsset::Cosmetic, 0, getCosmetic: $crown);
    }

    public function test_a_shelf_item_cannot_be_traded(): void
    {
        $double = $this->give($this->colton, 'frame', 'double');

        $this->expectException(InvalidArgumentException::class);

        $this->trades()->offer($this->colton, $this->westin, TradeAsset::Cosmetic, 0, TradeAsset::Tickets, 3, giveCosmetic: $double);
    }

    /** Otherwise the same item is promised twice and the second kid gets nothing. */
    public function test_the_same_item_cannot_be_up_in_two_trades_at_once(): void
    {
        $crown = $this->give($this->colton, 'frame', 'crown');
        $ada = Profile::factory()->for($this->household)->create(['name' => 'Ada', 'bonus_tickets' => 20]);

        $this->trades()->offer($this->colton, $this->westin, TradeAsset::Cosmetic, 0, TradeAsset::Tickets, 6, giveCosmetic: $crown);

        $this->expectException(InvalidArgumentException::class);

        $this->trades()->offer($this->colton, $ada, TradeAsset::Cosmetic, 0, TradeAsset::Tickets, 8, giveCosmetic: $crown);
    }

    /**
     * Items are not escrowed, so the world can move under an offer. Accepting
     * one whose item has gone is refused rather than silently handing over
     * nothing.
     */
    public function test_accepting_a_trade_whose_item_has_moved_on_is_refused(): void
    {
        $crown = $this->give($this->colton, 'frame', 'crown');

        $offer = $this->trades()->offer($this->colton, $this->westin, TradeAsset::Cosmetic, 0, TradeAsset::Tickets, 6, giveCosmetic: $crown);

        // The crown leaves Colton some other way before Westin answers.
        app(CosmeticService::class)->handOver($crown, $this->colton, $this->westin);
        app()->forgetScopedInstances();

        $this->expectException(OfferUnavailableException::class);

        $this->trades()->accept($offer->fresh(), $this->westin);
    }

    public function test_declining_a_trade_leaves_the_item_where_it_was(): void
    {
        $crown = $this->give($this->colton, 'frame', 'crown');

        $offer = $this->trades()->offer($this->colton, $this->westin, TradeAsset::Cosmetic, 0, TradeAsset::Tickets, 6, giveCosmetic: $crown);

        $this->trades()->decline($offer->fresh(), $this->westin);
        app()->forgetScopedInstances();

        $this->assertDatabaseHas('owned_cosmetics', ['profile_id' => $this->colton->id, 'cosmetic_id' => $crown->id]);
        $this->assertSame($crown->id, $this->colton->fresh()->worn_frame_id);
        // And it can be offered again, since nothing is holding it now.
        $this->assertNotNull($this->trades()->offer($this->colton, $this->westin, TradeAsset::Cosmetic, 0, TradeAsset::Tickets, 4, giveCosmetic: $crown));
    }

    public function test_a_traded_item_can_be_worn_by_its_new_owner(): void
    {
        $crown = $this->give($this->colton, 'frame', 'crown');

        $offer = $this->trades()->offer($this->colton, $this->westin, TradeAsset::Cosmetic, 0, TradeAsset::Tickets, 6, giveCosmetic: $crown);
        $this->trades()->accept($offer->fresh(), $this->westin);
        app()->forgetScopedInstances();

        $service = app(CosmeticService::class);
        $service->wear($this->westin->fresh(), $crown);

        $this->assertSame($crown->id, $this->westin->fresh()->worn_frame_id);
        $this->assertSame('crown', $service->wornIn($this->westin->fresh(), CosmeticSlot::Frame)?->recipe);
    }

    public function test_the_trades_page_composes_an_item_swap(): void
    {
        $crown = $this->give($this->colton, 'frame', 'crown');
        Auth::guard('profile')->login($this->colton->fresh());

        Volt::test('kid.trades')
            ->call('choose', 'swap')
            ->assertSee('An item')
            // The picker itself only appears once that side is set to an item.
            ->call('setGiveAsset', 'cosmetic')
            ->assertSee('Paper Crown')
            ->set('giveCosmeticId', $crown->id)
            ->call('setGetAsset', 'tickets')
            ->set('getAmount', '6')
            ->call('sendSwap', $this->westin->id)
            ->assertSee('Sent to Westin');

        $offer = SiblingOffer::latest('id')->firstOrFail();

        $this->assertSame(TradeAsset::Cosmetic, $offer->give_asset);
        $this->assertSame($crown->id, $offer->give_cosmetic_id);
        $this->assertSame(6, $offer->get_amount);
    }

    public function test_the_page_offers_no_item_picker_to_a_kid_with_nothing_to_trade(): void
    {
        Auth::guard('profile')->login($this->colton);

        Volt::test('kid.trades')
            ->call('choose', 'swap')
            ->assertDontSee('An item');
    }

    public function test_asking_for_an_item_the_chosen_sibling_has_not_got_says_so(): void
    {
        $crown = $this->give($this->colton, 'frame', 'crown');
        $ada = Profile::factory()->for($this->household)->create(['name' => 'Ada']);
        Auth::guard('profile')->login($this->colton->fresh());

        Volt::test('kid.trades')
            ->call('choose', 'swap')
            ->call('setGetAsset', 'cosmetic')
            // Colton's own crown, asked of Ada, who hasn't got it.
            ->set('getCosmeticId', $crown->id)
            ->call('sendSwap', $ada->id)
            ->assertSee('Pick something Ada has got.');

        $this->assertSame(0, SiblingOffer::count());
    }

    public function test_the_offer_reads_as_the_items_name_on_a_card(): void
    {
        $crown = $this->give($this->colton, 'frame', 'crown');

        $offer = $this->trades()->offer($this->colton, $this->westin, TradeAsset::Cosmetic, 0, TradeAsset::Tickets, 6, giveCosmetic: $crown);

        $this->assertSame('Paper Crown', $offer->giveText());
        $this->assertSame('6 tickets', $offer->getText());
        $this->assertSame('Paper Crown for 6 tickets', $offer->summary());
    }

    public function test_a_trade_offer_still_expires_and_leaves_the_item_alone(): void
    {
        $crown = $this->give($this->colton, 'frame', 'crown');

        $this->trades()->offer($this->colton, $this->westin, TradeAsset::Cosmetic, 0, TradeAsset::Tickets, 6, giveCosmetic: $crown);

        $this->travelTo(Carbon::parse('2026-09-16 12:00', 'America/Chicago')->addHours(SiblingOffer::LIFETIME_HOURS + 1));

        $this->trades()->expireStale($this->household);
        app()->forgetScopedInstances();

        $this->assertDatabaseHas('owned_cosmetics', ['profile_id' => $this->colton->id, 'cosmetic_id' => $crown->id]);
        $this->assertSame(0, SiblingOffer::live()->count());
    }
}
