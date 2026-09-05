<?php

namespace Tests\Feature;

use App\Enums\ArcadeGame;
use App\Models\ArcadeScore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ArcadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use LogicException;
use Tests\TestCase;

/**
 * Toys — the half of the arcade that keeps no score.
 *
 * `ArcadeGame::isRanked()` has existed since the page was rebuilt, and until
 * Slime Time landed it returned true for everything, so the whole unranked
 * branch rendered nothing and was proved by nothing. This is that branch: no
 * board, no weekly prize, no "beat NN", and — the part that is a rule rather
 * than a layout — no way to get a row onto `arcade_scores` from a game that
 * does not produce scores.
 *
 * That last one is not hypothetical. `post()` on the arcade component is
 * reachable from the browser whichever game is showing, so "the toy never
 * sends a score" is a fact about the toy and not a guarantee about the page.
 * The guarantee is here.
 *
 * The file also holds the read on `slime.js`'s one edit from its design bundle
 * — the mute check — because that edit is the kind of thing a replacement drop
 * undoes silently. See `ArcadeMilestoneTest` for the same arrangement around
 * `fart-dash.js`.
 */
class ArcadeToyTest extends TestCase
{
    use RefreshDatabase;

    private function loginKid(string $name = 'Nova'): Profile
    {
        $kid = Profile::factory()->for(Household::factory())->create(['name' => $name]);

        Auth::guard('profile')->login($kid);

        return $kid;
    }

    /** The toy the rest of this file is about, and proof there is one at all. */
    private function toy(): ArcadeGame
    {
        $toys = ArcadeGame::toys();

        $this->assertNotEmpty($toys, 'Nothing in the arcade is a toy, so this file proves nothing.');

        return $toys[0];
    }

    public function test_the_toy_is_the_only_unranked_game_and_the_cabinets_are_not(): void
    {
        $this->assertFalse(ArcadeGame::SlimeTime->isRanked());

        foreach (ArcadeGame::ranked() as $cabinet) {
            $this->assertTrue($cabinet->isRanked(), $cabinet->label().' stopped keeping score.');
        }

        $this->assertSame([ArcadeGame::SlimeTime], ArcadeGame::toys());
    }

    public function test_a_run_cannot_be_posted_to_a_toy(): void
    {
        /*
         * The rule the whole flag exists to enforce. A board that quietly
         * accepted runs from a game with no scoring would be a board nobody
         * could explain — and since the *page* holds which game is showing,
         * a request that arrives while the toy is up is exactly the shape this
         * has to refuse.
         */
        $kid = $this->loginKid();

        Volt::test('arcade')
            ->call('switchTo', $this->toy()->value)
            ->call('post', 40);

        $this->assertSame(0, ArcadeScore::count());
    }

    public function test_the_service_refuses_a_toy_run_even_when_the_page_does_not_ask(): void
    {
        // Belt and braces on purpose: the component checks first so no round
        // trip is wasted, and this is the check that survives somebody calling
        // the service from somewhere new.
        $kid = $this->loginKid();

        $this->assertNull(app(ArcadeService::class)->post($kid, $this->toy(), 40));
        $this->assertSame(0, ArcadeScore::count());
    }

    public function test_a_toy_has_no_board_no_target_and_no_record(): void
    {
        /*
         * All three blocks of the right-hand column go, not just the standings.
         * An All-time record of nothing, next to a weekly prize a toy cannot
         * win, is three empty boxes explaining a competition that is not
         * happening.
         */
        $this->loginKid();

        Volt::test('arcade')
            ->call('switchTo', $this->toy()->value)
            ->assertDontSee('This week')
            ->assertDontSee('All-time record')
            ->assertDontSee('Beat')
            ->assertDontSee('bonus tickets every Sunday');
    }

    public function test_switching_back_to_a_cabinet_brings_the_board_with_it(): void
    {
        // The board is gated on the game showing rather than torn down, so the
        // toy must not be a one-way door out of the competitive half.
        $this->loginKid();

        Volt::test('arcade')
            ->call('switchTo', $this->toy()->value)
            ->assertDontSee('All-time record')
            ->call('switchTo', ArcadeGame::WindyWalkies->value)
            ->assertSee('All-time record')
            ->assertSee('This week');
    }

    public function test_the_toy_says_there_is_nothing_to_win_where_a_unit_would_go(): void
    {
        // The line under a game's name says what the run is measured in. A toy
        // has no answer and asking throws, so the page says the true thing
        // instead of leaving a gap where a number belongs.
        $this->loginKid();

        Volt::test('arcade')
            ->call('switchTo', $this->toy()->value)
            ->assertSee('Slime Time')
            ->assertSee('nothing to win');
    }

    public function test_the_rail_lists_the_toy_under_its_own_heading(): void
    {
        // Cabinets above, toys below, and no leader line on a toy — a game
        // nobody can be winning must not be given a row that looks like
        // standings.
        $this->loginKid();

        Volt::test('arcade')
            ->assertSee('Games')
            ->assertSee('Toys')
            ->assertSee('Slime Time');
    }

