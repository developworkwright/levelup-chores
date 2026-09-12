<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Household;
use App\Models\Profile;
use App\Services\StreakService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The front door, reacting to the day behind it.
 *
 * This page never changed — the same tiles and the same numbers at breakfast and
 * at bedtime — which made it a dull thing to arrive at several times a day. The
 * tiles of kids who have put work in now wear the powered-up halo, and the sky
 * behind them runs shooting stars once anybody in the house has.
 *
 * The page is public, so what it may say is bounded: a halo reveals that a kid
 * did a chore today, which is less than the level and rank already printed
 * beside it and nothing like the real names and scores that got the arcade
 * moved behind the PIN.
 */
class LoginDoorTest extends TestCase
{
    use RefreshDatabase;

    private function kid(Household $household, string $name): Profile
    {
        return Profile::factory()->for($household)->create(['name' => $name]);
    }

    private function submitChore(Profile $kid): void
    {
        $chore = Chore::factory()->for($kid->household)->create();

        ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $kid->id,
            'status' => 'pending',
            'points_awarded' => 100,
            'submitted_at' => now(),
        ]);
    }

    public function test_a_quiet_house_gets_no_halo_and_no_stars(): void
    {
        $household = Household::factory()->create();
        $this->kid($household, 'Scout');

        Volt::test('login')
            ->assertDontSee('fq-powered-token')
            ->assertDontSee('fq-shooting-star');
    }

    public function test_a_kid_who_worked_today_wears_the_halo(): void
    {
        $household = Household::factory()->create();
        $scout = $this->kid($household, 'Scout');

        $this->submitChore($scout);

        Volt::test('login')
            ->assertSee('fq-powered-token')
            ->assertSee('fq-shooting-star');
    }

    /**
     * One kid working lights their own tile and the sky — not the whole row.
     * The halo is the thing on this page worth having, so it has to be
     * possible not to have it.
     */
    public function test_only_the_kid_who_worked_is_lit(): void
    {
        $household = Household::factory()->create();
        $scout = $this->kid($household, 'Scout');
        $this->kid($household, 'Nova');

        $this->submitChore($scout);

        $rendered = Volt::test('login')->html();

        $this->assertSame(1, substr_count($rendered, 'fq-powered-token'));
    }

    public function test_a_kid_with_no_run_has_no_fire(): void
    {
        $household = Household::factory()->create();
        $this->kid($household, 'Scout')->forceFill(['streak' => 0])->save();

        Volt::test('login')->assertDontSee('fq-streak-fire');
    }

    public function test_a_run_burns(): void
    {
        $household = Household::factory()->create();
        $this->kid($household, 'Scout')->forceFill(['streak' => 4])->save();

        Volt::test('login')
            ->assertSee('fq-streak-fire')
            ->assertSee('--fq-fire-tier: 2', false);
    }

    public function test_a_longer_run_burns_harder(): void
    {
        $household = Household::factory()->create();
        $this->kid($household, 'Scout')->forceFill(['streak' => 40])->save();

        Volt::test('login')->assertSee('--fq-fire-tier: 6', false);
    }

    /**
     * The steps are the milestone days, not a schedule of their own, so the
     * flame grows on exactly the mornings a chest is waiting. A ladder invented
     * separately would have the fire growing on days nothing happens, which
     * teaches the wrong thing about which days matter.
     */
    public function test_the_fire_grows_on_the_days_a_chest_lands(): void
    {
        $streaks = app(StreakService::class);

        foreach (array_keys(StreakService::STREAK_BONUSES) as $milestone) {
            $this->assertGreaterThan(
                $streaks->fireTier($milestone - 1),
                $streaks->fireTier($milestone),
                "The fire does not grow on day {$milestone}, which is a chest day."
            );
        }
    }

    public function test_the_fire_holds_at_the_top_rather_than_climbing_forever(): void
    {
        $streaks = app(StreakService::class);

        $this->assertSame(6, $streaks->fireTier(30));
        $this->assertSame(6, $streaks->fireTier(365));
        $this->assertSame(0, $streaks->fireTier(0));
    }

    /**
     * The fire is a sibling of the tile, never a child. As a child it would need
     * a negative z-index, and a negative-z child paints *over* its parent's own
     * background — which would put flames across the middle of the avatar
     * rather than behind it.
     */
    public function test_the_fire_sits_behind_the_tile_rather_than_inside_it(): void
    {
        $household = Household::factory()->create();
        $this->kid($household, 'Scout')->forceFill(['streak' => 9])->save();

        $rendered = Volt::test('login')->html();

        $this->assertLessThan(
            strpos($rendered, 'fq-avatar-tile'),
            strpos($rendered, 'fq-streak-fire'),
            'The fire is no longer rendered ahead of the tile it sits behind.'
        );

        // And the tile has to keep the stacking order that puts it on top.
        $this->assertStringContainsString('fq-avatar-tile relative z-10', $rendered);
    }

    /** The row is alive before anything has been read. */
    public function test_the_tiles_drift(): void
    {
        $household = Household::factory()->create();
        $this->kid($household, 'Scout');

        Volt::test('login')->assertSee('fq-avatar-idle');
    }

    /**
     * The fan's tilt is carried on the tile's own transform, and the bob rides
     * the anchor — if they ever end up on the same element the keyframe wins
     * and the fan flattens.
     */
    public function test_the_bob_and_the_tilt_stay_on_different_elements(): void
    {
        $household = Household::factory()->create();
        $this->kid($household, 'Scout');
        $this->kid($household, 'Nova');

        $rendered = Volt::test('login')->html();

        $this->assertStringNotContainsString('fq-avatar-tile fq-avatar-idle', $rendered);
        $this->assertStringNotContainsString('fq-avatar-idle fq-avatar-tile', $rendered);
    }
}
