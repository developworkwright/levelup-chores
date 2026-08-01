<?php

namespace Tests\Feature;

use App\Enums\PerkEffect;
use App\Exceptions\InsufficientTicketsException;
use App\Exceptions\PerkUnavailableException;
use App\Models\BonusPerk;
use App\Models\Chore;
use App\Models\Household;
use App\Models\OwnedPerk;
use App\Models\Profile;
use App\Models\Spin;
use App\Services\BonusShopService;
use App\Services\ChoreService;
use App\Services\HouseholdClock;
use App\Services\PerkInventoryService;
use App\Services\SpinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BonusShopTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): BonusShopService
    {
        return app(BonusShopService::class);
    }

    private function inventory(): PerkInventoryService
    {
        return app(PerkInventoryService::class);
    }

    private function perk(Profile $kid, PerkEffect $effect): BonusPerk
    {
        return BonusPerk::where('household_id', $kid->household_id)
            ->where('effect', $effect)
            ->firstOrFail();
    }

    private function buy(Profile $kid, PerkEffect $effect): OwnedPerk
    {
        return $this->shop()->purchase($kid, $this->perk($kid, $effect));
    }

    private function spinToday(Profile $kid, Chore $chore, int $multiplier = 2): Spin
    {
        return Spin::create([
            'profile_id' => $kid->id,
            'spin_date' => HouseholdClock::for($kid->household)->today(),
            'chore_id' => $chore->id,
            'multiplier' => $multiplier,
        ]);
    }

    public function test_buying_grants_a_perk_without_firing_it(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        $chore = Chore::factory()->for($household)->create();
        $this->spinToday($kid, $chore);

        $this->buy($kid, PerkEffect::WheelRespin);

        // The whole point of the inventory model: the spin survives until the
        // kid decides to use what they bought.
        $this->assertTrue(app(SpinService::class)->hasSpunToday($kid->refresh()));
        $this->assertSame(1, $this->inventory()->countOf($kid, PerkEffect::WheelRespin));
        $this->assertSame(10 - $this->perk($kid, PerkEffect::WheelRespin)->cost, $kid->bonus_tickets);
    }

    public function test_a_perk_can_be_bought_long_before_it_is_usable(): void
    {
        // Nothing has been spun, so a respin can't be used yet — but buying
        // one now is exactly what holding an inventory is for.
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        Chore::factory()->for($household)->create();

        $this->buy($kid, PerkEffect::WheelRespin);

        $this->assertSame(1, $this->inventory()->countOf($kid, PerkEffect::WheelRespin));
        $this->assertSame('Spin the wheel first', $this->inventory()->blockedReason($kid, PerkEffect::WheelRespin));
    }

    public function test_using_a_respin_clears_the_spin_and_spends_the_perk(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        $chore = Chore::factory()->for($household)->create();
        $this->spinToday($kid, $chore);

        $this->buy($kid, PerkEffect::WheelRespin);
        $this->inventory()->use($kid, PerkEffect::WheelRespin);

        $this->assertFalse(app(SpinService::class)->hasSpunToday($kid->refresh()));
        $this->assertSame(0, $this->inventory()->countOf($kid, PerkEffect::WheelRespin));
    }

    public function test_a_perk_that_cannot_be_applied_stays_in_the_pocket(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        Chore::factory()->for($household)->create();

        $this->buy($kid, PerkEffect::WheelRespin);

        $this->expectException(PerkUnavailableException::class);

        try {
            $this->inventory()->use($kid, PerkEffect::WheelRespin);
        } finally {
            // A failed use must not burn the perk.
            $this->assertSame(1, $this->inventory()->countOf($kid, PerkEffect::WheelRespin));
        }
    }

    public function test_using_a_perk_you_do_not_own_is_refused(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create();

        $this->expectException(PerkUnavailableException::class);

        $this->inventory()->use($kid, PerkEffect::WheelRespin);
    }

    public function test_using_a_reroll_swaps_the_quest_and_re_hides_it(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        Chore::factory()->for($household)->count(4)->create();

        $chores = app(ChoreService::class);
        $chores->revealQuest($kid);
        $before = $chores->questFor($kid)->chore_id;

        $this->buy($kid, PerkEffect::QuestReroll);
        $this->inventory()->use($kid, PerkEffect::QuestReroll);

        $this->assertNotSame($before, $chores->questFor($kid->refresh())->chore_id);
        $this->assertFalse($chores->isQuestRevealedToday($kid));
    }

    public function test_only_one_copy_is_spent_per_use(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 20]);
        $chore = Chore::factory()->for($household)->create();
        $this->spinToday($kid, $chore);

        $this->buy($kid, PerkEffect::WheelRespin);
        $this->buy($kid, PerkEffect::WheelRespin);

        $this->inventory()->use($kid, PerkEffect::WheelRespin);

        $this->assertSame(1, $this->inventory()->countOf($kid, PerkEffect::WheelRespin));
    }

    public function test_buying_without_enough_tickets_is_refused_and_changes_nothing(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 1]);
        Chore::factory()->for($household)->create();

        $this->expectException(InsufficientTicketsException::class);

        try {
            $this->buy($kid, PerkEffect::WheelRespin);
        } finally {
            $this->assertSame(1, $kid->refresh()->bonus_tickets);
            $this->assertSame(0, OwnedPerk::where('profile_id', $kid->id)->count());
        }
    }

    public function test_buying_a_perk_never_costs_xp(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create([
            'bonus_tickets' => 10,
            'xp' => Profile::XP_PER_LEVEL * 2,
        ]);
        Chore::factory()->for($household)->create();

        $level = $kid->level();
        $this->buy($kid, PerkEffect::WheelRespin);

        $this->assertSame(Profile::XP_PER_LEVEL * 2, $kid->refresh()->xp);
        $this->assertSame($level, $kid->level());
    }

    public function test_the_catalog_reports_how_many_are_held(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 20]);
        Chore::factory()->for($household)->create();

        $this->buy($kid, PerkEffect::QuestReroll);

        $catalog = $this->shop()->catalogFor($kid->refresh())->keyBy(fn ($e) => $e['perk']->effect->value);

        $this->assertSame(1, $catalog[PerkEffect::QuestReroll->value]['owned']);
        $this->assertSame(0, $catalog[PerkEffect::WheelRespin->value]['owned']);
    }

    public function test_the_shop_page_lists_perks_and_what_is_held(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 20]);
        Chore::factory()->for($household)->count(3)->create();

        $this->buy($kid, PerkEffect::QuestReroll);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.bonus')
            ->assertSee('Bonus Shop')
            ->assertSee('Your Perks')
            ->assertSee('Quest Reroll');
    }

    public function test_buying_from_the_page_adds_to_inventory(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 20]);
        Chore::factory()->for($household)->count(4)->create();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.bonus')->call('buy', $this->perk($kid, PerkEffect::QuestReroll)->id);

        $this->assertSame(1, $this->inventory()->countOf($kid->refresh(), PerkEffect::QuestReroll));
    }

    public function test_using_from_the_page_applies_the_perk(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 20]);
        Chore::factory()->for($household)->count(4)->create();

        $before = app(ChoreService::class)->questFor($kid)->chore_id;
        $this->buy($kid, PerkEffect::QuestReroll);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.bonus')->call('usePerk', PerkEffect::QuestReroll->value);

        $this->assertNotSame($before, app(ChoreService::class)->questFor($kid->refresh())->chore_id);
        $this->assertSame(0, $this->inventory()->countOf($kid, PerkEffect::QuestReroll));
    }

    public function test_a_failed_purchase_surfaces_a_message_instead_of_erroring(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 0]);
        Chore::factory()->for($household)->count(3)->create();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.bonus')
            ->call('buy', $this->perk($kid, PerkEffect::QuestReroll)->id)
            ->assertSee('Not enough tickets')
            ->assertSuccessful();
    }

    public function test_a_parent_cannot_open_the_bonus_shop(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        Chore::factory()->for($household)->create();

        Auth::guard('profile')->login($parent);

        Volt::test('kid.bonus')->assertForbidden();
    }
}
