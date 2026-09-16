<?php

namespace Tests\Feature;

use App\Enums\BountyKind;
use App\Enums\BountyStatus;
use App\Enums\PerkEffect;
use App\Enums\TradeAsset;
use App\Models\Badge;
use App\Models\BonusPerk;
use App\Models\Bounty;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\BonusShopService;
use App\Services\BountyService;
use App\Services\ChoreService;
use App\Services\SpinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The Quests page — the board, once the daily loop moved to Home.
 *
 * The pieces themselves are covered by the suites that own them: the mystery
 * chore by MysteryChoreTest, the bounty
 * board by BountyBoardTest, the spin itself by SpinFlowTest and WheelClaimTest.
 * What is tested here is the arrangement — that the sections come in the order
 * they should, that the chests and the boss are no longer among them, that the
 * wheel is (it came back from Home), and that the bounty board and badges count
 * work where they sit.
 */
class QuestPageLayoutTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        // Pinned to the middle of the day, like BountyBoardTest: settling a
        // bounty runs badge evaluation, and the wall-clock badges would
        // otherwise mint XP into an overnight run's assertions.
        $this->household = Household::factory()->create();

        $this->travelTo(Carbon::parse('2026-05-01 12:00', $this->household->timezone));

        $this->kid = Profile::factory()->for($this->household)->create(['name' => 'Rex', 'points' => 500]);

        Chore::factory()->for($this->household)->count(3)->create();

        Auth::guard('profile')->login($this->kid);
    }

    public function test_the_sections_come_in_the_order_the_handoff_fixes(): void
    {
        // The chests and the boss moved to Home. The wheel went with them and
        // came back, because it lands on a chore and every one of those rows is
        // on this page — so it sits directly above the board. The quest chest
        // that used to sit between the target and gratitude is gone entirely.
        Volt::test('kid.quests')
            ->assertOk()
            ->assertSeeInOrder([
                "Today's Target",
                'Gratitude Quest',
                'Bonus Wheel',
                'Side Quests',
                'Bounty Board',
            ], escape: false)
            ->assertDontSee('Quest Chest')
            ->assertDontSee('Choose your quest');
    }

    public function test_the_extras_left_the_board_for_home(): void
    {
        // The loot tray, the streak track and the boss strip are all on Home
        // now. The board is the board — plus the wheel that boosts it.
        Volt::test('kid.quests')
            ->assertOk()
            ->assertDontSee('Loot Tray')
            ->assertDontSee('Streak Chest')
            ->assertDontSee('Boss Fight');
    }

    public function test_the_spin_happens_on_the_board_it_boosts(): void
    {
        // Six, not three: the wheel draws from what is left once the quest
        // hand is dealt, and a household with only a hand's worth of chores
        // leaves it with nothing to land on.
        Chore::factory()->for($this->household)->count(3)->create();

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('SPIN')
            ->call('spin')
            ->assertSet('spinning', true)
            ->call('finishSpin')
            ->assertSet('spinRevealed', true);

        $this->assertNotNull($this->kid->spins()->first());
    }

    /**
     * The charge is sold beside the wheel because the window to use one closes
     * the moment the wheel goes — a kid sent to the shop first comes back to a
     * spent spin.
     */
    public function test_the_op_charge_is_offered_charged_and_then_gone_from_the_wheel(): void
    {
        Chore::factory()->for($this->household)->count(3)->create();
        $this->kid->update(['bonus_tickets' => 5]);

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('Buy an OP Spin')
            ->call('buyOpSpin')
            ->assertSee('Use OP Spin')
            ->call('usePerk', PerkEffect::OpSpin->value)
            ->assertSee('Wheel charged')
            ->assertSee('OP SPIN')
            // Spent by the spin itself: nothing left to sell or charge once
            // the wheel has gone.
            ->call('spin')
            ->call('finishSpin')
            ->assertDontSee('Buy an OP Spin')
            ->assertDontSee('Wheel charged');
    }

    /**
     * The respin cannot hand the charge back, and a button that just says
     * "respin" gives the kid no way of knowing that.
     */
    public function test_respinning_an_op_result_asks_first(): void
    {
        Chore::factory()->for($this->household)->count(3)->create();
        $this->kid->update(['bonus_tickets' => 10]);

        $respin = BonusPerk::where('household_id', $this->household->id)
            ->where('effect', PerkEffect::WheelRespin)
            ->firstOrFail();

        app(BonusShopService::class)->purchase($this->kid, $respin);

        // An ordinary spin gets the plain button; only a charged one is worth
        // a question.
        Volt::test('kid.quests')
            ->call('spin')
            ->call('finishSpin')
            ->assertSee('Use Wheel Respin')
            ->assertDontSee('OP charge', escape: false);

        app(SpinService::class)->clearToday($this->kid);
        app(SpinService::class)->charge($this->kid->refresh());

        Volt::test('kid.quests')
            ->call('spin')
            ->call('finishSpin')
            ->assertSee('OP charge', escape: false)
            ->assertSee('Respin anyway');
    }

    /**
     * A parent resetting the wheel from the console has to reach a Quests page
     * that is already open.
     *
     * It used to take a reload. "Spun today" was a mount snapshot and nothing
     * moved it again, so the page went on showing "Used today — back tomorrow"
     * over a spin that no longer existed — and since the SPIN button is
     * rendered in the other branch, there was nothing left to tap to find out
     * otherwise.
     */
    public function test_a_wheel_cleared_elsewhere_puts_the_spin_button_back(): void
    {
        // On top of setUp's three, which the quest hand takes.
        Chore::factory()->for($this->household)->count(3)->create();

        $page = Volt::test('kid.quests')
            ->call('spin')
            ->call('finishSpin')
            ->assertSee('Spun today')
            ->assertSee('Used today', escape: false);

        // The parent console, or another tab — anything that clears the row
        // without going back through this component.
        app(SpinService::class)->clearToday($this->kid);

        $page->call('$refresh')
            ->assertSee('One spin waiting')
            ->assertDontSee('Used today', escape: false)
            ->assertSee('SPIN')
            // Back to the top, like the respin perk leaves it: a second spin
            // shouldn't crawl on from where the first one stopped.
            ->assertSet('wheelDeg', 0)
            ->assertSet('spinRevealed', false);

        // Spinnable for real, not just repainted.
        $page->call('spin')->assertSet('spinning', true);
    }

    public function test_the_mystery_pill_announces_the_mystery_chore_in_both_states(): void
    {
        $service = app(ChoreService::class);
        $parent = Profile::factory()->parent()->for($this->household)->create();

        $chore = $service->mysteryChoreFor($this->household);

        Volt::test('kid.quests')->assertOk()->assertSee('Mystery chore live');

        $service->approve($service->claim($this->kid, $chore), $parent);

        Volt::test('kid.quests')->assertOk()->assertSee('Mystery chore found');
    }

    public function test_the_bounty_board_shows_a_takeable_job_and_takes_it(): void
    {
        $sibling = Profile::factory()->for($this->household)->create(['name' => 'Nova', 'points' => 500]);

        $job = app(BountyService::class)->post(
            $sibling,
            BountyKind::Wanted,
            TradeAsset::Points,
            120,
            'Make my bed',
        );

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('Bounty Board')
            ->assertSee('Make my bed')
            ->assertSee('You get')
            ->call('takeJob', $job->id)
            // Thrown out of the button that was pressed rather than rained from
            // the top of the screen. The coordinates only exist on the client,
            // so the payload names the origin and the overlay looks up where
            // the last tap landed.
            ->assertDispatched(
                'celebrate',
                fn (string $event, array $params) => $params['motion'] === 'burst'
                    && $params['origin'] === 'tap',
            );

        $job->refresh();

        $this->assertSame(BountyStatus::Claimed, $job->status);
        $this->assertSame($this->kid->id, $job->claimed_by_profile_id);
    }

    public function test_a_job_the_kid_posted_never_reaches_their_own_board(): void
    {
        app(BountyService::class)->post(
            $this->kid,
            BountyKind::Wanted,
            TradeAsset::Points,
            120,
            'Sweep my room',
        );

        // You cannot take your own job, so offering it here would be a button
        // that only ever refuses.
        Volt::test('kid.quests')
            ->assertOk()
            ->assertDontSee('Sweep my room')
            ->assertSee('Nothing up for grabs');
    }

    public function test_losing_the_race_for_a_job_explains_itself_on_the_page(): void
    {
        $sibling = Profile::factory()->for($this->household)->create(['name' => 'Nova', 'points' => 500]);
        $third = Profile::factory()->for($this->household)->create(['name' => 'Scout', 'points' => 500]);

        $job = app(BountyService::class)->post(
            $sibling,
            BountyKind::Wanted,
            TradeAsset::Points,
            120,
            'Make my bed',
        );

        $component = Volt::test('kid.quests')->assertOk()->assertSee('Make my bed');

        // A sibling gets there between the render and the tap.
        app(BountyService::class)->claim($job, $third);

        $component
            ->call('takeJob', $job->id)
            ->assertNotDispatched('celebrate')
            ->assertSee('no longer up for grabs');

        $this->assertSame($third->id, $job->refresh()->claimed_by_profile_id);
    }

    public function test_a_job_the_kid_cannot_afford_offers_the_shortfall_instead_of_the_button(): void
    {
        $sibling = Profile::factory()->for($this->household)->create(['name' => 'Nova', 'points' => 500]);
        $this->kid->update(['points' => 30]);

        // An offered job is paid for by whoever takes it.
        app(BountyService::class)->post(
            $sibling,
            BountyKind::Offered,
            TradeAsset::Points,
            200,
            'Wash the car',
        );

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('You pay')
            ->assertSee('Need 170 pts')
            ->assertDontSee('Hire them');
    }

    public function test_the_badge_grid_is_gone_and_the_nav_points_at_the_wall_instead(): void
    {
        $badge = Badge::where('key', 'first_quest')->firstOrFail();
        $this->kid->badges()->attach($badge->id, ['earned_at' => now()]);

        Volt::test('kid.quests')
            ->assertOk()
            // The header's badge tile went with the world rail: badges are a
            // row in the sheet now, which is a door rather than a readout.
            ->assertSee('Badges')
            ->assertSee(route('kid.badges'))
            // The wall itself lives on the page built for it. Two things the
            // old grid put on every render: the name of a badge nobody has
            // earned, and the placeholder a hidden one shows until they do.
            ->assertDontSee('Chore Legend')
            ->assertDontSee('???');
    }
}
