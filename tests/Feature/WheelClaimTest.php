<?php

namespace Tests\Feature;

use App\Enums\CompletionStatus;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Household;
use App\Models\Profile;
use App\Models\Spin;
use App\Services\SpinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class WheelClaimTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A household the wheel can land in predictably: one quest-eligible chore
     * to absorb the daily quest, and two the wheel draws from.
     *
     * The wheel pair carry a min_age so they can never be drawn as the day's
     * mystery chore — age-gated chores are excluded from that draw — which
     * keeps the mystery bonus out of the payout assertions below.
     *
     * @return array{0: Household, 1: Profile}
     */
    private function household(array $attributes = []): array
    {
        $household = Household::factory()->create($attributes);
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova', 'age' => 10]);

        Chore::factory()->for($household)->create(['name' => 'Main quest chore', 'quest_eligible' => true]);
        Chore::factory()->for($household)->count(2)->create([
            'quest_eligible' => false,
            'min_age' => 5,
            'points' => 100,
        ]);

        Auth::guard('profile')->login($kid);

        return [$household, $kid];
    }

    private function spin(Profile $kid): Spin
    {
        return app(SpinService::class)->spin($kid);
    }

    public function test_the_boosted_chore_can_be_claimed_from_the_wheel_page(): void
    {
        [, $kid] = $this->household();
        $boost = $this->spin($kid);

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Mark it done')
            ->call('claimBoostedChore');

        $completion = ChoreCompletion::where('profile_id', $kid->id)->firstOrFail();

        $this->assertSame($boost->chore_id, $completion->chore_id);
        $this->assertSame(CompletionStatus::Pending, $completion->status);
        // The point of claiming it here rather than on the board: the wheel's
        // multiplier is already baked into what it pays.
        $this->assertSame(100 * $boost->multiplier, $completion->points_awarded);
    }

    public function test_a_claimed_boost_reads_as_waiting_on_a_parent(): void
    {
        [, $kid] = $this->household();
        $this->spin($kid);

        Volt::test('kid.home')
            ->call('claimBoostedChore')
            ->assertSee('Waiting on a parent')
            ->assertDontSee('Mark it done');
    }

    public function test_the_boosted_claim_does_not_wait_on_the_main_quest(): void
    {
        [, $kid] = $this->household();
        $boost = $this->spin($kid);

        // The card used to keep its own copy of the board gate and refuse the
        // claim until the quest was cleared. There is no gate now, and the one
        // thing worth pinning is that no trace of it came back.
        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Mark it done')
            ->assertDontSee('Main quest first')
            ->call('claimBoostedChore');

        $completion = ChoreCompletion::where('profile_id', $kid->id)->firstOrFail();

        $this->assertSame($boost->chore_id, $completion->chore_id);
    }

    public function test_a_sibling_who_got_there_first_is_named(): void
    {
        [$household, $kid] = $this->household();
        $boost = $this->spin($kid);

        $sibling = Profile::factory()->for($household)->create(['name' => 'Rex']);

        ChoreCompletion::create([
            'chore_id' => $boost->chore_id,
            'profile_id' => $sibling->id,
            'status' => CompletionStatus::Pending,
            'points_awarded' => 100,
            'submitted_at' => now(),
        ]);

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Rex got this one')
            ->assertDontSee('Mark it done')
            ->call('claimBoostedChore');

        $this->assertSame(0, ChoreCompletion::where('profile_id', $kid->id)->count());
    }

    public function test_there_is_nothing_to_claim_before_the_wheel_is_spun(): void
    {
        $this->household();

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('No boost yet today.')
            ->assertDontSee('Mark it done');
    }

    public function test_claiming_without_a_spin_does_nothing(): void
    {
        [, $kid] = $this->household();

        Volt::test('kid.home')->call('claimBoostedChore');

        $this->assertSame(0, ChoreCompletion::where('profile_id', $kid->id)->count());
    }

    public function test_the_landed_panel_states_the_boost_as_points_not_just_a_multiplier(): void
    {
        // "3x" is arithmetic homework; the total is the thing worth getting
        // off the sofa for.
        [$household, $kid] = $this->household();

        $household->chores()->where('quest_eligible', false)->update(['points' => 175]);

        $boost = $this->spin($kid);

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee(number_format(175 * $boost->multiplier).' PTS');
    }
}
