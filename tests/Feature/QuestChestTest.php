<?php

namespace Tests\Feature;

use App\Enums\CompletionStatus;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class QuestChestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pinned to midday because the household day rolls over at 4am, not
     * midnight. The streak fixtures below are dated with now()->subDays(), so a
     * suite run in the small hours landed "yesterday's" quest on the
     * household's *today* — which reads as today already being cleared, and
     * takes the come-back-tomorrow wording with it.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::now()->startOfDay()->addHours(12));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_home_shows_the_streak_chest_ready_to_open(): void
    {
        $household = Household::factory()->create(['points_per_dollar' => 100]);
        $kid = Profile::factory()->for($household)->create(['streak' => 3, 'pending_streak_chest' => 3]);
        Chore::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        // The streak chest lives on Home now, alongside the rest of the daily
        // loop and the track that says what each milestone pays.
        Volt::test('kid.home')
            ->assertSee('Streak Chest')
            ->assertSee('Ready to open');
    }

    public function test_the_streak_chest_shows_the_bonus_in_points_not_dollars(): void
    {
        // STREAK_BONUSES is stored in dollars, but the kid-facing UI talks in
        // points everywhere else, so the chest converts before displaying.
        $household = Household::factory()->create(['points_per_dollar' => 100]);
        $kid = Profile::factory()->for($household)->create(['streak' => 3, 'pending_streak_chest' => 3]);
        Chore::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')
            ->assertSee('+100 PTS')
            ->assertDontSee('+$1');
    }

    public function test_the_streak_milestone_track_is_denominated_in_points(): void
    {
        $household = Household::factory()->create(['points_per_dollar' => 100]);
        $kid = Profile::factory()->for($household)->create(['streak' => 1]);
        Chore::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        // Day 3 pays $1 → 100 pts, day 30 pays $40 → 4000 pts.
        Volt::test('kid.home')
            ->assertSee('Day 3')
            ->assertSee('100 pts')
            ->assertSee('Day 30')
            ->assertSee('4,000 pts')
            ->assertDontSee('$1 ')
            ->assertDontSee('$40 ');
    }

    public function test_opening_the_streak_chest_from_home_clears_it(): void
    {
        $household = Household::factory()->create(['points_per_dollar' => 100]);
        $kid = Profile::factory()->for($household)->create(['streak' => 3, 'pending_streak_chest' => 3]);
        Chore::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')->call('openStreakChest');

        $this->assertNull($kid->refresh()->pending_streak_chest);
    }

    public function test_home_shows_the_come_back_tomorrow_message_when_no_chest_is_pending(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['streak' => 2]);
        $chore = Chore::factory()->for($household)->create();

        // A real two-day run behind the counter, not just the number: the page
        // expires a cached streak with nothing under it, which would drop this
        // to zero and take the day-3 wording with it.
        foreach ([1, 2] as $daysAgo) {
            $at = now()->copy()->subDays($daysAgo);

            DailyQuest::create([
                'household_id' => $household->id,
                'profile_id' => $kid->id,
                'chore_id' => $chore->id,
                'quest_date' => $at->toDateString(),
                'revealed_at' => $at,
                'completed_at' => $at,
            ]);

            ChoreCompletion::create([
                'chore_id' => $chore->id,
                'profile_id' => $kid->id,
                'status' => CompletionStatus::Approved,
                'points_awarded' => 10,
                'submitted_at' => $at,
                'decided_at' => $at,
            ]);
        }

        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')
            ->assertSee("Complete today's quest and come back tomorrow to open the chest", false);
    }

    public function test_an_outstanding_quest_still_gets_the_full_hero(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->assertSee("Today's main quest is inside")
            ->assertDontSee('Cleared');
    }

    public function test_arriving_with_the_quest_already_cleared_collapses_the_hero(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['name' => 'Water the plants']);

        Auth::guard('profile')->login($kid);

        app(ChoreService::class)->revealQuest($kid);
        app(ChoreService::class)->claimQuest($kid);

        // Landing on the tab afterwards should show a one-line summary, not
        // the full hero card pushing the chore board down the page. The claim
        // is still awaiting a parent, so the line says so rather than claiming
        // the quest is finished.
        Volt::test('kid.quests')
            ->assertSee($chore->name)
            ->assertSee('Waiting on parent')
            ->assertDontSee('Clear this one first', false)
            ->assertDontSee('Tap the chest to reveal', false);
    }

    public function test_an_approved_quest_reads_as_cleared_rather_than_waiting(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create(['name' => 'Water the plants']);

        $chores = app(ChoreService::class);
        $chores->revealQuest($kid);
        $chores->claimQuest($kid);

        $completion = ChoreCompletion::where('profile_id', $kid->id)->firstOrFail();
        $chores->approve($completion, $parent);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->assertSee('Cleared')
            ->assertDontSee('Waiting on parent');
    }

    public function test_clearing_the_quest_during_the_visit_keeps_the_hero_expanded(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        // Snapshotted at mount, so the card that was just celebrated doesn't
        // collapse out from under the kid the instant they finish it.
        Volt::test('kid.quests')
            ->call('revealQuest')
            ->call('claimQuest')
            ->assertSee('Quest cleared. Every side quest below is unlocked', false);
    }

    public function test_a_sent_back_quest_is_not_credited_as_cleared(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create();

        $chores = app(ChoreService::class);
        $chores->revealQuest($kid);
        $chores->claimQuest($kid);

        $chores->sendBack(ChoreCompletion::where('profile_id', $kid->id)->firstOrFail(), $parent);

        Auth::guard('profile')->login($kid);

        // Rejected work is neither cleared nor waiting — and the CTA has to
        // stay live, since redoing it is the whole point of sending it back.
        Volt::test('kid.quests')
            ->assertSee('Sent back')
            ->assertSee('Mark it done again')
            ->assertDontSee('Waiting on parent')
            ->assertDontSee('Cleared &#10003;', false);
    }

    public function test_a_kid_can_redo_a_quest_that_was_sent_back(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create();

        $chores = app(ChoreService::class);
        $chores->revealQuest($kid);
        $chores->claimQuest($kid);
        $chores->sendBack(ChoreCompletion::where('profile_id', $kid->id)->firstOrFail(), $parent);

        // Cleared, so the quest reads as open again rather than dead-ending on
        // a stamp from an attempt a parent explicitly rejected.
        $this->assertNull($chores->questFor($kid->fresh())->completed_at);

        Auth::guard('profile')->login($kid->fresh());

        Volt::test('kid.quests')->call('claimQuest');

        $this->assertTrue($chores->isQuestDoneToday($kid->fresh()));
        $this->assertSame(2, ChoreCompletion::where('profile_id', $kid->id)->count());
    }

    public function test_redoing_a_sent_back_quest_does_not_replay_the_chest(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create();

        $chores = app(ChoreService::class);
        $chores->revealQuest($kid);
        $chores->claimQuest($kid);
        $chores->sendBack(ChoreCompletion::where('profile_id', $kid->id)->firstOrFail(), $parent);

        // They already know which chore it is; making them re-open the chest to
        // redo work they were just told off for would read as mockery.
        $this->assertTrue($chores->isQuestRevealedToday($kid->fresh()));
    }

    public function test_a_claimed_quest_says_it_is_waiting_rather_than_done(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        // claimQuest() stamps completed_at straight away, but the points only
        // land on a parent's approval — so the CTA must not say "Cleared" and
        // imply the kid has already been paid.
        Volt::test('kid.quests')
            ->call('revealQuest')
            ->call('claimQuest')
            ->assertSee('Waiting on parent')
            ->assertDontSee('Cleared &#10003;', false);
    }
}
