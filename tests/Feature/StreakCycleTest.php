<?php

namespace Tests\Feature;

use App\Enums\CompletionStatus;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\LedgerEntry;
use App\Models\Profile;
use App\Services\StreakService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The chest track repeats instead of stopping at day 30.
 *
 * Reaching the last milestone used to leave a kid with nothing further to aim
 * at, which is the worst possible moment for the app to go quiet on them. Each
 * lap after the first pays double the base — flat, not compounding, because
 * points are backed by real money and doubling per lap reaches five figures on
 * a single chest inside a year.
 */
class StreakCycleTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create(['points_per_dollar' => 100]);

        $this->travelTo(Carbon::parse('2026-05-01 12:00', $this->household->timezone));
    }

    private function service(): StreakService
    {
        return app(StreakService::class);
    }

    /**
     * A kid on a live streak of $streak days, rendered honestly enough that the
     * page leaves it alone.
     *
     * syncStreak() is O(1) — it only asks whether today or yesterday counts —
     * so one approved quest behind them is all it takes to keep the cached
     * number. Without it the page zeroes the streak before anything renders.
     */
    private function kidOnStreak(int $streak): Profile
    {
        $chore = Chore::factory()->for($this->household)->create();
        $kid = Profile::factory()->for($this->household)->create(['streak' => $streak]);

        $yesterday = now()->copy()->subDay();

        DailyQuest::create([
            'household_id' => $this->household->id,
            'profile_id' => $kid->id,
            'chore_id' => $chore->id,
            'quest_date' => $yesterday->toDateString(),
            'revealed_at' => $yesterday,
            'completed_at' => $yesterday,
        ]);

        ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $kid->id,
            'status' => CompletionStatus::Approved,
            'points_awarded' => 10,
            'submitted_at' => $yesterday,
            'decided_at' => $yesterday,
        ]);

        return $kid;
    }

    public function test_the_first_lap_pays_the_base_amounts(): void
    {
        foreach (StreakService::STREAK_BONUSES as $day => $dollars) {
            $this->assertSame($dollars, $this->service()->streakBonusOn($day), "Day {$day}");
        }
    }

    public function test_the_second_lap_repeats_the_track_at_double(): void
    {
        // The days the user named when this was specified.
        $this->assertSame(2, $this->service()->streakBonusOn(33));
        $this->assertSame(6, $this->service()->streakBonusOn(35));
        $this->assertSame(10, $this->service()->streakBonusOn(37));
        $this->assertSame(30, $this->service()->streakBonusOn(44));
        $this->assertSame(80, $this->service()->streakBonusOn(60));
    }

    public function test_the_multiplier_does_not_compound_past_the_second_lap(): void
    {
        // The whole point of the flat multiplier: doubling per lap would make
        // day 90 pay $160 and day 360 pay $81,920.
        $this->assertSame(80, $this->service()->streakBonusOn(90));
        $this->assertSame(80, $this->service()->streakBonusOn(180));
        $this->assertSame(80, $this->service()->streakBonusOn(360));
        $this->assertSame(2, $this->service()->streakBonusOn(63));
        $this->assertSame(2, $this->service()->streakBonusOn(333));
    }

    public function test_a_day_that_closes_a_lap_belongs_to_the_lap_behind_it(): void
    {
        // Day 30 finishes the first lap rather than opening the second, so it
        // pays the base $40 and not $80.
        $this->assertSame(40, $this->service()->streakBonusOn(30));
        $this->assertSame(80, $this->service()->streakBonusOn(60));
    }

    public function test_days_between_milestones_pay_nothing(): void
    {
        foreach ([1, 2, 4, 10, 29, 31, 32, 34, 45, 59, 61] as $day) {
            $this->assertNull($this->service()->streakBonusOn($day), "Day {$day}");
        }

        $this->assertNull($this->service()->streakBonusOn(0));
        $this->assertNull($this->service()->streakBonusOn(-5));
    }

    public function test_the_next_milestone_carries_on_past_the_end_of_a_lap(): void
    {
        $kid = Profile::factory()->for($this->household)->create(['streak' => 30]);

        // This is the case that used to return null and strand the UI.
        $this->assertSame(33, $this->service()->nextStreakMilestone($kid));

        $kid->streak = 60;
        $this->assertSame(63, $this->service()->nextStreakMilestone($kid));

        $kid->streak = 44;
        $this->assertSame(60, $this->service()->nextStreakMilestone($kid));
    }

    public function test_the_track_shows_only_the_lap_the_kid_is_on(): void
    {
        $kid = Profile::factory()->for($this->household)->create(['streak' => 35]);

        $track = $this->service()->streakTrackFor($kid);

        $this->assertSame(2, $track['lap']);
        $this->assertSame([33, 35, 37, 44, 60], array_column($track['milestones'], 'day'));
        $this->assertSame([200, 600, 1000, 3000, 8000], array_column($track['milestones'], 'points'));
        $this->assertSame([true, true, false, false, false], array_column($track['milestones'], 'reached'));
    }

    public function test_the_track_waits_for_the_closing_chest_to_be_opened_before_turning_over(): void
    {
        // Hitting day 30 must not swap the track to days 33-60 while the day-30
        // chest is still sitting there unopened — that would replace the reward
        // out from under the moment that earned it.
        $kid = Profile::factory()->for($this->household)->create([
            'streak' => 30,
            'pending_streak_chest' => 30,
        ]);

        $this->assertSame(1, $this->service()->streakTrackFor($kid)['lap']);

        $kid->pending_streak_chest = null;

        $track = $this->service()->streakTrackFor($kid);

        $this->assertSame(2, $track['lap']);
        $this->assertSame(33, $track['milestones'][0]['day']);
    }

    /**
     * A chest queued before the payout moved to the reveal.
     *
     * Those were credited the moment the milestone was reached, and the old
     * code advanced the paid-through mark at the same time — so the mark
     * already sits on the chest's own day and there is nothing left under it.
     * That is what makes this change safe without a migration: the same walk
     * that pays a new chest finds an empty range on an old one and pays
     * nothing, rather than handing out the whole lap a second time.
     */
    public function test_a_chest_left_over_from_the_old_pay_on_reach_scheme_pays_nothing_twice(): void
    {
        $kid = Profile::factory()->for($this->household)->create([
            'streak' => 30,
            'pending_streak_chest' => 30,
            'points' => 4000,
        ]);

        $kid->streak_milestone_paid_through = 30;
        $kid->save();

        // The card still names the milestone it is celebrating, but the balance
        // does not move — the $40 is already in it.
        $this->assertSame(40, $this->service()->pendingStreakChestDollars($kid));
        $this->assertSame(['day' => 30, 'dollars' => 40], $this->service()->openStreakChest($kid));
        $this->assertSame(4000, $kid->refresh()->points);
        $this->assertSame(0, LedgerEntry::where('profile_id', $kid->id)->count());
    }

    public function test_opening_a_second_lap_chest_reveals_the_doubled_amount(): void
    {
        $kid = Profile::factory()->for($this->household)->create([
            'streak' => 33,
            'pending_streak_chest' => 33,
        ]);

        // The mark has to come with the streak. A kid standing on day 33 has
        // collected the day-30 chest, and the chest pays everything between the
        // mark and its own day — set one without the other and this reads as a
        // first-ever chest holding the whole first lap.
        $kid->streak_milestone_paid_through = 30;
        $kid->save();

        $this->assertSame(['day' => 33, 'dollars' => 2], $this->service()->openStreakChest($kid));
        $this->assertSame(33, $kid->refresh()->streak_milestone_paid_through);
    }

    public function test_the_quests_page_shows_the_second_lap_and_says_why_it_changed(): void
    {
        Auth::guard('profile')->login($this->kidOnStreak(31));

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Round 2')
            ->assertSee('Day 33')
            ->assertSee('200 pts')
            ->assertSee('8,000 pts')
            ->assertSee('every chest on this lap pays double', escape: false)
            // Last lap's numbers are gone from the card. escape: false so the
            // closing tag actually anchors "Day 3" against "Day 33".
            ->assertDontSee('Day 3<', escape: false)
            ->assertDontSee('4,000 pts');
    }

    public function test_the_quests_page_never_tells_a_kid_they_have_run_out_of_chests(): void
    {
        Auth::guard('profile')->login($this->kidOnStreak(30));

        Volt::test('kid.home')
            ->assertOk()
            ->assertDontSee("You've unlocked every streak chest")
            ->assertDontSee('All unlocked');
    }
}
