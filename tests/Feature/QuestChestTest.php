<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
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
}
