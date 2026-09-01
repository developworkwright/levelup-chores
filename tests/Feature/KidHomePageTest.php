<?php

namespace Tests\Feature;

use App\Enums\CompletionStatus;
use App\Enums\PerkEffect;
use App\Models\BonusPerk;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyChest;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChestService;
use App\Services\ChoreService;
use App\Services\MonsterService;
use App\Services\SpinService;
use App\Services\StreakService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Home — the kid landing page, laid out as the day in the order it happens.
 *
 * The individual mechanics are owned by the suites they belong to: the chest by
 * DailyChestTest, the spin by SpinFlowTest and WheelClaimTest, the standings by
 * ArenaPageTest. What is pinned here is the thing the page exists for — the
 * cards, always in the same order, each saying where the kid is on it, and every
 * one of them acting in place rather than pointing somewhere else. The wheel is
 * the one exception, and it earned it: it moved to Quests, so what stands in for
 * it here is a strip that points.
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

        // Pinned to the middle of the day: the standings draw an at-risk state
        // off the household's evening watch hour, and a test run that happens
        // to start after it would read as a run on the line.
        $this->travelTo(Carbon::parse('2026-05-01 12:00', $this->household->timezone));

        $this->kid = Profile::factory()->for($this->household)->create(['name' => 'Rex']);

        // Six, not three: the wheel draws from what is left once the quest hand
        // is dealt, and a household with only a hand's worth of chores leaves it
        // with nothing to land on.
        Chore::factory()->for($this->household)->count(6)->create();

        Auth::guard('profile')->login($this->kid);
    }

    public function test_the_page_lays_the_day_out_in_one_fixed_order(): void
    {
        app(MonsterService::class)->spawn($this->household, 'Pizza night', 1000);
        $this->household->update(['weekly_chore_target' => 10]);

        Volt::test('kid.home')
            ->assertOk()
            ->assertSeeInOrder([
                'Daily Quest',
                'Bonus Chest',
                'Streak Chest',
                // Not a section any more — a strip pointing at the wheel on
                // Quests, which still sits in the run where the wheel was.
                'Your Bonus Wheel spin is waiting',
                // Then what the house does together, and only then the one
                // card that ranks the kids against each other.
                'Weekly Prize',
                'The Fight',
                'House Standings',
            ]);
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

    public function test_the_quest_chest_deals_picks_and_claims_without_leaving_home(): void
    {
        // The whole point of the page: a kid lands here and the first thing
        // they see is a chest they can actually open.
        $page = Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Quest Chest', escape: false)
            ->call('dealHand');

        $hand = app(ChoreService::class)->offeredChoresFor($this->kid);

        $page->call('chooseQuest', $hand->first()->id)
            ->assertOk()
            ->assertSee($hand->first()->name)
            ->assertSee('Mark it done')
            ->call('claimQuest')
            ->assertOk();

        $this->assertNotNull(
            ChoreCompletion::where('profile_id', $this->kid->id)
                ->where('chore_id', $hand->first()->id)
                ->first()
        );
    }

    public function test_the_charm_can_still_be_bought_and_cast_from_home(): void
    {
        // The charm's window shuts the moment the chest opens, so a copy of the
        // hero that quietly dropped these buttons would cost a kid a ticket
        // they had already spent. Home draws the same component Quests does.
        $charm = BonusPerk::where('household_id', $this->household->id)
            ->where('effect', PerkEffect::QuestCharm)
            ->firstOrFail();

        $charm->update(['enabled' => true, 'cost' => 2]);

        $this->kid->update(['bonus_tickets' => 5]);

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Buy a Quest Charm')
            ->call('buyQuestCharm')
            ->assertOk()
            ->assertSee('Use Quest Charm');
    }

    public function test_a_quest_waiting_on_a_parent_says_so_rather_than_reading_as_done(): void
    {
        $service = app(ChoreService::class);
        $service->revealQuest($this->kid);
        $service->claimQuest($this->kid);

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Waiting on parent')
            ->assertDontSee('Mark it done');
    }

    public function test_a_sent_back_quest_says_so(): void
    {
        $service = app(ChoreService::class);
        $quest = $service->questFor($this->kid);
        $service->revealQuest($this->kid);
        $service->claimQuest($this->kid);

        ChoreCompletion::where('profile_id', $this->kid->id)
            ->where('chore_id', $quest->chore_id)
            ->update(['status' => CompletionStatus::Rejected]);

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Sent back');
    }

    public function test_the_bonus_chest_opens_in_place(): void
    {
        // One tap, and nowhere else to send them — the chest has no page of its
        // own, so making a kid travel for it is exactly the errand this page
        // was built to remove.
        Volt::test('kid.home')
            ->assertOk()
            ->assertSee("Open today's bonus chest")
            ->call('openDailyChest')
            ->assertOk();

        $this->assertNotNull(DailyChest::where('profile_id', $this->kid->id)->first());
    }

    public function test_the_chest_asks_before_it_is_spent_on_the_plain_table(): void
    {
        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Hold on');

        $service = app(ChoreService::class);
        $service->revealQuest($this->kid);
        $service->claimQuest($this->kid);

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Your chest is OP today')
            ->assertDontSee('Hold on');
    }

    public function test_a_chest_already_opened_in_another_tab_still_reveals_what_it_held(): void
    {
        // A stale tab, or a back-button visit to a page rendered before the
        // chest went. Opening again must describe the chest that exists rather
        // than dead-end on an empty prize card.
        app(ChestService::class)->open($this->kid);

        $page = Volt::test('kid.home')->assertOk()->call('openDailyChest');

        $this->assertNotNull($page->get('dailyChestPrize'));
        $this->assertSame(1, DailyChest::where('profile_id', $this->kid->id)->count());
    }

    /**
     * The wheel went to Quests — the kids kept going there to look for it, and
     * they were right: it lands on a side quest, and the board is over there.
     * What is left here is a strip, and the news it carries is the point of it.
     */
    public function test_the_wheel_is_a_pointer_at_quests_rather_than_a_spin(): void
    {
        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Your Bonus Wheel spin is waiting')
            ->assertSee(route('kid.quests').'#bonus-wheel', escape: false)
            // The wheel itself, and every control on it, are not here.
            ->assertDontSee('One Spin Per Day')
            ->assertDontSee('Active Boost');

        $spin = app(SpinService::class)->spin($this->kid);

        // Once it has gone, the strip stops advertising a spin and starts
        // reporting the boost that is live.
        Volt::test('kid.home')
            ->assertOk()
            ->assertSee($spin->multiplier.'x on '.$spin->chore->name)
            ->assertDontSee('Your Bonus Wheel spin is waiting');
    }

    /**
     * A streak that survives being looked at.
     *
     * The standings sync every kid's streak before drawing them — `streak` is a
     * cache, and a run with no approved quest behind it is expired on sight. So
     * a fixture that only sets the number renders as a house of zeroes.
     */
    private function runOf(Profile $kid, int $nights): void
    {
        $yesterday = Carbon::parse('2026-04-30 12:00', $this->household->timezone);
        $chore = $this->household->chores->first();

        DailyQuest::create([
            'household_id' => $this->household->id,
            'profile_id' => $kid->id,
            'chore_id' => $chore->id,
            'offered_chore_ids' => [$chore->id],
            'quest_date' => $yesterday->toDateString(),
            'dealt_at' => $yesterday,
            'revealed_at' => $yesterday,
            'completed_at' => $yesterday,
        ]);

        ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $kid->id,
            'status' => CompletionStatus::Approved,
            'points_awarded' => $chore->points,
            'submitted_at' => $yesterday->copy()->setTime(12, 0),
            'decided_at' => $yesterday->copy()->setTime(13, 0),
        ]);

        $kid->update(['streak' => $nights]);
    }

    /** Approved chores this week, which is all the weekly bar counts. */
    private function choresThisWeek(Profile $kid, int $count): void
    {
        $chore = Chore::where('household_id', $this->household->id)->firstOrFail();

        foreach (range(1, $count) as $ignored) {
            ChoreCompletion::create([
                'chore_id' => $chore->id,
                'profile_id' => $kid->id,
                'status' => CompletionStatus::Approved,
                'points_awarded' => 10,
                'submitted_at' => now(),
                'decided_at' => now(),
            ]);
        }
    }

    public function test_the_weekly_prize_bar_names_the_prize_and_counts_what_is_left(): void
    {
        $this->household->update(['weekly_chore_target' => 10, 'weekly_prize' => 'friday movie pick']);

        $nova = Profile::factory()->for($this->household)->create(['name' => 'Nova']);

        $this->choresThisWeek($this->kid, 3);
        $this->choresThisWeek($nova, 1);

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Weekly Prize')
            // The prize is the headline. "10 chores" is the price, not the
            // thing being bought, and a bar promising an unnamed reward is a
            // bar nobody chases.
            ->assertSee('friday movie pick')
            ->assertSee('4 / 10 CHORES', escape: false)
            ->assertSee('6 chores to go')
            ->assertSee('nobody has to win it');
    }

    public function test_a_hit_target_says_the_house_has_won_it(): void
    {
        $this->household->update(['weekly_chore_target' => 2, 'weekly_prize' => 'friday movie pick']);

        $this->choresThisWeek($this->kid, 2);

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Won it')
            ->assertSee('Target smashed');
    }

    public function test_no_weekly_target_draws_no_bar(): void
    {
        // A parent who hasn't set one gets no card at all rather than an empty
        // bar promising nothing.
        $this->household->update(['weekly_chore_target' => null]);

        Volt::test('kid.home')
            ->assertOk()
            ->assertDontSee('Weekly Prize');
    }

    public function test_the_standings_rank_the_house_by_the_run_each_kid_is_on(): void
    {
        $nova = Profile::factory()->for($this->household)->create(['name' => 'Nova']);

        $this->runOf($nova, 9);
        $this->runOf($this->kid, 2);

        Volt::test('kid.home')
            ->assertOk()
            // Nova is ahead, so Nova is first — the whole point of a table.
            ->assertSeeInOrder(['Nova', '9 NIGHTS IN A ROW', 'Rex', '2 NIGHTS IN A ROW'])
            ->assertSee(route('kid.arena'), false);
    }

    /** A genuine run of cleared quests, so syncStreak() leaves the streak alone. */
    private function giveKidAStreak(int $days): void
    {
        $chore = Chore::where('household_id', $this->household->id)->firstOrFail();

        foreach (range(1, $days) as $daysAgo) {
            $at = now()->copy()->subDays($daysAgo);

            DailyQuest::create([
                'household_id' => $this->household->id,
                'profile_id' => $this->kid->id,
                'chore_id' => $chore->id,
                'quest_date' => $at->toDateString(),
                'revealed_at' => $at,
                'completed_at' => $at,
            ]);

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

    public function test_the_streak_header_never_reads_as_a_day_you_are_already_on(): void
    {
        // The old mock's "Day 14 · 6 to go" only works when the milestone is
        // obviously ahead of you. On a fresh profile the same shape rendered
        // "DAY 3 · 3 TO GO" beside a header reading 0d, and it looked like it
        // was telling you what day of the streak you were on.
        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Start a run')
            ->assertDontSee('Day 3 ·', escape: false);

        // A real day behind the counter, not just the number: the page expires
        // a streak with nothing under it, and a bare update() would be zeroed
        // again before it rendered.
        $this->giveKidAStreak(1);

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('2 days to go');
    }

    public function test_the_streak_card_does_not_tell_a_kid_with_no_streak_to_keep_it_alive(): void
    {
        // escape: false because this is literal copy in the template rather
        // than an echoed variable, so Blade leaves the apostrophe alone.
        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Get any chore signed off to start a streak', escape: false)
            ->assertDontSee('Keep the streak alive');
    }

    public function test_a_waiting_streak_chest_can_be_opened_here(): void
    {
        $this->kid->update(['streak' => 3, 'pending_streak_chest' => 3]);

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Your streak chest is waiting')
            ->call('openStreakChest')
            ->assertOk();

        $this->assertNull($this->kid->fresh()->pending_streak_chest);
    }

    /**
     * The bug this closes: the bonus used to be credited the moment the
     * milestone was reached, so a kid logging in the next morning found the
     * points already in the tile at the top of the page and then opened a chest
     * that gave them nothing.
     */
    public function test_a_waiting_streak_chest_is_not_already_in_the_balance(): void
    {
        $this->kid->update(['streak' => 3, 'pending_streak_chest' => 3, 'points' => 0]);

        $page = Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Your streak chest is waiting');

        $this->assertSame(0, $this->kid->fresh()->points);

        $page->call('openStreakChest')->assertOk();

        // $1 at the household's own rate, and it arrives on the tap.
        $this->assertSame($this->household->points_per_dollar, $this->kid->fresh()->points);
    }

    public function test_the_streak_track_draws_its_milestones_as_growing_chests(): void
    {
        // The payout curve has to be readable by a kid who isn't going to
        // compare "100" against "4000" in their head, so the chests carry it.
        $html = Volt::test('kid.home')->assertOk()->html();

        preg_match_all('/width: (\d+)px; height: \d+px/', $html, $matches);

        $widths = array_map('intval', $matches[1]);

        $this->assertCount(count(StreakService::STREAK_BONUSES), $widths, 'One chest per milestone.');
        $this->assertSame($widths, array_values(array_unique($widths)), 'No two chests the same size.');

        $sorted = $widths;
        sort($sorted);

        $this->assertSame($sorted, $widths, 'The chests grow along the track.');
    }

    public function test_the_three_chests_are_not_all_the_same_colour(): void
    {
        // They stack on one page now. Three identical gold boxes read as one
        // thing repeated rather than as three different rewards.
        $this->kid->update(['streak' => 3, 'pending_streak_chest' => 3]);

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('background: linear-gradient(180deg, #ffe98a, #e0b312)', escape: false)
            ->assertSee('background: var(--fq-chest-blue-fill)', escape: false)
            ->assertSee('background: var(--fq-chest-streak-fill)', escape: false);
    }

    public function test_the_boss_caption_carries_the_pending_count(): void
    {
        app(MonsterService::class)->spawn($this->household, 'Pizza night', 1000);

        $service = app(ChoreService::class);
        $service->claim($this->kid, Chore::where('household_id', $this->household->id)->first());

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('The Fight')
            ->assertSee('Boss Fight')
            ->assertSee('1 PENDING');
    }

    public function test_a_household_with_nothing_standing_draws_no_boss(): void
    {
        // Nothing spawned, so there is no arena to draw and no strip for it.
        Volt::test('kid.home')
            ->assertOk()
            ->assertDontSee('Boss Fight');
    }

    public function test_home_still_renders_when_the_household_has_nothing_to_quest_on(): void
    {
        // Every chore gone. This is the one page a kid always lands on, so it
        // is the one page that must never be the thing that breaks.
        Chore::query()->delete();

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('No quest today');
    }
}
