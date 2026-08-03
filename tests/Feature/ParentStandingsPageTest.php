<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Models\StoreItem;
use App\Services\ChoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The parent's read-only view of the game the kids are playing: who's ahead on
 * what, and what each of them is actually saving toward.
 */
class ParentStandingsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_kid_cannot_open_the_standings(): void
    {
        $kid = Profile::factory()->for(Household::factory())->create();

        $this->actingAs($kid, 'profile')
            ->get(route('parent.standings'))
            ->assertForbidden();
    }

    public function test_it_ranks_the_kids_on_the_family_goal(): void
    {
        $household = Household::factory()->create(['goal_target' => 1000, 'goal_now' => 300]);
        $parent = Profile::factory()->parent()->for($household)->create();
        Profile::factory()->for($household)->create(['name' => 'Nova', 'goal_contribution' => 200]);
        Profile::factory()->for($household)->create(['name' => 'Rue', 'goal_contribution' => 100]);

        Auth::guard('profile')->login($parent);

        $boards = Volt::test('parent.standings')
            ->assertSee('Into the family goal')
            ->assertSee('Nova')
            ->assertSee('Rue')
            ->viewData('boards');

        $goalBoard = collect($boards)->firstWhere('label', 'Into the family goal');

        $this->assertSame('Nova', $goalBoard['rows'][0]['profile']->name);
        $this->assertTrue($goalBoard['rows'][0]['isLeader']);
        $this->assertFalse($goalBoard['rows'][1]['isLeader']);
    }

    public function test_a_tie_puts_both_kids_on_top(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        Profile::factory()->for($household)->create(['name' => 'Nova', 'streak' => 4]);
        Profile::factory()->for($household)->create(['name' => 'Rue', 'streak' => 4]);

        Auth::guard('profile')->login($parent);

        $boards = Volt::test('parent.standings')->viewData('boards');
        $streaks = collect($boards)->firstWhere('label', 'Streak');

        $this->assertTrue($streaks['rows']->every(fn (array $row) => $row['isLeader']));
    }

    public function test_a_board_of_zeroes_crowns_nobody(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        Profile::factory()->for($household)->create(['name' => 'Nova', 'streak' => 0]);

        Auth::guard('profile')->login($parent);

        $boards = Volt::test('parent.standings')->viewData('boards');
        $streaks = collect($boards)->firstWhere('label', 'Streak');

        $this->assertFalse($streaks['rows'][0]['isLeader']);
    }

    public function test_it_shows_what_each_kid_is_saving_for_and_how_far_off_it_is(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $item = StoreItem::factory()->for($household)->create(['name' => 'Lego set', 'cost' => 1000]);
        Profile::factory()->for($household)->create([
            'name' => 'Nova',
            'points' => 250,
            'saving_for_store_item_id' => $item->id,
            'daily_points_goal' => 150,
        ]);

        Auth::guard('profile')->login($parent);

        $goals = Volt::test('parent.standings')
            ->assertSee('Lego set')
            ->assertSee("Nova's goal", false)
            ->viewData('goals');

        $goal = $goals->first();

        $this->assertSame(750, $goal['remaining']);
        $this->assertSame(25, $goal['percent']);
        $this->assertSame(150, $goal['dailyGoal']);
        // 750 to go at the 150 a day they've committed to.
        $this->assertSame(5, $goal['daysAtGoal']);
    }

    public function test_a_kid_with_no_saving_goal_still_gets_a_card(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        Profile::factory()->for($household)->create(['name' => 'Rue', 'saving_for_store_item_id' => null]);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.standings')
            ->assertSee("Rue's goal", false)
            ->assertSee("Hasn't picked anything to save for yet", false);
    }

    public function test_it_counts_chores_done_this_week(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $nova = Profile::factory()->for($household)->create(['name' => 'Nova']);
        Profile::factory()->for($household)->create(['name' => 'Rue']);

        $chores = app(ChoreService::class);
        $chore = Chore::factory()->for($household)->create(['cadence' => 'unlimited']);
        $chores->approve($chores->claim($nova, $chore), $parent);

        Auth::guard('profile')->login($parent);

        $boards = Volt::test('parent.standings')->viewData('boards');
        $week = collect($boards)->firstWhere('label', 'Chores this week');

        $this->assertSame('Nova', $week['rows'][0]['profile']->name);
        $this->assertSame(1, $week['rows'][0]['value']);
        $this->assertSame(0, $week['rows'][1]['value']);
    }
}
