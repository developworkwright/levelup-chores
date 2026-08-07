<?php

namespace Tests\Feature;

use App\Enums\BossSkin;
use App\Enums\BossStage;
use App\Enums\CompletionStatus;
use App\Enums\LedgerKind;
use App\Models\BossDefeat;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Household;
use App\Models\LedgerEntry;
use App\Models\Profile;
use App\Services\BossService;
use App\Services\ChoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BossBattleTest extends TestCase
{
    use RefreshDatabase;

    private function boss(): BossService
    {
        return app(BossService::class);
    }

    private function chores(): ChoreService
    {
        return app(ChoreService::class);
    }

    /** A kid who has already watched the fight up to the damage given. */
    private function caughtUp(Profile $kid, Household $household, int $damage): Profile
    {
        $this->boss()->skinFor($household);

        $kid->forceFill([
            'boss_damage_seen' => $damage,
            'boss_battle_seen' => $household->fresh()->boss_battle,
        ])->save();

        return $kid->fresh();
    }

    /** Claims a chore for the kid and has the parent sign it off. */
    private function completeChore(Profile $kid, Profile $parent, Chore $chore): void
    {
        $this->chores()->claim($kid, $chore);

        $completion = ChoreCompletion::where('profile_id', $kid->id)
            ->where('chore_id', $chore->id)
            ->where('status', CompletionStatus::Pending)
            ->latest('id')
            ->firstOrFail();

        $this->chores()->approve($completion, $parent);
    }

    public function test_the_goal_is_drawn_as_health_the_family_is_taking_off(): void
    {
        $household = Household::factory()->create(['goal_target' => 1000, 'goal_now' => 250]);

        $state = $this->boss()->stateFor($household);

        $this->assertSame(1000, $state['maxHealth']);
        $this->assertSame(750, $state['health']);
        $this->assertSame(75, $state['healthPercent']);
        $this->assertSame(25, $state['damagePercent']);
        $this->assertSame(BossStage::Angry, $state['stage']);
        $this->assertFalse($state['defeated']);
    }

    public function test_a_household_with_no_target_has_no_monster(): void
    {
        $household = Household::factory()->create(['goal_target' => 0, 'goal_now' => 0]);

        $this->assertNull($this->boss()->stateFor($household));
    }

    public function test_a_boss_is_assigned_on_first_look_and_then_sticks(): void
    {
        $household = Household::factory()->create(['boss_key' => null, 'boss_started_at' => null]);

        $first = $this->boss()->skinFor($household);

        $this->assertSame(BossSkin::default(), $first);
        $this->assertNotNull($household->fresh()->boss_started_at);
        $this->assertSame($first, $this->boss()->skinFor($household->fresh()));
    }

    public function test_a_boss_on_its_last_points_never_rounds_up_to_untouched(): void
    {
        // 1 point left of 10,000 rounds to 0% on the nose — which would read as
        // a dead monster still standing.
        $household = Household::factory()->create(['goal_target' => 10000, 'goal_now' => 9999]);

        $state = $this->boss()->stateFor($household);

        $this->assertSame(1, $state['healthPercent']);
        $this->assertSame(BossStage::Desperate, $state['stage']);
        $this->assertFalse($state['defeated']);
    }

    public function test_finishing_the_goal_banks_a_defeat_naming_who_landed_it(): void
    {
        $household = Household::factory()->create(['goal_target' => 100, 'goal_now' => 0]);
        $kid = Profile::factory()->for($household)->create();
        $parent = Profile::factory()->for($household)->parent()->create();
        $chore = Chore::factory()->for($household)->create(['points' => 100, 'quest_eligible' => false]);

        $this->completeChore($kid, $parent, $chore);

        $defeat = BossDefeat::where('household_id', $household->id)->firstOrFail();

        $this->assertSame($kid->id, $defeat->finisher_profile_id);
        $this->assertSame(100, $defeat->health);
        $this->assertSame(BossSkin::default(), $defeat->boss_key);
        $this->assertSame(BossSkin::default()->label(), $defeat->boss_name);
        $this->assertSame(BossStage::Defeated, $this->boss()->stateFor($household->fresh())['stage']);
    }

    public function test_the_defeat_freezes_who_did_what(): void
    {
        $household = Household::factory()->create(['goal_target' => 100, 'goal_now' => 0]);
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $parent = Profile::factory()->for($household)->parent()->create();
        $chore = Chore::factory()->for($household)->create(['points' => 100, 'quest_eligible' => false]);

        $this->completeChore($kid, $parent, $chore);

        $defeat = BossDefeat::where('household_id', $household->id)->firstOrFail();

        $this->assertSame('Nova', $defeat->contributions[0]['name']);
        $this->assertSame(100, $defeat->contributions[0]['points']);

        // The snapshot has to survive the next goal wiping the live figures.
        $household->profiles()->update(['goal_contribution' => 0]);
        $this->assertSame(100, $defeat->fresh()->contributions[0]['points']);
    }

    public function test_a_battle_is_only_ever_banked_once(): void
    {
        $household = Household::factory()->create(['goal_target' => 100, 'goal_now' => 100]);
        $kid = Profile::factory()->for($household)->create();

        $this->assertNotNull($this->boss()->recordDefeat($household, $kid));
        $this->assertNull($this->boss()->recordDefeat($household->fresh(), $kid));
        $this->assertSame(1, BossDefeat::where('household_id', $household->id)->count());
    }

    public function test_the_beaten_monster_stays_standing_until_a_new_goal_starts(): void
    {
        $household = Household::factory()->create(['goal_target' => 100, 'goal_now' => 100]);
        $kid = Profile::factory()->for($household)->create();

        $this->boss()->recordDefeat($household, $kid);

        // Rotating here would swap in a fresh monster against a bar still
        // reading 100% damage — the new one would render as already dead.
        $this->assertSame(BossSkin::default(), $household->fresh()->boss_key);

        $this->boss()->startNewBattle($household);

        $this->assertSame(BossSkin::default()->next(), $household->fresh()->boss_key);
    }

    public function test_the_rotation_wraps_round_the_full_set(): void
    {
        $household = Household::factory()->create();
        $seen = [];

        foreach (BossSkin::cases() as $ignored) {
            $seen[] = $this->boss()->skinFor($household->fresh());
            $this->boss()->startNewBattle($household);
        }

        $this->assertSame(BossSkin::cases(), $seen);
        $this->assertSame(BossSkin::default(), $this->boss()->skinFor($household->fresh()));
    }

    public function test_the_finisher_is_told_they_did_it_and_everyone_else_is_told_who(): void
    {
        $household = Household::factory()->create(['goal_target' => 100, 'goal_now' => 0]);
        $nova = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $sam = Profile::factory()->for($household)->create(['name' => 'Sam']);
        $parent = Profile::factory()->for($household)->parent()->create();
        $chore = Chore::factory()->for($household)->create(['points' => 100, 'quest_eligible' => false]);

        $this->completeChore($nova, $parent, $chore);

        $this->assertSame('You', $nova->fresh()->pending_goal_finisher);
        $this->assertSame('Nova', $sam->fresh()->pending_goal_finisher);
        $this->assertSame(BossSkin::default()->label(), $sam->fresh()->pending_boss_name);
    }

    public function test_a_kid_who_has_never_looked_replays_nothing(): void
    {
        $household = Household::factory()->create(['goal_target' => 1000, 'goal_now' => 800]);
        $kid = Profile::factory()->for($household)->create();

        // Otherwise everyone meets the boss battle for the first time with a
        // full recap of a fight that happened before the feature existed.
        $steps = $this->boss()->replayFor($household, $kid);

        $this->assertCount(1, $steps);
        $this->assertSame(BossStage::Desperate, $steps[0]['stage']);
    }

    public function test_damage_missed_while_away_replays_one_stage_at_a_time(): void
    {
        $household = Household::factory()->create(['goal_target' => 1000, 'goal_now' => 0]);
        $kid = Profile::factory()->for($household)->create();
        $kid = $this->caughtUp($kid, $household, 0);

        // A busy afternoon: 78% of the monster came off while they were out.
        $household->update(['goal_now' => 780]);

        $steps = $this->boss()->replayFor($household->fresh(), $kid);

        $this->assertSame(
            [BossStage::Fresh, BossStage::Angry, BossStage::Damaged, BossStage::Desperate],
            array_column($steps, 'stage'),
        );

        // Each stage stops at its own boundary, and the last one at the truth.
        $this->assertSame([0, 250, 500, 780], array_column($steps, 'damage'));
        $this->assertSame(780, $steps[3]['damage']);
        $this->assertSame(280, $steps[3]['landed']);
    }

    public function test_damage_that_crosses_no_boundary_still_moves_the_bar(): void
    {
        $household = Household::factory()->create(['goal_target' => 1000, 'goal_now' => 0]);
        $kid = Profile::factory()->for($household)->create();
        $kid = $this->caughtUp($kid, $household, 100);

        $household->update(['goal_now' => 200]);

        $steps = $this->boss()->replayFor($household->fresh(), $kid);

        $this->assertCount(2, $steps);
        $this->assertSame(100, $steps[0]['damage']);
        $this->assertSame(200, $steps[1]['damage']);
        $this->assertSame(100, $steps[1]['landed']);
    }

    public function test_marking_seen_leaves_nothing_left_to_replay(): void
    {
        $household = Household::factory()->create(['goal_target' => 1000, 'goal_now' => 0]);
        $kid = Profile::factory()->for($household)->create();
        $kid = $this->caughtUp($kid, $household, 0);

        $household->update(['goal_now' => 600]);
        $household = $household->fresh();

        $this->assertGreaterThan(1, count($this->boss()->replayFor($household, $kid)));

        $this->boss()->markSeen($household, $kid);

        $this->assertCount(1, $this->boss()->replayFor($household, $kid->fresh()));
    }

    public function test_a_new_monster_is_watched_from_the_start(): void
    {
        $household = Household::factory()->create(['goal_target' => 1000, 'goal_now' => 900]);
        $kid = Profile::factory()->for($household)->create();
        $kid = $this->caughtUp($kid, $household, 900);

        // A new goal: the stored damage belongs to a monster that is gone.
        $this->boss()->startNewBattle($household);
        $household->update(['goal_now' => 300]);

        $steps = $this->boss()->replayFor($household->fresh(), $kid->fresh());

        $this->assertSame(BossStage::Fresh, $steps[0]['stage']);
        $this->assertSame(0, $steps[0]['damage']);
    }

    public function test_the_hit_feed_leaves_the_previous_monsters_fight_behind(): void
    {
        $household = Household::factory()->create(['goal_target' => 1000, 'goal_now' => 0]);
        $kid = Profile::factory()->for($household)->create();

        LedgerEntry::create([
            'household_id' => $household->id,
            'profile_id' => $kid->id,
            'kind' => LedgerKind::Earn,
            'amount' => 40,
            'description' => 'Old fight',
            'created_at' => now()->subDays(3),
        ]);

        $this->boss()->startNewBattle($household);

        LedgerEntry::create([
            'household_id' => $household->id,
            'profile_id' => $kid->id,
            'kind' => LedgerKind::Earn,
            'amount' => 60,
            'description' => 'This fight',
        ]);

        $hits = $this->boss()->hits($household->fresh());

        $this->assertCount(1, $hits);
        $this->assertSame('This fight', $hits->first()->description);
    }

    public function test_the_quests_page_shows_the_boss_but_does_not_spend_the_replay(): void
    {
        $household = Household::factory()->create(['goal_target' => 1000, 'goal_now' => 400]);
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create(['points' => 100]);
        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee(BossSkin::default()->label());

        // The catch-up walk belongs to the arena on the Goal page. If a glance
        // at the sidebar marked the kid as having watched, they would arrive at
        // the arena with nothing left to play.
        $this->assertNull($kid->fresh()->boss_damage_seen);
    }

    public function test_the_goal_page_arena_is_what_marks_the_fight_watched(): void
    {
        $household = Household::factory()->create(['goal_target' => 1000, 'goal_now' => 400]);
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create(['points' => 100]);
        Auth::guard('profile')->login($kid);

        Volt::test('kid.goal')
            ->assertOk()
            ->assertSee(BossSkin::default()->label());

        $this->assertSame(400, $kid->fresh()->boss_damage_seen);
    }

    public function test_the_feed_is_hits_only_not_spending(): void
    {
        $household = Household::factory()->create(['goal_target' => 1000]);
        $kid = Profile::factory()->for($household)->create();
        $this->boss()->skinFor($household);

        LedgerEntry::create([
            'household_id' => $household->id,
            'profile_id' => $kid->id,
            'kind' => LedgerKind::Spend,
            'amount' => -50,
            'description' => 'Bought a reward',
        ]);

        $this->assertCount(0, $this->boss()->hits($household->fresh()));
    }
}
