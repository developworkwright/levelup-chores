<?php

namespace Tests\Feature;

use App\Enums\ArcadeGame;
use App\Models\Profile;
use App\Services\ArcadeService;
use App\Services\StreakService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The arcade is never gated on chores.
 *
 * This file is the guard rail on a decision that was made, tried and then
 * deliberately reversed. A shutter was briefly built here — board visible,
 * games locked until a chore went in — and it was the wrong instrument: a
 * locked door tells a kid who has already drifted to go away, which is the
 * opposite of what the whole exercise is for. The day's work now turns extras
 * *on* instead, and never turns the app off.
 *
 * So what is asserted below is an absence. If any of it starts failing,
 * somebody has reintroduced a door and should have to argue for it.
 *
 * The rule itself — what counts as having worked today — lives with the other
 * day-rules in StreakService, and is covered by PoweredUpDayTest.
 */
class ArcadeChoreGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_kid_who_has_done_nothing_today_can_still_post_a_score(): void
    {
        $kid = Profile::factory()->create();

        $this->assertFalse(app(StreakService::class)->hasWorkedToday($kid));

        $this->assertNotNull(
            app(ArcadeService::class)->post($kid, ArcadeGame::ranked()[0], 50)
        );
    }

    public function test_a_kid_who_has_done_nothing_today_still_sees_the_games(): void
    {
        $kid = Profile::factory()->create();

        $this->actingAs($kid, 'profile')
            ->get(route('kid.arcade'))
            ->assertOk()
            ->assertDontSee('The arcade is shut')
            ->assertSee(ArcadeGame::default()->label());
    }
}