    public function test_a_toy_still_wears_the_new_pip(): void
    {
        /*
         * The pip lived only on the ranked half of the rail, because until now
         * every game that could arrive was ranked. Arriving is not a
         * competitive fact, and a toy is precisely the thing nobody goes
         * looking for: without this, the first one lands completely silently
         * in the app and only the push mentions it.
         */
        $kid = Profile::factory()->for(Household::factory())->create([
            'name' => 'Nova',
            'arcade_seen_at' => $this->toy()->releasedOn()->subDay(),
        ]);

        Auth::guard('profile')->login($kid);

        // Containment rather than equality: anything released after the toy is
        // new to this kid too, so asserting the exact list would make the next
        // game added the reason this one stopped being about the pip.
        $component = Volt::test('arcade')->assertSee('New');

        $this->assertContains($this->toy()->value, $component->get('newGames'));
    }

    public function test_asking_a_toy_a_scoring_question_is_an_error_rather_than_a_blank(): void
    {
        /*
         * Every one of these is behind `isRanked()` at its call site. They
         * throw so that a caller who forgets finds out at once, rather than
         * printing an empty unit into a sentence or handing `post()` a
         * throttle of zero.
         */
        $toy = $this->toy();

        foreach (['unit', 'scoreLabel', 'emptyBoard', 'postsPerHour'] as $question) {
            try {
                $toy->{$question}();
                $this->fail("{$question}() answered for a toy.");
            } catch (LogicException $e) {
                $this->assertStringContainsString('keeps no score', $e->getMessage());
            }
        }

        $this->expectException(LogicException::class);
        $toy->prizeReason(10);
    }

    public function test_a_toy_settles_no_weeks_and_wins_no_tickets(): void
    {
        /*
         * Weeks are read off the scores rather than counted back from today, so
         * a game that keeps none has no weeks to find — settlement needs no
         * special case for toys and this is the assertion that it still does
         * not. Opening the arcade is what settles, so this is the real path.
         */
        $kid = $this->loginKid();

        Volt::test('kid.arcade')->assertOk();

        $this->assertSame(0, $kid->fresh()->bonus_tickets);
        $this->assertSame(0, ArcadeScore::count());
    }

    public function test_the_toy_is_not_offered_a_weekly_leader_line(): void
    {
        // `weeklyLeaders()` is keyed by ranked game, and the rail reads it per
        // entry. A toy key appearing here would put "nobody yet" — a phrase
        // about a board — under a game that has none.
        $kid = $this->loginKid();

        $leaders = app(ArcadeService::class)->weeklyLeaders($kid->household);

        $this->assertArrayNotHasKey($this->toy()->value, $leaders);

        foreach (ArcadeGame::ranked() as $game) {
            $this->assertArrayHasKey($game->value, $leaders);
        }
    }

    public function test_both_new_game_files_still_read_the_one_mute_key(): void
    {
        /*
         * The single edit `slime.js` carries from its design bundle, and the one
         * a replacement drop would undo without looking broken: the toy would
         * simply keep making noise with the speaker button off.
         *
         * The arcade has one sound toggle for the whole page, and it works
         * because every game reads `fq-muted` at the moment it plays rather
         * than holding its own mute state — so the button governs whichever
         * game is running without any wiring between them.
         */
        $source = file_get_contents(resource_path('js/slime.js'));

        $this->assertStringContainsString(
            "if (localStorage.getItem('fq-muted') === '1') {",
            $source,
            'slime.js no longer checks the mute toggle.'
        );

        // Inside the one function every sound in the file goes through, so a
        // mute is a mute rather than a mute of some of the noises.
        $this->assertMatchesRegularExpression(
            "/ctx\(\) \{\s*(\/\/[^\n]*\n\s*)*if \(localStorage\.getItem\('fq-muted'\) === '1'\)/",
            $source,
            'slime.js checks the mute toggle somewhere other than its audio gateway.'
        );
    }

    public function test_the_toys_artwork_reaches_for_nothing_it_should_not(): void
    {
        // Shipped verbatim from a design bundle and loaded on every page in the
        // console. The same read `fart-dash.js` gets in ArcadeMilestoneTest.
        $source = file_get_contents(resource_path('js/slime.js'));

        foreach (['fetch(', 'XMLHttpRequest', 'eval(', 'new Function', 'document.cookie', 'import ', 'require('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "slime.js reaches for {$forbidden}.");
        }

        preg_match_all('/localStorage\.\w+\(([^)]*)\)/', $source, $storage);

        $this->assertSame(["'fq-muted'"], array_unique($storage[1]));

        $this->assertStringContainsString(
            "if (!customElements.get('slime-time')) {",
            $source,
            'slime.js is no longer guarded against being registered twice.'
        );
    }

    public function test_the_page_asks_for_the_element_the_game_file_defines(): void
    {
        /*
         * The seam between Blade and the shipped artwork is a string: the page
         * writes `<slime-time>` and the file registers `slime-time`. Nothing
         * checks that at build time, and a mismatch renders an empty box with
         * no error anywhere — the toy is simply missing.
         */
        $page = file_get_contents(resource_path('views/livewire/arcade.blade.php'));

        $this->assertStringContainsString('<slime-time ', $page, 'The arcade never renders <slime-time>.');

        $this->assertStringContainsString(
            "customElements.define('slime-time'",
            file_get_contents(resource_path('js/slime.js')),
            'slime.js does not define <slime-time>.'
        );

        // And it is bundled, or the element is never defined at all.
        $this->assertStringContainsString(
            "import './slime.js';",
            file_get_contents(resource_path('js/app.js'))
        );
    }
}
