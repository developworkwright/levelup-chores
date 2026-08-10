<?php

namespace Tests\Feature;

use App\Enums\BossSkin;
use App\Enums\ChoreCadence;
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
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The parent's side of the arena: naming what each monster guards, pricing it,
 * and putting a mis-tapped chore where the kid meant it to go.
 */
class ParentMonsterDeckTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $parent;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->kid = Profile::factory()->for($this->household)->create(['name' => 'Nova']);
        $this->parent = Profile::factory()->parent()->for($this->household)->create();

        Auth::guard('profile')->login($this->parent);

        // Today's mystery is parked on a chore nothing here claims. Left to
        // draw itself it lands on whichever chore a test just made and adds its
        // 500-point bonus to the damage under assertion.
        DailyMystery::create([
            'household_id' => $this->household->id,
            'mystery_date' => HouseholdClock::for($this->household)->today(),
            'chore_id' => $this->chore('Mystery decoy', 10)->id,
        ]);
    }

    private function arena(): MonsterService
    {
        return app(MonsterService::class);
    }

    private function chore(string $name = 'Vacuum', int $points = 100): Chore
    {
        return Chore::factory()->for($this->household)->create([
            'name' => $name,
            'points' => $points,
            'cadence' => ChoreCadence::Daily,
            'min_age' => null,
        ]);
    }

    private function spawn(MonsterTier $tier, string $reward, int $health = 1000): Monster
    {
        return $this->arena()->spawn($this->household, $tier, $reward, $health);
    }

    public function test_a_kid_cannot_open_the_deck(): void
    {
        Auth::guard('profile')->login($this->kid);

        $this->get(route('parent.monsters'))->assertForbidden();
    }

    public function test_an_empty_tier_offers_to_have_one_sent_in(): void
    {
        Volt::test('parent.monsters')
            ->assertOk()
            ->assertSee('Monster Deck')
            ->assertSee('Nothing standing.')
            ->assertSee('Send it in');
    }

    public function test_a_parent_names_a_reward_and_stands_a_monster_up(): void
    {
        Volt::test('parent.monsters')
            ->set('rewardNames.1', 'Ice cream outing')
            ->set('rewardCosts.1', '15.00')
            ->set('healths.1', '500')
            ->call('spawn', 1);

        $monster = $this->arena()->at($this->household, MonsterTier::One);

        $this->assertSame('Ice cream outing', $monster->reward_name);
        $this->assertSame(500, $monster->max_health);
        $this->assertSame(1500, $monster->reward_cost_cents);
    }

    public function test_a_monster_needs_a_reward_and_real_health(): void
    {
        Volt::test('parent.monsters')
            ->set('rewardNames.1', '')
            ->set('healths.1', '500')
            ->call('spawn', 1)
            ->assertSee('Needs a reward');

        Volt::test('parent.monsters')
            ->set('rewardNames.1', 'Ice cream')
            ->set('healths.1', '5')
            ->call('spawn', 1)
            ->assertSee('Needs a reward');

        $this->assertNull($this->arena()->at($this->household, MonsterTier::One));
    }

    public function test_a_tier_already_holding_a_monster_refuses_a_second(): void
    {
        $this->spawn(MonsterTier::One, 'Ice cream');

        Volt::test('parent.monsters')
            ->set('rewardNames.1', 'Another one')
            ->set('healths.1', '500')
            ->call('spawn', 1);

        $this->assertSame(1, Monster::where('tier', MonsterTier::One)->count());
    }

    public function test_the_cost_per_hundred_points_is_shown_for_each_tier(): void
    {
        $monster = $this->spawn(MonsterTier::One, 'Ice cream', 500);
        $monster->forceFill(['reward_cost_cents' => 1500])->save();

        // $15 over 500 points is 3 cents a point — $3.00 per hundred.
        Volt::test('parent.monsters')
            ->assertOk()
            ->assertSee('$3.00 PER 100 PTS');
    }

    public function test_a_reward_can_be_renamed_and_repriced(): void
    {
        $this->spawn(MonsterTier::Two, 'Pizza night');

        Volt::test('parent.monsters')
            ->call('renameReward', 2, 'Bowling night')
            ->call('setRewardCost', 2, '42.50');

        $monster = $this->arena()->at($this->household, MonsterTier::Two);

        $this->assertSame('Bowling night', $monster->reward_name);
        $this->assertSame(4250, $monster->reward_cost_cents);
    }

    public function test_a_blank_cost_clears_rather_than_reading_as_free(): void
    {
        $monster = $this->spawn(MonsterTier::Two, 'Pizza night');
        $monster->forceFill(['reward_cost_cents' => 4250])->save();

        Volt::test('parent.monsters')->call('setRewardCost', 2, '');

        $this->assertNull($monster->fresh()->reward_cost_cents);
    }

    public function test_health_can_be_nudged_but_never_under_the_damage_done(): void
    {
        $monster = $this->spawn(MonsterTier::Three, 'Weekend away', 1000);
        $this->arena()->land($monster, 800, $this->kid);

        Volt::test('parent.monsters')->call('adjustHealth', 3, 250);
        $this->assertSame(1250, $monster->fresh()->max_health);

        // Four nudges down would land on 250, under the 800 already dealt —
        // which would be a bar that can never empty.
        foreach (range(1, 4) as $ignored) {
            Volt::test('parent.monsters')->call('adjustHealth', 3, -250);
        }

        $this->assertSame(800, $monster->fresh()->max_health);
    }

    public function test_a_damage_nudge_moves_the_bar_without_crediting_a_kid(): void
    {
        $monster = $this->spawn(MonsterTier::One, 'Ice cream', 1000);
        $this->arena()->land($monster, 100, $this->kid);

        Volt::test('parent.monsters')->call('adjustDamage', 1, 100);

        $this->assertSame(200, $monster->fresh()->damage());
        $this->assertSame(
            100,
            $this->arena()->contributionsFor($monster->fresh())->firstWhere('profile_id', $this->kid->id)['points'],
        );
    }

    public function test_a_nudge_that_finishes_a_monster_off_banks_the_kill(): void
    {
        $monster = $this->spawn(MonsterTier::One, 'Ice cream', 200);
        $this->arena()->land($monster, 100, $this->kid);

        Volt::test('parent.monsters')->call('adjustDamage', 1, 100);

        $this->assertTrue($monster->fresh()->isDefeated());
    }

    public function test_the_skin_can_be_swapped_per_tier(): void
    {
        $monster = $this->spawn(MonsterTier::One, 'Ice cream');

        Volt::test('parent.monsters')->call('setSkin', 1, BossSkin::MoldKing->value);

        $this->assertSame(BossSkin::MoldKing, $monster->fresh()->skin);
    }

    public function test_the_weak_chore_can_be_swapped_and_cleared(): void
    {
        $monster = $this->spawn(MonsterTier::One, 'Ice cream');
        $chore = $this->chore('Take out the trash');

        Volt::test('parent.monsters')->call('setWeakness', 1, (string) $chore->id);
        $this->assertSame($chore->id, $monster->fresh()->weak_chore_id);

        Volt::test('parent.monsters')->call('setWeakness', 1, '');
        $this->assertNull($monster->fresh()->weak_chore_id);
    }

    public function test_a_chore_another_monster_flinches_at_is_not_offered(): void
    {
        $taken = $this->chore('Dishes');
        $free = $this->chore('Vacuum');

        $one = $this->spawn(MonsterTier::One, 'Ice cream');
        $two = $this->spawn(MonsterTier::Two, 'Pizza night');

        $this->arena()->setWeakness($one, $taken);
        $this->arena()->setWeakness($two, $free);

        $names = $this->arena()->weakChoreOptions($this->household, $two->fresh())->pluck('name');

        // Its own stays on the list — a parent reopening the picker has to see
        // what it is currently set to — while the other monster's is gone.
        $this->assertContains('Vacuum', $names);
        $this->assertNotContains('Dishes', $names);
    }

    /** Claims a chore aimed at a tier and has it approved. */
    private function landAChore(Chore $chore, MonsterTier $tier): ChoreCompletion
    {
        $completion = app(ChoreService::class)->claim($this->kid, $chore, $tier);
        app(ChoreService::class)->approve($completion, $this->parent);

        return $completion->fresh();
    }

    public function test_a_mis_tapped_chore_can_be_moved_to_the_monster_the_kid_meant(): void
    {
        $one = $this->spawn(MonsterTier::One, 'Ice cream', 5000);
        $three = $this->spawn(MonsterTier::Three, 'Weekend away', 5000);
        $this->arena()->setWeakness($one, $this->chore('Decoy'));
        $this->arena()->setWeakness($three, $this->chore('Other decoy'));

        $completion = $this->landAChore($this->chore('Vacuum'), MonsterTier::One);

        $this->assertSame(100, $one->fresh()->damage());

        Volt::test('parent.monsters')
            ->call('reaim', $completion->id, MonsterTier::Three->value)
            ->assertSee('Moved to Level 3.');

        $this->assertSame(0, $one->fresh()->damage());
        $this->assertSame(100, $three->fresh()->damage());
    }

    public function test_a_moved_hit_keeps_the_kid_who_earned_it(): void
    {
        $one = $this->spawn(MonsterTier::One, 'Ice cream', 5000);
        $three = $this->spawn(MonsterTier::Three, 'Weekend away', 5000);
        $this->arena()->setWeakness($one, $this->chore('Decoy'));
        $this->arena()->setWeakness($three, $this->chore('Other decoy'));

        $completion = $this->landAChore($this->chore('Vacuum'), MonsterTier::One);

        Volt::test('parent.monsters')->call('reaim', $completion->id, MonsterTier::Three->value);

        $board = $this->arena()->contributionsFor($three->fresh());

        $this->assertSame(100, $board->firstWhere('profile_id', $this->kid->id)['points']);
        $this->assertSame(MonsterHitKind::Hit, MonsterHit::where('monster_id', $three->id)->sole()->kind);

        // And the completion now says where its damage actually went.
        $this->assertSame(MonsterTier::Three, $completion->fresh()->target_tier);
    }

    public function test_a_hit_cannot_be_moved_off_a_monster_already_beaten(): void
    {
        $one = $this->spawn(MonsterTier::One, 'Ice cream', 100);
        $three = $this->spawn(MonsterTier::Three, 'Weekend away', 5000);
        $this->arena()->setWeakness($one, $this->chore('Decoy'));
        $this->arena()->setWeakness($three, $this->chore('Other decoy'));

        // The chore that finishes it off.
        $completion = $this->landAChore($this->chore('Vacuum'), MonsterTier::One);

        $this->assertTrue($one->fresh()->isDefeated());

        Volt::test('parent.monsters')
            ->call('reaim', $completion->id, MonsterTier::Three->value)
            ->assertSee('already been beaten');

        $this->assertTrue($one->fresh()->isDefeated());
        $this->assertSame(100, $one->fresh()->damage());
    }

    public function test_a_hit_from_another_household_is_refused(): void
    {
        $this->spawn(MonsterTier::One, 'Ice cream', 5000);

        $elsewhere = Household::factory()->create();
        $theirKid = Profile::factory()->for($elsewhere)->create();
        $theirChore = Chore::factory()->for($elsewhere)->create(['points' => 100]);
        $theirCompletion = app(ChoreService::class)->claim($theirKid, $theirChore);

        Volt::test('parent.monsters')->call('reaim', $theirCompletion->id, MonsterTier::One->value);

        $this->assertSame(0, $this->arena()->at($this->household, MonsterTier::One)->damage());
    }

    public function test_the_hit_feed_names_the_chore_and_the_monster_it_hit(): void
    {
        $one = $this->spawn(MonsterTier::One, 'Ice cream', 5000);
        $this->arena()->setWeakness($one, $this->chore('Decoy'));
        $this->landAChore($this->chore('Vacuum'), MonsterTier::One);

        Volt::test('parent.monsters')
            ->assertOk()
            ->assertSee('Recent hits')
            ->assertSee('Vacuum')
            ->assertSee($one->fresh()->skin->label());
    }

    public function test_beaten_monsters_reach_the_trophy_shelf(): void
    {
        $monster = $this->spawn(MonsterTier::One, 'Ice cream', 100);
        $this->arena()->land($monster, 100, $this->kid);
        $this->arena()->settle($monster, $this->kid);

        Volt::test('parent.monsters')
            ->assertOk()
            ->assertSee('Trophy shelf')
            ->assertSee('Ice cream')
            ->assertSee('FINISHED BY NOVA');
    }

    public function test_the_kids_page_glances_at_the_arena_and_links_to_the_deck(): void
    {
        // The page draws each kid's daily quest, which needs something to draw.
        $this->chore('Vacuum');
        $this->spawn(MonsterTier::One, 'Ice cream', 500);

        Volt::test('parent.kids')
            ->assertOk()
            ->assertSee('Family Goals')
            ->assertSee('Ice cream')
            ->assertSee('Open the Monster Deck')
            ->assertSee(route('parent.monsters'));
    }

    public function test_the_kids_page_says_when_nothing_is_standing(): void
    {
        $this->chore('Vacuum');

        Volt::test('parent.kids')
            ->assertOk()
            ->assertSee('Nothing standing, so the kids have nothing to aim at.');
    }
}
