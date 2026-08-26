<?php

namespace Tests\Feature;

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
 * The arena is on the two pages kids open most, so what it costs to draw
 * matters — the sort of thing that only turns up on a slow phone months later.
 *
 * These measure the *difference* an arena makes rather than the pages' totals.
 * A total is mostly other people's work and would move every time an unrelated
 * panel changed; the delta is the thing this feature owns.
 *
 * They used to compare one monster against three. There is only ever one now,
 * which removes the multiplication these were written to catch — what is left
 * is the guard on the fixed cost, and on a kill not costing a query per kid.
 */
class MonsterQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->kid = Profile::factory()->for($this->household)->create();
        Profile::factory()->for($this->household)->create();
        Profile::factory()->parent()->for($this->household)->create();

        foreach (range(1, 6) as $index) {
            Chore::factory()->for($this->household)->create(['name' => "Chore {$index}", 'points' => 100]);
        }

        Auth::guard('profile')->login($this->kid->fresh());
    }

    private function spawn(): void
    {
        $arena = app(MonsterService::class);
        $monster = $arena->spawn($this->household, 'Weekend away', 5000);
        $arena->land($monster, 500, $this->kid);
    }

    /**
     * Queries for one render, with the page's once-a-day lazy work already
     * done — the daily quest, the mystery draw and the week's weak point all
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

    public function test_the_quest_board_pays_a_fixed_price_for_the_strip(): void
    {
        $empty = $this->queriesToRender('kid.quests');

        $this->spawn();
        $standing = $this->queriesToRender('kid.quests');

        $this->assertLessThanOrEqual(
            3,
            $standing - $empty,
            'The strip cost '.($standing - $empty).' queries — it should be the monster and its weak chore.',
        );
    }

    public function test_the_arena_page_pays_a_fixed_price_for_the_card(): void
    {
        $empty = $this->queriesToRender('kid.arena');

        $this->spawn();
        $standing = $this->queriesToRender('kid.arena');

        // The card does more than the strip — a replay and the seen marker —
        // so it gets a little room, but not an open account.
        $this->assertLessThanOrEqual(
            8,
            $standing - $empty,
            'The arena card cost '.($standing - $empty).' queries.',
        );
    }

    public function test_a_kill_does_not_cost_a_query_per_kid_beyond_paying_them(): void
    {
        $this->spawn();

        // Two more mouths to pay, and the payout is one pass over the household.
        Profile::factory()->for($this->household)->create();
        Profile::factory()->for($this->household)->create();

        $chores = app(ChoreService::class);
        $parent = $this->household->profiles()->where('role', 'parent')->sole();
        $chore = Chore::where('household_id', $this->household->id)->first();
        $completion = $chores->claim($this->kid, $chore);

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
