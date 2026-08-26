<?php

namespace Tests\Feature;

use App\Enums\ChoreCadence;
use App\Enums\CompletionStatus;
use App\Enums\MonsterHitKind;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyMystery;
use App\Models\Household;
use App\Models\Monster;
use App\Models\MonsterHit;
use App\Models\Profile;
use App\Services\ChoreService;
use App\Services\HouseholdClock;
use App\Services\MonsterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A chore becoming damage: that it lands on the monster standing, what a weak
 * point is worth, and what happens when there is nothing to hit.
 *
 * There is no aiming any more. Three monsters stood here once and every claim
 * stopped to ask which of them it was for; the tests that covered that question
 * went with it, and what is left is the part that always mattered — the work
 * lands, once, on the fight in front of the family.
 */
class MonsterTargetingTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    private Profile $parent;

    /** A chore nothing under test ever claims, used to park the day's noise. */
    private Chore $decoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->kid = Profile::factory()->for($this->household)->create(['name' => 'Nova']);
        $this->parent = Profile::factory()->parent()->for($this->household)->create();

        $this->decoy = $this->chore(10, 'Decoy');

        // Today's mystery is pinned to that decoy. Left to draw itself it would
        // land on the chore under test and quietly add its 500-point bonus to
        // every number here.
        DailyMystery::create([
            'household_id' => $this->household->id,
            'mystery_date' => HouseholdClock::for($this->household)->today(),
            'chore_id' => $this->decoy->id,
        ]);
    }

    private function chores(): ChoreService
    {
        return app(ChoreService::class);
    }

    private function arena(): MonsterService
    {
        return app(MonsterService::class);
    }

    private function chore(int $points = 100, string $name = 'Vacuum'): Chore
    {
        return Chore::factory()->for($this->household)->create([
            'name' => $name,
            'points' => $points,
            'cadence' => ChoreCadence::Daily,
            'min_age' => null,
        ]);
    }

    /** Claims a chore and has the parent sign it off. */
    private function complete(Chore $chore): ChoreCompletion
    {
        $completion = $this->chores()->claim($this->kid, $chore);
        $this->chores()->approve($completion, $this->parent);

        return $completion->fresh();
    }

    /**
     * A monster whose weak point is parked on the decoy, so the weekly draw is
     * already settled and can't land on the chore a test is about to claim and
     * silently double it. Tests about weak points set their own.
     */
    private function spawn(int $health, string $reward = 'Reward'): Monster
    {
        $monster = $this->arena()->spawn($this->household, $reward, $health);
        $this->arena()->setWeakness($monster, $this->decoy);

        return $monster;
    }

    private function damage(): int
    {
        return $this->arena()->current($this->household)?->damage() ?? 0;
    }

    public function test_an_approved_chore_damages_the_monster_standing(): void
    {
        $this->spawn(500);

        $this->complete($this->chore(100));

        $this->assertSame(100, $this->damage());
    }

    public function test_a_claim_asks_the_kid_nothing_and_lands_all_the_same(): void
    {
        $this->spawn(500);

        // The whole point of the collapse: claim() takes the kid and the chore,
        // and there is no third argument for them to answer.
        $completion = $this->chores()->claim($this->kid, $this->chore(100));

        $this->assertSame(CompletionStatus::Pending, $completion->status);
        $this->assertSame(0, $this->damage(), 'Nothing lands until a parent approves.');

        $this->chores()->approve($completion, $this->parent);

        $this->assertSame(100, $this->damage());
    }

    public function test_a_weak_point_lands_double(): void
    {
        $monster = $this->spawn(500);
        $chore = $this->chore(100);
        $this->arena()->setWeakness($monster, $chore);

        $this->complete($chore);

        $this->assertSame(200, $this->damage());
    }

    public function test_an_ordinary_chore_lands_once(): void
    {
        $monster = $this->spawn(500);
        $this->arena()->setWeakness($monster, $this->chore(100, 'Dishes'));

        $this->complete($this->chore(100));

        $this->assertSame(100, $this->damage());
    }

    public function test_the_weak_point_is_frozen_at_the_moment_the_kid_commits(): void
    {
        $monster = $this->spawn(500);
        $chore = $this->chore(100);
        $other = $this->chore(100, 'Dishes');
        $this->arena()->setWeakness($monster, $chore);

        // Kid taps done while the vacuum is the weak point...
        $completion = $this->chores()->claim($this->kid, $chore);

        $this->assertTrue($completion->struck_weak_point);

        // ...a parent swaps it before getting round to approving.
        $this->arena()->setWeakness($monster->fresh(), $other);

        $this->chores()->approve($completion, $this->parent);

        $this->assertSame(200, $this->damage());
    }

    public function test_overkill_stops_at_the_kill(): void
    {
        $this->spawn(60);

        $this->complete($this->chore(100));

        // 60 of the 100 stuck. The other 40 has nowhere to go now the tier
        // above it is gone, and banking it against a monster nobody has named
        // yet would be a currency of its own.
        $beaten = $this->arena()->shelf($this->household)->sole();

        $this->assertSame(60, $beaten->damage());
        $this->assertNull($this->arena()->current($this->household));
    }

    public function test_a_killing_blow_credits_the_kid_who_landed_it(): void
    {
        $this->spawn(100);

        $this->complete($this->chore(100));

        $beaten = $this->arena()->shelf($this->household)->sole();

        $this->assertSame($this->kid->id, $beaten->finisher_profile_id);
        $this->assertSame(MonsterHitKind::Hit, MonsterHit::sole()->kind);
    }

    public function test_a_monster_beaten_before_approval_takes_nothing_further(): void
    {
        $monster = $this->spawn(100);

        $completion = $this->chores()->claim($this->kid, $this->chore(100));

        // A sibling finishes it off while this one waits on a parent.
        $this->arena()->land($monster, 100, $this->kid);
        $this->arena()->settle($monster, $this->kid);

        $this->chores()->approve($completion, $this->parent);

        $this->assertSame(100, $this->arena()->shelf($this->household)->sole()->damage());
        $this->assertSame(100, $this->kid->fresh()->points, 'The kid still keeps their points.');
    }

    public function test_an_empty_arena_takes_no_damage_and_breaks_nothing(): void
    {
        $this->complete($this->chore(100));

        $this->assertSame(0, MonsterHit::count());
        $this->assertSame(100, $this->kid->fresh()->points);
    }

    public function test_the_kid_keeps_their_own_points_as_well_as_moving_the_bar(): void
    {
        $this->spawn(500);

        $this->complete($this->chore(100));

        // Damage on the monster is a *shadow* of the same points, not a spend.
        $this->assertSame(100, $this->kid->fresh()->points);
        $this->assertSame(100, $this->damage());
    }

    public function test_a_rejected_chore_never_reaches_the_arena(): void
    {
        $this->spawn(500);

        $completion = $this->chores()->claim($this->kid, $this->chore(100));
        $this->chores()->sendBack($completion, $this->parent);

        $this->assertSame(0, $this->damage());
        $this->assertSame(CompletionStatus::Rejected, $completion->fresh()->status);
    }

    public function test_approving_twice_lands_the_damage_once(): void
    {
        $this->spawn(500);

        $completion = $this->chores()->claim($this->kid, $this->chore(100));
        $this->chores()->approve($completion, $this->parent);
        $this->chores()->approve($completion, $this->parent);

        $this->assertSame(100, $this->damage());
    }

    public function test_a_weak_point_is_drawn_once_a_week(): void
    {
        foreach (range(1, 6) as $index) {
            $this->chore(50, "Chore {$index}");
        }

        // Straight from the service: this needs a monster that has never had a
        // weak point drawn, which is exactly what the helper above prevents.
        $this->arena()->spawn($this->household, 'Reward', 500);

        $drawn = $this->arena()->rotateWeakness($this->household)->weak_chore_id;

        $this->assertNotNull($drawn);

        // A second look the same week finds the same board.
        $this->household->unsetRelation('chores');
        $again = $this->arena()->rotateWeakness($this->household->fresh());

        $this->assertSame($drawn, $again->weak_chore_id);
    }

    public function test_a_hand_picked_weak_chore_is_left_alone_for_the_rest_of_the_week(): void
    {
        $this->chore(50, 'Chore A');
        $chosen = $this->chore(50, 'Chore B');
        $monster = $this->spawn(500);

        $this->arena()->rotateWeakness($this->household);
        $this->arena()->setWeakness($monster->fresh(), $chosen);

        $this->household->unsetRelation('chores');
        $this->arena()->rotateWeakness($this->household->fresh());

        $this->assertSame($chosen->id, $monster->fresh()->weak_chore_id);
    }

    public function test_a_hand_picked_weak_chore_does_not_survive_into_next_week(): void
    {
        $this->chore(50, 'Chore A');
        $chosen = $this->chore(50, 'Chore B');
        $monster = $this->spawn(500);

        $this->arena()->rotateWeakness($this->household);
        $this->arena()->setWeakness($monster->fresh(), $chosen);

        // The stamp is what the draw reads, and it was left on last week.
        $monster->fresh()->forceFill(['weak_rotated_on' => now()->subWeeks(2)])->save();

        $this->travelTo(now()->addWeek());
        $this->household->unsetRelation('chores');
        $this->arena()->rotateWeakness($this->household->fresh());

        $this->assertNotNull($monster->fresh()->weak_chore_id);
        $this->assertNotNull($monster->fresh()->weak_rotated_on);
    }

    public function test_a_weak_chore_from_another_household_is_refused(): void
    {
        $monster = $this->spawn(500);
        $elsewhere = Chore::factory()->create(['name' => 'Not ours']);

        $this->expectException(\RuntimeException::class);

        $this->arena()->setWeakness($monster, $elsewhere);
    }

    public function test_undoing_a_day_takes_its_damage_back_off_the_monster(): void
    {
        $this->spawn(500);

        $this->complete($this->chore(100));

        $this->assertSame(100, $this->damage());

        $this->artisan('quest:reset-today')->assertSuccessful();

        $this->assertSame(0, $this->damage());
        $this->assertSame(0, MonsterHit::count());
    }
}
