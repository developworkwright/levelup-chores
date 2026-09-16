<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Models\Spin;
use App\Services\BadgeService;
use App\Services\ChoreService;
use App\Services\MonsterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /** A 4x is a 3x that went further — it can't be worth less to the badges. */
    public function test_wheel_winner_awarded_after_landing_a_4x_spin(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create();

        Spin::create([
            'profile_id' => $kid->id,
            'spin_date' => now(),
            'chore_id' => $chore->id,
            'multiplier' => 4,
            'was_op' => true,
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

    public function test_team_effort_awarded_to_every_kid_when_a_monster_goes_down(): void
    {
        $household = Household::factory()->create();
        app(MonsterService::class)->spawn($household, 'Ice cream', 100);
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

    /**
     * `speed_runner` used to live here: it measured the gap between opening the
     * quest chest and claiming the card. There is no chest, so there is nothing
     * to time from, and the badge is retired rather than rewritten — see
     * BadgeService::evaluate(). Kids who won it keep it.
     */
    public function test_perfect_board_not_awarded_with_the_board_half_done(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(3)->create();

        $service = app(ChoreService::class);
        $service->approve($service->claim($kid, $household->chores->first()), $parent);

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

        foreach ($household->chores as $chore) {
            $completion = $service->claim($kid, $chore);
            $service->approve($completion, $parent);
        }

        $this->assertTrue($kid->badges()->where('key', 'perfect_board')->exists());
    }
}
