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
 * Westin's Whacky Game — the arcade's fifth game, and the first one whose name
 * in the house is not the name in the file.
 *
 * It ships from the design as "Penguin Launch", and everything the browser
 * touches still says so: the element, both events, the file itself. That is the
 * same call `fart-dash.js` got and for the same reason — none of those strings
 * is user-facing, and renaming them would fork the file from the bundle to no
 * benefit. Exactly one string in the artwork *is* user-facing, the name drawn
 * on the start screen in the largest type on the board, and it is the one thing
 * here a replacement drop would silently undo. Most of this file is about that
 * seam.
 *
 * The rest is the arrangement every shipped game file gets — see
 * `ArcadeFlightTest` and `ArcadeToyTest` for the same reads on `grand-tour.js`
 * and `slime.js`.
 */
class ArcadePenguinTest extends TestCase
{
    use RefreshDatabase;

    private function loginKid(string $name = 'Nova'): Profile
    {
        $kid = Profile::factory()->for(Household::factory())->create(['name' => $name]);

        Auth::guard('profile')->login($kid);

        return $kid;
    }

    /**
     * The ladder as the shipped artwork carries it, read out of the file.
     *
     * The same read `ArcadeMilestoneTest` does on the walk and the flight, kept
     * here rather than shared because it is four lines and the alternative is a
     * base class holding one method.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function ladderInTheArtwork(): array
    {
        $source = file_get_contents(resource_path('js/penguin-launch.js'));

        preg_match('/const MILESTONES = \[(.*?)\n\];/s', $source, $block);

        $this->assertNotEmpty($block, 'Could not find MILESTONES in penguin-launch.js.');

        preg_match_all("/\[(\d+), '(.+?)'\]/", $block[1], $matches, PREG_SET_ORDER);

        return array_map(fn (array $match) => [(int) $match[1], $match[2]], $matches);
    }

    public function test_the_slide_competes(): void
    {
        // A game rather than a toy, which is what gives it a board, an all-time
        // record and a week to win.
        $this->assertTrue(ArcadeGame::PenguinLaunch->isRanked());
        $this->assertContains(ArcadeGame::PenguinLaunch, ArcadeGame::ranked());
        $this->assertNotContains(ArcadeGame::PenguinLaunch, ArcadeGame::toys());
    }

    public function test_a_finished_run_lands_on_its_own_board(): void
    {
        // Posted by the page when `pl-over` fires, to the game the server says
        // is showing — the same round trip a player makes.
        $kid = $this->loginKid();

        Volt::test('arcade')
            ->call('switchTo', ArcadeGame::PenguinLaunch->value)
            ->call('post', 317);

        $score = ArcadeScore::sole();

        $this->assertSame(317, $score->score);
        $this->assertSame(ArcadeGame::PenguinLaunch, $score->game);
        $this->assertSame($kid->id, $score->profile_id);
    }

    public function test_the_board_measures_the_run_in_metres(): void
    {
        /*
         * Score *is* distance here and nothing else adds to it — rings, mines
         * and power-ups are worth having because they carry the penguin
         * further, never because they pay points. That is what keeps this board
         * impossible to farm, and it is only true while the word under the
         * number is the distance the penguin actually covered.
         */
        $this->assertSame('metres', ArcadeGame::PenguinLaunch->unit());

        $this->loginKid();

