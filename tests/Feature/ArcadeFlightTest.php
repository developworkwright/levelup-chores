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
use Tests\TestCase;

/**
 * Grand Tour — the arcade's fourth game, and the first one whose score is not
 * a count of things the player passed.
 *
 * A tower is floors and a walk is lanes: one at a time, slowly, up to a number
 * a person could reach on their fingers. A flight is kilometres *plus* a bonus
 * for every gap threaded, earned a dozen a second, so a good run reaches four
 * figures. That difference is the only genuinely new thing about this game, and
 * it is what this file is mostly about — the ceiling that decides whether a run
 * is believed had to stop being one number for every game, and the word under
 * the score had to stop being the thing the plane actually flew.
 *
 * The rest is the arrangement every shipped game file gets: the seam between
 * Blade and the artwork is a string nothing checks at build time, and the one
 * edit the file carries from the design is the kind of thing a replacement drop
 * undoes without looking broken. See `ArcadeToyTest` and `ArcadeMilestoneTest`
 * for the same reads on `slime.js` and `fart-dash.js`.
 */
class ArcadeFlightTest extends TestCase
{
    use RefreshDatabase;

    private function loginKid(string $name = 'Nova'): Profile
    {
        $kid = Profile::factory()->for(Household::factory())->create(['name' => $name]);

        Auth::guard('profile')->login($kid);

        return $kid;
    }

    public function test_the_flight_competes(): void
    {
        // A cabinet rather than a toy, which is what gives it a board, an
        // all-time record and a week to win.
        $this->assertTrue(ArcadeGame::GrandTour->isRanked());
        $this->assertContains(ArcadeGame::GrandTour, ArcadeGame::ranked());
        $this->assertNotContains(ArcadeGame::GrandTour, ArcadeGame::toys());
    }

    public function test_a_finished_flight_lands_on_its_own_board(): void
    {
        // Posted by the page when `gt-over` fires, to the game the server says
        // is showing — the same round trip a player makes.
        $kid = $this->loginKid();

        Volt::test('arcade')
            ->call('switchTo', ArcadeGame::GrandTour->value)
            ->call('post', 214);

        $score = ArcadeScore::sole();

        $this->assertSame(214, $score->score);
        $this->assertSame(ArcadeGame::GrandTour, $score->game);
        $this->assertSame($kid->id, $score->profile_id);
    }

    public function test_the_board_shows_the_flight_in_points_and_not_in_kilometres(): void
    {
        /*
         * The score is the distance flown *plus* a bonus for every gap
         * threaded, so it is always larger than the kilometres the plane
         * actually covered. Labelling the board in km would have the game
         * quietly overstating how far anybody got, on a page the whole house
         * reads and long after the run.
         */
        $this->assertSame('points', ArcadeGame::GrandTour->unit());

        $kid = $this->loginKid();

        Volt::test('arcade')
            ->call('switchTo', ArcadeGame::GrandTour->value)
            ->call('post', 214)
            ->assertSee('214 points')
            ->assertDontSee('214 km');
    }

    public function test_the_ceiling_leaves_room_above_the_last_city(): void
    {
        /*
         * The ceiling exists to bound what a tampered request can write, not to
         * cap real play — and a flight is the game where those two are close
         * enough together to get wrong. The last rung of the ladder is about a
         * minute of flawless flying, and the score keeps climbing at ten to
         * twenty points a second after it, so anything near the ladder's own
         * top would be throwing away honest runs in silence.
         */
        $ladder = ArcadeService::milestonesFor(ArcadeGame::GrandTour);
        $lastCity = end($ladder)[0];

        $this->assertGreaterThan(
            $lastCity * 3,
            ArcadeGame::GrandTour->maxScore(),
            'The flight ceiling is close enough to the last city to throw away real runs.'
        );
    }

    public function test_the_page_asks_for_the_element_the_game_file_defines(): void
    {
        /*
         * The seam between Blade and the shipped artwork is a string: the page
         * writes `<grand-tour>` and the file registers `grand-tour`. Nothing
         * checks that at build time, and a mismatch renders an empty box with
         * no error anywhere — the game is simply missing.
         */
        $page = file_get_contents(resource_path('views/livewire/arcade.blade.php'));

        $this->assertStringContainsString('<grand-tour ', $page, 'The arcade never renders <grand-tour>.');

        // And the listener that turns a finished run into a row on the board.
        // Without it the game plays perfectly and nothing is ever posted.
        $this->assertStringContainsString('x-on:gt-over=', $page, 'The arcade never listens for a finished flight.');

        $source = file_get_contents(resource_path('js/grand-tour.js'));

        $this->assertStringContainsString("customElements.define('grand-tour'", $source);
        $this->assertStringContainsString("this.emit('gt-over'", $source);

        // And it is bundled, or the element is never defined at all.
        $this->assertStringContainsString(
            "import './grand-tour.js';",
            file_get_contents(resource_path('js/app.js'))
        );
    }

    public function test_the_flights_artwork_reaches_for_nothing_it_should_not(): void
    {
        // Shipped verbatim from the design and loaded on every page in the
        // console, so a later drop of the file gets the same read the first one
        // did rather than being trusted on the strength of the last one.
        $source = file_get_contents(resource_path('js/grand-tour.js'));

        foreach (['fetch(', 'XMLHttpRequest', 'eval(', 'new Function', 'document.cookie', 'import ', 'require('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "grand-tour.js reaches for {$forbidden}.");
        }

        // One storage key, and it is the mute toggle every other game already
        // reads — which is the whole reason one speaker button governs four
        // games with no wiring between them.
        preg_match_all('/localStorage\.\w+\(([^)]*)\)/', $source, $storage);

        $this->assertSame(["'fq-muted'"], array_unique($storage[1]));

        $this->assertStringContainsString(
            "if (!customElements.get('grand-tour')) {",
            $source,
            'grand-tour.js is no longer guarded against being registered twice.'
        );
    }

    public function test_the_flight_still_reads_the_one_mute_key(): void
    {
        /*
         * The single edit `grand-tour.js` carries from the design, and the one
         * a replacement drop would undo without looking broken: the game would
         * simply keep making noise with the speaker button off.
         *
         * It sits in `Sfx.wake()` because that is the one function every sound
         * in the file goes through — a mute has to be a mute rather than a mute
         * of some of the noises.
         */
        $source = file_get_contents(resource_path('js/grand-tour.js'));

        $this->assertMatchesRegularExpression(
            "/wake\(\) \{\s*(\/\/[^\n]*\n\s*)*if \(localStorage\.getItem\('fq-muted'\) === '1'\)/",
            $source,
            'grand-tour.js no longer checks the mute toggle in its audio gateway.'
        );
    }

    public function test_the_rail_lists_the_flight_as_a_game_rather_than_a_toy(): void
    {
        $this->loginKid();

        Volt::test('arcade')
            ->call('switchTo', ArcadeGame::GrandTour->value)
            ->assertSee('Grand Tour')
            ->assertSee('points flown')
            ->assertSee('This week')
            ->assertSee('All-time record');
    }
}
