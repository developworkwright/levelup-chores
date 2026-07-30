<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\Profile;
use App\Models\Spin;
use App\Services\BadgeService;
use App\Services\ChoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_busy_bee_awarded_after_more_than_three_chores_approved_same_day(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        $chores = Chore::factory()->for($household)->count(5)->create(['points' => 10]);

        $service = app(ChoreService::class);
        foreach ($chores->take(4) as $chore) {
            $completion = $service->claim($kid, $chore);
            $service->approve($completion, $parent);
        }

        $this->assertTrue($kid->badges()->where('key', 'busy_bee')->exists());
    }

    public function test_busy_bee_not_awarded_with_only_three_chores(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        $chores = Chore::factory()->for($household)->count(3)->create(['points' => 10]);

        $service = app(ChoreService::class);
        foreach ($chores as $chore) {
            $completion = $service->claim($kid, $chore);
            $service->approve($completion, $parent);
        }

        $this->assertFalse($kid->badges()->where('key', 'busy_bee')->exists());
    }

    public function test_big_saver_awarded_when_balance_reaches_threshold(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['points' => 500]);

        app(BadgeService::class)->evaluate($kid);

        $this->assertTrue($kid->badges()->where('key', 'big_saver')->exists());
    }

    public function test_wheel_winner_awarded_after_landing_a_3x_spin(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create();

        Spin::create([
            'profile_id' => $kid->id,
            'spin_date' => now(),
            'chore_id' => $chore->id,
            'multiplier' => 3,
        ]);

        app(BadgeService::class)->evaluate($kid);

        $this->assertTrue($kid->badges()->where('key', 'wheel_winner')->exists());
    }

    public function test_wheel_winner_not_awarded_for_a_2x_spin(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create();

        Spin::create([
            'profile_id' => $kid->id,
            'spin_date' => now(),
            'chore_id' => $chore->id,
            'multiplier' => 2,
        ]);

        app(BadgeService::class)->evaluate($kid);

        $this->assertFalse($kid->badges()->where('key', 'wheel_winner')->exists());
    }

    public function test_team_effort_awarded_to_every_kid_when_family_goal_is_reached(): void
    {
        $household = Household::factory()->create(['goal_target' => 100, 'goal_now' => 0]);
        $parent = Profile::factory()->parent()->for($household)->create();
        $kidA = Profile::factory()->for($household)->create();
        $kidB = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['points' => 100]);

        $service = app(ChoreService::class);
        $completion = $service->claim($kidA, $chore);
        $service->approve($completion, $parent);

        $this->assertTrue($kidA->badges()->where('key', 'team_effort')->exists());
        $this->assertTrue($kidB->badges()->where('key', 'team_effort')->exists());
    }

    public function test_speed_runner_awarded_when_quest_claimed_quickly_after_reveal(): void
    {
        // Pinned well clear of the household's day-boundary hour (default
        // 4am) — near midnight UTC, raw now() and HouseholdClock::today()
        // land on different calendar dates, which broke this test purely
        // on wall-clock timing rather than anything about the badge logic.
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00', 'UTC'));

        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create();

        DailyQuest::create([
            'household_id' => $household->id,
            'profile_id' => $kid->id,
            'chore_id' => $chore->id,
            'quest_date' => now(),
            'revealed_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);

        app(BadgeService::class)->evaluate($kid);

        $this->assertTrue($kid->badges()->where('key', 'speed_runner')->exists());

        Carbon::setTestNow();
    }

    public function test_speed_runner_not_awarded_when_quest_claimed_slowly(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create();

        DailyQuest::create([
            'household_id' => $household->id,
            'profile_id' => $kid->id,
            'chore_id' => $chore->id,
            'quest_date' => now(),
            'revealed_at' => now()->subHour(),
            'completed_at' => now(),
        ]);

        app(BadgeService::class)->evaluate($kid);

        $this->assertFalse($kid->badges()->where('key', 'speed_runner')->exists());
    }

    public function test_perfect_board_not_awarded_with_only_the_main_quest_done(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(3)->create();

        app(ChoreService::class)->claimQuest($kid);
        app(BadgeService::class)->evaluate($kid);

        $this->assertFalse($kid->badges()->where('key', 'perfect_board')->exists());
    }

    public function test_perfect_board_awarded_when_every_chore_is_approved_same_day(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(3)->create();

        $service = app(ChoreService::class);
        $quest = $service->claimQuest($kid);

        foreach ($household->chores as $chore) {
            if ($chore->id === $quest->chore_id) {
                continue;
            }

            $completion = $service->claim($kid, $chore);
            $service->approve($completion, $parent);
        }

        $this->assertTrue($kid->badges()->where('key', 'perfect_board')->exists());
    }
}