        Volt::test('arcade')
            ->call('switchTo', ArcadeGame::PenguinLaunch->value)
            ->call('post', 317)
            ->assertSee('317 metres')
            ->assertDontSee('317 points');
    }

    public function test_the_ladder_says_the_same_thing_in_both_languages(): void
    {
        /*
         * The artwork draws a milestone on the canvas the moment a kid passes
         * it, and the board labels their score from the PHP copy afterwards. A
         * disagreement would have the board congratulating somebody for
         * reaching a place the game never told them they had reached.
         *
         * The design *document* beside the game file prints a third, older
         * ladder — 120m/280m/480m and so on. The file is the half that draws,
         * so the file is the half PHP was matched to.
         */
        $this->assertSame(
            ArcadeService::milestonesFor(ArcadeGame::PenguinLaunch),
            $this->ladderInTheArtwork(),
            'penguin-launch.js and ArcadeService disagree about the ice.'
        );
    }

    public function test_the_ceiling_leaves_room_above_the_last_rung(): void
    {
        /*
         * The ceiling bounds what a tampered request can write; it is not a cap
         * on real play. A run that chains a ring arc onto a glare-ice slide
         * keeps building long after the last milestone has gone past, so a
         * ceiling near the top of the ladder would throw away honest runs in
         * silence — the kid is never told, and the run they were proudest of is
         * the one that vanishes.
         */
        $ladder = ArcadeService::milestonesFor(ArcadeGame::PenguinLaunch);
        $lastRung = end($ladder)[0];

        $this->assertGreaterThan(
            $lastRung * 3,
            ArcadeGame::PenguinLaunch->maxScore(),
            'The slide ceiling is close enough to the last rung to throw away real runs.'
        );
    }

    public function test_the_page_asks_for_the_element_the_game_file_defines(): void
    {
        /*
         * The seam between Blade and the shipped artwork is a string: the page
         * writes `<penguin-launch>` and the file registers `penguin-launch`.
         * Nothing checks that at build time, and a mismatch renders an empty
         * box with no error anywhere — the game is simply missing.
         */
        $page = file_get_contents(resource_path('views/livewire/arcade.blade.php'));

        $this->assertStringContainsString('<penguin-launch ', $page, 'The arcade never renders <penguin-launch>.');

        // And the listener that turns a finished run into a row on the board.
        // Without it the game plays perfectly and nothing is ever posted.
        $this->assertStringContainsString('x-on:pl-over=', $page, 'The arcade never listens for a finished run.');

        $source = file_get_contents(resource_path('js/penguin-launch.js'));

        $this->assertStringContainsString("customElements.define('penguin-launch'", $source);
        $this->assertStringContainsString("this.emit('pl-over'", $source);

        // And it is bundled, or the element is never defined at all.
        $this->assertStringContainsString(
            "import './penguin-launch.js';",
            file_get_contents(resource_path('js/app.js'))
        );
    }

    public function test_the_start_screen_draws_the_name_the_house_uses(): void
    {
        /*
         * The one user-facing string in the artwork, and the one a replacement
         * drop would undo without looking broken: the game would simply go back
         * to calling itself Penguin Launch, in the largest type on the board,
         * while every label around it said otherwise.
         *
         * Asserted off `label()` rather than written out twice, so renaming the
         * game in the enum is what fails this rather than a stale copy of the
         * old name passing forever.
         */
        $source = file_get_contents(resource_path('js/penguin-launch.js'));
        $label = ArcadeGame::PenguinLaunch->label();

        $this->assertSame("Westin's Whacky Game", $label);
        $this->assertStringContainsString('strokeText("'.$label.'"', $source);
        $this->assertStringContainsString('fillText("'.$label.'"', $source);

        // The design's own title, and only in the prose at the top of the file.
        $this->assertStringNotContainsString("fillText('Penguin Launch'", $source);
    }

    public function test_the_stage_knows_how_tall_a_board_this_game_draws(): void
    {
        /*
         * Four games draw 320x460 and this one draws 320x470, and the
         * full-screen stage sizes the box from a height budget converted
         * through that ratio. Reading 460 for a 470-tall board oversizes it by
         * about 2% and pushes the bottom of the ice off a short screen, which
         * is the exact failure the height budget exists to prevent — and it
         * would only ever show up full screen, on somebody else's phone.
         */
        $source = file_get_contents(resource_path('js/penguin-launch.js'));

        $this->assertStringContainsString(
            'aspect-ratio:320 / '.ArcadeGame::PenguinLaunch->boardHeight().';',
            $source,
            'penguin-launch.js draws a board a different shape from the one the stage sizes.'
        );

        $this->assertStringContainsString(
            '--fq-stage-ratio',
            file_get_contents(resource_path('views/livewire/arcade.blade.php')),
            'The stage no longer hands the game its own aspect ratio.'
        );
    }

    public function test_the_slides_artwork_reaches_for_nothing_it_should_not(): void
    {
        // Shipped verbatim from the design and loaded on every page in the
        // console, so a later drop of the file gets the same read the first one
        // did rather than being trusted on the strength of the last one.
        $source = file_get_contents(resource_path('js/penguin-launch.js'));

        foreach (['fetch(', 'XMLHttpRequest', 'eval(', 'new Function', 'document.cookie', 'import ', 'require('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "penguin-launch.js reaches for {$forbidden}.");
        }

        // One storage key, and it is the mute toggle every other game already
        // reads — which is the whole reason one speaker button governs five
        // games with no wiring between them.
        preg_match_all('/localStorage\.\w+\(([^)]*)\)/', $source, $storage);

        $this->assertSame(["'fq-muted'"], array_unique($storage[1]));

        $this->assertStringContainsString(
            "if (!customElements.get('penguin-launch')) {",
            $source,
            'penguin-launch.js is no longer guarded against being registered twice.'
        );
    }

    public function test_the_slide_still_reads_the_one_mute_key(): void
    {
        /*
         * The other edit the file carries from the design, and the other one a
         * replacement drop would undo without looking broken: the game would
         * simply keep making noise with the speaker button off.
         *
         * It sits in `Sfx.wake()` because that is the one function every sound
         * in the file goes through — a mute has to be a mute rather than a mute
         * of some of the noises.
         */
        $source = file_get_contents(resource_path('js/penguin-launch.js'));

        $this->assertMatchesRegularExpression(
            "/wake\(\) \{\s*(\/\/[^\n]*\n\s*)*if \(localStorage\.getItem\('fq-muted'\) === '1'\)/",
            $source,
            'penguin-launch.js no longer checks the mute toggle in its audio gateway.'
        );
    }

    public function test_the_rail_lists_the_slide_as_a_game_rather_than_a_toy(): void
    {
        $this->loginKid();

        Volt::test('arcade')
            ->call('switchTo', ArcadeGame::PenguinLaunch->value)
            ->assertSee("Westin's Whacky Game", false)
            ->assertSee('metres slid')
            ->assertSee('This week')
            ->assertSee('All-time record');
    }
}
