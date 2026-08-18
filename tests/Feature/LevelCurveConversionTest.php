<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The migration that steepened the curve had one job beyond adding columns: no
 * kid may lose a level over it. This runs the migration's own back-fill against
 * profiles carrying old-curve XP and checks that promise directly, rather than
 * restating the formula and proving only that arithmetic is arithmetic.
 */
class LevelCurveConversionTest extends TestCase
{
    use RefreshDatabase;

    /** The flat curve that was in force before the bands. */
    private const OLD_XP_PER_LEVEL = 200;

    /**
     * Invokes the migration's private back-fill. The columns already exist by
     * the time a test runs, so only the conversion half is replayed.
     */
    private function runConversion(): void
    {
        $migration = require database_path(
            'migrations/2026_08_17_170000_add_level_ranks_and_gated_rewards.php'
        );

        $preserve = new ReflectionMethod($migration, 'preserveLevels');
        $preserve->invoke($migration);
    }

    private function oldLevel(int $xp): int
    {
        return 1 + intdiv($xp, self::OLD_XP_PER_LEVEL);
    }

    private function oldBarPercent(int $xp): float
    {
        return ($xp % self::OLD_XP_PER_LEVEL) / (self::OLD_XP_PER_LEVEL / 100);
    }

    public function test_no_kid_loses_a_level_to_the_new_curve(): void
    {
        $household = Household::factory()->create();

        // Spread across all three bands, including the two boundaries and the
        // three levels the kids were actually on when this shipped.
        $samples = [0, 125, 925, 1800, 1999, 2000, 2300, 3000, 4200, 6000];

        $kids = [];

        foreach ($samples as $xp) {
            $kids[$xp] = Profile::factory()->for($household)->create(['xp' => $xp]);
        }

        $this->runConversion();

        foreach ($samples as $xp) {
            $kid = $kids[$xp]->refresh();

            $this->assertSame(
                $this->oldLevel($xp),
                $kid->level(),
                "A kid on {$xp} XP changed level.",
            );

            $this->assertEqualsWithDelta(
                $this->oldBarPercent($xp),
                $kid->xpBarPercent(),
                0.5,
                "A kid on {$xp} XP moved along their bar.",
            );
        }
    }

    public function test_the_conversion_is_banked_so_a_rebuild_can_add_it_back(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['xp' => 3000]);

        $this->runConversion();
        $kid->refresh();

        // 3000 was level 16 flat; level 16 under the bands starts at 3750.
        $this->assertSame(750, $kid->xp_adjustment);
        $this->assertSame(3750, $kid->xp);
        $this->assertSame(16, $kid->level());
    }

    public function test_a_kid_the_curve_already_agrees_with_is_left_alone(): void
    {
        $household = Household::factory()->create();

        // Anything inside the first band converts to itself.
        $kid = Profile::factory()->for($household)->create(['xp' => 925]);

        $this->runConversion();
        $kid->refresh();

        $this->assertSame(0, $kid->xp_adjustment);
        $this->assertSame(925, $kid->xp);
        $this->assertSame(5, $kid->level());
    }

    public function test_running_the_conversion_is_not_something_to_do_twice(): void
    {
        // Guarding the fact rather than the behaviour: the back-fill reads live
        // XP, so a second pass would convert already-converted totals. It runs
        // once, inside a migration. If that ever stops being true this test is
        // the thing that should fail.
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['xp' => 3000]);

        $this->runConversion();
        $once = $kid->refresh()->xp;

        $this->runConversion();

        $this->assertGreaterThan($once, $kid->refresh()->xp);
    }
}
