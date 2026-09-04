<?php

namespace Tests\Feature;

use App\Enums\ArcadeGame;
use App\Enums\ProfileRole;
use App\Models\ArcadeScore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ArcadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The arcade boards.
 *
 * Most of this file used to be about what a stranger could do to a game on a
 * page with no auth. The game moved behind the PIN so that the board could
 * carry real names, and what is tested here moved with it: a run names the
 * person who played, a board belongs to one household, and neither of those can
 * be steered from the browser.
 *
 * A second game added a third thing that cannot be steered from the browser
 * and a first thing that must never be mixed. The component opens on whichever
 * game `ArcadeGame::default()` names, so tests that want another one switch to
 * it the way a player would — `openOn()` below. Doing that explicitly rather
 * than leaning on the default is what stops the next game added from breaking
 * every board assertion in this file.
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

    /**
     * The arcade component, showing one named game.
     *
     * The rail is server-side state, so this is the same round trip a player
     * makes when they tap a game — it is not a way of reaching in and setting a
     * property the browser is not allowed to set.
     */
    private function openOn(ArcadeGame $game): Testable
    {
        return Volt::test('arcade')->call('switchTo', $game->value);
    }

    private function loginParent(string $name = 'Mum'): Profile
    {
        $parent = Profile::factory()
            ->for(Household::factory())
            ->create(['name' => $name, 'role' => ProfileRole::Parent]);

        Auth::guard('profile')->login($parent);

        return $parent;
    }

    public function test_a_run_lands_on_this_weeks_board_of_the_game_on_screen(): void
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
        // not played on. Switching games is what moves the target, and that
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

        // The score still arrives from the browser, so it is still a claim —
        // and the ceiling it is measured against belongs to the game it claims
        // to come off, because the games do not count the same sort of number.
        foreach (ArcadeGame::ranked() as $game) {
            $this->assertNull($this->arcade()->post($kid, $game, $game->maxScore() + 1));
            $this->assertNull($this->arcade()->post($kid, $game, 0));
        }

        $this->assertSame(0, ArcadeScore::count());
    }

    public function test_a_long_flight_is_believed_where_an_equally_long_tower_would_not_be(): void
    {
        /*
         * The reason the ceiling moved onto the game. A flight earns points a
         * dozen a second, so four figures is a good long run rather than a
         * tampered request — while the same number of floors is several times
         * further than anybody has ever stacked. One ceiling for both would
         * have to either wave the tower through or drop the flight, and
         * dropping it is silent: the kid is never told, and the run they were
         * proudest of is the one that vanishes.
         */
        $kid = $this->loginKid();
        $flight = 1400;

        $this->assertNotNull($this->arcade()->post($kid, ArcadeGame::GrandTour, $flight));
        $this->assertNull($this->arcade()->post($kid, ArcadeGame::StackTheMess, $flight));

        $this->assertSame(1, ArcadeScore::count());
        $this->assertSame(ArcadeGame::GrandTour, ArcadeScore::sole()->game);
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

    public function test_filling_one_games_hour_leaves_the_other_listening(): void
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

    public function test_one_games_board_never_shows_anothers_runs(): void
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

        $component = $this->openOn(ArcadeGame::WindyWalkies);

        $component->assertSee('GRIM DRIP')->assertDontSee('SALTY RATTLE');

        $component->call('switchTo', ArcadeGame::StackTheMess->value)
            ->assertSee('SALTY RATTLE')
            ->assertDontSee('GRIM DRIP');
    }

    public function test_the_rail_shows_this_weeks_leader_on_every_game(): void
    {
        // The house's rather than the reader's, which is the whole point of the
        // rail: the number under a button nobody has pressed yet is the number
        // somebody is currently winning with, so the list of games reads as a
        // standings glance before anything has been opened.
        $kid = $this->loginKid('Nova');
        $sibling = Profile::factory()->for($kid->household)->create(['name' => 'Rook']);
        $week = $this->arcade()->currentWeek();

        $this->score($kid, 34, $week, ArcadeGame::WindyWalkies);
        $this->score($kid, 21, $week, ArcadeGame::StackTheMess);
        $this->score($sibling, 90, $week, ArcadeGame::WindyWalkies);

        Volt::test('arcade')
            ->assertSee('best 90')
            ->assertSee('best 21')
            // Nova's own walk is not the walk to beat, so the rail never says
            // it — the reader's own number lives in the all-time block instead.
            ->assertDontSee('best 34');
    }

    public function test_a_game_nobody_has_played_this_week_says_so_rather_than_showing_a_zero(): void
    {
        // "Nobody yet" is an invitation and "best 0" is a scoreboard.
        $this->loginKid();

        Volt::test('arcade')
            ->assertSee('nobody yet')
            ->assertDontSee('best 0');
    }

    public function test_your_own_record_is_the_all_time_block_rather_than_the_rail(): void
    {
        $kid = $this->loginKid('Nova');
        $sibling = Profile::factory()->for($kid->household)->create(['name' => 'Rook']);

        $this->score($kid, 34, '1999-W01', ArcadeGame::WindyWalkies);
        $this->score($sibling, 90, '1999-W01', ArcadeGame::WindyWalkies);

        $this->openOn(ArcadeGame::WindyWalkies)
            ->assertSee('House')
            ->assertSee('90 lanes')
            ->assertSee('Yours')
            ->assertSee('34 lanes');
    }

    public function test_the_target_strip_names_the_score_that_takes_the_week(): void
    {
        // One more than the leader, because a tie keeps the incumbent — a
        // target of "equal it" would be a lie about how the week is settled.
        $kid = $this->loginKid('Nova');
        $sibling = Profile::factory()->for($kid->household)->create(['name' => 'Rook']);

        $this->score($sibling, 38, $this->arcade()->currentWeek(), ArcadeGame::WindyWalkies);

        $this->openOn(ArcadeGame::WindyWalkies)
            ->assertSee('Beat')
            ->assertSee('39')
            ->assertSee('for 3 tickets');
    }

    public function test_a_kid_already_on_top_is_told_they_are_leading_rather_than_to_beat_themselves(): void
    {
        $kid = $this->loginKid('Nova');

        $this->score($kid, 38, $this->arcade()->currentWeek(), ArcadeGame::WindyWalkies);

        $this->openOn(ArcadeGame::WindyWalkies)
            ->assertSee('Leading')
            ->assertSee('3 tickets if it holds')
            ->assertDontSee('Beat');
    }

    public function test_a_grown_up_is_never_promised_tickets_by_the_target_strip(): void
    {
        // They can top the week and get nothing for it — see ArcadeService.
        $parent = $this->loginParent();

        $this->score($parent, 38, $this->arcade()->currentWeek(), ArcadeGame::WindyWalkies);

        $this->openOn(ArcadeGame::WindyWalkies)
            ->assertSee('Leading')
            ->assertSee('hold it to take the week')
            ->assertDontSee('tickets if it holds');
    }

    public function test_the_board_shows_each_player_once_rather_than_their_best_three_runs(): void
    {
        // A board of runs lets one kid having a good evening fill all three
        // rows with their own name, and the column meant to say who is winning
        // then says nothing at all.
        $kid = $this->loginKid('Nova');
        $sibling = Profile::factory()->for($kid->household)->create(['name' => 'Rook']);
        $week = $this->arcade()->currentWeek();

        $this->score($kid, 30, $week, ArcadeGame::WindyWalkies);
        $this->score($kid, 28, $week, ArcadeGame::WindyWalkies);
        $this->score($kid, 26, $week, ArcadeGame::WindyWalkies);
        $this->score($sibling, 12, $week, ArcadeGame::WindyWalkies);

        $standings = $this->arcade()->weeklyStandings($kid->household, ArcadeGame::WindyWalkies);

        $this->assertCount(2, $standings);
        $this->assertSame(30, $standings->first()->score);
        $this->assertSame(12, $standings->last()->score);

        // Rook is last on a board of two, so they survive the cut to three.
        $this->openOn(ArcadeGame::WindyWalkies)->assertSee('Rook');
    }

    public function test_last_weeks_giant_still_holds_the_all_time_record(): void
    {
        $kid = $this->loginKid();

        // The weekly reset is what gives a new player a shot at the board; the
        // all-time line is what stops the reset erasing the big run.
        $this->score($kid, 40, '1999-W01', ArcadeGame::WindyWalkies, 'SALTY RATTLE');

        $this->openOn(ArcadeGame::WindyWalkies)
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

        // The same number means something else on the other game, which is
        // the point of there being two ladders.
        $this->assertSame('Off the kerb', $arcade->altitude(ArcadeGame::WindyWalkies, 0));
        $this->assertSame('Over the water', $arcade->altitude(ArcadeGame::WindyWalkies, 8));
        $this->assertSame('Through the doors', $arcade->altitude(ArcadeGame::WindyWalkies, 34));
        $this->assertSame('Legendary guff', $arcade->altitude(ArcadeGame::WindyWalkies, 400));
    }

    public function test_the_arcade_is_no_longer_on_the_public_login_page(): void
    {
        /*
         * The game stood on `/` for as long as its board held nothing about
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

    public function test_both_consoles_draw_both_games(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $parent = Profile::factory()->for($household)->parent()->create(['name' => 'Dad']);

        Auth::guard('profile')->login($kid);
        Volt::test('kid.arcade')->assertOk()->assertSee('Stack the Mess')->assertSee('Windy Walkies');

        Auth::guard('profile')->login($parent);
        Volt::test('parent.arcade')->assertOk()->assertSee('Stack the Mess')->assertSee('Windy Walkies');
    }

    public function test_the_kid_game_is_closed_to_parents_and_the_parent_one_to_kids(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $parent = Profile::factory()->for($household)->parent()->create();

        Auth::guard('profile')->login($parent);
        $this->get('/kid/arcade')->assertForbidden();

        Auth::guard('profile')->login($kid);
        $this->get('/parent/arcade')->assertForbidden();
    }

    public function test_every_game_can_be_played_full_screen(): void
    {
        $this->loginKid();

        // The overlay, the height each game stacks around its own canvas, and
        // the way back out — Escape works, but a tablet has no Escape, so the
        // control has to survive into the overlay itself.
        Volt::test('arcade')
            ->assertSee('x-data="fqStage"', false)
            ->assertSee('fq-stage-full', false)
            ->assertSee('--fq-stage-chrome', false)
            ->assertSee('Full screen')
            ->call('switchTo', ArcadeGame::WindyWalkies->value)
            ->assertSee('fq-stage-full', false)
            ->assertSee('--fq-stage-chrome', false)
            ->assertSee('Full screen');
    }

    public function test_the_controls_a_game_names_include_the_keys_it_now_takes(): void
    {
        $this->loginKid();

        Volt::test('arcade')
            ->call('switchTo', ArcadeGame::WindyWalkies->value)
            ->assertSee('WASD')
            ->call('switchTo', ArcadeGame::StackTheMess->value)
            ->assertSee('Tap, space or W');
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
