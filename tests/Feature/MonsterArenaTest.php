<?php

namespace Tests\Feature;

use App\Enums\BossSkin;
use App\Enums\BossStage;
use App\Models\Household;
use App\Models\Monster;
use App\Models\MonsterHit;
use App\Models\Profile;
use App\Services\MonsterService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonsterArenaTest extends TestCase
{
    use RefreshDatabase;

    private function arena(): MonsterService
    {
        return app(MonsterService::class);
    }

    private function household(): Household
    {
        return Household::factory()->create();
    }

    private function kid(Household $household, string $name = 'Nova'): Profile
    {
        return Profile::factory()->for($household)->create(['name' => $name]);
    }

    public function test_a_monster_can_be_stood_up_and_is_found_again(): void
    {
        $household = $this->household();

        $monster = $this->arena()->spawn($household, 'Pizza night', 800, 2500);

        $this->assertSame('Pizza night', $monster->reward_name);
        $this->assertSame(800, $monster->max_health);
        $this->assertSame(2500, $monster->reward_cost_cents);
        $this->assertSame(1, $monster->battle);
        $this->assertNull($monster->defeated_at);

        $this->assertTrue($this->arena()->current($household)->is($monster));
    }

    public function test_the_next_monster_wears_a_new_face(): void
    {
        $household = $this->household();
        $arena = $this->arena();
        $kid = $this->kid($household);

        $first = $arena->spawn($household, 'Ice cream', 100);
        $arena->land($first, 100, $kid);
        $arena->settle($first, $kid);

        $second = $arena->spawn($household, 'Pizza night', 2000);

        $this->assertNotSame(
            $first->skin->value,
            $second->skin->value,
            'Beating one must introduce somebody new, not refill the same bar.',
        );
    }

    public function test_a_second_monster_cannot_stand_beside_the_first(): void
    {
        $household = $this->household();
        $this->arena()->spawn($household, 'Ice cream', 500);

        $this->expectException(\RuntimeException::class);

        $this->arena()->spawn($household, 'A second one', 500);
    }

    public function test_the_next_one_counts_on_from_the_last(): void
    {
        $household = $this->household();
        $arena = $this->arena();

        $first = $arena->spawn($household, 'Ice cream', 100);
        $arena->land($first, 100, $this->kid($household));
        $arena->settle($first);

        $second = $arena->spawn($household, 'Ice cream again', 100);

        $this->assertSame(2, $second->battle);
        $this->assertTrue($arena->current($household)->is($second));
    }

    public function test_health_is_summed_from_hits(): void
    {
        $household = $this->household();
        $kid = $this->kid($household);
        $monster = $this->arena()->spawn($household, 'Ice cream', 500);

        $this->arena()->land($monster, 120, $kid);
        $this->arena()->land($monster, 80, $kid);

        $fresh = $this->arena()->current($household);

        $this->assertSame(200, $fresh->damage());
        $this->assertSame(300, $fresh->healthLeft());
    }

    public function test_a_blow_is_capped_at_what_is_left_and_reports_the_overkill(): void
    {
        $household = $this->household();
        $kid = $this->kid($household);
        $monster = $this->arena()->spawn($household, 'Ice cream', 100);

        $this->arena()->land($monster, 70, $kid);
        $applied = $this->arena()->land($monster, 50, $kid);

        // 30 stuck; the other 20 simply stops there. Nothing spills any more.
        $this->assertSame(30, $applied);
        $this->assertSame(100, $monster->damage());
        $this->assertSame(0, $monster->healthLeft());
    }

    public function test_a_beaten_monster_absorbs_nothing_further(): void
    {
        $household = $this->household();
        $kid = $this->kid($household);
        $monster = $this->arena()->spawn($household, 'Ice cream', 100);

        $this->arena()->land($monster, 100, $kid);
        $this->arena()->settle($monster, $kid);

        $this->assertSame(0, $this->arena()->land($monster, 40, $kid));
        $this->assertSame(100, $monster->fresh()->damage());
    }

    public function test_settling_banks_the_kill_once_and_freezes_the_leaderboard(): void
    {
        $household = $this->household();
        $nova = $this->kid($household, 'Nova');
        $pip = $this->kid($household, 'Pip');
        $monster = $this->arena()->spawn($household, 'Ice cream', 100);

        $this->arena()->land($monster, 70, $nova);
        $this->arena()->land($monster, 30, $pip);

        $this->assertTrue($this->arena()->settle($monster, $pip));
        $this->assertFalse($this->arena()->settle($monster, $pip), 'A kill must not be banked twice.');

        $monster->refresh();

        $this->assertNotNull($monster->defeated_at);
        $this->assertSame($pip->id, $monster->finisher_profile_id);
        $this->assertSame('Nova', $monster->contributions[0]['name']);
        $this->assertSame(70, $monster->contributions[0]['points']);
    }

    public function test_settling_does_nothing_while_the_monster_still_stands(): void
    {
        $household = $this->household();
        $monster = $this->arena()->spawn($household, 'Ice cream', 100);

        $this->arena()->land($monster, 40, $this->kid($household));

        $this->assertFalse($this->arena()->settle($monster));
        $this->assertNull($monster->fresh()->defeated_at);
    }

    public function test_a_beaten_monster_reads_as_beaten_even_after_its_bar_is_nudged_down(): void
    {
        $household = $this->household();
        $kid = $this->kid($household);
        $monster = $this->arena()->spawn($household, 'Ice cream', 100);

        $this->arena()->land($monster, 100, $kid);
        $this->arena()->settle($monster, $kid);
        $this->arena()->adjust($monster->fresh(), -60);

        $state = $this->arena()->stateFor($monster->fresh());

        $this->assertTrue($state['defeated']);
        $this->assertSame(BossStage::Defeated, $state['stage']);
    }

    public function test_a_parent_adjustment_moves_the_bar_without_crediting_anyone(): void
    {
        $household = $this->household();
        $kid = $this->kid($household);
        $monster = $this->arena()->spawn($household, 'Ice cream', 500);

        $this->arena()->land($monster, 100, $kid);
        $this->arena()->adjust($monster, 50);

        $this->assertSame(150, $monster->fresh()->damage());

        $contributions = $this->arena()->contributionsFor($monster->fresh());

        $this->assertSame(100, $contributions->firstWhere('profile_id', $kid->id)['points']);
        $this->assertSame(100, $contributions->firstWhere('profile_id', $kid->id)['percent']);
    }

    public function test_an_adjustment_cannot_push_a_bar_past_either_end(): void
    {
        $household = $this->household();
        $monster = $this->arena()->spawn($household, 'Ice cream', 100);

        $this->arena()->land($monster, 40, $this->kid($household));

        $this->arena()->adjust($monster, 500);
        $this->assertSame(100, $monster->damage(), 'Nudging up stops at full health.');

        $this->arena()->adjust($monster, -500);
        $this->assertSame(0, $monster->damage(), 'Nudging down stops at untouched.');
    }

    public function test_contributions_rank_kids_and_keep_the_idle_ones_on_the_board(): void
    {
        $household = $this->household();
        $nova = $this->kid($household, 'Nova');
        $pip = $this->kid($household, 'Pip');
        $this->kid($household, 'Wren');

        $monster = $this->arena()->spawn($household, 'Ice cream', 500);
        $this->arena()->land($monster, 60, $pip);
        $this->arena()->land($monster, 140, $nova);

        $board = $this->arena()->contributionsFor($monster);

        $this->assertSame(['Nova', 'Pip', 'Wren'], $board->pluck('name')->all());
        $this->assertSame([140, 60, 0], $board->pluck('points')->all());
        $this->assertSame([70, 30, 0], $board->pluck('percent')->all());
        $this->assertTrue($board[0]['isLeader']);
        $this->assertFalse($board[2]['isLeader']);
    }

    public function test_state_reports_the_stage_the_health_lands_in(): void
    {
        $household = $this->household();
        $kid = $this->kid($household);
        $monster = $this->arena()->spawn($household, 'Weekend away', 100);

        $this->arena()->land($monster, 60, $kid);

        $state = $this->arena()->stateFor($monster);

        $this->assertSame(40, $state['health']);
        $this->assertSame(40, $state['healthPercent']);
        $this->assertSame(60, $state['damagePercent']);
        $this->assertSame(BossStage::Damaged, $state['stage']);
        $this->assertSame('Weekend away', $state['reward']);
        $this->assertFalse($state['defeated']);
    }

    public function test_a_monster_on_its_last_points_never_rounds_up_to_untouched(): void
    {
        $household = $this->household();
        $monster = $this->arena()->spawn($household, 'Weekend away', 10000);

        $this->arena()->land($monster, 9999, $this->kid($household));

        $this->assertSame(1, $this->arena()->stateFor($monster)['healthPercent']);
    }

    public function test_the_shelf_holds_beaten_monsters_and_the_arena_does_not(): void
    {
        $household = $this->household();
        $kid = $this->kid($household);

        $first = $this->arena()->spawn($household, 'Ice cream', 100);
        $this->arena()->land($first, 100, $kid);
        $this->arena()->settle($first, $kid);

        $next = $this->arena()->spawn($household, 'Ice cream again', 100);

        $this->assertTrue($this->arena()->current($household)->is($next));
        $this->assertCount(1, $this->arena()->shelf($household));
        $this->assertSame('Ice cream', $this->arena()->shelf($household)->first()->reward_name);
    }

    public function test_the_hit_feed_reads_newest_first(): void
    {
        $household = $this->household();
        $kid = $this->kid($household);
        $monster = $this->arena()->spawn($household, 'Ice cream', 500);

        $this->arena()->land($monster, 10, $kid);
        $this->arena()->land($monster, 20, $kid);
        $this->arena()->land($monster, 30, $kid);

        $this->assertSame([30, 20, 10], $this->arena()->hits($monster)->pluck('damage')->all());
    }

    public function test_one_monster_per_battle(): void
    {
        $household = $this->household();
        Monster::factory()->for($household)->create(['battle' => 1]);

        $this->expectException(QueryException::class);

        Monster::factory()->for($household)->create(['battle' => 1]);
    }

    public function test_a_household_with_no_monsters_has_an_empty_arena(): void
    {
        $household = $this->household();

        $this->assertNull($this->arena()->current($household));
        $this->assertSame(BossSkin::default(), $this->arena()->nextSkin($household));
    }

    public function test_damage_is_read_without_a_second_query(): void
    {
        $household = $this->household();
        $kid = $this->kid($household);

        $this->arena()->land($this->arena()->spawn($household, 'Weekend away', 500), 75, $kid);

        $monster = $this->arena()->current($household);

        \DB::enableQueryLog();
        $damage = $monster->damage();
        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        $this->assertSame(75, $damage);
        $this->assertCount(0, $queries, 'Health must come from the loaded sum, not an extra query.');
    }

    public function test_an_untouched_monster_reports_no_damage_without_a_query(): void
    {
        $household = $this->household();
        $this->arena()->spawn($household, 'Ice cream', 500);

        $monster = $this->arena()->current($household);

        \DB::enableQueryLog();
        $damage = $monster->damage();
        \DB::disableQueryLog();

        $this->assertSame(0, $damage);
        $this->assertCount(0, \DB::getQueryLog());
    }

    /**
     * Damage from an earlier fight must not follow the family onto the next
     * monster — the shelf is history, and the bar in front of them is not.
     */
    public function test_hits_are_scoped_to_their_own_monster(): void
    {
        $household = $this->household();
        $kid = $this->kid($household);

        $first = $this->arena()->spawn($household, 'Ice cream', 500);
        $this->arena()->land($first, 100, $kid);
        $first->forceFill(['defeated_at' => now()])->save();

        $second = $this->arena()->spawn($household, 'Pizza night', 500);
        $this->arena()->land($second, 40, $kid);

        $this->assertSame(100, $first->fresh()->damage());
        $this->assertSame(40, $this->arena()->current($household)->damage());
        $this->assertSame(2, MonsterHit::where('household_id', $household->id)->count());
    }
}
