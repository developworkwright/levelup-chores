<?php

namespace Tests\Feature;

use App\Enums\CompletionStatus;
use App\Enums\LedgerKind;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyMystery;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\LedgerEntry;
use App\Models\Profile;
use App\Services\ChoreService;
use App\Services\HouseholdClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class MysteryChoreTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): ChoreService
    {
        return app(ChoreService::class);
    }

    public function test_a_mystery_chore_is_picked_automatically_with_no_parent_action(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['name' => 'Only candidate']);

        $chore = $this->service()->mysteryChoreFor($household);

        $this->assertNotNull($chore);
        $this->assertSame('Only candidate', $chore->name);
        $this->assertSame(1, DailyMystery::where('household_id', $household->id)->count());
    }

    public function test_the_pick_is_stable_across_repeated_calls_the_same_day(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->count(10)->create();

        $service = $this->service();
        $first = $service->mysteryChoreFor($household)->id;
        $second = $service->mysteryChoreFor($household)->id;

        $this->assertSame($first, $second);
        $this->assertSame(1, DailyMystery::where('household_id', $household->id)->count());
    }

    public function test_an_age_gated_chore_is_never_picked(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['name' => 'Too old for this', 'min_age' => 10]);
        $onlyEligible = Chore::factory()->for($household)->create(['name' => 'Any age']);

        $chore = $this->service()->mysteryChoreFor($household);

        $this->assertSame($onlyEligible->id, $chore->id);
    }

    public function test_an_unlimited_cadence_chore_is_never_picked(): void
    {
        // Unlimited chores are always freely repeatable by everyone — that
        // contradicts "first one to find it wins", so they're excluded.
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['name' => 'Laundry', 'cadence' => 'unlimited']);
        $onlyEligible = Chore::factory()->for($household)->create(['name' => 'Normal chore']);

        $chore = $this->service()->mysteryChoreFor($household);

        $this->assertSame($onlyEligible->id, $chore->id);
    }

    public function test_a_chore_already_completed_today_is_never_picked(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $alreadyDone = Chore::factory()->for($household)->create(['name' => 'Already done today']);
        $stillOpen = Chore::factory()->for($household)->create(['name' => 'Still open']);

        // Built directly rather than through claim(), which drags the spin
        // multiplier and the parent notification in for no reason — the
        // precondition here is just "this chore is spoken for".
        ChoreCompletion::create([
            'chore_id' => $alreadyDone->id,
            'profile_id' => $kid->id,
            'status' => CompletionStatus::Pending,
            'points_awarded' => $alreadyDone->points,
            'submitted_at' => now(),
        ]);

        $chore = $this->service()->mysteryChoreFor($household);

        $this->assertSame($stillOpen->id, $chore->id);
    }

    public function test_a_hinted_chore_wins_the_draw_over_unhinted_ones(): void
    {
        // Keeps the Bonus Shop's mystery hint sellable — there's no point
        // charging tickets for a clue on a chore the parent never wrote one for.
        $household = Household::factory()->create();
        Chore::factory()->for($household)->count(5)->create(['hint' => null]);
        $hinted = Chore::factory()->for($household)->create([
            'name' => 'The hinted one',
            'hint' => 'It lives under the sink.',
        ]);

        $this->assertSame($hinted->id, $this->service()->mysteryChoreFor($household)->id);
    }

    public function test_it_falls_back_to_unhinted_chores_when_none_have_hints(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->count(3)->create(['hint' => null]);

        $this->assertNotNull($this->service()->mysteryChoreFor($household));
    }

    public function test_a_hinted_chore_still_has_to_meet_every_other_rule(): void
    {
        $household = Household::factory()->create();
        // Hinted but age-gated, and hinted but unlimited — a hint doesn't buy
        // a chore past the fairness rules.
        Chore::factory()->for($household)->create(['hint' => 'Age gated', 'min_age' => 10]);
        Chore::factory()->for($household)->create(['hint' => 'Unlimited', 'cadence' => 'unlimited']);
        $eligible = Chore::factory()->for($household)->create(['name' => 'Plain but eligible', 'hint' => null]);

        $this->assertSame($eligible->id, $this->service()->mysteryChoreFor($household)->id);
    }

    public function test_a_reroll_keeps_the_pick_stable_for_the_rest_of_the_day(): void
    {
        // The swap has to persist, not just return a different chore — every
        // kid in the house must see the same one for the rest of the day.
        $household = Household::factory()->create();
        Chore::factory()->for($household)->count(5)->create();

        $service = $this->service();
        $service->mysteryChoreFor($household);
        $rerolled = $service->rerollMysteryChore($household);

        $this->assertSame($rerolled->id, $service->mysteryChoreFor($household)->id);
        $this->assertSame(1, DailyMystery::where('household_id', $household->id)->count());
    }

    public function test_a_reroll_never_lands_on_the_same_chore(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->count(6)->create();

        $service = $this->service();
        $before = $service->mysteryChoreFor($household);

        $this->assertNotSame($before->id, $service->rerollMysteryChore($household)->id);
    }

    public function test_a_reroll_with_no_alternative_returns_null(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create();

        $service = $this->service();
        $service->mysteryChoreFor($household);

        $this->assertNull($service->rerollMysteryChore($household));
    }

    public function test_a_reroll_is_refused_while_a_claim_is_waiting_on_a_parent(): void
    {
        // That kid has done the work and is one approval away from the bonus.
        // Plenty of alternatives exist, so a null here is the guard talking
        // rather than an empty pool.
        $household = Household::factory()->create(['require_quest_first' => false]);
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(4)->create();

        $this->service()->claim($kid, $this->service()->mysteryChoreFor($household));

        $this->assertNull($this->service()->rerollMysteryChore($household));
    }

    public function test_a_reroll_is_refused_once_the_bonus_has_been_won(): void
    {
        // Swapping after the payout would hang a second +500 on a different
        // chore the same day.
        $household = Household::factory()->create(['require_quest_first' => false]);
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(4)->create();

        $chore = $this->service()->mysteryChoreFor($household);
        $this->service()->approve($this->service()->claim($kid, $chore), $parent);

        $this->assertNull($this->service()->rerollMysteryChore($household));
    }

    public function test_a_reroll_is_allowed_once_a_claim_is_decided_with_nobody_winning(): void
    {
        // Where every mystery approved before the bonus moved to approval ends
        // up: the chore is on cooldown for the whole household, so nobody can
        // win today's bonus on it any more. Refusing the swap would leave the
        // parent with a dead mystery for the rest of the day.
        $household = Household::factory()->create(['require_quest_first' => false]);
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        $settled = Chore::factory()->for($household)->create(['name' => 'Already signed off']);
        Chore::factory()->for($household)->count(3)->create();

        $this->service()->approve($this->service()->claim($kid, $settled), $parent);

        // The day's mystery, pointed at the settled chore with no winner on it.
        DailyMystery::where('household_id', $household->id)->firstOrFail()->forceFill([
            'chore_id' => $settled->id,
            'found_by_profile_id' => null,
            'found_at' => null,
        ])->save();

        $rerolled = $this->service()->rerollMysteryChore($household);

        $this->assertNotNull($rerolled);
        $this->assertNotSame($settled->id, $rerolled->id);
    }

    public function test_a_reroll_assigns_one_when_none_was_drawn_yet(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->count(3)->create();

        $this->assertNotNull($this->service()->rerollMysteryChore($household));
    }

    public function test_returns_null_when_nothing_is_eligible(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['min_age' => 10]);
        Chore::factory()->for($household)->create(['cadence' => 'unlimited']);

        $this->assertNull($this->service()->mysteryChoreFor($household));
    }

    public function test_claiming_the_mystery_chore_awards_nothing_on_its_own(): void
    {
        $household = Household::factory()->create(['require_quest_first' => false]);
        $winner = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['points' => 100]);

        // The only eligible chore, so it's guaranteed to become the mystery.
        $this->service()->mysteryChoreFor($household);
        $completion = $this->service()->claim($winner, $chore);

        // Submitting is a kid saying they did it. The bonus waits for a parent
        // to say the same — otherwise submitting the whole board is a way of
        // being told which chore carries it.
        $this->assertSame(100, $completion->points_awarded);
        $this->assertNull($this->service()->mysteryFinderFor($household));

        $claimant = $this->service()->claimantFor($chore);
        $this->assertNotNull($claimant);
        $this->assertSame($winner->id, $claimant->profile_id);
    }

    public function test_approving_the_mystery_chore_pays_the_bonus(): void
    {
        $household = Household::factory()->create(['require_quest_first' => false]);
        $parent = Profile::factory()->parent()->for($household)->create();
        $winner = Profile::factory()->for($household)->create(['points' => 0]);
        $chore = Chore::factory()->for($household)->create(['points' => 100]);

        $this->service()->mysteryChoreFor($household);
        $completion = $this->service()->claim($winner, $chore);
        $this->service()->approve($completion, $parent);

        $this->assertSame(100 + ChoreService::MYSTERY_BONUS_POINTS, $completion->refresh()->points_awarded);
        $this->assertSame(100 + ChoreService::MYSTERY_BONUS_POINTS, $winner->refresh()->points);
        $this->assertSame($winner->id, $this->service()->mysteryFinderFor($household)->id);
    }

    public function test_the_bonus_reaches_the_ledger_as_part_of_the_approval(): void
    {
        // One entry, not two — the ledger is what the parent's Activity feed
        // reads, and a split payout there would read as the chore paying twice.
        $household = Household::factory()->create(['require_quest_first' => false]);
        $parent = Profile::factory()->parent()->for($household)->create();
        $winner = Profile::factory()->for($household)->create(['points' => 0]);
        $chore = Chore::factory()->for($household)->create(['points' => 100]);

        $this->service()->mysteryChoreFor($household);
        $this->service()->approve($this->service()->claim($winner, $chore), $parent);

        $entries = LedgerEntry::where('profile_id', $winner->id)->where('kind', LedgerKind::Earn)->get();

        $this->assertCount(1, $entries);
        $this->assertSame(100 + ChoreService::MYSTERY_BONUS_POINTS, $entries->first()->amount);
    }

    public function test_a_sent_back_mystery_claim_wins_nothing(): void
    {
        $household = Household::factory()->create(['require_quest_first' => false]);
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create(['points' => 0]);
        $chore = Chore::factory()->for($household)->create(['points' => 100]);

        $this->service()->mysteryChoreFor($household);
        $this->service()->sendBack($this->service()->claim($kid, $chore), $parent);

        $this->assertSame(0, $kid->refresh()->points);
        $this->assertNull($kid->pending_mystery_celebration);
        // Still up for grabs — a rejection reopens the chore, so the race it
        // carries has to reopen with it.
        $this->assertNull($this->service()->mysteryFinderFor($household));
    }

    public function test_the_race_survives_a_rejection_and_the_next_kid_can_win_it(): void
    {
        $household = Household::factory()->create(['require_quest_first' => false]);
        $parent = Profile::factory()->parent()->for($household)->create();
        $first = Profile::factory()->for($household)->create();
        $second = Profile::factory()->for($household)->create(['points' => 0]);
        $chore = Chore::factory()->for($household)->create(['points' => 100]);

        $this->service()->mysteryChoreFor($household);
        $this->service()->sendBack($this->service()->claim($first, $chore), $parent);
        $this->service()->approve($this->service()->claim($second, $chore), $parent);

        $this->assertSame($second->id, $this->service()->mysteryFinderFor($household)->id);
        $this->assertSame(100 + ChoreService::MYSTERY_BONUS_POINTS, $second->refresh()->points);
    }

    public function test_approving_an_ordinary_chore_pays_no_bonus(): void
    {
        $household = Household::factory()->create(['require_quest_first' => false]);
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create(['points' => 0]);
        // Hinted chores win the draw outright, which pins the mystery here.
        Chore::factory()->for($household)->create(['name' => 'The mystery', 'hint' => 'Under the sink']);
        $ordinary = Chore::factory()->for($household)->create(['name' => 'Ordinary', 'points' => 100]);

        $this->service()->mysteryChoreFor($household);
        $this->service()->approve($this->service()->claim($kid, $ordinary), $parent);

        $this->assertSame(100, $kid->refresh()->points);
        $this->assertNull($this->service()->mysteryFinderFor($household));
    }

    public function test_the_bonus_is_paid_once_even_if_approve_is_called_twice(): void
    {
        $household = Household::factory()->create(['require_quest_first' => false]);
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create(['points' => 0]);
        $chore = Chore::factory()->for($household)->create(['points' => 100]);

        $this->service()->mysteryChoreFor($household);
        $completion = $this->service()->claim($kid, $chore);

        $this->service()->approve($completion, $parent);
        $this->service()->approve($completion, $parent);

        $this->assertSame(100 + ChoreService::MYSTERY_BONUS_POINTS, $kid->refresh()->points);
    }

    public function test_a_find_approved_the_next_morning_still_counts_for_the_night_it_was_done(): void
    {
        // Keyed to the household day the work was submitted in, not the one the
        // approval lands in — otherwise a parent who signs off over breakfast
        // quietly costs a kid the bonus they won at bedtime.
        Carbon::setTestNow(Carbon::parse('2026-08-05 18:00:00'));

        $household = Household::factory()->create(['require_quest_first' => false]);
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create(['points' => 0]);
        $chore = Chore::factory()->for($household)->create(['points' => 100]);

        $yesterdaysMystery = $this->service()->mysteryChoreFor($household);
        $completion = $this->service()->claim($kid, $chore);

        Carbon::setTestNow(Carbon::parse('2026-08-06 09:00:00'));

        $this->service()->approve($completion, $parent);

        $this->assertSame($chore->id, $yesterdaysMystery->id);
        $this->assertSame(100 + ChoreService::MYSTERY_BONUS_POINTS, $kid->refresh()->points);
        $this->assertSame(
            $kid->id,
            DailyMystery::whereDate('mystery_date', '2026-08-05')->first()->found_by_profile_id,
        );
    }

    public function test_the_mystery_chore_stays_in_the_normal_side_quest_board(): void
    {
        $household = Household::factory()->create(['require_quest_first' => false]);
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['name' => 'Sweep the porch']);
        $filler = Chore::factory()->for($household)->create(['name' => 'Filler chore']);

        // Pin today's quest to the filler chore — otherwise the mystery
        // chore could be dealt as a quest card itself and correctly NOT show
        // on the side-quest board, which isn't what this checks. The hand is
        // pinned too: a row with no hand is one questFor() deals into.
        DailyQuest::create([
            'household_id' => $household->id,
            'profile_id' => $kid->id,
            'chore_id' => $filler->id,
            'offered_chore_ids' => [$filler->id],
            'quest_date' => HouseholdClock::for($household)->today(),
        ]);

        $board = $this->service()->boardFor($kid);

        $this->assertTrue($board->contains(fn ($entry) => $entry['chore']->id === $chore->id));
    }

    public function test_a_second_kid_cannot_claim_the_mystery_chore_once_someone_else_has(): void
    {
        $household = Household::factory()->create(['require_quest_first' => false]);
        $first = Profile::factory()->for($household)->create();
        $second = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['points' => 500]);
        // Unlimited cadence excludes it from mystery candidacy, guaranteeing
        // $chore (the only other eligible candidate) wins the pick.
        Chore::factory()->for($household)->create(['name' => 'Filler chore', 'cadence' => 'unlimited']);

        $this->service()->claim($first, $chore);

        Auth::guard('profile')->login($second);
        Volt::test('kid.quests')->call('claimChore', $chore->id);

        $this->assertSame(
            1,
            ChoreCompletion::where('chore_id', $chore->id)->count(),
            'Only the first claim should have created a completion row.'
        );
    }

    public function test_state_for_shows_ready_for_others_until_claimed_then_done(): void
    {
        $household = Household::factory()->create();
        $first = Profile::factory()->for($household)->create();
        $second = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create();

        $this->assertSame('ready', $this->service()->stateFor($second, $chore));

        $this->service()->claim($first, $chore);

        $this->assertSame('done', $this->service()->stateFor($second, $chore));
        $this->assertSame('pending', $this->service()->stateFor($first, $chore));
    }

    public function test_a_rejected_mystery_claim_reopens_it_for_everyone(): void
    {
        $household = Household::factory()->create(['require_quest_first' => false]);
        $parent = Profile::factory()->parent()->for($household)->create();
        $first = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create();

        $completion = $this->service()->claim($first, $chore);
        $this->service()->sendBack($completion, $parent);

        $this->assertNull($this->service()->claimantFor($chore));
    }

    public function test_the_quests_page_teases_the_bonus_amount_without_the_board_entry_showing_it(): void
    {
        // The chore's NAME is expected to show plainly in the side-quest
        // board, same as any chore — only its mystery status/bonus is
        // secret. This checks the board entry's own payout stays normal
        // (100) while the separate status card teases the real bonus.
        $household = Household::factory()->create(['require_quest_first' => false]);
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create(['name' => 'Sweep the porch', 'points' => 100]);
        $filler = Chore::factory()->for($household)->create(['name' => 'Filler quest chore']);

        // Pin today's quest — and its hand — to the filler chore so the
        // mystery chore is guaranteed to still be sitting on the side-quest
        // board rather than dealt as a card.
        DailyQuest::create([
            'household_id' => $household->id,
            'profile_id' => $kid->id,
            'chore_id' => $filler->id,
            'offered_chore_ids' => [$filler->id],
            'quest_date' => HouseholdClock::for($household)->today(),
        ]);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->assertSee("One of today's chores is worth a bonus", false)
            ->assertSee('Sweep the porch')
            ->assertSeeInOrder(['Sweep the porch', '+100'])
            ->assertSee('+'.ChoreService::MYSTERY_BONUS_POINTS.' pts');
    }

    /**
     * A household whose mystery chore is pinned to 'Scrub the tub' — the
     * unlimited filler is excluded from the draw — plus the parent needed to
     * settle the race, since nothing is won until someone approves it.
     *
     * @return array{0: Household, 1: Profile, 2: Chore}
     */
    private function pinnedMystery(): array
    {
        $household = Household::factory()->create(['require_quest_first' => false]);
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['name' => 'Scrub the tub']);
        Chore::factory()->for($household)->create(['name' => 'Filler chore', 'cadence' => 'unlimited']);

        return [$household, $parent, $chore];
    }

    public function test_the_quests_page_says_nothing_while_the_claim_is_still_pending(): void
    {
        // The bug this whole mechanism exists to close: submitting a chore used
        // to name it as the mystery on the spot, so a kid could hand in the
        // whole board and be told the answer for free.
        [$household, , $chore] = $this->pinnedMystery();
        $kid = Profile::factory()->for($household)->create(['name' => 'Winner Kid']);

        $this->service()->claim($kid, $chore);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->assertSee("One of today's chores is worth a bonus", false)
            ->assertDontSee('You found it')
            ->assertDontSee('found it — Scrub the tub', escape: false);
    }

    public function test_the_quests_page_reveals_who_completed_it_once_it_is_approved(): void
    {
        [$household, $parent, $chore] = $this->pinnedMystery();
        $winner = Profile::factory()->for($household)->create(['name' => 'Winner Kid']);
        $other = Profile::factory()->for($household)->create();

        $this->service()->approve($this->service()->claim($winner, $chore), $parent);

        Auth::guard('profile')->login($other);

        // The secret is spent once it's found, so the losers are told which
        // chore it was too.
        Volt::test('kid.quests')->assertSee('Winner Kid found it — Scrub the tub', escape: false);
    }

    public function test_the_winner_sees_their_own_congratulations_not_better_luck_next_time(): void
    {
        [$household, $parent, $chore] = $this->pinnedMystery();
        $winner = Profile::factory()->for($household)->create(['name' => 'Winner Kid']);

        $this->service()->approve($this->service()->claim($winner, $chore), $parent);

        Auth::guard('profile')->login($winner);

        Volt::test('kid.quests')
            ->assertSee('You found it')
            ->assertDontSee('Better luck next time')
            ->assertDontSee('Completed by Winner Kid');
    }

    public function test_the_winner_is_told_which_chore_it_was(): void
    {
        [$household, $parent, $chore] = $this->pinnedMystery();
        $winner = Profile::factory()->for($household)->create();

        $this->service()->approve($this->service()->claim($winner, $chore), $parent);

        Auth::guard('profile')->login($winner);

        // A kid with several claims waiting on a parent can't otherwise tell
        // which one carried the bonus.
        Volt::test('kid.quests')->assertSee('You found it — Scrub the tub', escape: false);
    }

    public function test_the_win_is_celebrated_on_the_kids_screen_the_next_time_they_look(): void
    {
        // Approval happens on a screen no kid is watching, so the moment has to
        // wait on the profile — the same path the family goal takes.
        [$household, $parent, $chore] = $this->pinnedMystery();
        $winner = Profile::factory()->for($household)->create();

        Auth::guard('profile')->login($winner);
        Volt::test('kid.quests')->assertOk()->assertDontSee('You found the Mystery Chore!');

        $this->service()->approve($this->service()->claim($winner, $chore), $parent);

        $this->assertSame('Scrub the tub', $winner->refresh()->pending_mystery_celebration);

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('rewards-earned')
            ->assertSee('You found the Mystery Chore!', escape: false)
            ->assertSee('Scrub the tub');

        // Shown once. A card that fires on every page load stops being a moment.
        Volt::test('kid.quests')->assertOk()->assertDontSee('rewards-earned');
    }

    public function test_the_losing_sibling_gets_no_celebration_card(): void
    {
        [$household, $parent, $chore] = $this->pinnedMystery();
        $winner = Profile::factory()->for($household)->create();
        $loser = Profile::factory()->for($household)->create();

        $this->service()->approve($this->service()->claim($winner, $chore), $parent);

        $this->assertNull($loser->refresh()->pending_mystery_celebration);
    }
}
