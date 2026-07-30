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

    public function test_the_bonus_wheel_never_lands_on_the_kids_daily_quest_chore(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(2)->create();

        $questChoreId = app(ChoreService::class)->questFor($kid)->chore_id;
        $spin = app(SpinService::class)->spin($kid);

        $this->assertNotSame($questChoreId, $spin->chore_id);
    }

    public function test_spinning_before_the_quest_is_ever_viewed_still_avoids_the_quest_chore(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(2)->create();

        // No prior call to questFor() — spin() must resolve/create it itself.
        $spin = app(SpinService::class)->spin($kid);
        $questChoreId = app(ChoreService::class)->questFor($kid)->chore_id;

        $this->assertNotSame($questChoreId, $spin->chore_id);
    }

    public function test_eligible_chores_are_uncapped_below_the_wheel_limit(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(5)->create();

        // One of the 5 gets assigned as the day's quest — 4 left over for
        // the wheel, well under the cap, so nothing should be trimmed.
        $chores = app(SpinService::class)->eligibleChoresFor($kid);

        $this->assertCount(4, $chores);
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
