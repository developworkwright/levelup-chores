<?php

namespace Tests\Feature;

use App\Enums\CompletionStatus;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyChest;
use App\Models\Household;
use App\Models\Meal;
use App\Models\Profile;
use App\Services\ChestService;
use App\Services\ChoreService;
use App\Services\GratitudeService;
use App\Services\HouseholdClock;
use App\Services\MonsterService;
use App\Services\SpinService;
use App\Services\StreakService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Home — the feed, and "Your day" beside it.
 *
 * The page was six full-height cards stacked down a scroll with the family feed
 * underneath them; it is now a six-row index that opens one row at a time, and
 * the feed gets the room. On a phone that index is a 3×2 board of tiles with the
 * panel opening below it; at desk size the same six are rows in a 340px column
 * and the feed sits alongside.
 *
 * The mechanics themselves belong to the suites that own them — the chest to
 * DailyChestTest, the spin to SpinFlowTest, the run to StreakDecayTest. What is
 * pinned here is the shape: what each row says while it is shut, that only one
 * opens, that the answer is remembered, and that everything still acts in place.
 */
class KidHomePageTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();

        // Pinned to the middle of a household day: every row reads its state off
        // the household clock, and a run that started either side of the 4am
        // rollover would be asserting about the clock instead.
        $this->travelTo(Carbon::parse('2026-05-01 12:00', $this->household->timezone));

        $this->kid = Profile::factory()->for($this->household)->create(['name' => 'Rex']);

        Chore::factory()->for($this->household)->count(6)->create(['points' => 120]);

        // A hinted decoy, because hinted chores win the mystery draw outright.
        // Without it the day's mystery can land on a chore one of these tests
        // approves, and its +500 turns up inside the money on the Work row.
        // Priced well above everything else so it is never the cheapest job,
        // which is the one the panel suggests.
        Chore::factory()->for($this->household)->create([
            'name' => 'The decoy',
            'points' => 999,
            'hint' => 'Somewhere warm',
        ]);

        Auth::guard('profile')->login($this->kid);
    }

    private function open(string $row): Testable
    {
        return Volt::test('kid.home')->call('toggleRow', $row);
    }

    /**
     * The page, loaded again from scratch.
     *
     * The guard hands back the same profile instance for the life of a test,
     * where a real second request resolves a fresh one from the session — so a
     * component that wrote to its own profile on the first load would read its
     * own stale copy on the second. Anything asserting about what a kid comes
     * back to has to come back properly.
     */
    private function reopen(): Testable
    {
        Auth::guard('profile')->login($this->kid->fresh());

        return Volt::test('kid.home');
    }

    public function test_the_day_is_an_index_and_the_room_is_beside_it(): void
    {
        Volt::test('kid.home')
            ->assertOk()
            ->assertSeeInOrder([
                // The index, its open panel, and the house rows under it — then
                // the room, which is the reason the day had to get smaller.
                'Your day',
                'Feelings',
                'Gratitude',
                'Meals',
                'Work today',
                'Family',
            ]);
    }

    /**
     * Feelings, Gratitude and Meals open like the day's rows but are not tasks,
     * so the counter still counts six — and answering does not move it.
     */
    public function test_the_house_rows_are_not_counted_as_the_day(): void
    {
        app(GratitudeService::class)->record($this->kid, ['one', 'two', 'three']);

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('0 of 6 done')
            ->assertSee("toggleRow('feelings')", escape: false)
            ->assertSee("toggleRow('gratitude')", escape: false)
            ->assertSee("toggleRow('meals')", escape: false);
    }

    public function test_every_row_of_the_day_is_on_the_index(): void
    {
        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Work')
            ->assertSee('Bonus Chest')
            ->assertSee('Bonus Wheel')
            ->assertSee('Streak Chest')
            ->assertSee('Weekly Prize')
            ->assertSee('The Fight')
            ->assertSee("toggleRow('chest')", escape: false);
    }

    /** A shut row still answers "what now". */
    public function test_a_shut_row_carries_its_status(): void
    {
        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('READY')
            ->assertSee('1 WAITING')
            ->assertSee('NOTHING YET');
    }

    public function test_the_counter_says_how_much_of_the_day_is_done(): void
    {
        Volt::test('kid.home')->assertOk()->assertSee('0 of 6 done');

        app(ChoreService::class)->claim($this->kid, $this->household->chores->first());
        app(ChestService::class)->open($this->kid);

        // Three, not two: the work is in, the chest is open, and the work
        // being in is also what makes tonight safe — a pending claim settles
        // the run's day, so the streak row is done as well.
        Volt::test('kid.home')->assertOk()->assertSee('3 of 6 done');
    }

    /** A kid who has never touched it arrives at the row that answers "what now". */
    public function test_work_is_the_row_that_starts_open(): void
    {
        Volt::test('kid.home')
            ->assertOk()
            ->assertSet('openRow', 'work')
            ->assertSee('Work today');
    }

    public function test_only_one_row_is_open_at_a_time(): void
    {
        $this->open('chest')
            ->assertSet('openRow', 'chest')
            ->assertSee("Open today's bonus chest")
            // Work shut itself on the way past. Without this the column grows
            // back into the page of stacked heroes it replaced.
            ->assertDontSee('Work today');
    }

    public function test_tapping_the_open_row_shuts_it(): void
    {
        Volt::test('kid.home')
            ->call('toggleRow', 'work')
            ->assertSet('openRow', null)
            ->assertDontSee('Work today');
    }

    /** A row a kid closed stays closed — across a page load, not just a render. */
    public function test_a_closed_row_is_remembered_for_the_household_day(): void
    {
        Volt::test('kid.home')->call('toggleRow', 'work');

        $this->assertNull($this->kid->refresh()->home_day_open);
        $this->assertTrue($this->kid->home_day_closed_on->isSameDay(HouseholdClock::for($this->household)->today()));

        $this->reopen()->assertSet('openRow', null);
    }

    public function test_an_open_row_is_remembered_too(): void
    {
        Volt::test('kid.home')->call('toggleRow', 'prize');

        $this->assertSame('prize', $this->kid->refresh()->home_day_open);

        $this->reopen()->assertSet('openRow', 'prize');
    }

    /** Yesterday's answer is yesterday's. */
    public function test_the_next_day_opens_work_again(): void
    {
        Volt::test('kid.home')->call('toggleRow', 'work')->assertSet('openRow', null);

        $this->travelTo(Carbon::parse('2026-05-02 12:00', $this->household->timezone));

        $this->reopen()->assertSet('openRow', 'work');
    }

    /**
     * Urgency outranks a remembered close, once: a streak chest is the one thing
     * on this page that is worth something and expires.
     */
    public function test_a_waiting_streak_chest_opens_its_own_row(): void
    {
        Volt::test('kid.home')->call('toggleRow', 'work')->assertSet('openRow', null);

        $this->kid->update(['streak' => 3, 'pending_streak_chest' => 3]);

        $this->reopen()
            ->assertSet('openRow', 'streak')
            ->assertSee('Your streak chest is waiting');
    }

    /** Once, though — closing it again has to stick. */
    public function test_closing_the_urgent_row_sticks(): void
    {
        $this->kid->update(['streak' => 3, 'pending_streak_chest' => 3]);

        Volt::test('kid.home')->assertSet('openRow', 'streak')->call('toggleRow', 'streak');

        $this->reopen()->assertSet('openRow', null);
    }

    /*
     * ------------------------------------------------------------------
     * Work — the row that stands where the daily quest did
     * ------------------------------------------------------------------
     */

    public function test_work_counts_today_in_money(): void
    {
        $chore = $this->household->chores->first();
        $service = app(ChoreService::class);
        $parent = Profile::factory()->parent()->for($this->household)->create();

        $service->approve($service->claim($this->kid, $chore), $parent);

        Volt::test('kid.home')
            ->assertOk()
            // Dollars are the headline and points the footnote, the same way a
            // board row is written.
            ->assertSee('$1.20')
            ->assertSee('120 pts')
            ->assertSee($chore->name)
            ->assertSee('Pick another job');
    }

    public function test_a_job_waiting_on_a_parent_says_so_rather_than_paying(): void
    {
        app(ChoreService::class)->claim($this->kid, $this->household->chores->first());

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('WAITING')
            ->assertSee('1 waiting');
    }

    public function test_a_sent_back_job_is_on_the_list_too(): void
    {
        $parent = Profile::factory()->parent()->for($this->household)->create();
        $service = app(ChoreService::class);

        $service->sendBack($service->claim($this->kid, $this->household->chores->first()), $parent);

        Volt::test('kid.home')->assertOk()->assertSee('SENT BACK');
    }

    /**
     * The replacement for the quest's *this one, now*. A board of jobs is a
     * decision, and the six-year-old is the kid who cannot make it.
     */
    public function test_an_empty_day_suggests_the_cheapest_job(): void
    {
        Chore::factory()->for($this->household)->create(['name' => 'Feed the cat', 'points' => 20]);

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('$0.00')
            ->assertSee('Feed the cat')
            ->assertSee('Do this one')
            ->assertSee('See all');
    }

    public function test_the_suggested_job_can_be_taken_without_leaving_home(): void
    {
        $cheap = Chore::factory()->for($this->household)->create(['name' => 'Feed the cat', 'points' => 20]);

        Volt::test('kid.home')->call('claimSuggested', $cheap->id)->assertOk();

        $this->assertDatabaseHas('chore_completions', [
            'chore_id' => $cheap->id,
            'profile_id' => $this->kid->id,
            'status' => CompletionStatus::Pending->value,
        ]);
    }

    /** A suggestion is minutes old by the time it is tapped. */
    public function test_a_suggested_job_a_sibling_took_first_says_so(): void
    {
        $sibling = Profile::factory()->for($this->household)->create();
        $cheap = Chore::factory()->for($this->household)->create(['name' => 'Feed the cat', 'points' => 20]);

        app(ChoreService::class)->claim($sibling, $cheap);

        Volt::test('kid.home')
            ->call('claimSuggested', $cheap->id)
            ->assertSee('That one just went');

        $this->assertSame(0, ChoreCompletion::where('profile_id', $this->kid->id)->count());
    }

    public function test_the_footer_says_how_big_the_board_is(): void
    {
        app(ChoreService::class)->claim($this->kid, $this->household->chores->first());

        Volt::test('kid.home')->assertOk()->assertSee('on the board');
    }

    /*
     * ------------------------------------------------------------------
     * The rest of the day, behind its rows
     * ------------------------------------------------------------------
     */

    public function test_the_bonus_chest_opens_in_place(): void
    {
        $this->open('chest')
            ->assertSee("Open today's bonus chest")
            ->call('openDailyChest')
            ->assertOk();

        $this->assertNotNull(DailyChest::where('profile_id', $this->kid->id)->first());
    }

    public function test_the_chest_row_says_it_has_been_opened(): void
    {
        app(ChestService::class)->open($this->kid);

        Volt::test('kid.home')->assertOk()->assertSee('OPENED');
    }

    public function test_the_wheel_row_points_at_the_page_that_spins_it(): void
    {
        $this->open('wheel')
            ->assertSee('Your Bonus Wheel spin is waiting')
            ->assertSee(route('kid.quests').'#bonus-wheel', escape: false);
    }

    public function test_a_spun_wheel_reads_as_used(): void
    {
        app(SpinService::class)->spin($this->kid);

        Volt::test('kid.home')->assertOk()->assertSee('USED');
    }

    public function test_the_streak_panel_carries_the_track(): void
    {
        $this->kid->update(['streak' => 3]);

        $this->open('streak')
            ->assertOk()
            ->assertSee('Streak Chest')
            ->assertSee('Next chest at day');
    }

    public function test_a_waiting_streak_chest_can_be_opened_here(): void
    {
        $this->kid->update(['streak' => 3, 'pending_streak_chest' => 3]);

        Volt::test('kid.home')
            ->assertSet('openRow', 'streak')
            ->call('openStreakChest')
            ->assertOk();

        $this->assertNull($this->kid->refresh()->pending_streak_chest);
    }

    public function test_the_weekly_prize_panel_names_the_prize(): void
    {
        $this->household->update([
            'weekly_chore_target' => 20,
            'weekly_prize' => 'Friday movie pick',
        ]);

        $this->open('prize')
            ->assertOk()
            ->assertSee('Friday movie pick');
    }

    public function test_the_prize_row_says_when_nothing_is_set(): void
    {
        Volt::test('kid.home')->assertOk()->assertSee('NONE SET');
    }

    public function test_the_fight_panel_draws_the_monster(): void
    {
        app(MonsterService::class)->spawn($this->household, 'Pizza night', 1000);

        $this->open('fight')->assertOk()->assertSee('Pizza night');
    }

    public function test_a_household_with_nothing_standing_says_so_on_the_row(): void
    {
        Volt::test('kid.home')->assertOk()->assertSee('Nothing standing');
    }

    /*
     * ------------------------------------------------------------------
     * Dinner, the feed, and the page as a whole
     * ------------------------------------------------------------------
     */

    private function meal(int $daysFromToday, string $name, ?string $note = null): Meal
    {
        return Meal::create([
            'household_id' => $this->household->id,
            'served_on' => HouseholdClock::for($this->household)->today()->addDays($daysFromToday),
            'name' => $name,
            'note' => $note,
        ]);
    }

    /** Tonight's dinner is on the Meals row's face, without opening anything. */
    public function test_the_meals_row_says_what_is_for_dinner_tonight(): void
    {
        $this->meal(0, 'Chicken curry');

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Tonight · Chicken curry');
    }

    /**
     * An absent answer has to be visibly absent — the same rule the feelings
     * strip follows. Saying nothing at all would read as the row being broken.
     */
    public function test_no_dinner_at_all_still_says_so(): void
    {
        Volt::test('kid.home')->assertOk()->assertSee('Tonight · nobody has said yet');

        $this->reopen()->call('toggleRow', 'meals')->assertSee("Nobody has said what's for dinner yet.", false);
    }

    /** Every night a grown-up has set, tonight first, and nothing already eaten. */
    public function test_the_meals_panel_lists_every_set_meal_from_tonight_on(): void
    {
        $this->meal(-1, 'Last night soup');
        $this->meal(3, 'Fish pie', 'with peas');
        $this->meal(0, 'Chicken curry');
        $this->meal(12, 'Birthday lasagne');

        Volt::test('kid.home')
            ->call('toggleRow', 'meals')
            ->assertSeeInOrder(['Tonight', 'Chicken curry', 'Fish pie', 'with peas', 'Birthday lasagne'])
            ->assertSee('3 SET')
            ->assertDontSee('Last night soup');
    }

    /** Said once while the panel is shut: it came out of the feed's card to come here. */
    public function test_dinner_is_not_also_inside_the_feed(): void
    {
        $this->meal(0, 'Chicken curry');

        $html = Volt::test('kid.home')->assertOk()->html();

        $this->assertSame(1, substr_count($html, 'Chicken curry'), 'Dinner must be on the page exactly once.');
    }

    public function test_the_family_feed_is_on_the_page_rather_than_linked_from_it(): void
    {
        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Family')
            // The composer, so the room can be answered from here.
            ->assertSee('Message everyone', escape: false);
    }

    /**
     * The feelings card is behind its row rather than a card under the day:
     * open, it was the tallest thing on the page and pushed the feed down.
     */
    public function test_the_feelings_card_waits_behind_its_row(): void
    {
        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('How are you today?')
            ->assertDontSee('How are you feeling today?');

        $this->reopen()
            ->call('toggleRow', 'feelings')
            ->assertSeeInOrder(['Feelings', 'How are you feeling today?', 'Message everyone']);
    }

    /**
     * The feed's quiet half — Today in the house and Grateful today — is left
     * off Home. On a phone it sorted under however long the chat had got, and
     * both are rows of the day now.
     */
    public function test_the_feed_leaves_its_quiet_half_to_the_day(): void
    {
        Volt::test('kid.home')
            ->assertDontSee('Today in the house')
            ->assertDontSee('Grateful today');

        $this->reopen()
            ->call('toggleRow', 'gratitude')
            ->assertSee('Hand it in')
            ->assertSee('Grateful today');
    }

    public function test_the_cards_are_not_numbered(): void
    {
        // The order is the habit, not a rule — nothing here is gated on
        // anything above it, and numbering them says otherwise.
        Volt::test('kid.home')
            ->assertOk()
            ->assertDontSee('Step 1')
            ->assertDontSee('Step 2');
    }

    public function test_home_still_renders_when_the_household_has_no_chores_at_all(): void
    {
        // This is the page a kid always lands on, so it is the one page that
        // must never be the thing that breaks.
        Chore::query()->delete();

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Your day')
            ->assertSee('Nothing on the board right now');
    }

    public function test_the_standings_have_left_the_page(): void
    {
        Profile::factory()->for($this->household)->create(['name' => 'Nova', 'points' => 900]);

        Volt::test('kid.home')
            ->assertOk()
            // Household is where the house is ranked; a second copy of that
            // table is the last thing a kid should scroll past on the way out
            // of their own day.
            ->assertDontSee('Where the house stands');
    }

    /** A run that survives being looked at, for the rows that read it. */
    private function giveKidAStreak(int $days): void
    {
        $chore = Chore::where('household_id', $this->household->id)->firstOrFail();

        foreach (range(1, $days) as $daysAgo) {
            $at = now()->copy()->subDays($daysAgo);

            ChoreCompletion::create([
                'chore_id' => $chore->id,
                'profile_id' => $this->kid->id,
                'status' => CompletionStatus::Approved,
                'points_awarded' => 10,
                'submitted_at' => $at,
                'decided_at' => $at,
            ]);
        }

        $this->kid->update(['streak' => $days]);
    }

    public function test_the_streak_row_counts_the_run(): void
    {
        $this->giveKidAStreak(4);

        app(StreakService::class)->syncStreak($this->kid);

        Volt::test('kid.home')->assertOk()->assertSee('Day 4');
    }
}
