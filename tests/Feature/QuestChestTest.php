<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class QuestChestTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_quests_page_shows_the_streak_chest_ready_to_open(): void
    {
        $household = Household::factory()->create(['points_per_dollar' => 100]);
        $kid = Profile::factory()->for($household)->create(['streak' => 3, 'pending_streak_chest' => 3]);
        Chore::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->assertSee('Streak Chest')
            ->assertSee('Your streak paid off');
    }

    public function test_the_streak_chest_shows_the_bonus_in_points_not_dollars(): void
    {
        // STREAK_BONUSES is stored in dollars, but the kid-facing UI talks in
        // points everywhere else, so the chest converts before displaying.
        $household = Household::factory()->create(['points_per_dollar' => 100]);
        $kid = Profile::factory()->for($household)->create(['streak' => 3, 'pending_streak_chest' => 3]);
        Chore::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->assertSee('+100 PTS')
            ->assertDontSee('+$1');
    }

    public function test_the_streak_milestone_track_is_denominated_in_points(): void
    {
        $household = Household::factory()->create(['points_per_dollar' => 100]);
        $kid = Profile::factory()->for($household)->create(['streak' => 1]);
        Chore::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        // Day 3 pays $1 → 100 pts, day 30 pays $40 → 4000 pts.
        Volt::test('kid.quests')
            ->assertSee('D3 · 100', false)
            ->assertSee('D30 · 4000', false)
            ->assertDontSee('D3 · $1', false);
    }

    public function test_opening_the_streak_chest_from_the_quests_page_clears_it(): void
    {
        $household = Household::factory()->create(['points_per_dollar' => 100]);
        $kid = Profile::factory()->for($household)->create(['streak' => 3, 'pending_streak_chest' => 3]);
        Chore::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')->call('openStreakChest');

        $this->assertNull($kid->refresh()->pending_streak_chest);
    }

    public function test_the_quests_page_shows_the_come_back_tomorrow_message_when_no_chest_is_pending(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['streak' => 2]);
        Chore::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->assertSee("Complete today's quest and come back tomorrow to open the chest", false);
    }

    public function test_an_outstanding_quest_still_gets_the_full_hero(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->assertSee('Tap the chest to reveal', false)
            ->assertDontSee('Cleared');
    }

    public function test_arriving_with_the_quest_already_cleared_collapses_the_hero(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['name' => 'Water the plants']);

        Auth::guard('profile')->login($kid);

        app(ChoreService::class)->revealQuest($kid);
        app(ChoreService::class)->claimQuest($kid);

        // Landing on the tab afterwards should show a one-line summary, not
        // the full hero card pushing the chore board down the page.
        Volt::test('kid.quests')
            ->assertSee($chore->name)
            ->assertSee('Cleared')
            ->assertDontSee('Clear this one first', false)
            ->assertDontSee('Tap the chest to reveal', false);
    }

    public function test_clearing_the_quest_during_the_visit_keeps_the_hero_expanded(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        // Snapshotted at mount, so the card that was just celebrated doesn't
        // collapse out from under the kid the instant they finish it.
        Volt::test('kid.quests')
            ->call('revealQuest')
            ->call('claimQuest')
            ->assertSee('Quest cleared. Every side quest below is unlocked', false);
    }
}
