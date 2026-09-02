<?php

namespace Tests\Feature;

use App\Models\ArcadeScore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ArcadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The arcade board.
 *
 * Most of this file used to be about what a stranger could do to a game on a
 * page with no auth. The cabinet moved behind the PIN so that the board could
 * carry real names, and what is tested here moved with it: a run names the
 * person who played, a board belongs to one household, and neither of those
 * can be steered from the browser.
 */
class ArcadeTest extends TestCase
{
    use RefreshDatabase;

    private function arcade(): ArcadeService
    {
        return app(ArcadeService::class);
    }

    private function loginKid(string $name = 'Nova'): Profile
    {
        $kid = Profile::factory()->for(Household::factory())->create(['name' => $name]);

        Auth::guard('profile')->login($kid);

        return $kid;
    }

    public function test_a_run_lands_on_this_weeks_board(): void
    {
        $kid = $this->loginKid();

        Volt::test('arcade')->call('post', 23);

        $score = ArcadeScore::sole();

        $this->assertSame(23, $score->score);
        $this->assertSame($this->arcade()->currentWeek(), $score->week);
        $this->assertSame($kid->id, $score->profile_id);
        $this->assertSame($kid->household_id, $score->household_id);
    }

    public function test_the_name_on_a_run_comes_off_the_profile_and_not_the_request(): void
    {
        // The browser sends a score and nothing else. There is no argument to
        // put a name in, which is what replaced the rolled codenames: the old
        // board took two integers because it could not be allowed to take text,
        // and this one takes neither.
        $kid = $this->loginKid('Nova');

        Volt::test('arcade')->call('post', 5);

        $this->assertSame('Nova', ArcadeScore::sole()->displayName());
    }

    public function test_renaming_a_kid_renames_every_run_they_ever_posted(): void
    {
        $kid = $this->loginKid('Nova');

        Volt::test('arcade')->call('post', 9);

        $kid->update(['name' => 'Supernova']);

        // The live profile, not the snapshot beside it — the same call
        // Quote::attribution() makes, and for the same reason.
        $this->assertSame('Supernova', ArcadeScore::sole()->fresh()->displayName());
    }

    public function test_a_run_from_the_years_the_board_was_public_still_reads(): void
    {
        $kid = $this->loginKid();

        // No profile behind it, because there was none to have. These rows keep
        // their rolled codename and stay on the board rather than becoming
        // blank rows nobody can explain.
        $old = ArcadeScore::create([
            'household_id' => $kid->household_id,
            'codename' => 'SALTY RATTLE',
            'score' => 14,
            'week' => $this->arcade()->currentWeek(),
        ]);

        $this->assertSame('SALTY RATTLE', $old->displayName());

        Volt::test('arcade')->assertSee('SALTY RATTLE');
    }

    public function test_an_impossible_score_is_not_written(): void
    {
        $kid = $this->loginKid();

        // The score still arrives from the browser, so it is still a claim.
        $this->assertNull($this->arcade()->post($kid, ArcadeService::MAX_SCORE + 1));
        $this->assertNull($this->arcade()->post($kid, 0));

        $this->assertSame(0, ArcadeScore::count());
    }

    public function test_the_board_stops_listening_once_a_player_has_posted_enough(): void
    {
        $kid = $this->loginKid();

        RateLimiter::clear('arcade-post:'.$kid->id);

        $component = Volt::test('arcade');

        for ($i = 0; $i < ArcadeService::POSTS_PER_HOUR + 5; $i++) {
            $component->call('post', 3);
        }

        $this->assertSame(ArcadeService::POSTS_PER_HOUR, ArcadeScore::count());
    }

    public function test_the_board_shows_this_week_and_not_last(): void
    {
        $kid = $this->loginKid();

        // Last week's run is deliberately the smaller one: the all-time line
        // under the board would otherwise show it and this would assert nothing.
        $this->score($kid, 8, '1999-W01', 'SALTY RATTLE');
        $this->score($kid, 12, $this->arcade()->currentWeek(), 'GRIM DRIP');

        Volt::test('arcade')
            ->assertSee('GRIM DRIP')
            ->assertDontSee('SALTY RATTLE');
    }

