<?php

namespace Tests\Feature;

use App\Enums\PerkEffect;
use App\Exceptions\PerkUnavailableException;
use App\Models\BonusPerk;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\BonusShopService;
use App\Services\ChoreService;
use App\Services\PerkInventoryService;
use App\Services\SpinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpinFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The quest is a hand of cards until one is taken, so the wheel has to
     * clear all of them rather than the single chore the quest row points at —
     * any of them might turn out to be the quest.
     */
    public function test_the_bonus_wheel_never_lands_on_a_card_in_the_kids_quest_hand(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(6)->create();

        $hand = app(ChoreService::class)->questFor($kid)->offeredChoreIds();
        $spin = app(SpinService::class)->spin($kid);

        $this->assertNotContains($spin->chore_id, $hand);
    }

    public function test_spinning_before_the_quest_is_ever_viewed_still_avoids_the_hand(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(6)->create();

        // No prior call to questFor() — spin() must resolve/create it itself.
        $spin = app(SpinService::class)->spin($kid);
        $hand = app(ChoreService::class)->questFor($kid)->offeredChoreIds();

        $this->assertNotContains($spin->chore_id, $hand);
    }

    public function test_eligible_chores_are_uncapped_below_the_wheel_limit(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(8)->create();

        // Three of the 8 are dealt as the day's quest hand — 5 left over for
        // the wheel, well under the cap, so nothing should be trimmed.
        $chores = app(SpinService::class)->eligibleChoresFor($kid);

        $this->assertCount(8 - ChoreService::HAND_SIZE, $chores);
    }

    public function test_eligible_chores_are_capped_and_stable_across_repeated_calls(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(20)->create();

        $service = app(SpinService::class);
        $first = $service->eligibleChoresFor($kid)->pluck('id');
        $second = $service->eligibleChoresFor($kid)->pluck('id');

        $this->assertCount(SpinService::MAX_WHEEL_CHORES, $first);
        $this->assertTrue($first->values()->all() === $second->values()->all());
    }

    public function test_the_landed_on_chore_is_always_present_in_the_capped_wheel_list_afterward(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(25)->create();

        $service = app(SpinService::class);
        $spin = $service->spin($kid);

        // Re-fetching after the spin exists must still include the winner,
        // even though the deterministic shuffle alone might not have picked
        // it — the wheel can never show a set that omits its own result.
        $afterSpin = $service->eligibleChoresFor($kid);

        $this->assertTrue($afterSpin->contains('id', $spin->chore_id));
        $this->assertLessThanOrEqual(SpinService::MAX_WHEEL_CHORES, $afterSpin->count());
    }

    /**
     * The charged table is the whole of what the ticket buys: 4x exists on it
     * and nowhere else. Rolled repeatedly rather than seeded, so this fails if
     * a 4x is ever wired onto the free spin.
     */
    public function test_only_a_charged_wheel_can_land_a_4x(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(6)->create();

        $service = app(SpinService::class);
        $charged = [];
        $plain = [];

        for ($i = 0; $i < 80; $i++) {
            $kid->spins()->delete();
            $service->charge($kid->refresh());
            $charged[] = $service->spin($kid->refresh())->multiplier;

            $kid->spins()->delete();
            $plain[] = $service->spin($kid->refresh())->multiplier;
        }

        $this->assertEmpty(array_diff($charged, [2, 3, 4]));
        $this->assertContains(4, $charged);
        $this->assertEmpty(array_diff($plain, [2, 3]));
    }

    public function test_the_charge_is_spent_by_the_spin_and_recorded_on_it(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(6)->create();

        $service = app(SpinService::class);

        $this->assertTrue($service->charge($kid));
        $this->assertTrue($service->isCharged($kid->refresh()));

        $spin = $service->spin($kid);

        $this->assertTrue($spin->was_op);
        $this->assertFalse($service->isCharged($kid->refresh()));
    }

    /**
     * A respin is a fresh spin, not a re-roll of the one that was paid for.
     * Handing the charge back would let a kid ride the 4x table until it paid.
     */
    public function test_a_respin_does_not_hand_the_op_charge_back(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 20]);
        Chore::factory()->for($household)->count(6)->create();

        $service = app(SpinService::class);
        $service->charge($kid);
        $service->spin($kid->refresh());

        $perk = BonusPerk::where('household_id', $household->id)
            ->where('effect', PerkEffect::WheelRespin)
            ->firstOrFail();

        app(BonusShopService::class)->purchase($kid->refresh(), $perk);
        app(PerkInventoryService::class)->use($kid->refresh(), PerkEffect::WheelRespin);

        $this->assertFalse($service->isCharged($kid->refresh()));

        $second = $service->spin($kid->refresh());

        $this->assertFalse($second->was_op);
        $this->assertContains($second->multiplier, [2, 3]);
    }

    /** Charges don't stack, and a perk that couldn't be applied isn't spent. */
    public function test_a_second_charge_is_refused_and_stays_in_the_pocket(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 20]);
        Chore::factory()->for($household)->count(6)->create();

        $perk = BonusPerk::where('household_id', $household->id)
            ->where('effect', PerkEffect::OpSpin)
            ->firstOrFail();

        $shop = app(BonusShopService::class);
        $inventory = app(PerkInventoryService::class);

        $shop->purchase($kid, $perk);
        $shop->purchase($kid->refresh(), $perk);

        $inventory->use($kid->refresh(), PerkEffect::OpSpin);

        $this->assertSame(
            'The wheel is already charged',
            $inventory->blockedReason($kid->refresh(), PerkEffect::OpSpin),
        );

        try {
            $inventory->use($kid, PerkEffect::OpSpin);
            $this->fail('A second charge should have been refused.');
        } catch (PerkUnavailableException) {
            $this->assertSame(1, $inventory->countOf($kid, PerkEffect::OpSpin));
        }
    }

    /** The charge goes on before the wheel does, or the ticket buys nothing today. */
    public function test_the_wheel_cannot_be_charged_after_it_has_been_spun(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(6)->create();

        app(SpinService::class)->spin($kid);

        $this->assertSame(
            'Charge the wheel before you spin',
            app(PerkInventoryService::class)->blockedReason($kid->refresh(), PerkEffect::OpSpin),
        );
    }
}
