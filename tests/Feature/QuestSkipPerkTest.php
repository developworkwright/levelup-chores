<?php

namespace Tests\Feature;

use App\Enums\CompletionStatus;
use App\Enums\PerkEffect;
use App\Exceptions\PerkUnavailableException;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\OwnedPerk;
use App\Models\Profile;
use App\Models\QuestSkip;
use App\Models\StreakRepair;
use App\Services\ArenaService;
use App\Services\BadgeService;
use App\Services\ChoreService;
use App\Services\HouseholdClock;
use App\Services\PerkInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The Day Off perk: buy your way past today's main quest.
 *
 * It does two things at once — opens the board that the quest gates, and makes
 * the streak count the day as kept. What it deliberately doesn't do is pay: you
 * skip the work, you skip the points.
 */
class QuestSkipPerkTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create(['require_quest_first' => true]);
        $this->kid = Profile::factory()->for($this->household)->create();

        foreach (range(1, 4) as $index) {
            Chore::factory()->for($this->household)->create(['name' => "Chore {$index}", 'points' => 100]);
        }
    }

    private function chores(): ChoreService
    {
        return app(ChoreService::class);
    }

    private function perks(): PerkInventoryService
    {
        return app(PerkInventoryService::class);
    }

    /** Puts a Day Off in the kid's pocket, as buying one would. */
    private function ownADayOff(): OwnedPerk
    {
        return $this->perks()->grant($this->kid, PerkEffect::QuestSkip, 'shop');
    }

    /** Clears the quest properly, the long way round. */
    private function doTheQuest(): void
    {
        $parent = Profile::factory()->parent()->for($this->household)->create();
        $quest = $this->chores()->claimQuest($this->kid);

        $completion = ChoreCompletion::where('profile_id', $this->kid->id)
            ->where('chore_id', $quest->chore_id)
            ->latest('id')
            ->firstOrFail();

        $this->chores()->approve($completion, $parent);
    }

    public function test_the_board_is_locked_until_the_quest_is_done(): void
    {
        $this->assertTrue($this->chores()->boardIsGated($this->kid));

        $states = $this->chores()->boardFor($this->kid)->pluck('state')->unique();

        $this->assertSame(['locked'], $states->all());
    }

    public function test_a_day_off_opens_the_board_without_the_quest(): void
    {
        $this->ownADayOff();
        $this->perks()->use($this->kid, PerkEffect::QuestSkip);

        $this->assertFalse($this->chores()->boardIsGated($this->kid->fresh()));
        $this->assertNotContains('locked', $this->chores()->boardFor($this->kid->fresh())->pluck('state')->all());
    }

    public function test_a_day_off_keeps_the_streak_alive(): void
    {
        // A chain running into yesterday, and a quest today that never gets done.
        $clock = HouseholdClock::for($this->household);
        DailyQuest::create([
            'household_id' => $this->household->id,
            'profile_id' => $this->kid->id,
            'chore_id' => Chore::where('household_id', $this->household->id)->first()->id,
            'quest_date' => $clock->today()->subDay()->toDateString(),
            'completed_at' => now()->subDay(),
        ]);
        StreakRepair::create([
            'profile_id' => $this->kid->id,
            'repaired_date' => $clock->today()->subDay(),
        ]);
        $this->kid->forceFill(['streak' => 1])->save();

        $this->ownADayOff();
        $this->perks()->use($this->kid->fresh(), PerkEffect::QuestSkip);

        $this->assertSame(2, $this->kid->fresh()->streak);
    }

    public function test_a_day_off_survives_the_streak_being_re_checked(): void
    {
        $this->ownADayOff();
        $this->perks()->use($this->kid, PerkEffect::QuestSkip);

        // syncStreak drops a chain that has quietly died — a bought day must
        // read as kept, or the perk is undone by the next page load.
        $this->chores()->syncStreak($this->kid->fresh());

        $this->assertSame(1, $this->kid->fresh()->streak);
    }

    public function test_a_day_off_pays_nothing(): void
    {
        $this->ownADayOff();
        $this->perks()->use($this->kid, PerkEffect::QuestSkip);

        $kid = $this->kid->fresh();

        $this->assertSame(0, $kid->points);
        $this->assertSame(0, $kid->xp);
        $this->assertSame(0, ChoreCompletion::where('profile_id', $kid->id)->count());
    }

    public function test_it_is_refused_once_the_quest_is_already_cleared(): void
    {
        $this->doTheQuest();
        $this->ownADayOff();

        $this->assertSame(
            "Today's quest is already cleared",
            $this->perks()->blockedReason($this->kid->fresh(), PerkEffect::QuestSkip),
        );

        $this->expectException(PerkUnavailableException::class);

        $this->perks()->use($this->kid->fresh(), PerkEffect::QuestSkip);
    }

    public function test_a_second_day_off_on_the_same_day_is_refused_and_not_spent(): void
    {
        $this->ownADayOff();
        $second = $this->ownADayOff();

        $this->perks()->use($this->kid, PerkEffect::QuestSkip);

        $this->assertStringStartsWith(
            'Back on ',
            $this->perks()->blockedReason($this->kid->fresh(), PerkEffect::QuestSkip),
        );

        try {
            $this->perks()->use($this->kid->fresh(), PerkEffect::QuestSkip);
            $this->fail('A second day off on one day should be refused.');
        } catch (PerkUnavailableException) {
            // A perk that could not be applied stays in the pocket.
            $this->assertNull($second->fresh()->used_at);
        }

        $this->assertSame(1, QuestSkip::where('profile_id', $this->kid->id)->count());
    }

    public function test_a_second_day_off_later_the_same_week_is_refused(): void
    {
        $this->ownADayOff();
        $this->ownADayOff();

        // Monday, so the rest of the week is still ahead of it.
        $this->travelTo(Carbon::parse('2026-08-10 09:00', $this->household->timezone));
        $this->perks()->use($this->kid->fresh(), PerkEffect::QuestSkip);

        $this->travelTo(Carbon::parse('2026-08-13 09:00', $this->household->timezone));

        $this->expectException(PerkUnavailableException::class);

        $this->perks()->use($this->kid->fresh(), PerkEffect::QuestSkip);
    }

    public function test_a_new_week_brings_the_day_off_back(): void
    {
        $this->ownADayOff();
        $this->ownADayOff();

        $this->travelTo(Carbon::parse('2026-08-13 09:00', $this->household->timezone));
        $this->perks()->use($this->kid->fresh(), PerkEffect::QuestSkip);

        // The following Monday.
        $this->travelTo(Carbon::parse('2026-08-17 09:00', $this->household->timezone));

        $this->assertNull($this->perks()->blockedReason($this->kid->fresh(), PerkEffect::QuestSkip));

        $this->perks()->use($this->kid->fresh(), PerkEffect::QuestSkip);

        $this->assertSame(2, QuestSkip::where('profile_id', $this->kid->id)->count());
    }

    public function test_the_refusal_says_when_the_next_one_is(): void
    {
        $this->ownADayOff();

        $this->travelTo(Carbon::parse('2026-08-13 09:00', $this->household->timezone));
        $this->perks()->use($this->kid->fresh(), PerkEffect::QuestSkip);

        // A date rather than a flat "no": a kid who can read when it comes back
        // saves it for the day they actually need it.
        $this->assertSame(
            'Back on Mon 17 Aug',
            $this->perks()->blockedReason($this->kid->fresh(), PerkEffect::QuestSkip),
        );
    }

    public function test_the_cap_holds_even_if_something_calls_the_service_directly(): void
    {
        $this->travelTo(Carbon::parse('2026-08-13 09:00', $this->household->timezone));

        $this->assertTrue($this->chores()->skipQuestToday($this->kid));

        $this->travelTo(Carbon::parse('2026-08-14 09:00', $this->household->timezone));

        // A rule that lives only in the thing greying out a button is a rule
        // the next caller quietly ignores.
        $this->assertFalse($this->chores()->skipQuestToday($this->kid->fresh()));
        $this->assertSame(1, QuestSkip::where('profile_id', $this->kid->id)->count());
    }

    public function test_the_bonus_shop_shows_when_it_comes_back(): void
    {
        Auth::guard('profile')->login($this->kid);
        $this->ownADayOff();
        $this->ownADayOff();

        $this->travelTo(Carbon::parse('2026-08-13 09:00', $this->household->timezone));
        $this->perks()->use($this->kid->fresh(), PerkEffect::QuestSkip);

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.bonus')
            ->assertOk()
            ->assertSee('Back on Mon 17 Aug');
    }

    public function test_it_costs_more_than_a_streak_restore(): void
    {
        // The whole pricing argument: a restore buys back one lost day, a day
        // off keeps the chain *and* opens the board without the work.
        $this->assertGreaterThan(
            PerkEffect::StreakRestore->defaults()['cost'],
            PerkEffect::QuestSkip->defaults()['cost'],
        );
    }

    public function test_the_board_says_the_day_was_bought_rather_than_earned(): void
    {
        Auth::guard('profile')->login($this->kid);

        $this->ownADayOff();
        $this->perks()->use($this->kid, PerkEffect::QuestSkip);

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('Day Off', false)
            ->assertDontSee('Locked Until Quest Is Done');
    }

    public function test_a_household_that_never_gated_the_board_is_unaffected(): void
    {
        $this->household->update(['require_quest_first' => false]);

        $this->assertFalse($this->chores()->boardIsGated($this->kid));

        // The perk still has something to sell: the streak half is the point
        // for a household that doesn't gate.
        $this->assertNull($this->perks()->blockedReason($this->kid, PerkEffect::QuestSkip));
    }

    public function test_a_skipped_day_is_not_a_perfect_board(): void
    {
        $this->ownADayOff();
        $this->perks()->use($this->kid, PerkEffect::QuestSkip);

        app(BadgeService::class)->evaluate($this->kid->fresh());

        // The badge is for clearing everything, and a bought day cleared nothing.
        $this->assertFalse($this->kid->badges()->where('key', 'perfect_board')->exists());
    }

    public function test_the_quest_itself_is_still_there_to_do_if_they_change_their_mind(): void
    {
        $this->ownADayOff();
        $this->perks()->use($this->kid, PerkEffect::QuestSkip);

        $this->doTheQuest();

        $this->assertTrue($this->chores()->isQuestDoneToday($this->kid->fresh()));
        $this->assertSame(CompletionStatus::Approved, ChoreCompletion::sole()->status);
    }

    public function test_a_bought_day_is_safe_on_the_arena_rather_than_at_risk(): void
    {
        $this->household->update(['day_boundary_hour' => 4, 'evening_watch_hour' => 19, 'timezone' => 'UTC']);
        $this->travelTo(Carbon::parse('2026-08-13 19:30', 'UTC'));

        $arena = app(ArenaService::class);

        // Past the watch hour with the quest open: the candle state.
        $this->assertSame(ArenaService::STATE_AT_RISK, $arena->stateFor($this->kid, false));

        $this->ownADayOff();
        $this->perks()->use($this->kid, PerkEffect::QuestSkip);

        // The quest is still open and always will be — that is what was bought
        // — so a state read off `completed_at` alone kept warning about a run
        // the perk had already made safe.
        $this->assertSame(ArenaService::STATE_SAFE, $arena->stateFor($this->kid->fresh(), false));
    }

    public function test_the_arena_names_the_day_off_instead_of_claiming_the_quest_was_cleared(): void
    {
        $this->household->update(['day_boundary_hour' => 4, 'evening_watch_hour' => 19, 'timezone' => 'UTC']);
        $this->travelTo(Carbon::parse('2026-08-13 19:30', 'UTC'));

        Auth::guard('profile')->login($this->kid);

        $this->ownADayOff();
        $this->perks()->use($this->kid, PerkEffect::QuestSkip);

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.arena')
            ->assertOk()
            ->assertSee('Day off')
            ->assertDontSee('At risk')
            // Safe, but nobody cleared anything: the lane must not hand the kid
            // credit for work the perk let them skip.
            ->assertDontSee('Quest cleared');
    }

    public function test_a_kid_who_bought_the_day_off_is_not_nudged_or_rescued(): void
    {
        $sibling = Profile::factory()->for($this->household)->create([
            'name' => 'Rex',
            'bonus_tickets' => 10,
        ]);

        $this->ownADayOff();
        $this->perks()->use($this->kid, PerkEffect::QuestSkip);

        $arena = app(ArenaService::class);

        // Neither is offered by the page any more, both being panels the safe
        // state never reaches — but a rule that lives only in a hidden button
        // is a rule the next caller ignores, and a rescue charges three
        // tickets for a run that was never in danger.
        $this->assertFalse($arena->nudge($sibling, $this->kid->fresh()));
        $this->assertFalse($arena->rescue($sibling, $this->kid->fresh()));
        $this->assertSame(
            'They bought the day off',
            $arena->rescueBlockedReason($sibling, $this->kid->fresh()),
        );
        $this->assertSame(10, $sibling->fresh()->bonus_tickets);
    }

    public function test_a_bought_day_reads_as_kept_when_tomorrow_is_checked(): void
    {
        $this->ownADayOff();
        $this->perks()->use($this->kid, PerkEffect::QuestSkip);

        $this->assertSame(1, $this->kid->fresh()->streak);

        // Tomorrow, with nothing done: yesterday still counts, so the chain is
        // hanging rather than dead.
        $this->travelTo(Carbon::now()->addDay());
        $this->chores()->syncStreak($this->kid->fresh());

        $this->assertSame(1, $this->kid->fresh()->streak);
    }
}
