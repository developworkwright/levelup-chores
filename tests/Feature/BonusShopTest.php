<?php

namespace Tests\Feature;

use App\Enums\PerkEffect;
use App\Exceptions\InsufficientTicketsException;
use App\Exceptions\PerkUnavailableException;
use App\Models\BonusPerk;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Models\Spin;
use App\Services\BonusShopService;
use App\Services\ChoreService;
use App\Services\HouseholdClock;
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

    /** The catalogue row backing an effect for this kid's household. */
    private function perk(Profile $kid, PerkEffect $effect): BonusPerk
    {
        return BonusPerk::where('household_id', $kid->household_id)
            ->where('effect', $effect)
            ->firstOrFail();
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

    public function test_a_respin_clears_todays_spin_and_charges_tickets(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        $chore = Chore::factory()->for($household)->create();
        $this->spinToday($kid, $chore);

        $this->shop()->purchase($kid, $this->perk($kid, PerkEffect::WheelRespin));

        $this->assertFalse(app(SpinService::class)->hasSpunToday($kid->refresh()));
        $this->assertSame(10 - $this->perk($kid, PerkEffect::WheelRespin)->cost, $kid->bonus_tickets);
    }

    public function test_a_respin_is_refused_before_spinning(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        Chore::factory()->for($household)->create();

        $this->expectException(PerkUnavailableException::class);

        try {
            $this->shop()->purchase($kid, $this->perk($kid, PerkEffect::WheelRespin));
        } finally {
            // Nothing happened, so nothing should have been charged.
            $this->assertSame(10, $kid->refresh()->bonus_tickets);
        }
    }

    public function test_a_reroll_swaps_the_quest_chore(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        Chore::factory()->for($household)->count(4)->create();

        $before = app(ChoreService::class)->questFor($kid)->chore_id;

        $this->shop()->purchase($kid, $this->perk($kid, PerkEffect::QuestReroll));

        $after = app(ChoreService::class)->questFor($kid->refresh())->chore_id;

        $this->assertNotSame($before, $after);
        $this->assertSame(10 - $this->perk($kid, PerkEffect::QuestReroll)->cost, $kid->bonus_tickets);
    }

    public function test_a_reroll_re_hides_the_quest_so_the_chest_opens_again(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        Chore::factory()->for($household)->count(4)->create();

        $chores = app(ChoreService::class);
        $chores->revealQuest($kid);
        $this->assertTrue($chores->isQuestRevealedToday($kid));

        $this->shop()->purchase($kid, $this->perk($kid, PerkEffect::QuestReroll));

        // A new quest earns a new reveal rather than being silently relabelled.
        $this->assertFalse($chores->isQuestRevealedToday($kid->refresh()));
    }

    public function test_a_reroll_is_refused_once_the_quest_is_cleared(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        Chore::factory()->for($household)->count(3)->create();

        app(ChoreService::class)->claimQuest($kid);

        $this->expectException(PerkUnavailableException::class);

        try {
            $this->shop()->purchase($kid, $this->perk($kid, PerkEffect::QuestReroll));
        } finally {
            $this->assertSame(10, $kid->refresh()->bonus_tickets);
        }
    }

    public function test_a_reroll_is_refused_when_there_is_no_other_chore(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        // The only chore in the household is the one already assigned.
        Chore::factory()->for($household)->create();

        $this->expectException(PerkUnavailableException::class);

        try {
            $this->shop()->purchase($kid, $this->perk($kid, PerkEffect::QuestReroll));
        } finally {
            $this->assertSame(10, $kid->refresh()->bonus_tickets);
        }
    }

    public function test_buying_without_enough_tickets_is_refused_and_changes_nothing(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 1]);
        $chore = Chore::factory()->for($household)->create();
        $this->spinToday($kid, $chore);

        $this->expectException(InsufficientTicketsException::class);

        try {
            $this->shop()->purchase($kid, $this->perk($kid, PerkEffect::WheelRespin));
        } finally {
            $this->assertSame(1, $kid->refresh()->bonus_tickets);
            // The spin must survive — the perk was never applied.
            $this->assertTrue(app(SpinService::class)->hasSpunToday($kid));
        }
    }

    public function test_buying_a_perk_never_costs_xp(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create([
            'bonus_tickets' => 10,
            'xp' => Profile::XP_PER_LEVEL * 2,
        ]);
        $chore = Chore::factory()->for($household)->create();
        $this->spinToday($kid, $chore);

        $level = $kid->level();
        $this->shop()->purchase($kid, $this->perk($kid, PerkEffect::WheelRespin));

        $this->assertSame(Profile::XP_PER_LEVEL * 2, $kid->refresh()->xp);
        $this->assertSame($level, $kid->level());
    }

    public function test_the_catalog_explains_why_a_perk_is_unavailable(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 0]);
        Chore::factory()->for($household)->create();

        $catalog = $this->shop()->catalogFor($kid)->keyBy(fn ($e) => $e['perk']->effect->value);

        $respin = $catalog[PerkEffect::WheelRespin->value];
        $this->assertFalse($respin['usable']);
        $this->assertFalse($respin['affordable']);
        $this->assertSame('Spin the wheel first', $respin['reason']);
    }

    public function test_the_shop_page_shows_the_balance_and_perks(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 6]);
        Chore::factory()->for($household)->count(3)->create();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.bonus')
            ->assertSee('Bonus Shop')
            ->assertSee('Quest Reroll')
            ->assertSee('6');
    }

    public function test_buying_from_the_page_applies_the_perk(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        Chore::factory()->for($household)->count(4)->create();

        Auth::guard('profile')->login($kid);

        $before = app(ChoreService::class)->questFor($kid)->chore_id;

        Volt::test('kid.bonus')->call('buy', $this->perk($kid, PerkEffect::QuestReroll)->id);

        $this->assertNotSame($before, app(ChoreService::class)->questFor($kid->refresh())->chore_id);
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
