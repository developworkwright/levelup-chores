<?php

namespace Tests\Feature;

use App\Enums\PerkEffect;
use App\Exceptions\PerkUnavailableException;
use App\Models\BonusPerk;
use App\Models\Household;
use App\Models\Profile;
use App\Services\BonusShopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BonusPerkCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function perk(Household $household, PerkEffect $effect): BonusPerk
    {
        return BonusPerk::where('household_id', $household->id)
            ->where('effect', $effect)
            ->firstOrFail();
    }

    public function test_every_effect_in_code_has_a_catalogue_row(): void
    {
        // The two halves have to stay in step: an effect with no row is a
        // perk nobody can buy, and a row with no effect would be unbuyable
        // in a noisier way.
        $household = Household::factory()->create();

        $rows = BonusPerk::where('household_id', $household->id)->pluck('effect')
            ->map(fn (PerkEffect $effect) => $effect->value)
            ->sort()
            ->values()
            ->all();

        $cases = collect(PerkEffect::cases())->map(fn ($c) => $c->value)->sort()->values()->all();

        $this->assertSame($cases, $rows);
    }

    public function test_a_parent_can_reprice_a_perk(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $perk = $this->perk($household, PerkEffect::WheelRespin);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.loot')
            ->call('adjustPerkCost', $perk->id, 2)
            ->call('adjustPerkCost', $perk->id, 1);

        $this->assertSame($perk->cost + 3, $perk->refresh()->cost);
    }

    public function test_a_perk_cost_never_drops_below_one(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $perk = $this->perk($household, PerkEffect::WheelRespin);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.loot')->call('adjustPerkCost', $perk->id, -500);

        $this->assertSame(1, $perk->refresh()->cost);
    }

    public function test_a_parent_can_rename_and_reword_a_perk(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $perk = $this->perk($household, PerkEffect::MysteryHint);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.loot')
            ->call('updatePerkName', $perk->id, '  Secret Clue  ')
            ->call('updatePerkDescription', $perk->id, 'A nudge in the right direction.');

        $perk->refresh();
        $this->assertSame('Secret Clue', $perk->name);
        $this->assertSame('A nudge in the right direction.', $perk->description);
        // Renaming must not disturb what the perk actually does.
        $this->assertSame(PerkEffect::MysteryHint, $perk->effect);
    }

    public function test_a_disabled_perk_leaves_the_kids_shop(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 20]);
        $perk = $this->perk($household, PerkEffect::QuestReroll);

        Auth::guard('profile')->login($parent);
        Volt::test('parent.loot')->call('togglePerk', $perk->id);

        $this->assertFalse($perk->refresh()->enabled);

        $offered = app(BonusShopService::class)->catalogFor($kid)
            ->map(fn ($entry) => $entry['perk']->effect);

        $this->assertFalse($offered->contains(PerkEffect::QuestReroll));
    }

    public function test_a_disabled_perk_cannot_be_bought_directly(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 20]);
        $perk = $this->perk($household, PerkEffect::QuestReroll);

        $perk->update(['enabled' => false]);

        $this->expectException(PerkUnavailableException::class);

        try {
            app(BonusShopService::class)->purchase($kid, $perk);
        } finally {
            $this->assertSame(20, $kid->refresh()->bonus_tickets);
        }
    }

    public function test_a_perk_from_another_household_cannot_be_bought(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 20]);

        $foreign = $this->perk(Household::factory()->create(), PerkEffect::QuestReroll);

        $this->expectException(PerkUnavailableException::class);

        try {
            app(BonusShopService::class)->purchase($kid, $foreign);
        } finally {
            $this->assertSame(20, $kid->refresh()->bonus_tickets);
        }
    }

    public function test_a_parent_cannot_edit_another_households_perk(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $foreign = $this->perk(Household::factory()->create(), PerkEffect::WheelRespin);
        $originalCost = $foreign->cost;

        Auth::guard('profile')->login($parent);

        Volt::test('parent.loot')->call('adjustPerkCost', $foreign->id, 99);

        $this->assertSame($originalCost, $foreign->refresh()->cost);
    }

    public function test_the_admin_page_lists_the_perks(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();

        Auth::guard('profile')->login($parent);

        Volt::test('parent.loot')
            ->assertSee('Bonus Shop')
            ->assertSee('Wheel Respin')
            ->assertSee('Streak Restore');
    }
}
