<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Household;
use App\Models\Profile;
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
