<?php

namespace Tests\Feature;

use App\Enums\ArcadeGame;
use App\Services\ArcadeService;
use Tests\TestCase;

/**
 * Each game's milestone ladder is owned by PHP because the leaderboard
 * labels a score with it; the artwork that draws each rung is owned by the game
 * file because only the canvas can draw a ceiling or a farmyard. That is a
 * seam, and this is the guard on it — the same arrangement
 * `BossSkinCatalogTest` watches over.
 *
 * Drift here is quiet and late: add "Through the roof" to PHP without adding
 * the roof to the artwork, and the banner announces a landmark that nobody ever
 * climbs past, weeks after the change, for the one kid good enough to reach it.
 *
 * The two games hold up their half differently, which is why there are two
 * checks rather than one. `arcade.js` keys its parallax scenery to the ladder
 * *by index* and never repeats the words, so what is checked is that there is
 * one scenery layer per rung. `fart-dash.js` ships verbatim from a design
 * bundle carrying its own copy of the list and draws the banner straight out of
 * it, so what is checked is that the copy still says the same thing.
 */
class ArcadeMilestoneTest extends TestCase
{
    /**
     * The scenery entries in `arcade.js`, counted by their index comments. Each
     * is written as `// 4 — Window height`, which is both the ordering and the
     * claim about which milestone it belongs to.
     *
     * @return array<int, string>
     */
    private function sceneryLabels(): array
    {
        $source = file_get_contents(resource_path('js/arcade.js'));

        preg_match('/const SCENERY = \[(.*?)\n\];/s', $source, $block);

        $this->assertNotEmpty($block, 'Could not find SCENERY in arcade.js.');

        preg_match_all('/^\s*\/\/ (\d+) — (.+)$/m', $block[1], $matches, PREG_SET_ORDER);

        $labels = [];

        foreach ($matches as $match) {
            $labels[(int) $match[1]] = trim($match[2]);
        }

        return $labels;
    }

    /**
     * The ladder `fart-dash.js` carries, read out of its own `MILESTONES`
     * array — the list it draws the in-game banner from.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function walkLadder(): array
    {
        $source = file_get_contents(resource_path('js/fart-dash.js'));

        preg_match('/const MILESTONES = \[(.*?)\n\];/s', $source, $block);

        $this->assertNotEmpty($block, 'Could not find MILESTONES in fart-dash.js.');

        preg_match_all("/\[(\d+), '(.+?)'\]/", $block[1], $matches, PREG_SET_ORDER);

        return array_map(fn (array $match) => [(int) $match[1], $match[2]], $matches);
    }

    public function test_every_tower_milestone_has_scenery_drawn_for_it(): void
    {
        $labels = $this->sceneryLabels();
        $ladder = ArcadeService::milestonesFor(ArcadeGame::StackTheMess);

        $this->assertCount(
            count($ladder),
            $labels,
            'arcade.js draws a different number of scenery layers than there are milestones.'
        );

        foreach ($ladder as $index => [$floors, $name]) {
            $this->assertArrayHasKey($index, $labels, "No scenery indexed {$index} in arcade.js.");
            $this->assertSame($name, $labels[$index], "Scenery {$index} names a different milestone.");
        }
    }

    public function test_the_walks_ladder_says_the_same_thing_in_both_languages(): void
    {
        // The banner is drawn inside the canvas from the JS copy and the board
        // is labelled from the PHP one, so a kid who reads "Past the tractors"
        // mid-run has to find that same phrase against their name afterwards.
        $this->assertSame(
            ArcadeService::milestonesFor(ArcadeGame::WindyWalkies),
            $this->walkLadder(),
            'fart-dash.js and ArcadeService disagree about the walk.'
        );
    }

    public function test_the_walks_artwork_reaches_for_nothing_it_should_not(): void
    {
        // Shipped verbatim from a design bundle and loaded on every page in the
        // console, so a later drop of the file gets the same read the first one
        // did rather than being trusted on the strength of the last one.
        $source = file_get_contents(resource_path('js/fart-dash.js'));

        foreach (['fetch(', 'XMLHttpRequest', 'eval(', 'new Function', 'document.cookie', 'import ', 'require('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "fart-dash.js reaches for {$forbidden}.");
        }

        // One storage key, and it is the mute toggle the other game already
        // writes — which is the whole reason the speaker button governs both
        // games with no wiring between them.
        preg_match_all('/localStorage\.\w+\(([^)]*)\)/', $source, $storage);

        $this->assertSame(["'fq-muted'"], array_unique($storage[1]));

        $this->assertStringContainsString(
            "if (!customElements.get('fart-dash')) {",
            $source,
            'fart-dash.js is no longer guarded against being registered twice.'
        );
    }

    public function test_the_start_screen_still_calls_the_game_by_its_real_name(): void
    {
        /*
         * The bundle shipped under the working title "Bean Dash" and draws its
         * own title screen, so that name was the largest type on the page. It
         * is the one line edited from the bundle, and it is the one thing a
         * replacement drop would silently undo — the game would look fine and
         * be called something the rest of the app has never heard of.
         *
         * The element and the events keep the old name on purpose; they are
         * internal and nobody reads them. This is the half that is read.
         */
        $source = file_get_contents(resource_path('js/fart-dash.js'));

        $this->assertStringContainsString(
            "ctx.fillText('".ArcadeGame::WindyWalkies->titleScreen()."'",
            $source,
            'fart-dash.js draws a title that is not the name of the game.'
        );

        $this->assertStringNotContainsString("fillText('BEAN DASH'", $source);
    }

    public function test_every_ladder_only_climbs(): void
    {
        // `altitude()` walks the list and keeps the last one it clears, so an
        // out-of-order entry would silently label a long run with a short name.
        foreach (ArcadeGame::cases() as $game) {
            $previous = -1;

            foreach (ArcadeService::milestonesFor($game) as [$at, $name]) {
                $this->assertGreaterThan($previous, $at, "Milestone '{$name}' is out of order.");
                $previous = $at;
            }
        }
    }

    public function test_every_ladder_starts_at_the_ground(): void
    {
        // Every run begins at zero and must still have a label.
        foreach (ArcadeGame::cases() as $game) {
            $this->assertSame(0, ArcadeService::milestonesFor($game)[0][0]);
        }
    }

    public function test_the_walks_rungs_land_on_the_scenery_changes(): void
    {
        /*
         * The biomes cycle every 14 lanes in `fart-dash.js`, and the three
         * named after them — the farmyard, the tractors and the supermarket
         * doors — are keyed to that on purpose. A rung that drifts off a
         * boundary announces a landmark the player cannot see out of the
         * window.
         */
        $ladder = collect(ArcadeService::milestonesFor(ArcadeGame::WindyWalkies))
            ->mapWithKeys(fn (array $rung) => [$rung[1] => $rung[0]]);

        $this->assertSame(14, $ladder['Down the lane']);
        $this->assertSame(20, $ladder['In the farmyard']);
        $this->assertSame(34, $ladder['Through the doors']);
    }
}
