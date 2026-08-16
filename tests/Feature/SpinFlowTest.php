<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChoreService;
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
}
