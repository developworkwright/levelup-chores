<?php

namespace Tests\Feature;

use App\Enums\MonsterTier;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChoreService;
use App\Services\MonsterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The arena is on the two pages kids open most, and it draws three monsters
 * where there used to be one. A query hidden inside the per-monster loop
 * triples quietly and nothing fails — the sort of cost that only turns up on a
 * slow phone months later.
 *
 * These measure the *difference* between one monster standing and three, not
 * the pages' totals. A total is mostly other people's work and would move every
 * time an unrelated panel changed; the delta is the thing this feature owns,
 * and it should stay flat as monsters are added.
 */
class MonsterQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create(['require_quest_first' => false]);
        $this->kid = Profile::factory()->for($this->household)->create();
        Profile::factory()->for($this->household)->create();
        Profile::factory()->parent()->for($this->household)->create();

        foreach (range(1, 6) as $index) {
            Chore::factory()->for($this->household)->create(['name' => "Chore {$index}", 'points' => 100]);
        }

        Auth::guard('profile')->login($this->kid->fresh());
    }

    private function spawn(MonsterTier $tier): void
    {
        $arena = app(MonsterService::class);
        $monster = $arena->spawn($this->household, $tier, "Reward {$tier->value}", 5000);
        $arena->land($monster, 500, $this->kid);
    }

    /**
     * Queries for one render, with the page's once-a-day lazy work already
     * done — the daily quest, the mystery draw and the week's weak points all
     * assign themselves on first sight, and counting those would measure the
     * setup rather than the page.
     */
    private function queriesToRender(string $component): int
    {
        Volt::test($component)->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();

        Volt::test($component)->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_quest_board_costs_the_same_whether_one_monster_stands_or_three(): void
    {
        $this->spawn(MonsterTier::One);
        $one = $this->queriesToRender('kid.quests');

        $this->spawn(MonsterTier::Two);
        $this->spawn(MonsterTier::Three);
        $three = $this->queriesToRender('kid.quests');

        $this->assertLessThanOrEqual(
            2,
            $three - $one,
            'Two more monsters added '.($three - $one).' queries — that is a lookup inside the loop.',
        );
    }

    public function test_the_arena_costs_the_same_whether_one_monster_stands_or_three(): void
    {
        $this->spawn(MonsterTier::One);
        $one = $this->queriesToRender('kid.goal');

        $this->spawn(MonsterTier::Two);
        $this->spawn(MonsterTier::Three);
        $three = $this->queriesToRender('kid.goal');

        // The goal page does more per monster than the strip — a replay and a
        // contributions board — so it gets a little room, but not a multiple.
        $this->assertLessThanOrEqual(
            6,
            $three - $one,
            'Two more monsters added '.($three - $one).' queries to the arena.',
        );
    }

    public function test_a_kill_does_not_cost_a_query_per_kid_beyond_paying_them(): void
    {
        $this->spawn(MonsterTier::Three);

        // Two more mouths to pay, and the payout is one pass over the household.
        Profile::factory()->for($this->household)->create();
        Profile::factory()->for($this->household)->create();

        $chores = app(ChoreService::class);
        $parent = $this->household->profiles()->where('role', 'parent')->sole();
        $chore = Chore::where('household_id', $this->household->id)->first();
        $completion = $chores->claim($this->kid, $chore, MonsterTier::Three);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $chores->approve($completion, $parent);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Loose on purpose: approval does a great deal besides the arena. This
        // is a tripwire for "it doubled", not a specification.
        $this->assertLessThan(120, $count, "Approving ran {$count} queries.");
    }
}
