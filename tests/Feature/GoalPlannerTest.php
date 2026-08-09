<?php

namespace Tests\Feature;

use App\Enums\CompletionStatus;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Household;
use App\Models\Profile;
use App\Models\StoreItem;
use App\Services\ChoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class GoalPlannerTest extends TestCase
{
    use RefreshDatabase;

    private function loginKid(Household $household, array $attributes = []): Profile
    {
        $kid = Profile::factory()->for($household)->create($attributes);
        Auth::guard('profile')->login($kid);

        return $kid;
    }

    /** Pins "today" so the forecast dates in the assertions below are stable. */
    private function freezeMay(Household $household): void
    {
        $this->travelTo(Carbon::parse('2026-05-01 12:00', $household->timezone));
    }

    private function service(): ChoreService
    {
        return app(ChoreService::class);
    }

    public function test_a_kid_can_pick_a_daily_target_and_it_sticks(): void
    {
        $household = Household::factory()->create();
        $kid = $this->loginKid($household);
        Chore::factory()->for($household)->create();

        Volt::test('kid.goal')
            ->assertOk()
            ->assertSee('Goal Planner')
            ->assertSee('set one yet')
            ->call('setDailyGoal', 300)
            ->assertSee('Points a day');

        $this->assertSame(300, $kid->fresh()->daily_points_goal);
    }

    public function test_the_stepper_moves_the_target_by_one_typical_chore(): void
    {
        // Chores here pay 100, so a rung of the ladder is 100 — the stepper has
        // to move by the same unit the ladder is built from.
        $household = Household::factory()->create();
        $kid = $this->loginKid($household);
        Chore::factory()->for($household)->create(['points' => 100]);

        Volt::test('kid.goal')
            ->call('setDailyGoal', 200)
            ->call('adjustDailyGoal', 100);

        $this->assertSame(300, $kid->fresh()->daily_points_goal);
    }

    public function test_the_target_cannot_be_stepped_below_the_floor(): void
    {
        $household = Household::factory()->create();
        $kid = $this->loginKid($household);
        Chore::factory()->for($household)->create();

        Volt::test('kid.goal')
            ->call('setDailyGoal', 100)
            ->call('adjustDailyGoal', -100)
            ->call('adjustDailyGoal', -100);

        $this->assertSame(5, $kid->fresh()->daily_points_goal);
    }

    public function test_clearing_the_target_puts_the_page_back_to_the_prompt(): void
    {
        $household = Household::factory()->create();
        $kid = $this->loginKid($household, ['daily_points_goal' => 200]);
        Chore::factory()->for($household)->create();

        Volt::test('kid.goal')
            ->call('clearDailyGoal')
            ->assertSee('set one yet');

        $this->assertNull($kid->fresh()->daily_points_goal);
    }

    public function test_it_forecasts_a_finish_date_for_every_rung_of_the_ladder(): void
    {
        $household = Household::factory()->create();
        $this->freezeMay($household);

        // 900 points still to find: 9 days at 100 a day, 5 at 200, 2 at 500.
        $item = StoreItem::factory()->for($household)->create(['name' => 'Skateboard', 'cost' => 1000]);
        $this->loginKid($household, ['points' => 100, 'saving_for_store_item_id' => $item->id]);
        Chore::factory()->for($household)->create(['points' => 100]);

        Volt::test('kid.goal')
            ->assertOk()
            ->assertSee('Skateboard')
            ->assertSee('900 TO GO')
            ->assertSee('9 days')
            ->assertSee('May 10, 2026')
            ->assertSee('5 days')
            ->assertSee('May 6, 2026')
            ->assertSee('2 days')
            ->assertSee('May 3, 2026');
    }

    public function test_the_chosen_target_is_spelled_out_as_a_date(): void
    {
        $household = Household::factory()->create();
        $this->freezeMay($household);

        $item = StoreItem::factory()->for($household)->create(['name' => 'Skateboard', 'cost' => 1000]);
        $this->loginKid($household, [
            'points' => 100,
            'saving_for_store_item_id' => $item->id,
            'daily_points_goal' => 300,
        ]);
        Chore::factory()->for($household)->create(['points' => 100]);

        Volt::test('kid.goal')
            ->assertOk()
            // 900 to go at 300 a day is three days: May 1 + 3.
            ->assertSee('3')
            ->assertSee('May 4, 2026');
    }

    public function test_the_ladder_stops_once_a_bigger_number_stops_moving_the_date(): void
    {
        // Chores pay 400 here and there are only 300 points to go, so every rung
        // is a one-day answer. Six rows all reading "1 day" is a table that has
        // stopped saying anything — three is the floor so there's still a choice.
        $household = Household::factory()->create();
        $item = StoreItem::factory()->for($household)->create(['cost' => 300]);
        $this->loginKid($household, ['saving_for_store_item_id' => $item->id]);
        Chore::factory()->for($household)->create(['points' => 400]);

        $html = Volt::test('kid.goal')->assertOk()->html();

        $this->assertSame(3, substr_count($html, 'PTS/DAY'));
    }

    public function test_the_countdown_banner_names_the_day_the_goal_lands(): void
    {
        $household = Household::factory()->create();
        $this->freezeMay($household);

        $item = StoreItem::factory()->for($household)->create(['name' => 'Skateboard', 'cost' => 1000]);
        $this->loginKid($household, [
            'points' => 100,
            'saving_for_store_item_id' => $item->id,
            'daily_points_goal' => 300,
        ]);
        Chore::factory()->for($household)->create(['points' => 100]);

        Volt::test('kid.goal')
            ->assertOk()
            ->assertSee('days until Skateboard is yours')
            ->assertSee('If you earn 300 points a day')
            ->assertSee('May 4, 2026');
    }

    public function test_the_countdown_banner_stays_away_until_there_is_a_plan(): void
    {
        // A goal but no daily target: there's nothing to count down at, and a
        // banner reading "— days until —" says less than no banner.
        $household = Household::factory()->create();
        $item = StoreItem::factory()->for($household)->create(['name' => 'Skateboard', 'cost' => 1000]);
        $this->loginKid($household, ['points' => 100, 'saving_for_store_item_id' => $item->id]);
        Chore::factory()->for($household)->create(['points' => 100]);

        Volt::test('kid.goal')
            ->assertOk()
            ->assertDontSee('is yours');
    }

    public function test_the_family_goal_is_answered_with_the_kids_own_target(): void
    {
        $household = Household::factory()->create(['goal_target' => 1200, 'goal_now' => 0]);
        Profile::factory()->for($household)->create();
        $this->loginKid($household, ['daily_points_goal' => 200]);
        Chore::factory()->for($household)->create(['points' => 100]);

        // 200 each across two kids is 400 a day, so 1200 takes three days.
        Volt::test('kid.goal')
            ->assertOk()
            ->assertSee('3 DAYS IF EVERYONE EARNS 200 EACH');
    }

    public function test_a_kid_with_no_saving_goal_still_gets_the_family_plan(): void
    {
        $household = Household::factory()->create(['goal_target' => 1200, 'goal_now' => 0]);
        $this->loginKid($household);
        Chore::factory()->for($household)->create();

        Volt::test('kid.goal')
            ->assertOk()
            ->assertSee('Nothing picked yet')
            ->assertSee('Family Goal plan')
            ->assertSee('1,200 TO GO');
    }

    public function test_the_family_plan_multiplies_each_kids_number_by_the_kid_count(): void
    {
        $household = Household::factory()->create(['goal_target' => 1200, 'goal_now' => 0]);
        $this->freezeMay($household);

        Profile::factory()->for($household)->create();
        Profile::factory()->for($household)->create();
        $this->loginKid($household);
        // A parent must not inflate the split — only kids earn points.
        Profile::factory()->parent()->for($household)->create();
        Chore::factory()->for($household)->create(['points' => 100]);

        Volt::test('kid.goal')
            ->assertOk()
            ->assertSee('3 KIDS EARNING')
            // 100 each across three kids is 300 a day, so 1200 takes four days.
            ->assertSee('300')
            ->assertSee('4 days')
            ->assertSee('May 5, 2026');
    }

    public function test_approving_a_chore_credits_the_kid_on_the_family_goal(): void
    {
        $household = Household::factory()->create(['goal_target' => 1000, 'goal_now' => 0]);
        $kid = Profile::factory()->for($household)->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['points' => 250]);

        $completion = ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $kid->id,
            'status' => CompletionStatus::Pending,
            'points_awarded' => 250,
            'submitted_at' => now(),
        ]);

        $this->service()->approve($completion, $parent);

        $this->assertSame(250, $household->fresh()->goal_now);
        $this->assertSame(250, $kid->fresh()->goal_contribution);
    }

    public function test_a_kid_is_only_credited_for_what_the_goal_had_room_for(): void
    {
        // The goal caps at its target, so crediting the full payout would leave
        // the contributions totalling more than the bar they sit under.
        $household = Household::factory()->create(['goal_target' => 300, 'goal_now' => 200]);
        $kid = Profile::factory()->for($household)->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['points' => 250]);

        $completion = ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $kid->id,
            'status' => CompletionStatus::Pending,
            'points_awarded' => 250,
            'submitted_at' => now(),
        ]);

        $this->service()->approve($completion, $parent);

        $this->assertSame(300, $household->fresh()->goal_now);
        $this->assertSame(100, $kid->fresh()->goal_contribution);
    }

    public function test_contributors_are_ranked_with_the_leader_crowned(): void
    {
        $household = Household::factory()->create(['goal_target' => 1000, 'goal_now' => 400]);
        Profile::factory()->for($household)->create(['name' => 'Nova', 'goal_contribution' => 300]);
        Profile::factory()->for($household)->create(['name' => 'Rex', 'goal_contribution' => 100]);
        Profile::factory()->parent()->for($household)->create(['name' => 'Mum']);

        $contributors = $this->service()->goalContributors($household);

        $this->assertSame(['Nova', 'Rex'], $contributors->pluck('profile.name')->all());
        $this->assertSame([75, 25], $contributors->pluck('percent')->all());
        $this->assertSame([true, false], $contributors->pluck('isLeader')->all());
    }

    public function test_a_tie_shares_the_crown(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create(['name' => 'Nova', 'goal_contribution' => 200]);
        Profile::factory()->for($household)->create(['name' => 'Rex', 'goal_contribution' => 200]);

        $this->assertSame(
            [true, true],
            $this->service()->goalContributors($household)->pluck('isLeader')->all()
        );
    }

    public function test_nobody_is_crowned_before_anyone_has_contributed(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create(['name' => 'Nova']);

        $contributors = $this->service()->goalContributors($household);

        $this->assertSame([false], $contributors->pluck('isLeader')->all());
        $this->assertSame([0], $contributors->pluck('percent')->all());
    }

    /**
     * The standings moved here with the Loot Tray redesign: the Quests page now
     * carries the goal as a boss strip and nothing else, so the breakdown of who
     * put what in belongs to the page built for planning it.
     */
    public function test_the_goal_page_shows_who_is_pulling_hardest(): void
    {
        $household = Household::factory()->create(['goal_target' => 1000, 'goal_now' => 400]);
        Profile::factory()->for($household)->create(['name' => 'Nova', 'goal_contribution' => 300]);
        $this->loginKid($household, ['name' => 'Rex', 'goal_contribution' => 100]);
        Chore::factory()->for($household)->create();

        $html = Volt::test('kid.goal')->assertOk()->assertSee('75%')->assertSee('25%')->html();

        $this->assertStringContainsString('pulling hardest', $html);
        $this->assertLessThan(
            strrpos($html, 'Rex'),
            strpos($html, 'Nova'),
            'The biggest contributor should be listed first.'
        );
    }

    public function test_the_quests_page_tracks_progress_against_the_daily_target(): void
    {
        $household = Household::factory()->create();
        $kid = $this->loginKid($household, ['daily_points_goal' => 300]);
        $chore = Chore::factory()->for($household)->create(['points' => 100]);

        // Pending, not approved: the work is done as far as the kid is
        // concerned, so the bar must not wait on a parent's inbox.
        ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $kid->id,
            'status' => CompletionStatus::Pending,
            'points_awarded' => 100,
            'submitted_at' => now(),
        ]);

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('Target')
            ->assertSee('100 / 300 PTS')
            ->assertSee('200 TO GO');
    }

    public function test_a_rejected_chore_drops_back_out_of_todays_progress(): void
    {
        $household = Household::factory()->create();
        $kid = $this->loginKid($household, ['daily_points_goal' => 300]);
        $chore = Chore::factory()->for($household)->create(['points' => 100]);

        ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $kid->id,
            'status' => CompletionStatus::Rejected,
            'points_awarded' => 100,
            'submitted_at' => now(),
        ]);

        $this->assertSame(0, $this->service()->pointsEarnedToday($kid));
    }

    public function test_todays_progress_ends_at_the_household_day_boundary(): void
    {
        // Claimed at 2am, which the household clock still calls yesterday — so
        // today's target starts from zero even though the date matches.
        $household = Household::factory()->create(['day_boundary_hour' => 4]);
        $kid = $this->loginKid($household, ['daily_points_goal' => 300]);
        $chore = Chore::factory()->for($household)->create(['points' => 100]);

        $this->travelTo(Carbon::parse('2026-05-11 09:00', $household->timezone));

        ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $kid->id,
            'status' => CompletionStatus::Approved,
            'points_awarded' => 100,
            'submitted_at' => Carbon::parse('2026-05-11 02:00', $household->timezone),
        ]);

        $this->assertSame(0, $this->service()->pointsEarnedToday($kid));
    }

    public function test_the_quests_page_nudges_a_kid_with_no_target_yet(): void
    {
        $household = Household::factory()->create();
        $this->loginKid($household);
        Chore::factory()->for($household)->create();

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('Target')
            ->assertSee('Make a plan');
    }

    public function test_a_pace_averages_the_last_seven_finished_days(): void
    {
        $household = Household::factory()->create();
        $kid = $this->loginKid($household);
        $chore = Chore::factory()->for($household)->create();

        // 700 points across the window averages 100 a day. Today's own chore is
        // outside it — a partial day would drag the average down at breakfast.
        foreach (range(1, 7) as $daysAgo) {
            ChoreCompletion::create([
                'chore_id' => $chore->id,
                'profile_id' => $kid->id,
                'status' => CompletionStatus::Approved,
                'points_awarded' => 100,
                'submitted_at' => now()->subDays($daysAgo),
            ]);
        }

        ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $kid->id,
            'status' => CompletionStatus::Approved,
            'points_awarded' => 900,
            'submitted_at' => now(),
        ]);

        $this->assertSame(100.0, $this->service()->dailyPace($kid));
    }

    public function test_the_family_pace_adds_the_kids_together(): void
    {
        $household = Household::factory()->create();
        $chore = Chore::factory()->for($household)->create();

        foreach (['Nova', 'Rex'] as $name) {
            $kid = Profile::factory()->for($household)->create(['name' => $name]);

            ChoreCompletion::create([
                'chore_id' => $chore->id,
                'profile_id' => $kid->id,
                'status' => CompletionStatus::Approved,
                'points_awarded' => 700,
                'submitted_at' => now()->subDay(),
            ]);
        }

        $this->assertSame(200.0, $this->service()->householdDailyPace($household));
    }

    public function test_resetting_the_family_goal_wipes_every_contribution(): void
    {
        $household = Household::factory()->create(['goal_target' => 1000, 'goal_now' => 600]);
        $nova = Profile::factory()->for($household)->create(['goal_contribution' => 400]);
        $rex = Profile::factory()->for($household)->create(['goal_contribution' => 200]);
        $parent = Profile::factory()->parent()->for($household)->create();
        Chore::factory()->for($household)->create();

        Auth::guard('profile')->login($parent);

        Volt::test('parent.kids')->call('resetGoalProgress');

        $this->assertSame(0, $household->fresh()->goal_now);
        $this->assertSame(0, $nova->fresh()->goal_contribution);
        $this->assertSame(0, $rex->fresh()->goal_contribution);
    }

    /**
     * The backfill runs on an already-migrated database, so it can't be
     * exercised by the suite's own migrate step — it's invoked directly.
     */
    private function runBackfill(): void
    {
        (require database_path('migrations/2026_08_02_233631_backfill_goal_contributions.php'))->up();
    }

    public function test_the_backfill_splits_existing_progress_by_who_did_the_work(): void
    {
        // 900 points of progress banked before contributions were tracked, and
        // approved chores running 2:1 between the two kids.
        $household = Household::factory()->create(['goal_target' => 1000, 'goal_now' => 900]);
        $nova = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $rex = Profile::factory()->for($household)->create(['name' => 'Rex']);
        $chore = Chore::factory()->for($household)->create();

        foreach ([[$nova, 400], [$nova, 400], [$rex, 400]] as [$kid, $points]) {
            ChoreCompletion::create([
                'chore_id' => $chore->id,
                'profile_id' => $kid->id,
                'status' => CompletionStatus::Approved,
                'points_awarded' => $points,
                'submitted_at' => now(),
            ]);
        }

        $this->runBackfill();

        $this->assertSame(600, $nova->fresh()->goal_contribution);
        $this->assertSame(300, $rex->fresh()->goal_contribution);
    }

    public function test_the_backfill_hands_the_rounding_remainder_to_the_top_earner(): void
    {
        // 100 across three equal kids doesn't divide, and the leftovers have to
        // land somewhere or the board totals less than the bar above it.
        $household = Household::factory()->create(['goal_target' => 1000, 'goal_now' => 100]);
        $chore = Chore::factory()->for($household)->create();

        foreach ([300, 200, 100] as $points) {
            $kid = Profile::factory()->for($household)->create();

            ChoreCompletion::create([
                'chore_id' => $chore->id,
                'profile_id' => $kid->id,
                'status' => CompletionStatus::Approved,
                'points_awarded' => $points,
                'submitted_at' => now(),
            ]);
        }

        $this->runBackfill();

        // 50 + 33 + 16 leaves one point over, which the top earner takes.
        $this->assertSame(100, (int) $household->profiles()->sum('goal_contribution'));
        $this->assertSame(51, (int) $household->profiles()->max('goal_contribution'));
    }

    public function test_the_backfill_leaves_hand_typed_progress_unattributed(): void
    {
        // Progress with no approved chores behind it came from a parent nudging
        // the number. Splitting it would invent a race nobody ran.
        $household = Household::factory()->create(['goal_target' => 1000, 'goal_now' => 500]);
        $kid = Profile::factory()->for($household)->create();

        $this->runBackfill();

        $this->assertSame(0, $kid->fresh()->goal_contribution);
    }

    public function test_a_parent_cannot_open_the_goal_planner(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();

        Auth::guard('profile')->login($parent);

        Volt::test('kid.goal')->assertForbidden();
    }
}
