<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\BadgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BadgeXpTest extends TestCase
{
    use RefreshDatabase;

    public function test_earning_a_badge_awards_its_xp(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['xp' => 0, 'streak' => 3]);
        Chore::factory()->for($household)->create();

        $reward = Badge::where('key', 'streak_3')->firstOrFail()->xp_reward;
        $this->assertGreaterThan(0, $reward);

        app(BadgeService::class)->evaluate($kid);

        $this->assertSame($reward, $kid->refresh()->xp);
    }

    public function test_a_badge_pays_its_xp_only_once(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['xp' => 0, 'streak' => 3]);
        Chore::factory()->for($household)->create();

        $service = app(BadgeService::class);
        $service->evaluate($kid);
        $xpAfterFirst = $kid->refresh()->xp;

        // evaluate() is called after every approval, so re-running it must not
        // keep topping up XP for a badge already hanging on the wall.
        $service->evaluate($kid);
        $service->evaluate($kid);

        $this->assertSame($xpAfterFirst, $kid->refresh()->xp);
        $this->assertSame(1, $kid->badges()->where('key', 'streak_3')->count());
    }

    public function test_badge_xp_can_push_a_kid_up_a_level(): void
    {
        $household = Household::factory()->create();
        // One XP short of level 2, so a single badge tips it over.
        $kid = Profile::factory()->for($household)->create([
            'xp' => Profile::XP_PER_LEVEL - 1,
            'streak' => 3,
        ]);
        Chore::factory()->for($household)->create();

        $this->assertSame(1, $kid->level());

        app(BadgeService::class)->evaluate($kid);

        $this->assertSame(2, $kid->refresh()->level());
    }

    public function test_every_badge_carries_an_xp_reward(): void
    {
        $this->assertSame(0, Badge::where('xp_reward', '<=', 0)->count());
    }

    public function test_the_full_badge_set_is_worth_a_meaningful_number_of_levels(): void
    {
        // Guards the tiering as a whole: if someone zeroes a reward or fat
        // fingers an extra digit, the total moves off this range.
        $total = Badge::sum('xp_reward');

        $this->assertGreaterThanOrEqual(2000, $total);
        $this->assertLessThanOrEqual(2500, $total);
    }

    public function test_level_and_xp_bar_track_the_curve_constant(): void
    {
        $household = Household::factory()->create();
        $step = Profile::XP_PER_LEVEL;

        $fresh = Profile::factory()->for($household)->create(['xp' => 0]);
        $this->assertSame(1, $fresh->level());
        $this->assertSame(0.0, $fresh->xpBarPercent());

        $halfway = Profile::factory()->for($household)->create(['xp' => (int) ($step / 2)]);
        $this->assertSame(1, $halfway->level());
        $this->assertSame(50.0, $halfway->xpBarPercent());

        $levelled = Profile::factory()->for($household)->create(['xp' => $step * 3]);
        $this->assertSame(4, $levelled->level());
        $this->assertSame(0.0, $levelled->xpBarPercent());
    }
}
