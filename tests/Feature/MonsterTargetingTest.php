<?php

namespace Tests\Feature;

use App\Enums\ChoreCadence;
use App\Enums\CompletionStatus;
use App\Enums\MonsterHitKind;
use App\Enums\MonsterTier;
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
 * A chore becoming damage: which monster it lands on, what a weak point is
 * worth, and where the excess goes when it kills.
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

    /** Claims a chore at a tier and has the parent sign it off. */
    private function complete(Chore $chore, ?MonsterTier $target = null): ChoreCompletion
    {
        $completion = $this->chores()->claim($this->kid, $chore, $target);
        $this->chores()->approve($completion, $this->parent);

        return $completion->fresh();
    }

    /**
     * A monster whose weak point is parked on the decoy, so the weekly draw is
     * already settled and can't land on the chore a test is about to claim and
     * silently double it. Tests about weak points set their own.
     */
    private function spawn(MonsterTier $tier, int $health, string $reward = 'Reward'): Monster
    {
        $monster = $this->arena()->spawn($this->household, $tier, $reward, $health);
        $this->arena()->setWeakness($monster, $this->decoy);

        return $monster;
    }

    private function damageAt(MonsterTier $tier): int
    {
        return $this->arena()->at($this->household, $tier)?->damage() ?? 0;
    }

    public function test_a_chore_damages_the_tier_the_kid_aimed_at(): void
    {
        $this->spawn(MonsterTier::One, 500);
        $this->spawn(MonsterTier::Two, 500);

        $this->complete($this->chore(100), MonsterTier::Two);

        $this->assertSame(0, $this->damageAt(MonsterTier::One));
        $this->assertSame(100, $this->damageAt(MonsterTier::Two));
    }

    public function test_the_choice_is_recorded_when_the_kid_commits(): void
    {
        $this->spawn(MonsterTier::One, 500);

        $completion = $this->chores()->claim($this->kid, $this->chore(100), MonsterTier::One);

        $this->assertSame(MonsterTier::One, $completion->target_tier);
    }

    public function test_an_unaimed_chore_hits_the_highest_tier_standing(): void
    {
        $this->spawn(MonsterTier::One, 500);
        $this->spawn(MonsterTier::Three, 500);

        $this->complete($this->chore(100));

        $this->assertSame(0, $this->damageAt(MonsterTier::One));
        $this->assertSame(100, $this->damageAt(MonsterTier::Three));
    }

    public function test_a_weak_point_lands_double(): void
    {
        $monster = $this->spawn(MonsterTier::One, 500);
        $chore = $this->chore(100);
        $this->arena()->setWeakness($monster, $chore);

        $this->complete($chore, MonsterTier::One);

        $this->assertSame(200, $this->damageAt(MonsterTier::One));
    }

    public function test_a_weak_point_only_counts_on_the_monster_that_flinches_at_it(): void
    {
        $this->spawn(MonsterTier::One, 500);
        $two = $this->spawn(MonsterTier::Two, 500);
        $chore = $this->chore(100);
        $this->arena()->setWeakness($two, $chore);

        $this->complete($chore, MonsterTier::One);

        $this->assertSame(100, $this->damageAt(MonsterTier::One));
    }

    public function test_swapping_the_weak_chore_cannot_halve_a_hit_already_aimed(): void
    {
        $monster = $this->spawn(MonsterTier::One, 500);
        $chore = $this->chore(100);
        $other = $this->chore(100, 'Dishes');
        $this->arena()->setWeakness($monster, $chore);

        // Kid taps done while the vacuum is the weak point...
        $completion = $this->chores()->claim($this->kid, $chore, MonsterTier::One);

        // ...a parent swaps it before getting round to approving.
        $this->arena()->setWeakness($monster->fresh(), $other);

        $this->chores()->approve($completion, $this->parent);

        $this->assertSame(200, $this->damageAt(MonsterTier::One));
    }

    public function test_overkill_spills_onto_the_tier_above(): void
    {
        $this->spawn(MonsterTier::One, 60);
        $this->spawn(MonsterTier::Two, 500);

        $this->complete($this->chore(100), MonsterTier::One);

        $this->assertSame(60, $this->arena()->shelf($this->household)->sole()->damage());
        $this->assertSame(40, $this->damageAt(MonsterTier::Two));
    }

    public function test_a_spill_is_marked_as_one_but_still_belongs_to_the_kid(): void
    {
        $this->spawn(MonsterTier::One, 60);
        $two = $this->spawn(MonsterTier::Two, 500);

        $this->complete($this->chore(100), MonsterTier::One);

        $spill = MonsterHit::where('monster_id', $two->id)->sole();

        $this->assertSame(MonsterHitKind::Spill, $spill->kind);
        $this->assertSame($this->kid->id, $spill->profile_id);
        $this->assertSame(40, $this->arena()->contributionsFor($two->fresh())->firstWhere('profile_id', $this->kid->id)['points']);
    }

    public function test_one_chore_can_finish_off_two_monsters(): void
    {
        $this->spawn(MonsterTier::One, 30, 'Ice cream');
        $this->spawn(MonsterTier::Two, 30, 'Pizza night');
        $this->spawn(MonsterTier::Three, 5000, 'Weekend away');

        $this->complete($this->chore(100), MonsterTier::One);

        $this->assertSame(
            ['Pizza night', 'Ice cream'],
            $this->arena()->shelf($this->household)->pluck('reward_name')->all(),
        );
        $this->assertSame(40, $this->damageAt(MonsterTier::Three));
    }

    public function test_the_finisher_is_credited_on_every_monster_a_cascade_takes_down(): void
    {
        $this->spawn(MonsterTier::One, 30);
        $this->spawn(MonsterTier::Two, 30);

        $this->complete($this->chore(100), MonsterTier::One);

        foreach ($this->arena()->shelf($this->household) as $beaten) {
            $this->assertSame($this->kid->id, $beaten->finisher_profile_id);
        }
    }

    public function test_a_hit_aimed_at_an_empty_tier_climbs_rather_than_vanishing(): void
    {
        $this->spawn(MonsterTier::Three, 500);

        $this->complete($this->chore(100), MonsterTier::One);

        $this->assertSame(100, $this->damageAt(MonsterTier::Three));

        // It skipped two empty tiers on the way up, so it is still the kid's
        // own blow rather than something that rolled off a monster.
        $this->assertSame(MonsterHitKind::Hit, MonsterHit::sole()->kind);
    }

    public function test_a_tier_beaten_before_approval_sends_the_hit_upward(): void
    {
        $one = $this->spawn(MonsterTier::One, 100);
        $this->spawn(MonsterTier::Two, 500);

        $completion = $this->chores()->claim($this->kid, $this->chore(100), MonsterTier::One);

        // A sibling finishes it off while this one waits on a parent.
        $this->arena()->land($one, 100, $this->kid);
        $this->arena()->settle($one, $this->kid);

        $this->chores()->approve($completion, $this->parent);

        $this->assertSame(100, $this->damageAt(MonsterTier::Two));
    }

    public function test_an_empty_arena_takes_no_damage_and_breaks_nothing(): void
    {
        $this->complete($this->chore(100));

        $this->assertSame(0, MonsterHit::count());
        $this->assertSame(100, $this->kid->fresh()->points);
    }

    public function test_the_kid_keeps_their_own_points_whichever_monster_they_pick(): void
    {
        $this->spawn(MonsterTier::One, 500);

        $this->complete($this->chore(100), MonsterTier::One);

        // Damage on the monster is a *shadow* of the same points, not a spend.
        // Choosing between the three costs a kid nothing, which is what makes
        // it a negotiation between siblings rather than a sacrifice.
        $this->assertSame(100, $this->kid->fresh()->points);
        $this->assertSame(100, $this->damageAt(MonsterTier::One));
    }

    public function test_a_rejected_chore_never_reaches_the_arena(): void
    {
        $this->spawn(MonsterTier::One, 500);

        $completion = $this->chores()->claim($this->kid, $this->chore(100), MonsterTier::One);
        $this->chores()->sendBack($completion, $this->parent);

        $this->assertSame(0, $this->damageAt(MonsterTier::One));
        $this->assertSame(CompletionStatus::Rejected, $completion->fresh()->status);
    }

    public function test_approving_twice_lands_the_damage_once(): void
    {
        $this->spawn(MonsterTier::One, 500);

        $completion = $this->chores()->claim($this->kid, $this->chore(100), MonsterTier::One);
        $this->chores()->approve($completion, $this->parent);
        $this->chores()->approve($completion, $this->parent);

        $this->assertSame(100, $this->damageAt(MonsterTier::One));
    }

    public function test_weak_points_are_drawn_once_a_week_and_are_all_different(): void
    {
        foreach (range(1, 6) as $index) {
            $this->chore(50, "Chore {$index}");
        }

        // Straight from the service: these need monsters that have never had a
        // weak point drawn, which is exactly what the helper above prevents.
        foreach (MonsterTier::cases() as $tier) {
            $this->arena()->spawn($this->household, $tier, 'Reward', 500);
        }

        $live = $this->arena()->rotateWeaknesses($this->household);
        $drawn = $live->map(fn (Monster $monster) => $monster->weak_chore_id)->all();

        $this->assertCount(3, array_filter($drawn));
        $this->assertCount(3, array_unique($drawn), 'One chore must not be everybody\'s weak point.');

        // A second look the same week finds the same board.
        $this->household->unsetRelation('chores');
        $again = $this->arena()->rotateWeaknesses($this->household->fresh());

        $this->assertSame($drawn, $again->map(fn (Monster $monster) => $monster->weak_chore_id)->all());
    }

    public function test_a_hand_picked_weak_chore_is_left_alone_for_the_rest_of_the_week(): void
    {
        $this->chore(50, 'Chore A');
        $chosen = $this->chore(50, 'Chore B');
        $monster = $this->spawn(MonsterTier::One, 500);

        $this->arena()->rotateWeaknesses($this->household);
        $this->arena()->setWeakness($monster->fresh(), $chosen);

        $this->household->unsetRelation('chores');
        $this->arena()->rotateWeaknesses($this->household->fresh());

        $this->assertSame($chosen->id, $monster->fresh()->weak_chore_id);
    }

    public function test_a_hand_picked_weak_chore_does_not_survive_into_next_week(): void
    {
        $this->chore(50, 'Chore A');
        $chosen = $this->chore(50, 'Chore B');
        $monster = $this->spawn(MonsterTier::One, 500);

        $this->arena()->rotateWeaknesses($this->household);
        $this->arena()->setWeakness($monster->fresh(), $chosen);

        // The stamp is what the draw reads, and it was left on last week.
        $monster->fresh()->forceFill(['weak_rotated_on' => now()->subWeeks(2)])->save();

        $this->travelTo(now()->addWeek());
        $this->household->unsetRelation('chores');
        $this->arena()->rotateWeaknesses($this->household->fresh());

        $this->assertNotNull($monster->fresh()->weak_chore_id);
        $this->assertNotNull($monster->fresh()->weak_rotated_on);
    }

    public function test_a_weak_chore_from_another_household_is_refused(): void
    {
        $monster = $this->spawn(MonsterTier::One, 500);
        $elsewhere = Chore::factory()->create(['name' => 'Not ours']);

        $this->expectException(\RuntimeException::class);

        $this->arena()->setWeakness($monster, $elsewhere);
    }

    public function test_undoing_a_day_takes_its_damage_back_off_the_monsters(): void
    {
        $this->spawn(MonsterTier::One, 500);

        $this->complete($this->chore(100), MonsterTier::One);

        $this->assertSame(100, $this->damageAt(MonsterTier::One));

        $this->artisan('quest:reset-today')->assertSuccessful();

        $this->assertSame(0, $this->damageAt(MonsterTier::One));
        $this->assertSame(0, MonsterHit::count());
    }
}
