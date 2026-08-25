<?php

namespace Tests\Feature;

use App\Services\ArcadeService;
use Tests\TestCase;

/**
 * The milestone ladder is owned by PHP because the leaderboard labels a score
 * with it; the scenery for each rung is owned by `resources/js/arcade.js`
 * because only the canvas can draw a ceiling. That is a seam, and this is the
 * guard on it — the same arrangement `BossSkinCatalogTest` watches over.
 *
 * Drift here is quiet and late: add "Through the roof" to PHP without adding
 * the roof to the artwork, and the banner announces a landmark that nobody
 * ever climbs past, weeks after the change, for the one kid good enough to
 * reach it.
 */
class ArcadeMilestoneTest extends TestCase
{
    /**
     * The scenery entries, counted by their index comments. Each is written as
     * `// 4 — Window height`, which is both the ordering and the claim about
     * which milestone it belongs to.
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

    public function test_every_milestone_has_scenery_drawn_for_it(): void
    {
        $labels = $this->sceneryLabels();

        $this->assertCount(
            count(ArcadeService::MILESTONES),
            $labels,
            'arcade.js draws a different number of scenery layers than there are milestones.'
        );

        foreach (ArcadeService::MILESTONES as $index => [$floors, $name]) {
            $this->assertArrayHasKey($index, $labels, "No scenery indexed {$index} in arcade.js.");
            $this->assertSame($name, $labels[$index], "Scenery {$index} names a different milestone.");
        }
    }

    public function test_the_ladder_only_climbs(): void
    {
        // `altitude()` walks the list and keeps the last one it clears, so an
        // out-of-order entry would silently label tall towers with a short name.
        $previous = -1;

        foreach (ArcadeService::MILESTONES as [$floors, $name]) {
            $this->assertGreaterThan($previous, $floors, "Milestone '{$name}' is out of order.");
            $previous = $floors;
        }
    }

    public function test_the_first_milestone_starts_at_the_ground(): void
    {
        // Every run begins at zero floors and must still have a label.
        $this->assertSame(0, ArcadeService::MILESTONES[0][0]);
    }
}
