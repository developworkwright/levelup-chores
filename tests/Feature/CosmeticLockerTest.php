<?php

namespace Tests\Feature;

use App\Enums\CosmeticSlot;
use App\Enums\CosmeticStock;
use App\Enums\TicketKind;
use App\Exceptions\CosmeticUnavailableException;
use App\Exceptions\InsufficientTicketsException;
use App\Models\BonusTicketEntry;
use App\Models\Cosmetic;
use App\Models\CosmeticDrop;
use App\Models\Household;
use App\Models\OwnedCosmetic;
use App\Models\Profile;
use App\Services\CosmeticService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CosmeticLockerTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday, so a week has days behind it and days ahead.
        $this->travelTo(Carbon::parse('2026-09-16 12:00', 'America/Chicago'));

        $this->household = Household::factory()->create();
        $this->kid = Profile::factory()->for($this->household)->create(['name' => 'Colton', 'bonus_tickets' => 12]);
    }

    private function service(): CosmeticService
    {
        return app(CosmeticService::class);
    }

    private function item(string $slot, string $recipe): Cosmetic
    {
        return Cosmetic::where('household_id', $this->household->id)->where('slot', $slot)->where('recipe', $recipe)->firstOrFail();
    }

    public function test_buying_a_shelf_item_spends_tickets_owns_it_and_puts_it_on(): void
    {
        $double = $this->item('frame', 'double');

        $this->service()->buy($this->kid, $double);

        $this->kid->refresh();
        $this->assertSame(9, $this->kid->bonus_tickets);
        $this->assertSame($double->id, $this->kid->worn_frame_id);
        $this->assertDatabaseHas('owned_cosmetics', ['profile_id' => $this->kid->id, 'cosmetic_id' => $double->id, 'tickets_paid' => 3]);
        $this->assertSame(-3, BonusTicketEntry::where('profile_id', $this->kid->id)->where('kind', TicketKind::Purchase)->sum('amount'));
    }

    public function test_buying_something_already_owned_just_wears_it_and_never_charges_twice(): void
    {
        $double = $this->item('frame', 'double');
        $hairline = $this->item('frame', 'hairline');

        $this->service()->buy($this->kid, $double);
        $this->service()->buy($this->kid, $hairline);
        $this->service()->buy($this->kid, $double);

        $this->kid->refresh();
        $this->assertSame(12 - 3 - 2, $this->kid->bonus_tickets);
        $this->assertSame($double->id, $this->kid->worn_frame_id);
        $this->assertSame(2, OwnedCosmetic::where('profile_id', $this->kid->id)->count());
    }

    public function test_a_kid_short_of_tickets_is_refused_and_keeps_them(): void
    {
        $this->kid->update(['bonus_tickets' => 2]);

        try {
            $this->service()->buy($this->kid, $this->item('frame', 'double'));
            $this->fail('A kid with 2 tickets bought a 3-ticket frame.');
        } catch (InsufficientTicketsException) {
        }

        $this->kid->refresh();
        $this->assertSame(2, $this->kid->bonus_tickets);
        $this->assertNull($this->kid->worn_frame_id);
        $this->assertSame(0, OwnedCosmetic::count());
    }

    public function test_free_house_items_are_owned_by_everybody_and_can_be_worn(): void
    {
        $standard = $this->item('plate', 'standard');

        $this->assertTrue($this->service()->owns($this->kid, $standard));

        $this->service()->wear($this->kid, $standard);

        $this->assertSame($standard->id, $this->kid->refresh()->worn_plate_id);
        $this->assertSame(12, $this->kid->bonus_tickets);
    }

    public function test_an_unowned_item_cannot_be_worn_for_free(): void
    {
        $this->expectException(CosmeticUnavailableException::class);

        $this->service()->wear($this->kid, $this->item('frame', 'double'));
    }

    public function test_the_rotation_is_fifteen_items_and_the_same_for_the_same_week(): void
    {
        // The seeded catalog has fewer than fifteen rotating items, so add
        // enough to make the pick a real choice.
        foreach (range(1, 20) as $i) {
            Cosmetic::create([
                'household_id' => $this->household->id, 'slot' => 'frame', 'recipe' => 'hairline',
                'name' => "Extra {$i}", 'cost' => 3, 'stock' => 'weekly', 'published_at' => now(),
            ]);
        }

        $first = $this->service()->rotationThisWeek($this->household)->keys()->all();
        app()->forgetScopedInstances();
        $again = app(CosmeticService::class)->rotationThisWeek($this->household)->keys()->all();
        $nextWeek = app(CosmeticService::class)->rotationThisWeek($this->household, '2026-W39')->keys()->all();

        $this->assertCount(CosmeticService::ROTATION_SIZE, $first);
        $this->assertSame($first, $again);
        $this->assertNotSame($first, $nextWeek);
    }

    public function test_a_rotating_item_out_of_rotation_cannot_be_bought(): void
    {
        foreach (range(1, 20) as $i) {
            Cosmetic::create([
                'household_id' => $this->household->id, 'slot' => 'frame', 'recipe' => 'hairline',
                'name' => "Extra {$i}", 'cost' => 3, 'stock' => 'weekly', 'published_at' => now(),
            ]);
        }

        $rotation = $this->service()->rotationThisWeek($this->household);
        $out = Cosmetic::where('household_id', $this->household->id)
            ->where('stock', CosmeticStock::Rotating)
            ->whereNotIn('id', $rotation->keys())
            ->firstOrFail();

        $this->expectException(CosmeticUnavailableException::class);

        $this->service()->buy($this->kid, $out);
    }

    public function test_limiteds_drop_one_per_slot_and_never_come_back(): void
    {
        $limited = $this->service()->limitedThisWeek($this->household);

        // The seeded catalog has exactly one limited per slot.
        $this->assertCount(7, $limited);
        $this->assertSame(7, CosmeticDrop::where('week', '2026-W38')->count());

        $crown = $this->item('frame', 'crown');
        $this->assertTrue($this->service()->isForSale($crown));

        // A new week: the retired crown is not minted again.
        $this->travelTo(Carbon::parse('2026-09-23 12:00', 'America/Chicago'));
        app()->forgetScopedInstances();

        $this->assertCount(0, app(CosmeticService::class)->limitedThisWeek($this->household));
        $this->assertFalse(app(CosmeticService::class)->isForSale($crown->fresh()));
    }

    public function test_a_limited_bought_in_its_week_stays_owned_and_worn_afterwards(): void
    {
        $this->service()->buy($this->kid, $this->item('frame', 'crown'));

        $this->travelTo(Carbon::parse('2026-10-01 12:00', 'America/Chicago'));
        app()->forgetScopedInstances();

        $service = app(CosmeticService::class);
        $this->assertSame('crown', $service->wornIn($this->kid->fresh(), CosmeticSlot::Frame)?->recipe);
        $this->assertTrue($service->shelfFor($this->kid, CosmeticSlot::Frame)->contains('recipe', 'crown'));
    }

    public function test_a_pulled_item_stops_selling_but_stays_on_whoever_bought_it(): void
    {
        $double = $this->item('frame', 'double');
        $this->service()->buy($this->kid, $double);

        $double->update(['pulled_at' => now()]);
        app()->forgetScopedInstances();

        $sibling = Profile::factory()->for($this->household)->create(['bonus_tickets' => 20]);

        $this->assertSame($double->id, app(CosmeticService::class)->wornIn($this->kid->fresh(), CosmeticSlot::Frame)?->id);
        $this->assertFalse(app(CosmeticService::class)->shelfFor($sibling, CosmeticSlot::Frame)->contains('id', $double->id));

        $this->expectException(CosmeticUnavailableException::class);
        app(CosmeticService::class)->buy($sibling, $double->fresh());
    }

    public function test_a_draft_is_invisible_to_kids(): void
    {
        $draft = Cosmetic::create([
            'household_id' => $this->household->id, 'slot' => 'frame', 'art_path' => 'cosmetics/1/x.png',
            'name' => 'Secret Antlers', 'cost' => 2, 'stock' => 'shelf',
        ]);

        $this->assertFalse($this->service()->shelfFor($this->kid, CosmeticSlot::Frame)->contains('id', $draft->id));
        $this->assertFalse($this->service()->owns($this->kid, $draft));
    }

    public function test_another_households_item_cannot_be_bought(): void
    {
        $elsewhere = Household::factory()->create();
        $theirs = Cosmetic::where('household_id', $elsewhere->id)->where('recipe', 'double')->firstOrFail();

        $this->expectException(CosmeticUnavailableException::class);

        $this->service()->buy($this->kid, $theirs);
    }

    public function test_tapping_an_unowned_item_tries_it_on_without_spending_anything(): void
    {
        Auth::guard('profile')->login($this->kid);
        $double = $this->item('frame', 'double');

        Volt::test('kid.locker')
            ->assertSee('This week only')
            ->call('choose', $double->id)
            ->assertSee('TRYING IT ON · NOT BOUGHT YET')
            ->assertSee('Buy · 3 ✦')
            ->assertSee('recipe="double"', false)
            ->assertNotDispatched('celebrate');

        $this->kid->refresh();
        $this->assertSame(12, $this->kid->bonus_tickets);
        $this->assertNull($this->kid->worn_frame_id);
        $this->assertSame(0, OwnedCosmetic::count());
    }

    public function test_buying_from_the_try_on_bar_spends_and_puts_it_on(): void
    {
        Auth::guard('profile')->login($this->kid);
        $double = $this->item('frame', 'double');

        Volt::test('kid.locker')
            ->call('choose', $double->id)
            ->call('buyTrying')
            ->assertSee('YOURS NOW')
            ->assertDontSee('TRYING IT ON')
            ->assertDispatched('celebrate');

        $this->kid->refresh();
        $this->assertSame(9, $this->kid->bonus_tickets);
        $this->assertSame($double->id, $this->kid->worn_frame_id);
    }

    public function test_the_bought_card_only_says_everyone_sees_it_when_everyone_does(): void
    {
        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.locker')
            ->call('choose', $this->item('frame', 'double')->id)
            ->call('buyTrying')
            ->assertSee('everyone in the house can see it');

        Volt::test('kid.locker')
            ->call('choose', $this->item('theme', 'lagoon')->id)
            ->call('buyTrying')
            ->assertSee('only you see it')
            ->assertDontSee('everyone in the house can see it');
    }

    public function test_every_slot_says_who_sees_it_and_only_three_claim_everyone(): void
    {
        $public = array_filter(CosmeticSlot::cases(), fn (CosmeticSlot $slot) => str_contains($slot->seenBy(), 'everyone'));

        $this->assertEqualsCanonicalizing(
            [CosmeticSlot::Frame, CosmeticSlot::Avatar, CosmeticSlot::Plate],
            array_values($public),
        );
    }

    public function test_putting_it_back_leaves_the_kid_as_they_were(): void
    {
        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.locker')
            ->call('choose', $this->item('frame', 'double')->id)
            ->call('putBack')
            ->assertDontSee('TRYING IT ON')
            ->assertSee('THE HOUSE SEES YOUR FACE, FRAME & PLATE');

        $this->assertNull($this->kid->fresh()->worn_frame_id);
    }

    public function test_trying_on_a_theme_repaints_the_page_until_it_is_put_back(): void
    {
        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.locker')
            ->call('choose', $this->item('theme', 'ember')->id)
            ->assertSee('data-fq-theme-trial', false)
            ->assertSee('--fq-bg: #160604', false)
            ->call('putBack')
            ->assertDontSee('data-fq-theme-trial', false);
    }

    public function test_trying_on_a_cabinet_shows_it_round_a_game_screen(): void
    {
        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.locker')
            ->call('choose', $this->item('cabinet', 'chrome')->id)
            ->assertSee('data-fq-cabinet-preview', false)
            ->assertSee('<fq-cabinet', false)
            ->assertSee('recipe="chrome"', false);
    }

    public function test_trying_on_a_tap_effect_can_be_set_off_without_firing_the_worn_one(): void
    {
        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.locker')
            ->call('choose', $this->item('spark', 'bats')->id)
            ->assertSee('trigger="fq-spark-preview"', false)
            ->assertSee('See it');
    }

    public function test_a_kid_short_of_tickets_can_still_try_it_on_but_not_buy(): void
    {
        $this->kid->update(['bonus_tickets' => 0]);
        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.locker')
            ->assertSee('3 short')
            ->call('choose', $this->item('frame', 'double')->id)
            ->assertSee('TRYING IT ON')
            ->call('buyTrying')
            ->assertSee('Not enough tickets');

        $this->assertSame(0, OwnedCosmetic::count());
    }

    public function test_tapping_something_already_owned_wears_it_straight_away(): void
    {
        Auth::guard('profile')->login($this->kid);
        $standard = $this->item('plate', 'standard');

        Volt::test('kid.locker')
            ->call('choose', $standard->id)
            ->assertDontSee('TRYING IT ON');

        $this->assertSame($standard->id, $this->kid->fresh()->worn_plate_id);
    }

    public function test_a_frame_can_be_taken_off_again(): void
    {
        Auth::guard('profile')->login($this->kid);
        $this->service()->buy($this->kid, $this->item('frame', 'double'));

        Volt::test('kid.locker')
            ->assertSee('Take off')
            ->call('takeOff', 'frame');

        $this->assertNull($this->kid->fresh()->worn_frame_id);
    }

    public function test_the_flavor_chips_filter_the_shelf(): void
    {
        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.locker')
            ->call('pickFlavor', 'monster')
            ->assertSee('Fangs')
            ->assertDontSee('Paw Ring');
    }

    public function test_the_header_wears_the_face_frame_and_plate_everywhere(): void
    {
        $this->kid->update(['bonus_tickets' => 30]);
        foreach ([['avatar', 'wolf'], ['frame', 'paws'], ['plate', 'metal']] as [$slot, $recipe]) {
            $this->service()->buy($this->kid, $this->item($slot, $recipe));
        }

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.bonus')
            ->assertSee('kind="avatar"', false)
            ->assertSee('recipe="wolf"', false)
            ->assertSee('recipe="paws"', false)
            ->assertSee('<fq-plate', false);
    }

    public function test_a_worn_theme_repaints_the_kids_own_pages(): void
    {
        $this->service()->buy($this->kid, $this->item('theme', 'lagoon'));

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.bonus')
            ->assertSee('data-fq-theme', false)
            ->assertSee('--fq-bg: #04121a', false);
    }

    public function test_the_house_theme_paints_nothing_extra(): void
    {
        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.bonus')->assertDontSee('data-fq-theme', false);
    }

    public function test_the_locker_is_behind_shop_in_the_kid_rail(): void
    {
        Auth::guard('profile')->login($this->kid);

        $this->get(route('kid.locker'))
            ->assertOk()
            ->assertSee(route('kid.bonus'), false);
    }
}
