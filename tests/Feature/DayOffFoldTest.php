<?php

namespace Tests\Feature;

use App\Enums\PerkEffect;
use App\Models\DailyChest;
use App\Models\Household;
use App\Models\OwnedPerk;
use App\Models\Profile;
use App\Services\ChoreService;
use App\Services\PerkInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Retiring the Day Off perk had to leave nothing behind that reads as a bug on
 * a kid's screen: no tickets quietly burnt, no run shortened overnight, and no
 * stored `'quest_skip'` string left for `PerkEffect` to choke on.
 *
 * The migration is replayed against a household that actually held those rows,
 * rather than the conversion being restated here — this is the only pass it
 * ever gets, and it runs once on real data.
 */
class DayOffFoldTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->kid = Profile::factory()->for($this->household)->create();
    }

    /**
     * Puts the schema and the catalogue row back the way they were the day
     * before the fold, by running the migration that created them.
     */
    private function restoreDayOff(): void
    {
        $migration = require database_path('migrations/2026_08_11_002837_create_quest_skips_table.php');

        $migration->up();
    }

    private function fold(): void
    {
        $migration = require database_path('migrations/2026_08_26_210000_fold_day_off_into_streak_restore.php');

        $migration->up();
    }

    /** Written raw: the model casts `effect`, and the enum no longer has this case. */
    private function ownDayOff(?Carbon $consumedAt = null): int
    {
        return DB::table('owned_perks')->insertGetId([
            'profile_id' => $this->kid->id,
            'effect' => 'quest_skip',
            'source' => OwnedPerk::SOURCE_SHOP,
            'acquired_at' => now(),
            'consumed_at' => $consumedAt,
        ]);
    }

    private function buyDayOffOn(Carbon $date): void
    {
        DB::table('quest_skips')->insert([
            'profile_id' => $this->kid->id,
            'skip_date' => $date->toDateString(),
            'created_at' => now(),
        ]);
    }

    public function test_a_held_day_off_becomes_a_streak_restore(): void
    {
        $this->restoreDayOff();
        $this->ownDayOff();

        $this->fold();

        // Read through the service, not the column: the point is that the kid
        // has something they can actually spend, not that a string changed.
        $this->assertSame(
            1,
            app(PerkInventoryService::class)->countOf($this->kid, PerkEffect::StreakRestore),
        );
    }

    public function test_a_spent_day_off_is_converted_too(): void
    {
        $this->restoreDayOff();
        $id = $this->ownDayOff(now());

        $this->fold();

        // Left alone this is a landmine rather than a cosmetic problem: the
        // Stats page hydrates spent perks, and a value PerkEffect has never
        // heard of throws on the way out of the database.
        $perk = OwnedPerk::findOrFail($id);

        $this->assertSame(PerkEffect::StreakRestore, $perk->effect);
        $this->assertTrue($perk->isUsed());
    }

    public function test_an_unopened_chest_holding_one_is_converted(): void
    {
        $this->restoreDayOff();

        $id = DB::table('daily_chests')->insertGetId([
            'profile_id' => $this->kid->id,
            'chest_date' => now()->toDateString(),
            'reward_kind' => 'perk',
            'reward_effect' => 'quest_skip',
            'created_at' => now(),
        ]);

        $this->fold();

        $this->assertSame(PerkEffect::StreakRestore, DailyChest::findOrFail($id)->reward_effect);
    }

    public function test_the_catalogue_row_goes(): void
    {
        $this->restoreDayOff();

        $this->assertTrue(DB::table('bonus_perks')->where('effect', 'quest_skip')->exists());

        $this->fold();

        // BonusPerkCatalogTest asserts cases and rows match exactly, so a row
        // left behind here fails the shop rather than sitting harmlessly.
        $this->assertFalse(DB::table('bonus_perks')->where('effect', 'quest_skip')->exists());
    }

    public function test_a_day_already_bought_still_counts_toward_the_streak(): void
    {
        $this->restoreDayOff();

        $bought = now()->subDays(2)->startOfDay();
        $this->buyDayOffOn($bought);

        // `questApprovedOn()` used to read quest_skips beside streak_repairs.
        // It only reads repairs now, so a day the kid paid for keeps counting
        // only if the migration moved it across — drop the table without that
        // and a live run is a night shorter the morning after deploy.
        $this->fold();

        $this->assertTrue(app(ChoreService::class)->questApprovedOn($this->kid, $bought));
    }

    public function test_a_day_that_was_both_bought_and_repaired_does_not_collide(): void
    {
        $this->restoreDayOff();

        $day = now()->subDay()->startOfDay();

        DB::table('streak_repairs')->insert([
            'profile_id' => $this->kid->id,
            'repaired_date' => $day->toDateString(),
            'created_at' => now(),
        ]);

        $this->buyDayOffOn($day);

        $this->fold();

        // streak_repairs is unique on (profile_id, repaired_date). Both rows
        // say the same thing to the streak, so one of them is enough.
        $this->assertSame(
            1,
            DB::table('streak_repairs')
                ->where('profile_id', $this->kid->id)
                ->whereDate('repaired_date', $day)
                ->count(),
        );
    }

    public function test_the_table_is_gone_afterwards(): void
    {
        $this->restoreDayOff();

        $this->assertTrue(Schema::hasTable('quest_skips'));

        $this->fold();

        $this->assertFalse(Schema::hasTable('quest_skips'));
    }
}
