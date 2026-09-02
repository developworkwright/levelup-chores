<?php

namespace Tests\Feature;

use App\Enums\ArcadeGame;
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
 * The arcade boards.
 *
 * Most of this file used to be about what a stranger could do to a game on a
 * page with no auth. The cabinet moved behind the PIN so that the board could
 * carry real names, and what is tested here moved with it: a run names the
 * person who played, a board belongs to one household, and neither of those can
 * be steered from the browser.
 *
 * A second cabinet added a third thing that cannot be steered from the browser
 * and a first thing that must never be mixed. The component opens on whichever
 * game `ArcadeGame::default()` names, so tests that want the other one switch
 * to it the way a player would.
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

    public function test_a_run_lands_on_this_weeks_board_of_the_cabinet_on_screen(): void
    {
        $kid = $this->loginKid();

        Volt::test('arcade')->call('post', 23);

        $score = ArcadeScore::sole();

        $this->assertSame(23, $score->score);
        $this->assertSame($this->arcade()->currentWeek(), $score->week);
        $this->assertSame($kid->id, $score->profile_id);
        $this->assertSame($kid->household_id, $score->household_id);
        $this->assertSame(ArcadeGame::default(), $score->game);
    }

    public function test_the_game_a_run_is_posted_to_comes_off_the_server_and_not_the_request(): void
    {
        // The same rule as the name, for the same reason: `post()` takes a
        // score and nothing else, so a run cannot be aimed at a board it was
        // not played on. Switching cabinets is what moves the target, and that
        // is a server-side action.
        $kid = $this->loginKid();

        Volt::test('arcade')
            ->call('switchTo', ArcadeGame::StackTheMess->value)
            ->call('post', 12);

        $this->assertSame(ArcadeGame::StackTheMess, ArcadeScore::sole()->game);
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
        // blank rows nobody can explain. They are Stack the Mess scores by
        // definition — it was the only game — which is what the `game` column's
        // default says, and this is the assertion that it says it.
        $old = ArcadeScore::create([
            'household_id' => $kid->household_id,
            'codename' => 'SALTY RATTLE',
            'score' => 14,
            'week' => $this->arcade()->currentWeek(),
        ]);

        $this->assertSame('SALTY RATTLE', $old->displayName());
        $this->assertSame(ArcadeGame::StackTheMess, $old->fresh()->game);

        Volt::test('arcade')
            ->call('switchTo', ArcadeGame::StackTheMess->value)
            ->assertSee('SALTY RATTLE');
    }

    public function test_an_impossible_score_is_not_written(): void
    {
        $kid = $this->loginKid();

        // The score still arrives from the browser, so it is still a claim.
        $this->assertNull($this->arcade()->post($kid, ArcadeGame::WindyWalkies, ArcadeService::MAX_SCORE + 1));
        $this->assertNull($this->arcade()->post($kid, ArcadeGame::WindyWalkies, 0));

        $this->assertSame(0, ArcadeScore::count());
    }

    public function test_the_board_stops_listening_once_a_player_has_posted_enough(): void
    {
        $kid = $this->loginKid();
        $game = ArcadeGame::default();

        RateLimiter::clear('arcade-post:'.$kid->id.':'.$game->value);

        $component = Volt::test('arcade');

        for ($i = 0; $i < $game->postsPerHour() + 5; $i++) {
            $component->call('post', 3);
        }

        $this->assertSame($game->postsPerHour(), ArcadeScore::count());
    }

    public function test_filling_one_cabinets_hour_leaves_the_other_one_listening(): void
    {
        /*
         * The throttle is per game because a run is a very different length in
         * each: a walk can end three seconds after it starts, so the two share
         * neither a limit nor a bucket. A kid who has spent an hour on one game
         * must not find the other silently refusing to record them.
         */
        $kid = $this->loginKid();
        $walk = ArcadeGame::WindyWalkies;
        $tower = ArcadeGame::StackTheMess;

        RateLimiter::clear('arcade-post:'.$kid->id.':'.$walk->value);
        RateLimiter::clear('arcade-post:'.$kid->id.':'.$tower->value);

        $component = Volt::test('arcade')->call('switchTo', $walk->value);

        for ($i = 0; $i < $walk->postsPerHour() + 3; $i++) {
            $component->call('post', 3);
        }

        $component->call('switchTo', $tower->value)->call('post', 7);

        $this->assertSame($walk->postsPerHour(), ArcadeScore::where('game', $walk)->count());
        $this->assertSame(1, ArcadeScore::where('game', $tower)->count());
    }

    public function test_the_board_shows_this_week_and_not_last(): void
    {
        $kid = $this->loginKid();

        // Last week's run is deliberately the smaller one: the all-time line
        // under the board would otherwise show it and this would assert nothing.
        $this->score($kid, 8, '1999-W01', ArcadeGame::default(), 'SALTY RATTLE');
        $this->score($kid, 12, $this->arcade()->currentWeek(), ArcadeGame::default(), 'GRIM DRIP');

        Volt::test('arcade')
            ->assertSee('GRIM DRIP')
            ->assertDontSee('SALTY RATTLE');
    }

    public function test_one_cabinets_board_never_shows_the_others_runs(): void
    {
        /*
         * The whole reason for the `game` column. A tower is floors and a walk
         * is lanes, so a board holding both ranks numbers that are not the same
         * kind of number — and the taller one wins every week regardless of who
         * played what.
         */
        $kid = $this->loginKid();
        $week = $this->arcade()->currentWeek();

        $this->score($kid, 12, $week, ArcadeGame::WindyWalkies, 'GRIM DRIP');
        $this->score($kid, 60, $week, ArcadeGame::StackTheMess, 'SALTY RATTLE');

        $component = Volt::test('arcade');

        $component->assertSee('GRIM DRIP')->assertDontSee('SALTY RATTLE');

        $component->call('switchTo', ArcadeGame::StackTheMess->value)
            ->assertSee('SALTY RATTLE')
            ->assertDontSee('GRIM DRIP');
    }

    public function test_the_switcher_shows_each_players_own_best_on_each_cabinet(): void
    {
        // Their own rather than the house's: the number under a button you are
        // about to press should be the one you are about to try to beat.
        $kid = $this->loginKid('Nova');
        $sibling = Profile::factory()->for($kid->household)->create(['name' => 'Rook']);
        $week = $this->arcade()->currentWeek();

        $this->score($kid, 34, $week, ArcadeGame::WindyWalkies);
        $this->score($kid, 21, $week, ArcadeGame::StackTheMess);
        $this->score($sibling, 90, $week, ArcadeGame::WindyWalkies);

        Volt::test('arcade')
            ->assertSee('Best 34')
            ->assertSee('Best 21')
            ->assertDontSee('Best 90');
    }

    public function test_a_kid_who_has_never_played_a_cabinet_is_shown_a_zero_to_beat(): void
    {
        $this->loginKid();

        Volt::test('arcade')->assertSee('Best 0');
    }

    public function test_last_weeks_giant_still_holds_the_all_time_record(): void
    {
        $kid = $this->loginKid();

        // The weekly reset is what gives a new player a shot at the board; the
        // all-time line is what stops the reset erasing the big run.
        $this->score($kid, 40, '1999-W01', ArcadeGame::WindyWalkies, 'SALTY RATTLE');

        Volt::test('arcade')
            ->assertSee('All-time record')
            ->assertSee('40 lanes');
    }

    public function test_the_all_time_record_is_counted_in_the_units_of_its_own_game(): void
    {
        // A tower is floors and a walk is lanes, and the line under each board
        // says which — the one place the two boards are told apart in words.
        $kid = $this->loginKid();

        $this->score($kid, 40, '1999-W01', ArcadeGame::StackTheMess);

        Volt::test('arcade')
            ->call('switchTo', ArcadeGame::StackTheMess->value)
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

        $this->score($stranger, 99, $this->arcade()->currentWeek(), ArcadeGame::default(), 'Rook');

        Volt::test('arcade')
            ->assertDontSee('Rook')
            // Not even as the all-time record, which is the query that would
            // most easily have been left global.
            ->assertDontSee('99 lanes');
    }

    public function test_a_tie_is_broken_by_who_got_there_first(): void
    {
        $kid = $this->loginKid();
        $week = $this->arcade()->currentWeek();

        $first = $this->score($kid, 9, $week, ArcadeGame::default());
        $second = $this->score($kid, 9, $week, ArcadeGame::default());

        $board = $this->arcade()->weeklyTop($kid->household, ArcadeGame::default());

        $this->assertSame($first->id, $board->first()->id);
        $this->assertSame($second->id, $board->last()->id);
    }

    public function test_the_board_is_a_top_ten(): void
    {
        $kid = $this->loginKid();
        $week = $this->arcade()->currentWeek();

        for ($i = 1; $i <= 14; $i++) {
            $this->score($kid, $i, $week, ArcadeGame::default());
        }

        $top = $this->arcade()->weeklyTop($kid->household, ArcadeGame::default());

        $this->assertCount(10, $top);
        $this->assertSame(14, $top->first()->score);
        $this->assertSame(5, $top->last()->score);
    }

    public function test_a_score_is_labelled_with_how_far_it_got_on_its_own_ladder(): void
    {
        $arcade = $this->arcade();

        $this->assertSame('On the rug', $arcade->altitude(ArcadeGame::StackTheMess, 0));
        $this->assertSame('Sofa height', $arcade->altitude(ArcadeGame::StackTheMess, 3));
        $this->assertSame('Sofa height', $arcade->altitude(ArcadeGame::StackTheMess, 5));
        $this->assertSame('Ceiling', $arcade->altitude(ArcadeGame::StackTheMess, 18));
        $this->assertSame('Outer space', $arcade->altitude(ArcadeGame::StackTheMess, 400));

        // The same number means something else on the other cabinet, which is
        // the point of there being two ladders.
        $this->assertSame('Off the kerb', $arcade->altitude(ArcadeGame::WindyWalkies, 0));
        $this->assertSame('Over the water', $arcade->altitude(ArcadeGame::WindyWalkies, 8));
        $this->assertSame('Through the doors', $arcade->altitude(ArcadeGame::WindyWalkies, 34));
        $this->assertSame('Legendary guff', $arcade->altitude(ArcadeGame::WindyWalkies, 400));
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
            ->assertDontSee('Stack the Mess')
            ->assertDontSee('Windy Walkies');
    }

    public function test_both_consoles_draw_both_cabinets(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $parent = Profile::factory()->for($household)->parent()->create(['name' => 'Dad']);

        Auth::guard('profile')->login($kid);
        Volt::test('kid.arcade')->assertOk()->assertSee('Stack the Mess')->assertSee('Windy Walkies');

        Auth::guard('profile')->login($parent);
        Volt::test('parent.arcade')->assertOk()->assertSee('Stack the Mess')->assertSee('Windy Walkies');
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

    private function score(Profile $profile, int $score, string $week, ArcadeGame $game, ?string $name = null): ArcadeScore
    {
        return ArcadeScore::create([
            'household_id' => $profile->household_id,
            'profile_id' => $name === null ? $profile->id : null,
            'game' => $game,
            'codename' => $name ?? $profile->name,
            'score' => $score,
            'week' => $week,
        ]);
    }
}
