<?php

namespace Tests\Feature;

use App\Enums\CompletionStatus;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ArenaService;
use App\Services\ChoreService;
use App\Services\StreakService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Any approved chore earns the streak day — the main quest has no special
 * standing.
 *
 * Gating the run on the quest alone meant a kid could clear six side quests and
 * still watch their streak die overnight, which taught exactly the wrong lesson
 * about doing the work. The quest keeps its own pull through the chest, the
 * bold card and the charm; it no longer holds the streak hostage.
 *
 * The old rule is invisible to the existing streak suites — they all build runs
 * by approving the quest chore, which counts under either rule — so everything
 * that actually pins the new behaviour lives here.
 */
class ChoreStreakTest extends TestCase
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

        // One quest-eligible chore to absorb the whole hand, so every chore
        // made by sideChore() below is guaranteed to be a *side* quest rather
        // than a card the deal might have taken. See HAND_SIZE.
        Chore::factory()->for($this->household)->create([
            'name' => 'The main quest chore',
            'quest_eligible' => true,
            'min_age' => 1,
        ]);
    }

    private function service(): ChoreService
    {
        return app(ChoreService::class);
    }

    private function streaks(): StreakService
    {
        return app(StreakService::class);
    }

    /**
     * A chore the daily deal can never hand out.
     *
     * `min_age` is set for a second reason: an age-gated chore is never
     * eligible to be drawn as the day's mystery chore, so approving one can't
     * quietly add the +500 bonus underneath a points assertion.
     */
    private function sideChore(string $name = 'Sweep the porch'): Chore
    {
        return Chore::factory()->for($this->household)->create([
            'name' => $name,
            'points' => 100,
            'quest_eligible' => false,
            'min_age' => 1,
        ]);
    }

    /** Claims a chore and has the parent sign it off, the way the app does. */
    private function clearSideChore(?Chore $chore = null): void
    {
        $completion = $this->service()->claim($this->kid, $chore ?? $this->sideChore());

        $this->service()->approve($completion, $this->parent);
    }

    public function test_an_approved_side_quest_earns_the_streak_day(): void
    {
        $this->clearSideChore();

        // The point of the whole change: the chest was never opened, and the
        // day still counts.
        $this->assertSame(1, $this->kid->refresh()->streak);
        $this->assertFalse($this->service()->isQuestDoneToday($this->kid));
    }

    public function test_a_run_can_be_built_entirely_from_side_quests(): void
    {
        // Three days behind, none of them a quest, then one cleared properly
        // today. Nothing in this test ever touches a DailyQuest row — under the
        // old rule the walk-back stopped dead at the first day without a quest
        // completion, which was every one of them.
        $this->runOf(3);

        $this->clearSideChore($this->sideChore('One more'));

        $this->assertSame(4, $this->kid->refresh()->streak);
    }

    public function test_a_side_quest_only_run_still_pays_its_milestone(): void
    {
        $chore = $this->sideChore();

        // Two days behind, so today's approval lands on day 3 — the first
        // milestone on the track.
        foreach ([1, 2] as $daysBack) {
            ChoreCompletion::create([
                'chore_id' => $chore->id,
                'profile_id' => $this->kid->id,
                'status' => CompletionStatus::Approved,
                'points_awarded' => 100,
                'submitted_at' => now()->copy()->subDays($daysBack),
                'decided_at' => now()->copy()->subDays($daysBack),
            ]);
        }

        $this->clearSideChore($this->sideChore('Today'));

        $this->kid->refresh();

        $this->assertSame(3, $this->kid->streak);
        // The chest is a reveal gate, not a payment gate — the milestone is
        // banked the moment it is crossed.
        $this->assertSame(3, $this->kid->pending_streak_chest);
        $this->assertSame(3, $this->kid->streak_milestone_paid_through);
    }

    public function test_a_day_with_nothing_approved_still_breaks_the_run(): void
    {
        $chore = $this->sideChore();

        // Work two days ago, nothing yesterday. Opening the rule up must not
        // turn it into no rule at all.
        ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $this->kid->id,
            'status' => CompletionStatus::Approved,
            'points_awarded' => 100,
            'submitted_at' => now()->copy()->subDays(2),
            'decided_at' => now()->copy()->subDays(2),
        ]);

        $this->kid->update(['streak' => 1]);
        $this->streaks()->syncStreak($this->kid);

        $this->assertSame(0, $this->kid->refresh()->streak);
    }

    /**
     * Work done in the small hours belongs to the night before.
     *
     * This is the one case the walk's rewrite could have broken silently. It
     * used to ask "is there an approved completion between the start of day D
     * and the start of day D+1", a pure timestamp window; it now pulls the
     * window once and buckets each completion with `HouseholdClock::dayFor()`.
     * Those agree only because `dayFor()` puts anything before the boundary
     * hour on the previous day — exactly the span `startOf()` bounds. Every
     * other streak fixture in the suite submits at noon, so nothing else would
     * notice if that stopped being true.
     */
    public function test_a_chore_finished_before_the_boundary_counts_for_the_night_before(): void
    {
        $household = Household::factory()->create(['day_boundary_hour' => 4]);
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['min_age' => 1]);

        // `->utc()` is load-bearing. Eloquent writes a Carbon by formatting it
        // as-is rather than converting it, so a household-local Carbon lands in
        // the column as if that wall clock were UTC — a 2am Chicago fixture
        // would store 02:00 UTC, which is 9pm Chicago, an ordinary evening.
        // Production never hits this because everything writes `now()`, which
        // is already UTC. See HouseholdClock::startOf(), which converts for the
        // same reason on the read side.
        $submit = function (string $at) use ($household, $kid, $chore) {
            $moment = Carbon::parse($at, $household->timezone)->utc();

            ChoreCompletion::create([
                'chore_id' => $chore->id,
                'profile_id' => $kid->id,
                'status' => CompletionStatus::Approved,
                'points_awarded' => 100,
                'submitted_at' => $moment,
                'decided_at' => $moment,
            ]);
        };

        $day = fn (string $date) => Carbon::parse($date, $household->timezone);

        // 2am on the 2nd is still the 1st's household day.
        $submit('2026-05-02 02:00');

        $this->assertTrue($this->streaks()->streakDayEarnedOn($kid, $day('2026-05-01')));
        $this->assertFalse($this->streaks()->streakDayEarnedOn($kid, $day('2026-05-02')));

        // 4am is the boundary itself, and the boundary opens the new day
        // rather than closing the old one.
        $submit('2026-05-02 04:00');

        $this->assertTrue($this->streaks()->streakDayEarnedOn($kid, $day('2026-05-02')));
    }

    public function test_a_pending_claim_does_not_earn_the_day(): void
    {
        // The strict walk is what the run is built from, and a claim a parent
        // has not looked at is not work anyone has signed off on.
        $this->service()->claim($this->kid, $this->sideChore());

        $this->assertFalse(
            $this->streaks()->streakDayEarnedOn($this->kid, now()->copy()->startOfDay()),
        );
        $this->assertSame(0, $this->kid->refresh()->streak);
    }

    public function test_a_pending_claim_still_secures_the_night(): void
    {
        $this->service()->claim($this->kid, $this->sideChore());

        // Deliberately the generous read: the kid has done their part, and the
        // Arena must not show them at risk over a parent's response time.
        $this->assertTrue($this->streaks()->streakDaySecuredToday($this->kid));
    }

    public function test_the_arena_reads_a_side_quest_as_a_safe_night(): void
    {
        $this->clearSideChore();

        $lane = app(ArenaService::class)
            ->tonightFor($this->household)
            ->firstWhere(fn (array $row) => $row['profile']->is($this->kid));

        $this->assertSame(ArenaService::STATE_SAFE, $lane['state']);
    }

    public function test_a_sibling_cannot_rescue_a_kid_who_already_did_a_chore(): void
    {
        $sibling = Profile::factory()->for($this->household)->create(['bonus_tickets' => 10]);

        $this->kid->update(['streak' => 3]);
        $this->clearSideChore();

        $arena = app(ArenaService::class);

        $this->assertSame(
            'Their night is already safe',
            $arena->rescueBlockedReason($sibling, $this->kid->refresh()),
        );
        $this->assertFalse($arena->rescue($sibling, $this->kid));
        // Refused before anything was charged.
        $this->assertSame(10, $sibling->refresh()->bonus_tickets);
    }

    public function test_a_nudge_is_refused_once_any_chore_is_in(): void
    {
        $sibling = Profile::factory()->for($this->household)->create();

        $this->clearSideChore();

        $this->assertFalse(app(ArenaService::class)->nudge($sibling, $this->kid));
    }

    public function test_the_repair_window_closes_once_today_is_secured(): void
    {
        $chore = $this->sideChore();

        // A live run that broke yesterday: the day before it counted, so a
        // restore would normally be on offer.
        ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $this->kid->id,
            'status' => CompletionStatus::Approved,
            'points_awarded' => 100,
            'submitted_at' => now()->copy()->subDays(2),
            'decided_at' => now()->copy()->subDays(2),
        ]);

        $this->assertNotNull($this->streaks()->repairableStreakDate($this->kid));

        // Clearing anything today starts a fresh chain, and buying the broken
        // day back there would splice a finished run onto it.
        $this->clearSideChore($this->sideChore('Today'));

        $this->assertNull($this->streaks()->repairableStreakDate($this->kid->refresh()));
    }

    public function test_the_quest_still_earns_the_day_on_its_own(): void
    {
        // The rule got wider, not different — the old path has to keep working.
        $this->service()->claimQuest($this->kid);

        $quest = $this->service()->questFor($this->kid);

        $completion = ChoreCompletion::where('profile_id', $this->kid->id)
            ->where('chore_id', $quest->chore_id)
            ->where('status', CompletionStatus::Pending)
            ->latest('id')
            ->firstOrFail();

        $this->service()->approve($completion, $this->parent);

        $this->assertSame(1, $this->kid->refresh()->streak);
    }

    /** A run of $days days ending yesterday, built without touching a quest. */
    private function runOf(int $days): void
    {
        $chore = $this->sideChore('Old work');

        for ($day = 1; $day <= $days; $day++) {
            ChoreCompletion::create([
                'chore_id' => $chore->id,
                'profile_id' => $this->kid->id,
                'status' => CompletionStatus::Approved,
                'points_awarded' => 100,
                'submitted_at' => now()->copy()->subDays($day),
                'decided_at' => now()->copy()->subDays($day),
            ]);
        }
    }

    public function test_the_second_approval_of_a_day_does_not_move_the_streak(): void
    {
        $this->runOf(4);

        $this->clearSideChore($this->sideChore('First today'));
        $this->assertSame(5, $this->kid->refresh()->streak);

        // The day is already earned, so approvalCouldMoveTheRun() skips the
        // recompute. The number has to be identical either way — this is the
        // correctness half of that optimisation.
        $this->clearSideChore($this->sideChore('Second today'));

        $this->assertSame(5, $this->kid->refresh()->streak);
    }

    public function test_a_backlog_approval_on_an_already_earned_day_leaves_the_run_alone(): void
    {
        $this->runOf(3);
        // Clears today, which is what puts a real number in the cache.
        $this->clearSideChore($this->sideChore('Today'));
        $this->assertSame(4, $this->kid->refresh()->streak);

        // A second chore from yesterday, still sitting in the queue. Yesterday
        // already counts, so signing this one off must leave the run exactly
        // where it was — it is the case the already-earned guard skips.
        $completion = ChoreCompletion::create([
            'chore_id' => $this->sideChore('Yesterday, unapproved')->id,
            'profile_id' => $this->kid->id,
            'status' => CompletionStatus::Pending,
            'points_awarded' => 100,
            'submitted_at' => now()->copy()->subDay(),
        ]);

        $this->service()->approve($completion, $this->parent);

        $this->assertSame(4, $this->kid->refresh()->streak);
    }

    /**
     * An approval's cost must not scale with how long the kid's run is.
     *
     * Two things keep it flat, and this is the guard on both. The streak walk
     * asks for its whole window in one go rather than three queries per day
     * (`earnedDaysBetween()`), and an approval on an already-earned day skips
     * the recompute entirely (`approvalCouldMoveTheRun()`). Between them a
     * 60-day run went from 622 queries an approval to 44 — and a parent
     * clearing a backlog of eight used to pay the 622 eight times over.
     */
    public function test_an_approval_on_a_long_run_does_not_rewalk_the_whole_chain(): void
    {
        $this->runOf(60);
        $this->clearSideChore($this->sideChore('First today'));

        $completion = $this->service()->claim($this->kid, $this->sideChore('Second today'));

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->service()->approve($completion, $this->parent);

        // Headroom over the 44 this actually costs, since an approval does
        // plenty besides the streak — ledger, XP, monsters, badges, tickets.
        // The point of the bound is that it tracks the approval's own work and
        // not the length of the run: at 366 days it must still be this number.
        $this->assertLessThan(70, $queries, "One approval cost {$queries} queries.");
    }
}
