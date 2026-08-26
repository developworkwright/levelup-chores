<?php

namespace Tests\Feature;

use App\Enums\BossSkin;
use App\Enums\ChoreCadence;
use App\Models\Chore;
use App\Models\DailyMystery;
use App\Models\Household;
use App\Models\Monster;
use App\Models\Profile;
use App\Services\HouseholdClock;
use App\Services\MonsterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The parent's side of the arena: naming what the monster guards, pricing it,
 * and setting how much work it takes to bring down.
 *
 * The re-aim tools that used to live here — a recent-hits feed with a "move to
 * another monster" button beside every blow — went with the tiers. There is
 * nowhere to move a hit to when only one monster stands, and the mis-tap they
 * fixed was the picker's, which no longer exists to be mis-tapped.
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

    private function spawn(string $reward, int $health = 1000): Monster
    {
        return $this->arena()->spawn($this->household, $reward, $health);
    }

    public function test_a_kid_cannot_open_the_deck(): void
    {
        Auth::guard('profile')->login($this->kid);

        $this->get(route('parent.monsters'))->assertForbidden();
    }

    public function test_an_empty_arena_offers_to_have_one_sent_in(): void
    {
        Volt::test('parent.monsters')
            ->assertOk()
            ->assertSee('Monster Deck')
            ->assertSee('Nothing standing')
            ->assertSee('Send it in');
    }

    public function test_a_parent_names_a_reward_and_stands_a_monster_up(): void
    {
        Volt::test('parent.monsters')
            ->set('rewardName', 'Ice cream outing')
            ->set('rewardCost', '15.00')
            ->set('health', '500')
            ->call('spawn');

        $monster = $this->arena()->current($this->household);

        $this->assertSame('Ice cream outing', $monster->reward_name);
        $this->assertSame(500, $monster->max_health);
        $this->assertSame(1500, $monster->reward_cost_cents);
    }

    public function test_a_monster_needs_a_reward_and_real_health(): void
    {
        Volt::test('parent.monsters')
            ->set('rewardName', '')
            ->set('health', '500')
            ->call('spawn')
            ->assertSee('Needs a reward');

        Volt::test('parent.monsters')
            ->set('rewardName', 'Ice cream')
            ->set('health', '5')
            ->call('spawn')
            ->assertSee('Needs a reward');

        $this->assertNull($this->arena()->current($this->household));
    }

    public function test_a_monster_already_standing_refuses_a_second(): void
    {
        $this->spawn('Ice cream');

        Volt::test('parent.monsters')
            ->set('rewardName', 'Another one')
            ->set('health', '500')
            ->call('spawn');

        $this->assertSame(1, Monster::where('household_id', $this->household->id)->count());
    }

    public function test_the_cost_per_hundred_points_is_shown(): void
    {
        $monster = $this->spawn('Ice cream', 500);
        $monster->forceFill(['reward_cost_cents' => 1500])->save();

        // $15 over 500 points is 3 cents a point — $3.00 per hundred.
        Volt::test('parent.monsters')
            ->assertOk()
            ->assertSee('$3.00 PER 100 PTS');
    }

    public function test_a_reward_can_be_renamed_and_repriced(): void
    {
        $this->spawn('Pizza night');

        Volt::test('parent.monsters')
            ->call('renameReward', 'Bowling night')
            ->call('setRewardCost', '42.50');

        $monster = $this->arena()->current($this->household);

        $this->assertSame('Bowling night', $monster->reward_name);
        $this->assertSame(4250, $monster->reward_cost_cents);
    }

    public function test_a_blank_cost_clears_rather_than_reading_as_free(): void
    {
        $monster = $this->spawn('Pizza night');
        $monster->forceFill(['reward_cost_cents' => 4250])->save();

        Volt::test('parent.monsters')->call('setRewardCost', '');

        $this->assertNull($monster->fresh()->reward_cost_cents);
    }

    public function test_health_can_be_nudged_but_never_under_the_damage_done(): void
    {
        $monster = $this->spawn('Weekend away', 1000);
        $this->arena()->land($monster, 800, $this->kid);

        Volt::test('parent.monsters')->call('adjustHealth', 250);
        $this->assertSame(1250, $monster->fresh()->max_health);

        // Four nudges down would land on 250, under the 800 already dealt —
        // which would be a bar that can never empty.
        foreach (range(1, 4) as $ignored) {
            Volt::test('parent.monsters')->call('adjustHealth', -250);
        }

        $this->assertSame(800, $monster->fresh()->max_health);
    }

    public function test_a_damage_nudge_moves_the_bar_without_crediting_a_kid(): void
    {
        $monster = $this->spawn('Ice cream', 1000);
        $this->arena()->land($monster, 100, $this->kid);

        Volt::test('parent.monsters')->call('adjustDamage', 100);

        $this->assertSame(200, $monster->fresh()->damage());
        $this->assertSame(
            100,
            $this->arena()->contributionsFor($monster->fresh())->firstWhere('profile_id', $this->kid->id)['points'],
        );
    }

    public function test_a_nudge_that_finishes_a_monster_off_banks_the_kill(): void
    {
        $monster = $this->spawn('Ice cream', 200);
        $this->arena()->land($monster, 100, $this->kid);

        Volt::test('parent.monsters')->call('adjustDamage', 100);

        $this->assertTrue($monster->fresh()->isDefeated());
    }

    public function test_the_skin_can_be_swapped(): void
    {
        $monster = $this->spawn('Ice cream');

        Volt::test('parent.monsters')->call('setSkin', BossSkin::MoldKing->value);

        $this->assertSame(BossSkin::MoldKing, $monster->fresh()->skin);
    }

    public function test_the_weak_chore_can_be_swapped_and_cleared(): void
    {
        $monster = $this->spawn('Ice cream');
        $chore = $this->chore('Take out the trash');

        Volt::test('parent.monsters')->call('setWeakness', (string) $chore->id);
        $this->assertSame($chore->id, $monster->fresh()->weak_chore_id);

        Volt::test('parent.monsters')->call('setWeakness', '');
        $this->assertNull($monster->fresh()->weak_chore_id);
    }

    public function test_the_weak_chore_picker_offers_the_board(): void
    {
        $this->chore('Dishes');
        $this->chore('Vacuum');
        $this->spawn('Ice cream');

        $names = $this->arena()->weakChorePool($this->household)->pluck('name');

        // Nothing to exclude any more — a second monster's weak point was the
        // only thing that ever came off this list.
        $this->assertContains('Vacuum', $names);
        $this->assertContains('Dishes', $names);
    }

    public function test_the_deck_names_the_monster_and_its_progress(): void
    {
        $monster = $this->spawn('Ice cream', 1000);
        $this->arena()->land($monster, 250, $this->kid);

        Volt::test('parent.monsters')
            ->assertOk()
            ->assertSee($monster->skin->label())
            ->assertSee('250 / 1,000 PTS');
    }

    public function test_beaten_monsters_reach_the_trophy_shelf(): void
    {
        $monster = $this->spawn('Ice cream', 100);
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
        $this->spawn('Ice cream', 500);

        Volt::test('parent.kids')
            ->assertOk()
            ->assertSee('Family Goal')
            ->assertSee('Ice cream')
            ->assertSee('Open the Monster Deck')
            ->assertSee(route('parent.monsters'));
    }

    public function test_the_kids_page_says_when_nothing_is_standing(): void
    {
        $this->chore('Vacuum');

        Volt::test('parent.kids')
            ->assertOk()
            ->assertSee("Nothing standing, so the kids' work has nothing to land on.", false);
    }
}