    public function test_last_weeks_giant_still_holds_the_all_time_record(): void
    {
        $kid = $this->loginKid();

        // The weekly reset is what gives a new player a shot at the board; the
        // all-time line is what stops the reset from erasing the big run.
        $this->score($kid, 40, '1999-W01', 'SALTY RATTLE');

        Volt::test('arcade')
            ->assertSee('All-time record')
            ->assertSee('40 floors');
    }

    public function test_one_house_never_sees_another_houses_board(): void
    {
        /*
         * The reason the codenames existed, answered a different way. A shared
         * board with real names on it would have told every household in the
         * database what the others call their children; scoping every query to
         * the household is what makes putting names there safe at all.
         */
        $kid = $this->loginKid('Nova');
        $stranger = Profile::factory()->for(Household::factory())->create(['name' => 'Rook']);

        $this->score($stranger, 99, $this->arcade()->currentWeek(), 'Rook');

        Volt::test('arcade')
            ->assertDontSee('Rook')
            // Not even as the all-time record, which is the query that would
            // most easily have been left global.
            ->assertDontSee('99 floors');
    }

    public function test_a_tie_is_broken_by_who_got_there_first(): void
    {
        $kid = $this->loginKid();
        $week = $this->arcade()->currentWeek();

        $first = $this->score($kid, 9, $week);
        $second = $this->score($kid, 9, $week);

        $board = $this->arcade()->weeklyTop($kid->household);

        $this->assertSame($first->id, $board->first()->id);
        $this->assertSame($second->id, $board->last()->id);
    }

    public function test_the_board_is_a_top_ten(): void
    {
        $kid = $this->loginKid();
        $week = $this->arcade()->currentWeek();

        for ($i = 1; $i <= 14; $i++) {
            $this->score($kid, $i, $week);
        }

        $top = $this->arcade()->weeklyTop($kid->household);

        $this->assertCount(10, $top);
        $this->assertSame(14, $top->first()->score);
        $this->assertSame(5, $top->last()->score);
    }

    public function test_a_score_is_labelled_with_how_high_it_got(): void
    {
        $arcade = $this->arcade();

        $this->assertSame('On the rug', $arcade->altitude(0));
        $this->assertSame('Sofa height', $arcade->altitude(3));
        $this->assertSame('Sofa height', $arcade->altitude(5));
        $this->assertSame('Ceiling', $arcade->altitude(18));
        $this->assertSame('Outer space', $arcade->altitude(400));
    }

    public function test_the_arcade_is_no_longer_on_the_public_login_page(): void
    {
        /*
         * The cabinet stood on `/` for as long as its board held nothing about
         * anybody. It holds names now, and `/` is world-readable — so this
         * assertion is the other half of the decision to put them there.
         */
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create(['name' => 'Nova']);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('The Arcade')
            ->assertDontSee('Stack the Mess');
    }

    public function test_both_consoles_draw_the_same_cabinet(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $parent = Profile::factory()->for($household)->parent()->create(['name' => 'Dad']);

        Auth::guard('profile')->login($kid);
        Volt::test('kid.arcade')->assertOk()->assertSee('Stack');

        Auth::guard('profile')->login($parent);
        Volt::test('parent.arcade')->assertOk()->assertSee('Stack');
    }

    public function test_the_kid_cabinet_is_closed_to_parents_and_the_parent_one_to_kids(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $parent = Profile::factory()->for($household)->parent()->create();

        Auth::guard('profile')->login($parent);
        $this->get('/kid/arcade')->assertForbidden();

        Auth::guard('profile')->login($kid);
        $this->get('/parent/arcade')->assertForbidden();
    }

    private function score(Profile $profile, int $score, string $week, ?string $name = null): ArcadeScore
    {
        return ArcadeScore::create([
            'household_id' => $profile->household_id,
            'profile_id' => $name === null ? $profile->id : null,
            'codename' => $name ?? $profile->name,
            'score' => $score,
            'week' => $week,
        ]);
    }
}
