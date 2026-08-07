<?php

namespace Tests\Feature;

use App\Enums\ChoreCadence;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The board is ordered by urgency first — one-time chores, then anything on a
 * parent's clock — and by payout below that, so the rest of the list answers
 * "what's the biggest thing I could do right now?" without any scrolling.
 */
class BoardOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): ChoreService
    {
        return app(ChoreService::class);
    }

    /**
     * Boards exclude whichever chore became today's quest, so fixtures need one
     * quest-eligible chore to absorb the assignment.
     */
    private function household(): Household
    {
        $household = Household::factory()->create(['require_quest_first' => false]);

        Chore::factory()->for($household)->create([
            'name' => 'The quest',
            'quest_eligible' => true,
        ]);

        return $household;
    }

    private function chore(Household $household, string $name, int $points, array $attributes = []): Chore
    {
        return Chore::factory()->for($household)->create($attributes + [
            'name' => $name,
            'points' => $points,
            'quest_eligible' => false,
        ]);
    }

    /** @return array<int, string> */
    private function boardNames(Profile $kid): array
    {
        return $this->service()->boardFor($kid)
            ->map(fn (array $entry) => $entry['chore']->name)
            ->all();
    }

    public function test_the_board_is_ordered_by_payout(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();

        // Created in a deliberately unhelpful order — insertion order is what
        // the board used to fall back on.
        $this->chore($household, 'Middling', 100);
        $this->chore($household, 'Peanuts', 25);
        $this->chore($household, 'Worth it', 400);

        $this->assertSame(['Worth it', 'Middling', 'Peanuts'], $this->boardNames($kid));
    }

    public function test_a_one_time_chore_outranks_a_better_paying_regular(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();

        $this->chore($household, 'Big daily', 500);
        $this->chore($household, 'Small one-time', 50, ['cadence' => ChoreCadence::Once]);

        // Points don't buy your way past a chore that's gone the moment a
        // sibling taps it.
        $this->assertSame(['Small one-time', 'Big daily'], $this->boardNames($kid));
    }

    public function test_a_chore_on_a_deadline_sits_above_the_regulars(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();

        $this->chore($household, 'Big daily', 500);
        $this->chore($household, 'Closing soon', 50, ['expires_at' => now()->addHour()]);

        $this->assertSame(['Closing soon', 'Big daily'], $this->boardNames($kid));
    }

    public function test_one_time_chores_still_lead_a_closing_one(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();

        $this->chore($household, 'Closing soon', 500, ['expires_at' => now()->addHour()]);
        $this->chore($household, 'One-time', 50, ['cadence' => ChoreCadence::Once]);

        $this->assertSame(['One-time', 'Closing soon'], $this->boardNames($kid));
    }

    public function test_payout_orders_within_a_tier_too(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();

        $this->chore($household, 'Cheap one-time', 50, ['cadence' => ChoreCadence::Once]);
        $this->chore($household, 'Rich one-time', 300, ['cadence' => ChoreCadence::Once]);
        $this->chore($household, 'A daily', 100);

        $this->assertSame(['Rich one-time', 'Cheap one-time', 'A daily'], $this->boardNames($kid));
    }

    public function test_a_closed_chore_drops_out_of_the_urgent_tier(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();

        // A passed deadline is no longer a race — there's nothing left to hurry
        // for, so it takes its place among the regulars on points alone.
        $this->chore($household, 'Time ran out', 50, ['expires_at' => now()->subHour()]);
        $this->chore($household, 'Still going', 100);

        $this->assertSame(['Still going', 'Time ran out'], $this->boardNames($kid));
    }
}
