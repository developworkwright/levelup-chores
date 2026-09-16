<?php

namespace Tests\Feature;

use App\Enums\CompletionStatus;
use App\Enums\LedgerKind;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Household;
use App\Models\LedgerEntry;
use App\Models\Profile;
use App\Notifications\ParentApprovalNeeded;
use App\Services\ChoreService;
use App\Services\MonsterService;
use App\Services\StreakService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class ChoreFlowTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ChoreService
    {
        return app(ChoreService::class);
    }

    /**
     * The full daily loop: the kid does a chore and the parent approves it.
     * Since the streak moves on approval, streak tests have to run both
     * halves, not just the claim.
     */
    private function earnTheDay(Profile $kid, Profile $parent): void
    {
        $chore = $kid->household->chores()->first();

        $this->service()->approve($this->service()->claim($kid, $chore), $parent);
    }

    public function test_the_whole_board_is_on_the_board(): void
    {
        // It used to come back entirely `'locked'`, and after that it still had
        // the day's quest hand cut out of it. Both are gone: every chore a kid
        // could do is a row they can see.
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(6)->create(['points' => 100]);

        $board = $this->service()->boardFor($kid);

        $this->assertCount(6, $board);
        $this->assertTrue($board->every(fn ($entry) => $entry['state'] === 'ready'));
    }

    public function test_the_board_stays_open_once_a_chore_is_claimed(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(6)->create(['points' => 100]);

        $this->service()->claim($kid, $household->chores->first());

        $board = $this->service()->boardFor($kid);

        // Their own claim reads as pending; nothing else is touched.
        $this->assertSame(1, $board->where('state', 'pending')->count());
        $this->assertSame(5, $board->where('state', 'ready')->count());
    }

    public function test_approving_a_completion_credits_points_xp_and_family_goal(): void
    {
        $household = Household::factory()->create();
        $monster = app(MonsterService::class)->spawn($household, 'Weekend away', 1000);
        // Pinned to the middle of the day. `early_bird` and `night_owl` key off
        // the wall clock and each pay 100 XP, so on the real one this asserted
        // 50 XP by day and failed by 150 after 10pm.
        $this->travelTo(Carbon::parse('2026-05-01 12:00', $household->timezone));
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        // Age-gated so it can never be auto-picked as the day's mystery
        // chore, which would silently add a bonus and break this
        // assertion's exact point-value math.
        $chore = Chore::factory()->for($household)->create(['points' => 100, 'min_age' => 1]);

        $completion = $this->service()->claim($kid, $chore);
        $this->service()->approve($completion, $parent);

        $kid->refresh();

        $this->assertSame(100, $kid->points);
        $this->assertSame(ChoreService::XP_PER_CHORE, $kid->xp);
        $this->assertSame(100, $monster->fresh()->damage());
        $this->assertSame(CompletionStatus::Approved, $completion->refresh()->status);
    }

    public function test_a_monster_never_takes_more_damage_than_it_has_health(): void
    {
        $household = Household::factory()->create();
        // Nothing above it to spill onto, so the overkill has nowhere to go.
        $monster = app(MonsterService::class)->spawn($household, 'Weekend away', 50);
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['points' => 100, 'min_age' => 1]);

        $completion = $this->service()->claim($kid, $chore);
        $this->service()->approve($completion, $parent);

        $this->assertSame(50, $monster->fresh()->damage());
        $this->assertTrue($monster->fresh()->isDefeated());
    }

    public function test_sending_back_a_completion_does_not_credit_points(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['points' => 100]);

        $completion = $this->service()->claim($kid, $chore);
        $this->service()->sendBack($completion, $parent);

        $this->assertSame(0, $kid->refresh()->points);
        $this->assertSame(CompletionStatus::Rejected, $completion->refresh()->status);
    }

    public function test_a_chore_on_cooldown_cannot_be_claimed_again_same_day(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['points' => 100, 'cadence' => 'daily']);

        $completion = $this->service()->claim($kid, $chore);
        $this->service()->approve($completion, $parent);

        $this->assertSame('done', $this->service()->stateFor($kid, $chore));
    }

    public function test_an_unlimited_cadence_chore_never_locks_even_with_a_pending_claim(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['points' => 100, 'cadence' => 'unlimited']);

        $first = $this->service()->claim($kid, $chore);
        // Still pending — a daily/weekly chore would show 'pending' here.
        $this->assertSame('ready', $this->service()->stateFor($kid, $chore));

        $this->service()->approve($first, $parent);
        // Approved — a daily chore would now be 'done' until tomorrow.
        $this->assertSame('ready', $this->service()->stateFor($kid, $chore));

        // Nothing stops a second claim from stacking on top of the first.
        $second = $this->service()->claim($kid, $chore);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, ChoreCompletion::where('chore_id', $chore->id)->count());
    }

    public function test_streak_increments_on_consecutive_days_and_resets_on_a_gap(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create();

        // Day 1.
        $this->earnTheDay($kid, $parent);
        $this->assertSame(1, $kid->refresh()->streak);

        // Day 2, consecutive.
        Carbon::setTestNow(now()->addDay());
        $this->earnTheDay($kid, $parent);
        $this->assertSame(2, $kid->refresh()->streak);

        // Day 4, gap — resets to 1.
        Carbon::setTestNow(now()->addDays(2));
        $this->earnTheDay($kid, $parent);
        $this->assertSame(1, $kid->refresh()->streak);

        Carbon::setTestNow();
    }

    public function test_claiming_alone_does_not_move_the_streak(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create();

        $this->service()->claim($kid, $chore);

        // The work is in, but the streak waits for a parent.
        $this->assertSame(0, $kid->refresh()->streak);
        $this->assertNull($kid->pending_streak_chest);
    }

    public function test_a_rejected_chore_does_not_count_toward_the_streak(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create();

        $completion = $this->service()->claim($kid, $chore);

        $this->service()->sendBack($completion, $parent);

        $this->assertSame(0, $kid->refresh()->streak);
    }

    public function test_a_backlog_approved_out_of_order_still_lands_on_the_right_streak(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create();

        // Three days claimed, nothing approved yet.
        $completions = [];
        for ($day = 0; $day < 3; $day++) {
            if ($day > 0) {
                Carbon::setTestNow(now()->addDay());
            }

            $completions[] = $this->service()->claim($kid, $household->chores->first());
        }

        $this->assertSame(0, $kid->refresh()->streak);

        // The parent works through the backlog newest-first, which an
        // incrementing counter would get wrong.
        foreach (array_reverse($completions) as $completion) {
            $this->service()->approve($completion, $parent);
        }

        Carbon::setTestNow();

        $this->assertSame(3, $kid->refresh()->streak);
    }

    public function test_the_three_day_milestone_queues_a_chest_and_pays_nothing_until_it_is_opened(): void
    {
        $household = Household::factory()->create(['points_per_dollar' => 100]);
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        // Worth no points and age-gated, so approving it adds neither chore
        // points nor a mystery bonus — the balance is pure streak money.
        Chore::factory()->for($household)->create(['points' => 0, 'min_age' => 1]);

        $this->earnTheDay($kid, $parent); // Day 1.
        Carbon::setTestNow(now()->addDay());
        $this->earnTheDay($kid, $parent); // Day 2.
        Carbon::setTestNow(now()->addDay());
        $this->earnTheDay($kid, $parent); // Day 3 — hits the $1 milestone.
        Carbon::setTestNow();

        $kid->refresh();
        $this->assertSame(3, $kid->streak);
        $this->assertSame(3, $kid->pending_streak_chest);

        // Nothing paid yet. A kid coming back the next morning to a balance
        // that already held the bonus then opened a chest that gave them
        // nothing — the reveal was spoiled by the thing it was revealing.
        $this->assertSame(0, $kid->points);
        $this->assertSame(0, $this->streakBonusEntries($kid)->count());
        $this->assertSame(0, $kid->streak_milestone_paid_through);

        app(StreakService::class)->openStreakChest($kid);

        $this->assertSame(100, $kid->refresh()->points); // $1 at 100 points/$.
        $this->assertSame(3, $kid->streak_milestone_paid_through);

        $entry = $this->streakBonusEntries($kid)->latest('id')->first();
        $this->assertSame(LedgerKind::Earn, $entry->kind);
        $this->assertSame(100, $entry->amount);
        $this->assertStringContainsString('3-day streak bonus', $entry->description);
    }

    /**
     * The streak-bonus lines only. Approving a chore writes a ledger entry of
     * its own — worth 0 points in these tests, but still a row — so a blanket
     * count here would be counting the approvals.
     *
     * @return Builder<LedgerEntry>
     */
    private function streakBonusEntries(Profile $kid): Builder
    {
        return LedgerEntry::where('profile_id', $kid->id)
            ->where('description', 'like', '%streak bonus%');
    }

    public function test_opening_the_streak_chest_clears_the_pending_flag_and_reveals_the_prize(): void
    {
        $household = Household::factory()->create(['points_per_dollar' => 100]);
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create(['points' => 0, 'min_age' => 1]);

        $this->earnTheDay($kid, $parent);
        Carbon::setTestNow(now()->addDay());
        $this->earnTheDay($kid, $parent);
        Carbon::setTestNow(now()->addDay());
        $this->earnTheDay($kid, $parent); // Day 3 milestone.
        Carbon::setTestNow();

        $result = app(StreakService::class)->openStreakChest($kid->refresh());

        $this->assertSame(['day' => 3, 'dollars' => 1], $result);
        $this->assertNull($kid->refresh()->pending_streak_chest);
    }

    public function test_opening_the_streak_chest_with_nothing_pending_returns_null(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();

        $this->assertNull(app(StreakService::class)->openStreakChest($kid));
    }

    public function test_next_streak_milestone_reports_the_smallest_day_ahead_of_the_current_streak(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['streak' => 3]);

        $this->assertSame(5, app(StreakService::class)->nextStreakMilestone($kid));
    }

    public function test_streak_bonuses_accumulate_correctly_through_day_seven(): void
    {
        $household = Household::factory()->create(['points_per_dollar' => 100]);
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create(['points' => 0, 'min_age' => 1]);

        for ($day = 1; $day <= 7; $day++) {
            if ($day > 1) {
                Carbon::setTestNow(now()->addDay());
            }
            $this->earnTheDay($kid, $parent);
        }

        Carbon::setTestNow();
        $kid->refresh();

        $this->assertSame(7, $kid->streak);
        // Three chests earned and none opened, so only the last is on the lid —
        // and nothing has been paid for yet.
        $this->assertSame(7, $kid->pending_streak_chest);
        $this->assertSame(0, $kid->points);

        // The one chest carries everything underneath it. $1 (day 3) + $3
        // (day 5) + $5 (day 7) = $9 = 900 points. Nothing else was approved in
        // this test, so this isolates the bonus math exactly.
        $result = app(StreakService::class)->openStreakChest($kid);

        $this->assertSame(['day' => 7, 'dollars' => 9], $result);
        $this->assertSame(900, $kid->refresh()->points);
        $this->assertSame(7, $kid->streak_milestone_paid_through);

        // One ledger line per milestone, not one lump: each is its own thing
        // the kid earned, and the Activity feed reads them back that way.
        $this->assertSame(3, $this->streakBonusEntries($kid)->count());
    }

    public function test_no_streak_bonus_on_a_non_milestone_day(): void
    {
        $household = Household::factory()->create(['points_per_dollar' => 100]);
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create(['points' => 0, 'min_age' => 1]);

        $this->earnTheDay($kid, $parent); // Day 1.
        Carbon::setTestNow(now()->addDay());
        $this->earnTheDay($kid, $parent); // Day 2 — not a milestone.
        Carbon::setTestNow();

        $this->assertSame(2, $kid->refresh()->streak);
        $this->assertSame(0, $kid->points);
    }

    public function test_board_excludes_chores_the_kid_is_too_young_for(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['age' => 6]);
        Chore::factory()->for($household)->create(['name' => 'Open to everyone', 'min_age' => null]);
        Chore::factory()->for($household)->create(['name' => 'Too old for this one', 'min_age' => 10]);

        $board = $this->service()->boardFor($kid);

        $this->assertCount(1, $board);
        $this->assertSame('Open to everyone', $board->first()['chore']->name);
    }

    public function test_a_kid_old_enough_can_see_an_age_restricted_chore(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['age' => 12]);
        $restricted = Chore::factory()->for($household)->create(['name' => 'For older kids', 'min_age' => 10]);

        $board = $this->service()->boardFor($kid);
        $entry = $board->first(fn ($e) => $e['chore']->id === $restricted->id);

        $this->assertNotNull($entry);
        $this->assertSame('ready', $entry['state']);
    }

    public function test_claiming_a_chore_notifies_parents_of_the_household_but_not_kids(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create();

        $this->service()->claim($kid, $chore);

        Notification::assertSentTo($parent, ParentApprovalNeeded::class);
        Notification::assertNotSentTo($kid, ParentApprovalNeeded::class);
    }

    public function test_a_failed_parent_notification_does_not_break_the_claim(): void
    {
        // Production hit this: QUEUE_CONNECTION fell back to "database" with
        // no jobs table, so queueing the alert threw and 500'd the kid's
        // claim. Finishing a chore is the critical path; alerting is not.
        Notification::shouldReceive('send')->andThrow(new RuntimeException('queue unavailable'));

        $household = Household::factory()->create();
        Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create();

        $completion = $this->service()->claim($kid, $chore);

        $this->assertDatabaseHas('chore_completions', [
            'id' => $completion->id,
            'profile_id' => $kid->id,
            'status' => CompletionStatus::Pending->value,
        ]);
    }
}
