<?php

namespace Tests\Feature;

use App\Enums\BossStage;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Monster;
use App\Models\Profile;
use App\Services\MonsterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The catch-up walk, and the cards waiting for a kid who missed a kill.
 *
 * Both exist for the same reason: chores are approved all day on a screen no
 * kid is looking at, so without them a kid coming home from school finds the
 * monsters already half dead and the good bit already over.
 */
class MonsterReplayTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->kid = Profile::factory()->for($this->household)->create(['name' => 'Nova']);
        Chore::factory()->for($this->household)->create(['points' => 100]);

        Auth::guard('profile')->login($this->kid);
    }

    private function arena(): MonsterService
    {
        return app(MonsterService::class);
    }

    private function spawn(int $health = 1000, string $reward = 'Ice cream'): Monster
    {
        return $this->arena()->spawn($this->household, $reward, $health);
    }

    /** Marks this kid as having watched the monster up to the damage given. */
    private function caughtUpTo(Monster $monster, int $damage): void
    {
        // Re-read rather than built on `$this->kid`, which is the instance from
        // setUp and knows nothing about any earlier call to this.
        $kid = $this->kid->fresh();
        $seen = $kid->monsters_seen ?? [];
        $seen[(string) $monster->id] = $damage;

        $kid->forceFill(['monsters_seen' => $seen])->save();
        Auth::guard('profile')->login($kid->fresh());
    }

    public function test_a_monster_never_watched_replays_nothing(): void
    {
        $monster = $this->spawn();
        $this->arena()->land($monster, 400, $this->kid);

        $steps = $this->arena()->replayFor($monster->fresh(), $this->kid);

        // Otherwise the first person to ever open the page sits through a recap
        // of a fight they have been following all week.
        $this->assertCount(1, $steps);
        $this->assertSame(400, $steps[0]['damage']);
    }

    public function test_the_first_look_seeds_the_marker_without_replaying(): void
    {
        $monster = $this->spawn();
        $this->arena()->land($monster, 400, $this->kid);

        $this->arena()->markSeen($this->household, $this->kid);

        $this->assertSame([(string) $monster->id => 400], $this->kid->fresh()->monsters_seen);
    }

    public function test_damage_missed_is_walked_through_stage_by_stage(): void
    {
        $monster = $this->spawn(1000);
        $this->arena()->land($monster, 100, $this->kid);
        $this->caughtUpTo($monster, 100);

        // A busy afternoon while the kid was at school.
        $this->arena()->land($monster->fresh(), 800, $this->kid);

        $steps = $this->arena()->replayFor(
            $this->arena()->current($this->household),
            $this->kid->fresh(),
        );

        // Starts where they left it and ends on the truth.
        $this->assertSame(100, $steps[0]['damage']);
        $this->assertSame(900, end($steps)['damage']);

        // And stops at each stage boundary on the way rather than sliding past.
        $this->assertSame(
            [BossStage::Fresh, BossStage::Angry, BossStage::Damaged, BossStage::Desperate],
            array_column($steps, 'stage'),
        );
    }

    public function test_each_step_reports_the_blow_that_got_there(): void
    {
        $monster = $this->spawn(1000);
        $this->arena()->land($monster, 100, $this->kid);
        $this->caughtUpTo($monster, 100);
        $this->arena()->land($monster->fresh(), 800, $this->kid);

        $steps = $this->arena()->replayFor($this->arena()->current($this->household), $this->kid->fresh());

        // 100 seen, 900 dealt: the walk stops at 250 and 500 on the way, so the
        // bar visibly halts at each stage rather than sliding through them.
        $this->assertSame(
            [0, 150, 250, 400],
            array_column($steps, 'landed'),
        );
    }

    public function test_damage_that_crosses_no_boundary_still_moves_the_bar(): void
    {
        $monster = $this->spawn(1000);
        $this->arena()->land($monster, 100, $this->kid);
        $this->caughtUpTo($monster, 100);
        $this->arena()->land($monster->fresh(), 50, $this->kid);

        $steps = $this->arena()->replayFor($this->arena()->current($this->household), $this->kid->fresh());

        $this->assertCount(2, $steps);
        $this->assertSame(50, $steps[1]['landed']);
    }

    public function test_a_kid_already_up_to_date_replays_nothing(): void
    {
        $monster = $this->spawn(1000);
        $this->arena()->land($monster, 400, $this->kid);
        $this->caughtUpTo($monster, 400);

        $this->assertCount(1, $this->arena()->replayFor($monster->fresh(), $this->kid->fresh()));
    }

    public function test_looking_marks_the_monster_and_forgets_it_once_beaten(): void
    {
        $monster = $this->spawn(1000, 'Weekend away');

        $this->arena()->land($monster, 250, $this->kid);
        $this->arena()->markSeen($this->household, $this->kid);

        $this->assertSame([(string) $monster->id => 250], $this->kid->fresh()->monsters_seen);

        $this->arena()->land($monster->fresh(), 750, $this->kid);
        $this->arena()->settle($monster->fresh(), $this->kid);
        $this->arena()->markSeen($this->household, $this->kid->fresh());

        // The beaten one drops out — its last blows arrive as a kill card
        // instead, and the map stays one key wide however many fights the
        // family gets through.
        $this->assertSame([], $this->kid->fresh()->monsters_seen);
    }

    public function test_the_arena_page_plays_the_catch_up_once_and_only_once(): void
    {
        $monster = $this->spawn(1000);
        $this->arena()->land($monster, 100, $this->kid);
        $this->caughtUpTo($monster, 100);
        $this->arena()->land($monster->fresh(), 800, $this->kid);

        Volt::test('kid.arena')
            ->assertOk()
            ->assertSee('Catching you up');

        // Marked on the way past, so the second visit has nothing left to play.
        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.arena')
            ->assertOk()
            ->assertDontSee('Catching you up');
    }

    public function test_the_replay_starts_without_waiting(): void
    {
        $monster = $this->spawn(1000);

        $this->arena()->land($monster, 100, $this->kid);
        $this->caughtUpTo($monster, 100);
        $this->arena()->land($monster->fresh(), 800, $this->kid);

        // There was a queue here when three cards each had a catch-up to play
        // and they had to take turns. With one card there is nothing to wait
        // behind, and a delay would just be the page sitting still.
        $state = Volt::test('kid.arena')->assertOk()->viewData('monsterState');

        $this->assertSame(0, $state['startDelay']);
        $this->assertGreaterThan(1, count($state['steps']));
    }

    public function test_the_quests_strip_never_spends_the_replay(): void
    {
        $monster = $this->spawn(1000);
        $this->arena()->land($monster, 100, $this->kid);
        $this->caughtUpTo($monster, 100);
        $this->arena()->land($monster->fresh(), 800, $this->kid);

        Volt::test('kid.quests')->assertOk();

        // A glance at the strip must not count as having watched, or the kid
        // arrives at the arena with nothing left to play.
        $this->assertSame(100, $this->kid->fresh()->monsters_seen[(string) $monster->id]);
    }

    public function test_a_kill_waits_on_the_profile_until_the_kid_next_looks(): void
    {
        $sibling = Profile::factory()->for($this->household)->create(['name' => 'Pip']);
        $monster = $this->spawn(100, 'Ice cream outing');

        $this->arena()->land($monster, 100, $sibling);
        $this->arena()->settle($monster, $sibling);

        // "You" for whoever swung, their name for everyone else.
        $this->assertSame('Pip', $this->kid->fresh()->pending_monster_kills[0]['finisher']);
        $this->assertSame('You', $sibling->fresh()->pending_monster_kills[0]['finisher']);
        $this->assertSame('Ice cream outing', $this->kid->fresh()->pending_monster_kills[0]['reward']);
    }

    public function test_the_kill_card_is_shown_once_then_cleared(): void
    {
        $monster = $this->spawn(100, 'Ice cream outing');
        $this->arena()->land($monster, 100, $this->kid);
        $this->arena()->settle($monster, $this->kid);

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('is down!', false)
            ->assertSee('Ice cream outing', false)
            ->assertSee('landed the final blow', false);

        $this->assertNull($this->kid->fresh()->pending_monster_kills);

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.quests')->assertOk()->assertDontSee('is down!', false);
    }

    public function test_every_kill_queues_its_own_card(): void
    {
        foreach (['Ice cream', 'Pizza night'] as $reward) {
            $monster = $this->spawn(100, $reward);
            $this->arena()->land($monster, 100, $this->kid);
            $this->arena()->settle($monster, $this->kid);
        }

        $queued = $this->kid->fresh()->pending_monster_kills;

        $this->assertCount(2, $queued);
        $this->assertSame(['Ice cream', 'Pizza night'], array_column($queued, 'reward'));
    }

    public function test_a_kid_back_from_a_long_absence_is_not_buried_in_set_pieces(): void
    {
        foreach (range(1, 5) as $round) {
            $monster = $this->arena()->spawn($this->household, "Reward {$round}", 100);
            $this->arena()->land($monster, 100, $this->kid);
            $this->arena()->settle($monster, $this->kid);
        }

        $queued = $this->kid->fresh()->pending_monster_kills;

        $this->assertCount(MonsterService::KILL_QUEUE_LIMIT, $queued);
        $this->assertSame('Reward 5', end($queued)['reward']);
    }

    public function test_the_card_names_the_monster_that_died_not_the_one_now_standing(): void
    {
        $monster = $this->spawn(100, 'Ice cream');
        $died = $monster->skin->label();

        $this->arena()->land($monster, 100, $this->kid);
        $this->arena()->settle($monster, $this->kid);

        // A parent lines the next one up before the kid ever logs in.
        $this->arena()->spawn($this->household, 'Bowling', 500);

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee($died.' is down!', false)
            ->assertSee('Ice cream', false);
    }
}
