<?php

namespace Tests\Feature;

use App\Enums\ChoreCadence;
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
 * Tapping "done" on the quest board, and what the board shows of the fight.
 *
 * This file used to be about the picker — the overlay that stopped after every
 * finished chore to ask which of three monsters it landed on. The picker is
 * gone, and most of what is here now exists to make sure it stays gone: a claim
 * is one tap, start to finish, with nothing in between it and the board.
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
    private function spawn(string $reward): void
    {
        $monster = app(MonsterService::class)->spawn($this->household, $reward, 5000);
        app(MonsterService::class)->setWeakness($monster, $this->decoy);
    }

    private function damage(): int
    {
        return app(MonsterService::class)->current($this->household)?->damage() ?? 0;
    }

    public function test_finishing_a_chore_asks_nothing_and_claims_it(): void
    {
        $this->spawn('Weekend away');

        Volt::test('kid.quests')->call('claimChore', $this->chore->id);

        $this->assertSame($this->chore->id, ChoreCompletion::sole()->chore_id);
        $this->assertSame($this->kid->id, ChoreCompletion::sole()->profile_id);
    }

    public function test_an_empty_arena_claims_straight_through(): void
    {
        Volt::test('kid.quests')->call('claimChore', $this->chore->id);

        $this->assertSame($this->chore->id, ChoreCompletion::sole()->chore_id);
    }

    /**
     * The race the old picker's second pass existed to catch, still caught —
     * now by the one claimability check on the way in.
     */
    public function test_a_chore_a_sibling_already_took_is_not_claimed_twice(): void
    {
        $this->spawn('Weekend away');

        $sibling = Profile::factory()->for($this->household)->create(['name' => 'Pip']);
        app(ChoreService::class)->claim($sibling, $this->chore);

        Volt::test('kid.quests')
            ->call('claimChore', $this->chore->id)
            ->assertSee('got to Vacuum first');

        $this->assertSame($sibling->id, ChoreCompletion::sole()->profile_id);
    }

    public function test_the_arena_strip_names_what_the_monster_is_guarding(): void
    {
        $this->spawn('Weekend away');

        // The strip moved to Home; the Quests page keeps only the watcher.
        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Boss Fight')
            ->assertSee('Weekend away');
    }

    public function test_the_arena_page_draws_the_monster_standing(): void
    {
        $this->spawn('Weekend away');

        Volt::test('kid.arena')
            ->assertOk()
            ->assertSee('What the house is fighting')
            ->assertSee('Weekend away');
    }

    public function test_the_arena_page_says_so_when_the_arena_is_empty(): void
    {
        Volt::test('kid.arena')
            ->assertOk()
            ->assertSee('Nothing standing yet');
    }

    public function test_the_weak_chore_is_called_out_on_the_card(): void
    {
        $this->spawn('Weekend away');
        app(MonsterService::class)->setWeakness(
            app(MonsterService::class)->current($this->household),
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
        $this->spawn('Weekend away');

        Volt::test('kid.quests')->call('claimChore', $this->chore->id);

        $this->assertSame(0, $this->damage());

        $parent = Profile::factory()->parent()->for($this->household)->create();
        app(ChoreService::class)->approve(ChoreCompletion::sole(), $parent);

        $this->assertSame(100, $this->damage());
    }
}
