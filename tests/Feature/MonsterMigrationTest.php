<?php

namespace Tests\Feature;

use App\Enums\BossSkin;
use App\Enums\MonsterHitKind;
use App\Enums\MonsterTier;
use App\Models\Household;
use App\Models\Monster;
use App\Models\MonsterHit;
use App\Models\Profile;
use App\Services\MonsterService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The one-way move from a single family goal onto the three-tier arena.
 *
 * Worth testing rather than eyeballing: it runs once, against a database with
 * real history in it, and there is no second chance to get the trophy shelf
 * back if it drops something.
 *
 * The migration itself is re-run here by hand — a test database has already
 * migrated by the time a household exists to migrate, so the only way to watch
 * it work on real rows is to hand it some and call it again.
 */
class MonsterMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Puts the single-goal schema back so the migration under test has the
     * columns it reads.
     *
     * They were dropped once nothing was left reading them, which happens
     * *after* this migration in the sequence — so a fresh install still runs
     * the real thing against the real shape. A test database has finished
     * migrating by the time it has a household to migrate, so the only way to
     * watch this work on real rows is to hand the old shape back first.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Schema::table('households', function (Blueprint $table) {
            $table->string('goal_name')->nullable();
            $table->unsignedInteger('goal_target')->default(0);
            $table->unsignedInteger('goal_now')->default(0);
            $table->string('boss_key')->nullable();
            $table->timestamp('boss_started_at')->nullable();
            $table->unsignedInteger('boss_battle')->default(1);
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->unsignedInteger('goal_contribution')->default(0);
        });

        Schema::create('boss_defeats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            $table->string('boss_key');
            $table->string('boss_name');
            $table->unsignedInteger('battle')->default(1);
            $table->unsignedInteger('health');
            $table->string('goal_name')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('defeated_at');
            $table->unsignedBigInteger('finisher_profile_id')->nullable();
            $table->json('contributions');
            $table->timestamps();
        });
    }

    private function migrate(): void
    {
        (require database_path('migrations/2026_08_09_193004_seed_monsters_from_boss_battle.php'))->up();
    }

    /** A household as it looked before the arena, written straight to the row. */
    private function household(array $attributes = []): Household
    {
        $household = Household::factory()->create();

        DB::table('households')->where('id', $household->id)->update([
            'goal_name' => 'Trip to the zoo',
            'goal_target' => 2000,
            'goal_now' => 1200,
            'boss_key' => BossSkin::Sockmoth->value,
            'boss_battle' => 3,
            'boss_started_at' => now()->subDays(9),
            ...$attributes,
        ]);

        return $household;
    }

    /** The old columns are gone from the model, so they are read by hand. */
    private function goalColumn(Household $household, string $column): mixed
    {
        return DB::table('households')->where('id', $household->id)->value($column);
    }

    /** A kid with points already banked against the old single family goal. */
    private function contributor(Household $household, string $name, int $points): Profile
    {
        $kid = Profile::factory()->for($household)->create(['name' => $name]);

        DB::table('profiles')->where('id', $kid->id)->update(['goal_contribution' => $points]);

        return $kid;
    }

    /** A defeat as the old boss battle recorded it. */
    private function recordOldDefeat(Household $household, array $attributes = []): void
    {
        DB::table('boss_defeats')->insert([
            'household_id' => $household->id,
            'boss_key' => BossSkin::Gnash->value,
            'boss_name' => 'Gnash',
            'battle' => 1,
            'health' => 1000,
            'goal_name' => 'Pizza night',
            'started_at' => now()->subDays(9),
            'defeated_at' => now(),
            'finisher_profile_id' => null,
            'contributions' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ]);
    }

    public function test_the_current_family_goal_becomes_the_live_level_three_monster(): void
    {
        $household = $this->household();

        $this->migrate();

        $monster = app(MonsterService::class)->at($household, MonsterTier::Three);

        $this->assertNotNull($monster);
        $this->assertSame('Trip to the zoo', $monster->reward_name);
        $this->assertSame(2000, $monster->max_health);
        $this->assertSame(BossSkin::Sockmoth, $monster->skin);
        $this->assertSame(3, $monster->battle);
        $this->assertSame(1200, $monster->damage());
        $this->assertSame(800, $monster->healthLeft());
    }

    public function test_the_lower_tiers_start_empty_for_a_parent_to_name(): void
    {
        $household = $this->household();

        $this->migrate();

        $arena = app(MonsterService::class);

        $this->assertNull($arena->at($household, MonsterTier::One));
        $this->assertNull($arena->at($household, MonsterTier::Two));
        $this->assertCount(1, $arena->live($household));
    }

    public function test_each_kids_contribution_survives_as_a_hit_in_their_name(): void
    {
        $household = $this->household();
        $nova = $this->contributor($household, 'Nova', 700);
        $pip = $this->contributor($household, 'Pip', 400);

        $this->migrate();

        $monster = app(MonsterService::class)->at($household, MonsterTier::Three);
        $board = app(MonsterService::class)->contributionsFor($monster);

        $this->assertSame(700, $board->firstWhere('profile_id', $nova->id)['points']);
        $this->assertSame(400, $board->firstWhere('profile_id', $pip->id)['points']);
    }

    public function test_damage_nobody_can_be_credited_for_arrives_as_an_adjustment(): void
    {
        $household = $this->household();
        $this->contributor($household, 'Nova', 700);
        $this->contributor($household, 'Pip', 400);

        $this->migrate();

        $monster = app(MonsterService::class)->at($household, MonsterTier::Three);

        // 1200 on the bar, 1100 of it earned: the remaining 100 is whatever a
        // parent nudged or the daily reset rolled back, and belongs to nobody.
        $adjustment = MonsterHit::where('monster_id', $monster->id)
            ->where('kind', MonsterHitKind::Adjust)
            ->sole();

        $this->assertSame(100, $adjustment->damage);
        $this->assertNull($adjustment->profile_id);
        $this->assertSame(1200, $monster->damage());
    }

    public function test_a_household_with_nothing_to_fight_over_gets_no_monster(): void
    {
        $household = $this->household(['goal_target' => 0, 'goal_now' => 0]);

        $this->migrate();

        $this->assertTrue(app(MonsterService::class)->live($household)->isEmpty());
    }

    public function test_the_trophy_shelf_is_rebuilt_from_past_defeats(): void
    {
        $household = $this->household();

        $this->recordOldDefeat($household, [
            'contributions' => json_encode([['profile_id' => 1, 'name' => 'Nova', 'points' => 1000, 'percent' => 100]]),
        ]);

        $this->migrate();

        $shelved = app(MonsterService::class)->shelf($household)->sole();

        $this->assertSame('Pizza night', $shelved->reward_name);
        $this->assertSame(BossSkin::Gnash, $shelved->skin);
        $this->assertSame(MonsterTier::Three, $shelved->tier);
        $this->assertSame(1000, $shelved->max_health);
        $this->assertTrue($shelved->isDefeated());
        $this->assertSame('Nova', $shelved->contributions[0]['name']);

        // Its bar has to read finished, or the shelf shows a beaten monster at
        // full health.
        $this->assertSame(0, $shelved->healthLeft());
    }

    public function test_a_boss_beaten_but_not_yet_replaced_is_not_stood_up_twice(): void
    {
        $household = $this->household(['goal_now' => 2000]);

        // The state a family sits in between the killing blow and a parent
        // starting the next goal: the kill is banked at the current battle and
        // the monster is still on screen, KO'd.
        $this->recordOldDefeat($household, ['battle' => 3, 'health' => 2000, 'goal_name' => 'Trip to the zoo']);

        $this->migrate();

        $monsters = Monster::where('household_id', $household->id)->get();

        $this->assertCount(1, $monsters);
        $this->assertTrue($monsters->sole()->isDefeated());
        $this->assertTrue(app(MonsterService::class)->live($household)->isEmpty());
    }

    public function test_households_are_migrated_independently(): void
    {
        $first = $this->household(['goal_name' => 'Trip to the zoo']);
        $second = $this->household(['goal_name' => 'New bikes', 'goal_target' => 5000, 'goal_now' => 250]);

        $this->migrate();

        $arena = app(MonsterService::class);

        $this->assertSame('Trip to the zoo', $arena->at($first, MonsterTier::Three)->reward_name);
        $this->assertSame('New bikes', $arena->at($second, MonsterTier::Three)->reward_name);
        $this->assertSame(250, $arena->at($second, MonsterTier::Three)->damage());
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $household = $this->household();
        $this->contributor($household, 'Nova', 1200);
        $this->recordOldDefeat($household);

        $this->migrate();
        $this->migrate();

        $this->assertSame(2, Monster::where('household_id', $household->id)->count());
        $this->assertSame(1200, app(MonsterService::class)->at($household, MonsterTier::Three)->damage());
    }

    public function test_it_comes_back_for_a_goal_that_started_after_it_first_ran(): void
    {
        // The state this actually shipped into: the boss was beaten and not yet
        // replaced when the migration ran, so nothing was stood up — and then a
        // parent started the next goal an hour later.
        $household = $this->household(['goal_now' => 2000]);
        $this->recordOldDefeat($household, ['battle' => 3, 'health' => 2000]);

        $this->migrate();

        $this->assertTrue(app(MonsterService::class)->live($household)->isEmpty());

        DB::table('households')->where('id', $household->id)->update([
            'goal_name' => 'Bowling night',
            'goal_target' => 3000,
            'goal_now' => 0,
            'boss_battle' => 4,
        ]);

        $this->migrate();

        $standing = app(MonsterService::class)->at($household, MonsterTier::Three);

        $this->assertSame('Bowling night', $standing->reward_name);
        $this->assertSame(4, $standing->battle);
        $this->assertCount(1, app(MonsterService::class)->shelf($household));
    }

    public function test_the_family_goal_columns_are_left_untouched(): void
    {
        $household = $this->household();

        $this->migrate();

        // It copies rather than moves. On the day it ran these were still the
        // numbers on everyone's screen, and a data migration that guts its own
        // source has nothing to be re-run against if it got something wrong.
        $this->assertSame('Trip to the zoo', $this->goalColumn($household, 'goal_name'));
        $this->assertSame(2000, (int) $this->goalColumn($household, 'goal_target'));
        $this->assertSame(1200, (int) $this->goalColumn($household, 'goal_now'));
    }
}
