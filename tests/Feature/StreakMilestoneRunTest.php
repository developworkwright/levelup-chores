<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\LedgerEntry;
use App\Models\Profile;
use App\Services\ChoreService;
use App\Services\StreakService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The milestone high-water mark belongs to a run, not to a lifetime.
 *
 * `streak_milestone_paid_through` shipped as a lifetime mark, which made every
 * chest a once-ever prize by accident. A kid who opened a 7-day chest, lost the
 * run and built it back to seven got nothing at day 3, day 5 or day 7 the
 * second time round — and because the track draws `reached` straight off the
 * streak number, all three rendered as already opened. No chest, no ledger
 * line, and a screen insisting they had collected it.
 *
 * The mark still has a job — it is what stops a kid lapsing a streak and buying
 * a repair to collect every milestone again — so it is scoped to a run rather
 * than dropped, keyed on the run's start date. A repair splices the missed
 * night back in and leaves the run starting where it always did; a genuinely
 * new run starts later. `test_repairing_never_pays_a_milestone_bonus_twice` in
 * {@see PerkStreakAndHintTest} is the other half of this file.
 */
class StreakMilestoneRunTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $parent;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create(['points_per_dollar' => 100]);
        $this->travelTo(Carbon::parse('2026-05-01 12:00', $this->household->timezone));

        $this->parent = Profile::factory()->parent()->for($this->household)->create();
        $this->kid = Profile::factory()->for($this->household)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function streaks(): StreakService
    {
        return app(StreakService::class);
    }

    /**
     * Clears one chore today, end to end, the way the app does.
     *
     * A fresh chore each time: an age gate keeps it out of the mystery draw, so
     * approving it can't quietly add a bonus underneath a ledger assertion, and
     * a new row each day sidesteps any per-chore cooldown.
     */
    private function clearAChore(): void
    {
        $chore = Chore::factory()->for($this->household)->create([
            'name' => 'Chore for '.now()->toDateString(),
            'points' => 10,
            'quest_eligible' => false,
            'min_age' => 1,
        ]);

        $chores = app(ChoreService::class);

        $chores->approve($chores->claim($this->kid, $chore), $this->parent);
    }

    /**
     * Lives out `$days` consecutive days, clearing one chore on each and
     * opening any chest that turns up — a kid actually playing, rather than a
     * parent signing off a backlog in one sitting.
     */
    private function liveRunOf(int $days): void
    {
        for ($day = 1; $day <= $days; $day++) {
            $this->clearAChore();
            $this->streaks()->openStreakChest($this->kid->refresh());

            if ($day < $days) {
                $this->travelTo(now()->addDay());
            }
        }
    }

    /** How many times a milestone has been paid for, by day. */
    private function timesPaid(int $milestone): int
    {
        return LedgerEntry::where('profile_id', $this->kid->id)
            ->where('description', 'like', "%{$milestone}-day streak bonus%")
            ->count();
    }

    public function test_a_second_run_pays_its_milestones_all_over_again(): void
    {
        // The bug as it was reported: seven nights, chests collected, the run
        // lost, seven nights built back — and the whole track silently dead.
        $this->liveRunOf(7);

        $this->assertSame(7, $this->kid->refresh()->streak);
        $this->assertSame(1, $this->timesPaid(7));

        // Three clear days with nothing signed off kills the run outright:
        // far enough back that no repair could reach it.
        $this->travelTo(now()->addDays(4));
        $this->streaks()->syncStreak($this->kid->refresh());
        $this->assertSame(0, $this->kid->refresh()->streak);

        $this->liveRunOf(7);

        $this->assertSame(7, $this->kid->refresh()->streak);

        // Every chest on the new run's track, paid a second time — because it
        // is a second run, and the kid earned all seven nights of it.
        $this->assertSame(2, $this->timesPaid(3));
        $this->assertSame(2, $this->timesPaid(5));
        $this->assertSame(2, $this->timesPaid(7));
    }

    public function test_the_seven_day_chest_of_a_second_run_is_offered_and_pays(): void
    {
        $this->liveRunOf(7);

        $this->travelTo(now()->addDays(4));
        $this->streaks()->syncStreak($this->kid->refresh());

        // Six nights of the new run, chests collected as they came, then the
        // seventh — the day the son's chest never appeared on.
        $this->liveRunOf(6);
        $this->travelTo(now()->addDay());
        $this->clearAChore();

        $kid = $this->kid->refresh();

        $this->assertSame(7, $kid->streak);
        $this->assertSame(7, $kid->pending_streak_chest, 'A seven-night run has to offer a chest.');
        $this->assertSame(5, $this->streaks()->pendingStreakChestDollars($kid));

        $this->assertSame(['day' => 7, 'dollars' => 5], $this->streaks()->openStreakChest($kid));

        // $5 at 100 points to the dollar, and the run keeps its own mark.
        $this->assertSame(500, LedgerEntry::where('profile_id', $kid->id)
            ->where('description', 'like', '%7-day streak bonus%')
            ->latest('id')->first()->amount);
        $this->assertSame(7, $kid->refresh()->streak_milestone_paid_through);
    }

    public function test_a_run_extended_backwards_does_not_pay_its_milestones_twice(): void
    {
        // A parent clearing a backlog of older days lengthens the run at the
        // *start*, which moves its start date earlier without any new run
        // having begun. Read as a new run, that would pay the whole track
        // again — which is why the reset is keyed on a strictly later start.
        $this->liveRunOf(3);

        $this->assertSame(1, $this->timesPaid(3));

        $backlog = Chore::factory()->for($this->household)->create([
            'name' => 'Signed off late',
            'points' => 10,
            'quest_eligible' => false,
            'min_age' => 1,
        ]);

        $chores = app(ChoreService::class);
        $completion = $chores->claim($this->kid, $backlog);

        // Claimed today, but done three days ago — the day belongs to the kid
        // who did the work, so this joins the run behind its first night.
        $completion->forceFill(['submitted_at' => now()->subDays(3)])->save();

        $chores->approve($completion->refresh(), $this->parent);

        $this->assertSame(4, $this->kid->refresh()->streak);
        $this->assertSame(1, $this->timesPaid(3));
    }

    public function test_a_chest_left_unopened_when_a_run_dies_still_pays_only_what_it_held(): void
    {
        // The chest is earned and stays earned, but it belongs to the run that
        // ended. Clearing the mark out from under it would re-open every
        // milestone beneath its lid — $9 for a chest that is worth $5.
        $this->liveRunOf(6);
        $this->travelTo(now()->addDay());
        $this->clearAChore();

        $this->assertSame(7, $this->kid->refresh()->pending_streak_chest);

        // Walk away from it. The run dies with the chest still shut.
        $this->travelTo(now()->addDays(4));
        $this->streaks()->syncStreak($this->kid->refresh());
        $this->assertSame(0, $this->kid->refresh()->streak);

        $this->clearAChore();

        $kid = $this->kid->refresh();

        // Still the old run's chest, still holding the old run's day 7 alone.
        $this->assertSame(7, $kid->pending_streak_chest);
        $this->assertSame(['day' => 7, 'dollars' => 5], $this->streaks()->openStreakChest($kid));

        // And the new run picks the track up from its own day 3 afterwards.
        $this->travelTo(now()->addDay());
        $this->clearAChore();
        $this->travelTo(now()->addDay());
        $this->clearAChore();

        $kid = $this->kid->refresh();

        $this->assertSame(3, $kid->streak);
        $this->assertSame(3, $kid->pending_streak_chest);
        $this->assertSame(1, $this->streaks()->pendingStreakChestDollars($kid));
    }

    public function test_a_mark_with_no_recorded_run_is_adopted_rather_than_reset(): void
    {
        // Every profile in the database predates the run column. Resetting on a
        // null would hand a second payout to every kid standing mid-run at the
        // moment of the migration, which is the worse of the two mistakes.
        $this->liveRunOf(3);

        $this->kid->forceFill(['streak_milestone_run_started_on' => null])->save();

        $this->travelTo(now()->addDay());
        $this->clearAChore();

        $kid = $this->kid->refresh();

        $this->assertSame(4, $kid->streak);
        $this->assertNull($kid->pending_streak_chest, 'Day 3 was already paid for in this same run.');
        $this->assertSame(1, $this->timesPaid(3));
        $this->assertSame(
            now()->subDays(3)->toDateString(),
            $kid->streak_milestone_run_started_on->toDateString(),
            'The run it is standing in is adopted, so the next one can be told apart from it.',
        );
    }
}
