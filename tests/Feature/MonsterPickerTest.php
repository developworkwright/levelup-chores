<?php

namespace Tests\Feature;

use App\Enums\ChoreCadence;
use App\Enums\MonsterTier;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyMystery;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChoreService;
use App\Services\HouseholdClock;
use App\Services\MonsterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The moment the board stops and asks which monster a finished chore lands on.
 */
class MonsterPickerTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    private Chore $chore;

    /** A chore nothing under test claims, used to park the day's draws. */
    private Chore $decoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create([
            'require_quest_first' => false,
        ]);

        $this->kid = Profile::factory()->for($this->household)->create(['name' => 'Nova']);
        Auth::guard('profile')->login($this->kid);

        $this->chore = $this->makeChore('Vacuum');
        $decoy = $this->decoy = $this->makeChore('Decoy');
        $today = HouseholdClock::for($this->household)->today();

        // Both of the day's draws are parked on a chore no test claims. Left to
        // themselves either one could land on the chore under test — the
        // mystery would add its 500-point bonus to every number here, and the
        // daily quest would make the chore unclaimable from the board, since a
        // quest chore goes through its own path.
        DailyMystery::create([
            'household_id' => $this->household->id,
            'mystery_date' => $today,
            'chore_id' => $decoy->id,
        ]);

        DailyQuest::create([
            'household_id' => $this->household->id,
            'profile_id' => $this->kid->id,
            'chore_id' => $decoy->id,
            'quest_date' => $today->toDateString(),
            'revealed_at' => now(),
        ]);
    }

    private function makeChore(string $name): Chore
    {
        return Chore::factory()->for($this->household)->create([
            'name' => $name,
            'points' => 100,
            'cadence' => ChoreCadence::Daily,
            'min_age' => null,
        ]);
    }

    /**
     * A monster whose weak point is parked on the decoy, so the weekly draw is
     * settled and can't land on the chore a test is about to claim and quietly
     * double it. The test about weak points sets its own.
     */
    private function spawn(MonsterTier $tier, string $reward): void
    {
        $monster = app(MonsterService::class)->spawn($this->household, $tier, $reward, 5000);
        app(MonsterService::class)->setWeakness($monster, $this->decoy);
    }

    private function damageAt(MonsterTier $tier): int
    {
        return app(MonsterService::class)->at($this->household, $tier)?->damage() ?? 0;
    }

    public function test_finishing_a_chore_asks_which_monster_when_more_than_one_stands(): void
    {
        $this->spawn(MonsterTier::One, 'Ice cream');
        $this->spawn(MonsterTier::Three, 'Weekend away');

        Volt::test('kid.quests')
            ->call('claimChore', $this->chore->id)
            ->assertSet('targetingChoreId', $this->chore->id)
            ->assertSee('Who takes the hit?')
            ->assertSee('Ice cream')
            ->assertSee('Weekend away');

        // Nothing is claimed until they answer.
        $this->assertSame(0, ChoreCompletion::count());
    }

    public function test_one_monster_standing_is_not_a_question_worth_asking(): void
    {
        $this->spawn(MonsterTier::Two, 'Pizza night');

        Volt::test('kid.quests')
            ->call('claimChore', $this->chore->id)
            ->assertSet('targetingChoreId', null)
            ->assertDontSee('Who takes the hit?');

        $this->assertSame(MonsterTier::Two, ChoreCompletion::sole()->target_tier);
    }

    public function test_an_empty_arena_claims_straight_through(): void
    {
        Volt::test('kid.quests')
            ->call('claimChore', $this->chore->id)
            ->assertSet('targetingChoreId', null);

        $this->assertSame(1, ChoreCompletion::count());
        $this->assertNull(ChoreCompletion::sole()->target_tier);
    }

    public function test_picking_a_monster_claims_the_chore_against_it(): void
    {
        $this->spawn(MonsterTier::One, 'Ice cream');
        $this->spawn(MonsterTier::Three, 'Weekend away');

        Volt::test('kid.quests')
            ->call('claimChore', $this->chore->id)
            ->call('aimAt', MonsterTier::Three->value)
            ->assertSet('targetingChoreId', null)
            ->assertDontSee('Who takes the hit?');

        $this->assertSame(MonsterTier::Three, ChoreCompletion::sole()->target_tier);
    }

    public function test_the_pick_is_remembered_for_next_time(): void
    {
        $this->spawn(MonsterTier::One, 'Ice cream');
        $this->spawn(MonsterTier::Three, 'Weekend away');

        Volt::test('kid.quests')
            ->call('claimChore', $this->chore->id)
            ->call('aimAt', MonsterTier::One->value);

        $this->assertSame(MonsterTier::One, $this->kid->fresh()->last_monster_tier);
    }

    public function test_backing_out_claims_nothing(): void
    {
        $this->spawn(MonsterTier::One, 'Ice cream');
        $this->spawn(MonsterTier::Three, 'Weekend away');

        Volt::test('kid.quests')
            ->call('claimChore', $this->chore->id)
            ->call('cancelAim')
            ->assertSet('targetingChoreId', null)
            ->assertDontSee('Who takes the hit?');

        $this->assertSame(0, ChoreCompletion::count());
    }

    public function test_a_monster_that_falls_while_the_picker_is_open_is_refused(): void
    {
        $this->spawn(MonsterTier::One, 'Ice cream');
        $this->spawn(MonsterTier::Three, 'Weekend away');

        $component = Volt::test('kid.quests')->call('claimChore', $this->chore->id);

        // A sibling finishes it off between the question and the answer.
        $one = app(MonsterService::class)->at($this->household, MonsterTier::One);
        app(MonsterService::class)->land($one, 5000, $this->kid);
        app(MonsterService::class)->settle($one, $this->kid);

        $component->call('aimAt', MonsterTier::One->value)
            ->assertSet('targetingChoreId', null)
            ->assertSee('That one just went down!');

        $this->assertSame(0, ChoreCompletion::count());
    }

    public function test_a_chore_taken_by_a_sibling_mid_choice_is_not_claimed(): void
    {
        $this->spawn(MonsterTier::One, 'Ice cream');
        $this->spawn(MonsterTier::Three, 'Weekend away');

        $component = Volt::test('kid.quests')->call('claimChore', $this->chore->id);

        $sibling = Profile::factory()->for($this->household)->create(['name' => 'Pip']);
        app(ChoreService::class)->claim($sibling, $this->chore);

        $component->call('aimAt', MonsterTier::Three->value);

        // Theirs is the only claim on it — the second pass caught the race.
        $this->assertSame($sibling->id, ChoreCompletion::sole()->profile_id);
    }

    public function test_the_arena_strip_names_what_each_monster_is_guarding(): void
    {
        $this->spawn(MonsterTier::One, 'Ice cream');
        $this->spawn(MonsterTier::Three, 'Weekend away');

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('Boss Fight')
            ->assertSee('Ice cream')
            ->assertSee('Weekend away')
            ->assertSee('2 MONSTERS UP');
    }

    public function test_the_arena_page_draws_all_three_and_names_the_empty_ones(): void
    {
        $this->spawn(MonsterTier::One, 'Ice cream');
        $this->spawn(MonsterTier::Three, 'Weekend away');

        Volt::test('kid.arena')
            ->assertOk()
            ->assertSee('Three monsters, three rewards')
            ->assertSee('Ice cream')
            ->assertSee('Weekend away')
            // Level 2 has nobody in it, and a hole in the row would read as a
            // bug rather than an invitation.
            ->assertSee('Empty')
            ->assertSee('Worth a few weeks.');
    }

    public function test_the_arena_page_says_so_when_the_arena_is_empty(): void
    {
        // One empty state now, not two. The second line came from the Goals
        // page's "long game" panel, which said the same thing a second time
        // beside the arena row — both have moved, and only the arena's own
        // wording survived the move.
        Volt::test('kid.arena')
            ->assertOk()
            ->assertSee('Nothing standing yet');
    }

    public function test_the_weak_chore_is_called_out_on_the_card(): void
    {
        $this->spawn(MonsterTier::One, 'Ice cream');
        app(MonsterService::class)->setWeakness(
            app(MonsterService::class)->at($this->household, MonsterTier::One),
            $this->chore,
        );

        Volt::test('kid.arena')
            ->assertOk()
            ->assertSee('Flinches at')
            ->assertSee('Vacuum')
            ->assertSee('double damage');
    }

    public function test_the_damage_lands_once_a_parent_approves(): void
    {
        $this->spawn(MonsterTier::One, 'Ice cream');
        $this->spawn(MonsterTier::Three, 'Weekend away');

        Volt::test('kid.quests')
            ->call('claimChore', $this->chore->id)
            ->call('aimAt', MonsterTier::One->value);

        $this->assertSame(0, $this->damageAt(MonsterTier::One));

        $parent = Profile::factory()->parent()->for($this->household)->create();
        app(ChoreService::class)->approve(ChoreCompletion::sole(), $parent);

        $this->assertSame(100, $this->damageAt(MonsterTier::One));
        $this->assertSame(0, $this->damageAt(MonsterTier::Three));
    }
}
