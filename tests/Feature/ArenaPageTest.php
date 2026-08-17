<?php

namespace Tests\Feature;

use App\Enums\CompletionStatus;
use App\Exceptions\InsufficientTicketsException;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\Nudge;
use App\Models\Profile;
use App\Models\StreakRescue;
use App\Services\ArenaService;
use App\Services\ChoreService;
use App\Services\HouseholdClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The Arena — the kid landing page.
 *
 * The rule under all of it: nothing expires at bedtime. `evening_watch_hour`
 * is a display threshold, not a deadline.
 */
class ArenaPageTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        // 4am rollover, 7pm watch hour — the defaults. Pinned to mid-afternoon
        // so the base case is "open, and nobody is being shouted at yet".
        $this->household = Household::factory()->create([
            'day_boundary_hour' => 4,
            'evening_watch_hour' => 19,
            'timezone' => 'UTC',
        ]);

        $this->travelTo(Carbon::parse('2026-05-04 15:00', 'UTC'));
    }

    private function kid(string $name = 'Nova', int $streak = 0): Profile
    {
        return Profile::factory()->for($this->household)->create([
            'name' => $name,
            'age' => 10,
            'streak' => $streak,
        ]);
    }

    private function chores(int $count = 4): void
    {
        Chore::factory()->for($this->household)->count($count)->create([
            'min_age' => null,
            'quest_eligible' => true,
        ]);
    }

    private function arena(): ArenaService
    {
        return app(ArenaService::class);
    }

    public function test_kids_land_on_the_arena_rather_than_quests(): void
    {
        $kid = $this->kid();
        $this->chores();

        Auth::guard('profile')->login($kid);

        $this->get('/kid')->assertRedirect('/kid/arena');
    }

    public function test_the_arena_renders_the_race_and_the_monsters(): void
    {
        $this->kid('Nova');
        $this->kid('Rex');
        $this->chores();

        Auth::guard('profile')->login(Profile::firstWhere('name', 'Nova'));

        Volt::test('kid.arena')
            ->assertOk()
            ->assertSee('Nights in a row')
            ->assertSee('What the house is fighting')
            ->assertSee('Nova')
            ->assertSee('Rex');
    }

    public function test_the_page_states_the_rollover_and_never_a_bedtime_deadline(): void
    {
        $kid = $this->kid();
        $this->chores();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.arena')
            ->assertSee('NOTHING EXPIRES AT BEDTIME', escape: false)
            ->assertSee('THE DAY ROLLS AT 4:00AM');
    }

    public function test_an_open_quest_before_the_watch_hour_raises_no_alarm(): void
    {
        $kid = $this->kid();
        $this->chores();

        $this->assertSame(ArenaService::STATE_OPEN, $this->arena()->stateFor($kid, false));

        Auth::guard('profile')->login($kid);

        Volt::test('kid.arena')
            ->assertSee('1 quest still open.')
            ->assertSee('Nothing\'s on the line until 7:00pm', escape: false)
            ->assertDontSee('still on the line');
    }

    public function test_an_untouched_morning_is_not_reported_as_a_clean_sweep(): void
    {
        $this->kid('Nova');
        $this->kid('Rex');
        $this->chores();

        Auth::guard('profile')->login(Profile::firstWhere('name', 'Nova'));

        // Nobody can *be* at risk before the watch hour, so counting at-risk
        // kids to decide this read every untouched morning as a clean sweep —
        // the one claim on this panel that has to be earned.
        Volt::test('kid.arena')
            ->assertSee('2 quests still open.')
            ->assertDontSee('Every quest cleared.');
    }

    public function test_a_genuinely_finished_day_still_says_so(): void
    {
        $kid = $this->kid('Nova');
        $this->chores();

        $chores = app(ChoreService::class);
        $chores->revealQuest($kid);
        $chores->claimQuest($kid);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.arena')
            ->assertSee('Every quest cleared.')
            ->assertDontSee('still open.');
    }

    public function test_a_broken_run_still_counts_as_a_quest_to_do(): void
    {
        $kid = $this->kid('Nova', 0);
        $this->chores();

        // A run that died overnight leaves tonight's quest just as undone as
        // anyone else's, so it must not be filed under "cleared".
        $this->approveQuestOn($kid, HouseholdClock::for($this->household)->today()->subDays(2));

        Auth::guard('profile')->login($kid);

        Volt::test('kid.arena')
            ->assertSee('1 quest still open.')
            ->assertDontSee('Every quest cleared.');
    }

    public function test_the_same_quest_reads_as_at_risk_once_the_watch_hour_passes(): void
    {
        $kid = $this->kid('Nova', 6);
        $this->chores();

        // A live run needs something behind it. The Arena re-syncs every kid's
        // streak on load — `profiles.streak` is a cache and this is the one
        // page that shows kids who haven't opened the app since theirs died —
        // so a bare `streak => 6` on the factory is zeroed before it renders.
        $this->approveQuestOn($kid, HouseholdClock::for($this->household)->today()->subDay());

        $this->travelTo(Carbon::parse('2026-05-04 19:30', 'UTC'));

        $this->assertSame(ArenaService::STATE_AT_RISK, $this->arena()->stateFor($kid, false));

        Auth::guard('profile')->login($kid);

        // escape: false — the apostrophe is literal template text, not `{{ }}`
        // output, so Blade never escapes it and an escaped needle can't match.
        Volt::test('kid.arena')
            ->assertSee("Nova's 6 nights are on the line.", escape: false)
            ->assertSee('Go claim it');
    }

    public function test_the_watch_hour_is_a_household_setting_not_a_constant(): void
    {
        $kid = $this->kid();
        $this->chores();

        $this->household->update(['evening_watch_hour' => 21]);
        $this->travelTo(Carbon::parse('2026-05-04 20:00', 'UTC'));

        // 8pm is past the default 7pm but not past this household's 9pm.
        $this->assertSame(ArenaService::STATE_OPEN, $this->arena()->stateFor($kid->refresh(), false));

        $this->travelTo(Carbon::parse('2026-05-04 21:30', 'UTC'));

        $this->assertSame(ArenaService::STATE_AT_RISK, $this->arena()->stateFor($kid->refresh(), false));
    }

    public function test_a_cleared_quest_is_safe_whatever_the_hour(): void
    {
        $kid = $this->kid();
        $this->chores();

        $this->travelTo(Carbon::parse('2026-05-04 23:00', 'UTC'));

        $this->assertSame(ArenaService::STATE_SAFE, $this->arena()->stateFor($kid, true));
    }

    public function test_the_risk_ramp_climbs_over_the_window_and_then_holds(): void
    {
        $this->kid();

        $this->travelTo(Carbon::parse('2026-05-04 18:00', 'UTC'));
        $this->assertSame(0.0, $this->arena()->riskRamp($this->household));

        $this->travelTo(Carbon::parse('2026-05-04 20:30', 'UTC'));
        $this->assertEqualsWithDelta(0.5, $this->arena()->riskRamp($this->household), 0.01);

        // Held rather than run past the end — nothing actually expires, so a
        // flame that guttered out would be the screen telling a lie.
        $this->travelTo(Carbon::parse('2026-05-05 01:00', 'UTC'));
        $this->assertSame(1.0, $this->arena()->riskRamp($this->household));
    }

    public function test_a_run_that_never_started_does_not_read_as_a_broken_one(): void
    {
        $kid = $this->kid('Nova', 0);
        $this->chores();

        // Zero streak, nothing behind it. That is not the same fact as a run
        // that died overnight and must not wear the skull.
        $this->assertFalse($this->arena()->brokeAtLastRollover($kid));
        $this->assertSame(ArenaService::STATE_OPEN, $this->arena()->stateFor($kid, false));
    }

    public function test_a_run_that_died_at_the_rollover_reads_as_broken(): void
    {
        $kid = $this->kid('Nova', 0);
        $this->chores();

        // Signed off two days ago, nothing yesterday: the shape of a run that
        // ended at the last rollover.
        $this->approveQuestOn($kid, HouseholdClock::for($this->household)->today()->subDays(2));

        $this->assertTrue($this->arena()->brokeAtLastRollover($kid->refresh()));
        $this->assertSame(ArenaService::STATE_BROKEN, $this->arena()->stateFor($kid->refresh(), false));

        Auth::guard('profile')->login($kid);

        Volt::test('kid.arena')->assertSee('Back to zero');
    }

    /** Stamps an approved quest on a past household day, the shortest way. */
    private function approveQuestOn(Profile $kid, Carbon $day): void
    {
        $chore = $this->household->chores->first();

        DailyQuest::create([
            'household_id' => $this->household->id,
            'profile_id' => $kid->id,
            'chore_id' => $chore->id,
            'offered_chore_ids' => [$chore->id],
            'quest_date' => $day->toDateString(),
            'dealt_at' => $day,
            'revealed_at' => $day,
            'completed_at' => $day,
        ]);

        ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $kid->id,
            'status' => CompletionStatus::Approved,
            'points_awarded' => $chore->points,
            'submitted_at' => $day->copy()->setTime(12, 0),
            // `decided_at`, not `approved_at` — the latter isn't a column, so
            // it was silently dropped and left these fixtures invisible to
            // anything reading the approval time (the ticker, claimantFor).
            'decided_at' => $day->copy()->setTime(13, 0),
        ]);
    }

    public function test_a_kid_can_nudge_a_sibling_once_a_night(): void
    {
        $nova = $this->kid('Nova');
        $rex = $this->kid('Rex');
        $this->chores();

        $this->assertTrue($this->arena()->nudge($nova, $rex));
        // One each per night. Three kids each nudging four times is a pile-on,
        // and the thing being nudged is a small child.
        $this->assertFalse($this->arena()->nudge($nova, $rex));
        $this->assertSame(1, Nudge::where('to_profile_id', $rex->id)->count());

        // A different sibling still gets their own.
        $ziggy = $this->kid('Ziggy');
        $this->assertTrue($this->arena()->nudge($ziggy, $rex));
    }

    public function test_a_kid_cannot_nudge_themselves(): void
    {
        $nova = $this->kid('Nova');
        $this->chores();

        $this->assertFalse($this->arena()->nudge($nova, $nova));
    }

    public function test_a_nudge_is_stamped_publicly_on_the_targets_card(): void
    {
        $nova = $this->kid('Nova');
        $rex = $this->kid('Rex');
        $this->chores();

        $this->approveQuestOn($rex, HouseholdClock::for($this->household)->today()->subDay());
        $rex->update(['streak' => 4]);

        $this->arena()->nudge($nova, $rex);
        $this->travelTo(Carbon::parse('2026-05-04 19:30', 'UTC'));

        Auth::guard('profile')->login($nova);

        // The shared screen is the point, not a notification.
        Volt::test('kid.arena')->assertSee('Nudged by Nova');
    }

    public function test_the_at_risk_card_offers_nudge_and_rescue_on_a_siblings_run(): void
    {
        $nova = $this->kid('Nova');
        $rex = $this->kid('Rex');
        $this->chores();

        $this->approveQuestOn($rex, HouseholdClock::for($this->household)->today()->subDay());
        $rex->update(['streak' => 4]);
        $nova->update(['bonus_tickets' => 10]);

        $this->travelTo(Carbon::parse('2026-05-04 19:30', 'UTC'));

        Auth::guard('profile')->login($nova);

        Volt::test('kid.arena')
            ->assertSee('Nudge Rex')
            ->assertSee('Rescue')
            ->assertSee('A rescue keeps the run alive — the night still pays nothing.', escape: false);
    }

    public function test_a_kid_sees_no_peer_actions_on_their_own_card(): void
    {
        $nova = $this->kid('Nova');
        $this->chores();

        $this->approveQuestOn($nova, HouseholdClock::for($this->household)->today()->subDay());
        $nova->update(['streak' => 4]);

        $this->travelTo(Carbon::parse('2026-05-04 19:30', 'UTC'));

        Auth::guard('profile')->login($nova);

        Volt::test('kid.arena')
            ->assertSee('Go claim it')
            ->assertDontSee('Nudge Nova')
            ->assertDontSee('A rescue keeps the run alive', escape: false);
    }

    public function test_a_rescue_costs_the_rescuer_tickets_and_keeps_the_run_alive(): void
    {
        $nova = $this->kid('Nova');
        $rex = $this->kid('Rex');
        $this->chores();

        $today = HouseholdClock::for($this->household)->today();
        $this->approveQuestOn($rex, $today->copy()->subDay());
        $rex->update(['streak' => 4]);
        $nova->update(['bonus_tickets' => 5]);

        $this->assertTrue($this->arena()->rescue($nova, $rex));

        $this->assertSame(5 - ArenaService::RESCUE_COST, $nova->refresh()->bonus_tickets);
        // The night now counts toward the run, exactly as a repair does.
        $this->assertTrue(app(ChoreService::class)->questApprovedOn($rex, $today));
    }

    public function test_a_rescued_night_keeps_the_run_but_not_the_ladder(): void
    {
        $nova = $this->kid('Nova');
        $rex = $this->kid('Rex');
        $this->chores();

        $today = HouseholdClock::for($this->household)->today();

        // Two earned nights behind them, so a third would cross the day-3
        // milestone if the rescued night counted toward the ladder.
        $this->approveQuestOn($rex, $today->copy()->subDays(2));
        $this->approveQuestOn($rex, $today->copy()->subDay());
        $rex->update(['streak' => 2]);

        $nova->update(['bonus_tickets' => 5]);
        $this->assertTrue($this->arena()->rescue($nova, $rex));

        $chores = app(ChoreService::class);

        // The run stands at three nights — tonight now counts.
        $this->assertTrue($chores->questApprovedOn($rex, $today));
        // One of those three was bought, so the ladder is standing at two. The
        // rescue button's copy promises exactly this, and a day-3 milestone
        // paid off the back of it would make that copy a lie.
        $this->assertSame(1, $chores->rescuedNightsInRun($rex));
        $this->assertSame(0, $rex->refresh()->streak_milestone_paid_through);
        $this->assertNull($rex->pending_streak_chest);
    }

    public function test_a_rescue_is_refused_when_there_is_no_run_to_save(): void
    {
        $nova = $this->kid('Nova');
        $rex = $this->kid('Rex', 0);
        $this->chores();

        $nova->update(['bonus_tickets' => 5]);

        // Charging three tickets to keep a zero at zero is the sort of thing a
        // kid falls for exactly once.
        $this->assertFalse($this->arena()->rescue($nova, $rex));
        $this->assertSame(5, $nova->refresh()->bonus_tickets);
        $this->assertSame('No run to save yet', $this->arena()->rescueBlockedReason($nova, $rex));
    }

    public function test_a_second_rescue_on_the_same_night_is_refused(): void
    {
        $nova = $this->kid('Nova');
        $rex = $this->kid('Rex');
        $ziggy = $this->kid('Ziggy');
        $this->chores();

        $this->approveQuestOn($rex, HouseholdClock::for($this->household)->today()->subDay());
        $rex->update(['streak' => 4]);
        $nova->update(['bonus_tickets' => 5]);
        $ziggy->update(['bonus_tickets' => 5]);

        $this->assertTrue($this->arena()->rescue($nova, $rex));
        // A second rescuer must not be charged for a night already bought.
        $this->assertFalse($this->arena()->rescue($ziggy, $rex));
        $this->assertSame(5, $ziggy->refresh()->bonus_tickets);
    }

    public function test_a_kid_short_on_tickets_cannot_rescue(): void
    {
        $nova = $this->kid('Nova');
        $rex = $this->kid('Rex');
        $this->chores();

        $this->approveQuestOn($rex, HouseholdClock::for($this->household)->today()->subDay());
        $rex->update(['streak' => 4]);
        $nova->update(['bonus_tickets' => ArenaService::RESCUE_COST - 1]);

        $this->expectException(InsufficientTicketsException::class);

        $this->arena()->rescue($nova, $rex);
    }

    public function test_a_kid_with_no_run_is_not_told_one_is_on_the_line(): void
    {
        $nova = $this->kid('Nova', 0);
        $this->chores();

        $this->travelTo(Carbon::parse('2026-05-04 19:30', 'UTC'));

        Auth::guard('profile')->login($nova);

        // "Nova's 0-night run is still on the line" claims a loss that cannot
        // happen — there is nothing to lose yet, only something to start.
        Volt::test('kid.arena')
            ->assertSee('Nova could start a run tonight.', escape: false)
            ->assertDontSee('0 nights are on the line', escape: false);
    }

    /** An approved chore for a kid, on today's household day. */
    private function approveChore(Profile $kid, int $points, ?Carbon $at = null): ChoreCompletion
    {
        $chore = Chore::factory()->for($this->household)->create([
            'name' => "Job {$points}",
            'points' => $points,
            'min_age' => null,
            'quest_eligible' => false,
        ]);

        return ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $kid->id,
            'status' => CompletionStatus::Approved,
            'points_awarded' => $points,
            'submitted_at' => $at ?? now(),
            'decided_at' => $at ?? now(),
        ]);
    }

    public function test_todays_board_counts_only_approved_work_and_crowns_the_leader(): void
    {
        $nova = $this->kid('Nova');
        $rex = $this->kid('Rex');
        $this->chores();

        $this->approveChore($nova, 100);
        $this->approveChore($nova, 50);
        $this->approveChore($rex, 300);

        // A pending claim is work nobody has looked at — counting it would let
        // a kid take the lead by submitting.
        ChoreCompletion::create([
            'chore_id' => $this->household->chores->first()->id,
            'profile_id' => $rex->id,
            'status' => CompletionStatus::Pending,
            'points_awarded' => 999,
            'submitted_at' => now(),
        ]);

        $rows = $this->arena()->choresToday($this->household);

        $this->assertSame(2, $rows->firstWhere('profile.id', $nova->id)['chores']);
        $this->assertSame(150, $rows->firstWhere('profile.id', $nova->id)['points']);
        $this->assertSame(1, $rows->firstWhere('profile.id', $rex->id)['chores']);
        $this->assertTrue($rows->firstWhere('profile.id', $nova->id)['leader']);
        $this->assertFalse($rows->firstWhere('profile.id', $rex->id)['leader']);
    }

    public function test_nobody_leads_a_day_nobody_has_started(): void
    {
        $this->kid('Nova');
        $this->kid('Rex');
        $this->chores();

        // A gold bar at zero would crown whoever happens to sort first.
        $this->assertEmpty($this->arena()->choresToday($this->household)->where('leader', true));
    }

    public function test_the_superlatives_name_the_first_and_biggest_of_the_day(): void
    {
        $nova = $this->kid('Nova');
        $rex = $this->kid('Rex');
        $this->chores();

        $this->approveChore($nova, 50, Carbon::parse('2026-05-04 08:00', 'UTC'));
        $this->approveChore($rex, 400, Carbon::parse('2026-05-04 11:00', 'UTC'));

        $lines = $this->arena()->superlatives($this->household);

        $this->assertSame($nova->id, $lines['first']['profile']->id);
        $this->assertSame($rex->id, $lines['biggest']['profile']->id);
    }

    public function test_a_superlative_with_nothing_behind_it_is_absent_rather_than_filled_in(): void
    {
        $this->kid('Nova');
        $this->chores();

        $lines = $this->arena()->superlatives($this->household);

        // "BIGGEST JOB · nobody" is worse than the row not being there.
        $this->assertNull($lines['first']);
        $this->assertNull($lines['biggest']);
        // And "last standing" says the others have fallen. With nobody
        // finished it would single out one kid for being exactly where
        // everyone else is.
        $this->assertNull($lines['last']);
    }

    public function test_last_standing_appears_only_once_somebody_has_finished(): void
    {
        $nova = $this->kid('Nova');
        $rex = $this->kid('Rex');
        $this->chores();

        $chores = app(ChoreService::class);
        $chores->revealQuest($nova);
        $chores->claimQuest($nova);

        $lines = $this->arena()->superlatives($this->household);

        $this->assertNotNull($lines['last']);
        $this->assertSame($rex->id, $lines['last']['profile']->id);
    }

    public function test_the_crown_note_says_how_safe_the_lead_is(): void
    {
        $nova = $this->kid('Nova');
        $rex = $this->kid('Rex');
        $this->chores();

        $this->approveChore($nova, 100);
        $this->approveChore($nova, 100);
        $this->approveChore($rex, 100);

        // "Raylan is winning" ends the day. The margin is the invitation.
        $this->assertStringContainsString(
            'one ahead of Rex with the evening still to go',
            $this->arena()->crown($this->household)['note'],
        );
    }

    public function test_a_tied_crown_says_it_is_tied_rather_than_zero_ahead(): void
    {
        $nova = $this->kid('Nova');
        $rex = $this->kid('Rex');
        $this->chores();

        $this->approveChore($nova, 100);
        $this->approveChore($rex, 100);

        $this->assertStringContainsString('level with', $this->arena()->crown($this->household)['note']);
    }

    public function test_the_prize_standing_ranks_on_the_same_metric_as_the_house_bar(): void
    {
        $nova = $this->kid('Nova');
        $rex = $this->kid('Rex');
        $this->chores();
        $this->household->update(['weekly_chore_target' => 20]);

        // Rex has the longer run; Nova has done more this week. The prize is
        // the house target's reward, so the standing follows the bar next to
        // it — ranking on nights made these two cards two competitions.
        $this->approveQuestOn($rex, HouseholdClock::for($this->household)->today()->subDay());
        $rex->update(['streak' => 5]);
        $this->approveChore($nova, 100);
        $this->approveChore($nova, 100);

        $standing = $this->arena()->prizeStanding($this->household->fresh());

        $this->assertSame($nova->id, $standing->first()['profile']->id);
        $this->assertSame(2, $standing->first()['chores']);
        $this->assertSame(1, $standing->first()['rank']);
    }

    public function test_both_weekly_cards_name_the_same_goal_and_the_same_prize(): void
    {
        $this->kid('Nova');
        $this->chores();

        $this->household->update([
            'weekly_chore_target' => 20,
            'weekly_prize' => 'Friday movie pick',
        ]);

        Auth::guard('profile')->login(Profile::firstWhere('name', 'Nova'));

        $html = Volt::test('kid.arena')->assertOk()->html();

        // The bar names the reward instead of "the bonus"...
        $this->assertStringContainsString('20 = THE PRIZE', $html);
        // ...and the prize card names the target instead of leaving it next
        // door. Neither card can now be read on its own as a separate contest.
        $this->assertStringContainsString('20 chores', $html);
        $this->assertStringContainsString("Who's put in what", $html);
        $this->assertStringNotContainsString('nights', $html);
    }

    public function test_a_day_with_nothing_done_says_so_rather_than_drawing_empty_bars(): void
    {
        $kid = $this->kid('Nova');
        $this->kid('Rex');
        $this->chores();

        Auth::guard('profile')->login($kid);

        // Three empty tracks under a divider with nothing beneath it read as
        // broken rather than as "nobody has started".
        Volt::test('kid.arena')
            ->assertSee('Nothing done yet today', escape: false)
            ->assertDontSee('Last standing');
    }

    public function test_the_crown_rotates_through_its_titles_by_day(): void
    {
        $this->kid('Nova');
        $this->chores();

        $seen = [];

        foreach (range(0, 2) as $offset) {
            $this->travelTo(Carbon::parse('2026-05-04 15:00', 'UTC')->addDays($offset));
            $crown = $this->arena()->crown($this->household);
            $seen[] = $crown['label'];

            // Tomorrow's is named so everyone knows what to go after next.
            $this->assertNotSame($crown['label'], $crown['tomorrow']);
        }

        // Three days, three different titles — a board with one permanent
        // winner stops being a board.
        $this->assertCount(3, array_unique($seen));
    }

    public function test_the_house_week_bar_needs_a_target_to_exist(): void
    {
        $this->kid('Nova');
        $this->chores();

        // A bar against a number nobody chose is worse than no bar.
        $this->assertNull($this->arena()->houseWeek($this->household));

        $this->household->update(['weekly_chore_target' => 20]);

        $this->assertNotNull($this->arena()->houseWeek($this->household->fresh()));
    }

    public function test_the_house_week_bar_sums_every_kid_against_one_target(): void
    {
        $nova = $this->kid('Nova');
        $rex = $this->kid('Rex');
        $this->chores();
        $this->household->update(['weekly_chore_target' => 10]);

        $this->approveChore($nova, 100);
        $this->approveChore($nova, 100);
        $this->approveChore($rex, 100);

        $week = $this->arena()->houseWeek($this->household->fresh());

        $this->assertSame(3, $week['done']);
        $this->assertSame(10, $week['target']);
        $this->assertSame(30, $week['percent']);
        $this->assertSame(2, $week['segments']->firstWhere('profile.id', $nova->id)['chores']);
    }

    public function test_the_ticker_is_structured_events_not_ledger_sentences(): void
    {
        $nova = $this->kid('Nova');
        $rex = $this->kid('Rex');
        $this->chores();

        $this->approveQuestOn($rex, HouseholdClock::for($this->household)->today()->subDay());
        $rex->update(['streak' => 3]);
        $nova->update(['bonus_tickets' => 5]);

        $this->arena()->nudge($nova, $rex);
        $this->arena()->rescue($nova, $rex);

        $events = $this->arena()->ticker($this->household);

        // Every row is glyph / who / what / value / when — a feed, not a wall
        // of sentences. Reading the ledger's own descriptions gave lines like
        // "Scout — Own bed", which say nothing to the kid looking at them.
        foreach ($events as $event) {
            $this->assertArrayHasKey('glyph', $event);
            $this->assertInstanceOf(Profile::class, $event['profile']);
            $this->assertNotSame('', $event['what']);
        }

        $nudge = $events->firstWhere('glyph', '🔔');
        $this->assertSame($nova->id, $nudge['profile']->id);
        $this->assertSame('nudged Rex', $nudge['what']);

        // Neither a nudge nor a rescue moves a balance, so neither writes a
        // ledger row — both are built from their own tables.
        $rescue = $events->firstWhere('glyph', '♡');
        $this->assertSame("kept Rex's run alive", $rescue['what']);
    }

    public function test_clearing_the_quest_is_a_different_ticker_row_from_a_chore(): void
    {
        $kid = $this->kid('Nova');
        $this->chores();

        $chores = app(ChoreService::class);
        $chores->revealQuest($kid);
        $chores->claimQuest($kid);

        $completion = ChoreCompletion::where('profile_id', $kid->id)->latest('id')->first();
        $completion->update(['status' => CompletionStatus::Approved, 'decided_at' => now()]);

        $events = $this->arena()->ticker($this->household);

        // One database row covers both, but clearing the day is not the same
        // event as doing a chore and must not wear the same tick.
        $this->assertNotNull($events->firstWhere('glyph', '🔥'));
        $this->assertNull($events->firstWhere('glyph', '✓'));
    }

    public function test_the_page_renders_the_week_and_the_prize_when_a_parent_has_set_them(): void
    {
        $nova = $this->kid('Nova');
        $this->chores();

        $this->household->update([
            'weekly_chore_target' => 20,
            'weekly_prize' => 'Friday movie pick + $5',
            'weekly_prize_note' => 'Hit 20 between you.',
        ]);

        Auth::guard('profile')->login($nova);

        Volt::test('kid.arena')
            ->assertSee('The house, this week')
            // Names the prize once one is set; falls back to "HOUSE BONUS"
            // only when there is nothing to name.
            ->assertSee('20 = THE PRIZE')
            ->assertSee('Friday movie pick + $5')
            // Ranked on nights, because that is what the prize is settled on.
            // escape: false — literal template text, so Blade never escapes
            // the apostrophe and an escaped needle can't match.
            ->assertSee("Who's put in what", escape: false)
            // Nothing records which parent set it, so it must not name one.
            ->assertSee('SET BY A GROWN-UP')
            // The deadline is what turns #2 into something to act on.
            ->assertSee('RESETS SUNDAY 4:00AM', escape: false);
    }

    public function test_a_parent_sets_the_watch_hour_the_target_and_the_prize(): void
    {
        $this->kid('Nova');
        $this->chores();

        $parent = Profile::factory()->parent()->for($this->household)->create();
        Auth::guard('profile')->login($parent);

        Volt::test('parent.kids')
            ->assertSee('The Arena')
            // The control must not read as a bedtime — nothing expires at it.
            ->assertSee('Nothing expires', escape: false)
            ->assertSee('Save Arena settings')
            ->set('eveningWatchHour', 21)
            ->set('weeklyChoreTarget', '60')
            ->set('weeklyPrize', 'Friday movie pick')
            ->set('weeklyPrizeNote', 'Hit 60 between you.')
            // Nothing is written until the button is pressed. Setting the
            // fields alone used to save, which is what left a parent unable to
            // tell "saved" from "silently didn't".
            ->assertSet('arenaMessage', null)
            ->call('saveArenaSettings')
            ->assertSee('Saved.');

        $household = $this->household->fresh();

        $this->assertSame(21, $household->evening_watch_hour);
        $this->assertSame(60, $household->weekly_chore_target);
        $this->assertSame('Friday movie pick', $household->weekly_prize);
        $this->assertSame('Hit 60 between you.', $household->weekly_prize_note);
    }

    public function test_clearing_the_weekly_target_switches_the_house_bar_off(): void
    {
        $this->kid('Nova');
        $this->chores();
        $this->household->update(['weekly_chore_target' => 40]);

        $parent = Profile::factory()->parent()->for($this->household)->create();
        Auth::guard('profile')->login($parent);

        Volt::test('parent.kids')
            ->set('weeklyChoreTarget', '')
            ->call('saveArenaSettings')
            // Says what switching it off actually did, rather than a bare
            // "Saved." over a bar that has just disappeared from the Arena.
            ->assertSee('the house bar stays off', escape: false);

        $this->assertNull($this->household->fresh()->weekly_chore_target);
        $this->assertNull($this->arena()->houseWeek($this->household->fresh()));
    }

    public function test_the_watch_hour_cannot_be_set_to_the_morning(): void
    {
        $this->kid('Nova');
        $this->chores();

        $parent = Profile::factory()->parent()->for($this->household)->create();
        Auth::guard('profile')->login($parent);

        // A watch hour over breakfast would have the Arena calling a quest at
        // risk before anyone had a chance to do it.
        Volt::test('parent.kids')
            ->set('eveningWatchHour', 6)
            ->call('saveArenaSettings')
            // Echoed back from what was written, so the parent sees the number
            // they really have rather than the one they typed.
            ->assertSet('eveningWatchHour', 15);

        $this->assertSame(15, $this->household->fresh()->evening_watch_hour);
    }

    public function test_a_household_with_no_usable_watch_hour_degrades_instead_of_crashing(): void
    {
        $kid = $this->kid('Nova');
        $this->chores();

        // Two ways the hour arrives unusable. The column is NOT NULL with a
        // default, so null only ever happens in memory — which is exactly the
        // case that bit: a database default is applied on insert and never
        // read back, so a freshly-created household carries no value at all
        // until it is reloaded.
        $inMemory = $this->household->replicate();
        $inMemory->evening_watch_hour = null;

        $this->assertNull(HouseholdClock::for($inMemory)->eveningWatch());
        $this->assertSame(0.0, $this->arena()->riskRamp($inMemory));

        // And 200 is a legal row for an unsignedTinyInteger and an illegal
        // hour, which atTime() also refuses.
        $this->household->forceFill(['evening_watch_hour' => 200])->save();

        $this->assertNull(HouseholdClock::for($this->household->fresh())->eveningWatch());
        $this->assertSame(0.0, $this->arena()->riskRamp($this->household->fresh()));
        // Nobody is ever at risk without a threshold, which is the right way
        // to fail for something that is only ever a display state.
        $this->assertSame(
            ArenaService::STATE_OPEN,
            $this->arena()->stateFor($kid->refresh(), false),
        );

        Auth::guard('profile')->login($kid);
        Volt::test('kid.arena')->assertOk();
    }

    public function test_a_kid_cannot_nudge_or_rescue_a_parent(): void
    {
        $kid = $this->kid('Nova');
        $parent = Profile::factory()->parent()->for($this->household)->create(['name' => 'Mum']);
        $this->chores();
        $kid->update(['bonus_tickets' => 10]);

        // Reachable by id from a public Livewire method. questFor() would
        // otherwise build a daily quest for a grown-up.
        $this->assertFalse($this->arena()->nudge($kid, $parent));
        $this->assertFalse($this->arena()->rescue($kid, $parent));

        Auth::guard('profile')->login($kid);
        Volt::test('kid.arena')->call('nudge', $parent->id)->call('rescue', $parent->id);

        $this->assertSame(0, Nudge::where('to_profile_id', $parent->id)->count());
        $this->assertNull(DailyQuest::where('profile_id', $parent->id)->first());
        $this->assertSame(10, $kid->refresh()->bonus_tickets);
    }

    public function test_a_second_rescuer_losing_the_race_is_refused_not_a_500(): void
    {
        $nova = $this->kid('Nova');
        $rex = $this->kid('Rex');
        $ziggy = $this->kid('Ziggy');
        $this->chores();

        $today = HouseholdClock::for($this->household)->today();
        $this->approveQuestOn($rex, $today->copy()->subDay());
        $rex->update(['streak' => 4]);
        $nova->update(['bonus_tickets' => 5]);
        $ziggy->update(['bonus_tickets' => 5]);

        // The row Ziggy is about to collide with, written behind the service's
        // back so its own wasRescuedOn() guard can't see it — the window a
        // check-then-insert always leaves open.
        StreakRescue::create([
            'profile_id' => $rex->id,
            'rescued_by_profile_id' => $nova->id,
            'rescued_date' => $today,
            'tickets_paid' => ArenaService::RESCUE_COST,
        ]);

        $this->assertFalse($this->arena()->rescue($ziggy, $rex));
        // Rolled back, so the loser keeps their tickets.
        $this->assertSame(5, $ziggy->refresh()->bonus_tickets);
        $this->assertSame(1, StreakRescue::where('profile_id', $rex->id)->count());
    }

    public function test_the_arena_asks_for_tonight_once_per_render(): void
    {
        $this->kid('Nova');
        $this->kid('Rex');
        $this->chores();

        Auth::guard('profile')->login(Profile::firstWhere('name', 'Nova'));

        // Five call sites want tonightFor(); unmemoised that walked every kid
        // five times on the page every kid lands on.
        \DB::enableQueryLog();
        Volt::test('kid.arena')->assertOk();
        $queries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertLessThan(
            160,
            $queries,
            "The Arena ran {$queries} queries for two kids — the per-kid walk is repeating.",
        );
    }

    public function test_the_ticker_reports_the_run_as_it_stood_that_day(): void
    {
        $kid = $this->kid('Nova');
        $this->chores();

        $today = HouseholdClock::for($this->household)->today();

        // Cleared two days ago, missed yesterday: the run is dead now, but
        // that day's row still has to say what was true then.
        $this->approveQuestOn($kid, $today->copy()->subDays(2));
        app(ChoreService::class)->syncStreak($kid);

        $this->assertSame(0, $kid->refresh()->streak);

        $cleared = $this->arena()->ticker($this->household)->firstWhere('glyph', '🔥');

        $this->assertNotNull($cleared);
        // Not "0 nights in a row" directly above its own 💀 row.
        $this->assertStringContainsString('1 night in a row', $cleared['what']);
    }

    public function test_the_flag_ladder_matches_the_streak_bonuses(): void
    {
        $flags = $this->arena()->flags();

        $this->assertSame(
            array_keys(ChoreService::STREAK_BONUSES),
            array_column($flags, 'nights'),
        );

        // Spread evenly along the track rather than linearly on nights, which
        // would bunch the first four flags into the left tenth.
        $this->assertSame([14.0, 30.0, 46.0, 68.0, 94.0], array_column($flags, 'left'));
    }

    public function test_a_streak_interpolates_between_the_two_flags_it_sits_between(): void
    {
        $flags = $this->arena()->flags();

        $this->assertSame(2.0, $this->arena()->positionFor(0, $flags));
        $this->assertSame(14.0, $this->arena()->positionFor(3, $flags));
        $this->assertSame(30.0, $this->arena()->positionFor(5, $flags));
        $this->assertSame(94.0, $this->arena()->positionFor(30, $flags));

        // Four nights sits between the 3 and 5 flags, so between 14% and 30%.
        $four = $this->arena()->positionFor(4, $flags);
        $this->assertGreaterThan(14.0, $four);
        $this->assertLessThan(30.0, $four);
    }
}
