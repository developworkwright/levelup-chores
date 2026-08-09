<?php

namespace Tests\Feature;

use App\Enums\BountyKind;
use App\Enums\BountyStatus;
use App\Enums\ChoreCadence;
use App\Enums\CompletionStatus;
use App\Enums\TradeAsset;
use App\Exceptions\BountyUnavailableException;
use App\Exceptions\InsufficientPointsException;
use App\Models\Bounty;
use App\Models\ChoreCompletion;
use App\Models\Household;
use App\Models\Profile;
use App\Services\BountyService;
use App\Services\ChoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BountyBoardTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $poster;

    private Profile $sibling;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->poster = Profile::factory()->for($this->household)->create(['name' => 'Nova', 'points' => 500]);
        $this->sibling = Profile::factory()->for($this->household)->create(['name' => 'Scout', 'points' => 500]);

        // Pinned to the middle of the day. Settling a bounty runs badge
        // evaluation, and `early_bird` and `night_owl` key off the wall clock —
        // left to the real one, an overnight test run mints badge XP and an
        // extra ticket into the assertions below.
        $this->travelTo(Carbon::parse('2026-05-01 12:00', $this->household->timezone));
    }

    private function service(): BountyService
    {
        return app(BountyService::class);
    }

    private function postWanted(int $amount = 100): Bounty
    {
        return $this->service()->post($this->poster, BountyKind::Wanted, TradeAsset::Points, $amount, 'Make my bed');
    }

    private function postOffered(int $amount = 200): Bounty
    {
        return $this->service()->post($this->poster, BountyKind::Offered, TradeAsset::Points, $amount, 'Wash the car');
    }

    public function test_a_wanted_bounty_holds_the_posters_points_up_front(): void
    {
        // Same rule the loot shop and sibling trades follow: three 100-point
        // jobs must not be postable on a 100-point balance.
        $this->postWanted(100);

        $this->assertSame(400, $this->poster->fresh()->points);
    }

    public function test_an_offered_bounty_holds_nothing_until_somebody_takes_it(): void
    {
        $bounty = $this->postOffered(200);

        // The poster is selling work, not buying it — there is nothing of
        // theirs to hold, and their balance is irrelevant to the price.
        $this->assertSame(500, $this->poster->fresh()->points);

        $this->service()->claim($bounty, $this->sibling);

        $this->assertSame(300, $this->sibling->fresh()->points);
        $this->assertSame(500, $this->poster->fresh()->points);
    }

    public function test_a_kid_cannot_post_a_wanted_bounty_they_cannot_pay_for(): void
    {
        $this->expectException(InsufficientPointsException::class);

        $this->postWanted(900);
    }

    public function test_a_kid_cannot_take_an_offered_bounty_they_cannot_pay_for(): void
    {
        $bounty = $this->postOffered(400);
        $this->sibling->update(['points' => 100]);

        // Checked when they answer rather than when it was posted: they never
        // agreed to hold anything earlier, and balances move.
        $this->expectException(InsufficientPointsException::class);

        $this->service()->claim($bounty, $this->sibling);
    }

    public function test_the_roles_swap_with_the_kind(): void
    {
        $wanted = $this->postWanted();
        $this->service()->claim($wanted, $this->sibling);

        $this->assertTrue($wanted->fresh()->isWorker($this->sibling));
        $this->assertTrue($wanted->fresh()->isPayer($this->poster));

        $offered = $this->postOffered();
        $this->service()->claim($offered, $this->sibling);

        $this->assertTrue($offered->fresh()->isWorker($this->poster));
        $this->assertTrue($offered->fresh()->isPayer($this->sibling));
    }

    public function test_a_wanted_bounty_pays_the_worker_when_the_poster_confirms(): void
    {
        $bounty = $this->postWanted(100);

        $this->service()->claim($bounty, $this->sibling);
        $this->service()->markDone($bounty->fresh(), $this->sibling);
        $this->service()->confirm($bounty->fresh(), $this->poster);

        $this->assertSame(400, $this->poster->fresh()->points);
        $this->assertSame(600, $this->sibling->fresh()->points);
        $this->assertSame(BountyStatus::Paid, $bounty->fresh()->status);
    }

    public function test_an_offered_bounty_pays_the_poster_when_the_taker_confirms(): void
    {
        $bounty = $this->postOffered(200);

        $this->service()->claim($bounty, $this->sibling);
        // The poster did the work on this one, so the poster reports it.
        $this->service()->markDone($bounty->fresh(), $this->poster);
        $this->service()->confirm($bounty->fresh(), $this->sibling);

        $this->assertSame(700, $this->poster->fresh()->points);
        $this->assertSame(300, $this->sibling->fresh()->points);
    }

    public function test_only_the_worker_can_report_the_job_done(): void
    {
        $bounty = $this->postWanted();
        $this->service()->claim($bounty, $this->sibling);

        $this->expectException(BountyUnavailableException::class);

        $this->service()->markDone($bounty->fresh(), $this->poster);
    }

    public function test_only_the_payer_can_settle_it(): void
    {
        $bounty = $this->postWanted();
        $this->service()->claim($bounty, $this->sibling);
        $this->service()->markDone($bounty->fresh(), $this->sibling);

        $this->expectException(BountyUnavailableException::class);

        $this->service()->confirm($bounty->fresh(), $this->sibling);
    }

    public function test_sending_a_job_back_puts_it_up_again_and_pays_nobody(): void
    {
        $bounty = $this->postWanted(100);

        $this->service()->claim($bounty, $this->sibling);
        $this->service()->markDone($bounty->fresh(), $this->sibling);
        $this->service()->sendBack($bounty->fresh(), $this->poster);

        $fresh = $bounty->fresh();

        $this->assertSame(BountyStatus::Open, $fresh->status);
        $this->assertNull($fresh->claimed_by_profile_id);
        $this->assertNull($fresh->auto_release_at);

        // The poster still wants it done, so their points stay held; the
        // sibling was paid nothing for work that wasn't accepted.
        $this->assertSame(400, $this->poster->fresh()->points);
        $this->assertSame(500, $this->sibling->fresh()->points);
    }

    public function test_sending_back_an_offered_job_releases_the_payer(): void
    {
        $bounty = $this->postOffered(200);

        $this->service()->claim($bounty, $this->sibling);
        $this->service()->markDone($bounty->fresh(), $this->poster);
        $this->service()->sendBack($bounty->fresh(), $this->sibling);

        // Here the taker was the one holding, and they have walked away.
        $this->assertSame(500, $this->sibling->fresh()->points);
        $this->assertSame(500, $this->poster->fresh()->points);
        $this->assertSame(BountyStatus::Open, $bounty->fresh()->status);
    }

    public function test_the_poster_can_take_back_a_job_nobody_has_taken(): void
    {
        $bounty = $this->postWanted(100);

        $this->service()->cancel($bounty, $this->poster);

        $this->assertSame(500, $this->poster->fresh()->points);
        $this->assertSame(BountyStatus::Cancelled, $bounty->fresh()->status);
    }

    public function test_a_job_already_taken_cannot_be_taken_back(): void
    {
        $bounty = $this->postWanted();
        $this->service()->claim($bounty, $this->sibling);

        // Otherwise pulling it is a way to walk away from work under way.
        $this->expectException(BountyUnavailableException::class);

        $this->service()->cancel($bounty->fresh(), $this->poster);
    }

    public function test_a_kid_cannot_take_their_own_job(): void
    {
        $bounty = $this->postWanted();

        $this->expectException(BountyUnavailableException::class);

        $this->service()->claim($bounty, $this->poster);
    }

    public function test_only_one_sibling_can_hold_a_job(): void
    {
        $third = Profile::factory()->for($this->household)->create(['points' => 500]);
        $bounty = $this->postWanted();

        $this->service()->claim($bounty, $this->sibling);

        $this->expectException(BountyUnavailableException::class);

        $this->service()->claim($bounty->fresh(), $third);
    }

    public function test_an_untaken_job_lapses_and_gives_the_points_back(): void
    {
        $bounty = $this->postWanted(100);

        $this->travel(Bounty::OPEN_HOURS + 1)->hours();
        $this->service()->sweep($this->household);

        $this->assertSame(BountyStatus::Expired, $bounty->fresh()->status);
        $this->assertSame(500, $this->poster->fresh()->points);
    }

    public function test_a_taker_who_goes_quiet_loses_the_job(): void
    {
        $bounty = $this->postOffered(200);
        $this->service()->claim($bounty, $this->sibling);

        $this->travel(Bounty::CLAIM_HOURS + 1)->hours();
        $this->service()->sweep($this->household);

        $fresh = $bounty->fresh();

        $this->assertSame(BountyStatus::Open, $fresh->status);
        $this->assertNull($fresh->claimed_by_profile_id);
        // They were holding the money, and they are out of the deal.
        $this->assertSame(500, $this->sibling->fresh()->points);
    }

    public function test_a_payer_who_says_nothing_pays_anyway(): void
    {
        $bounty = $this->postWanted(100);

        $this->service()->claim($bounty, $this->sibling);
        $this->service()->markDone($bounty->fresh(), $this->sibling);

        $this->travel(Bounty::CONFIRM_HOURS + 1)->hours();
        $this->service()->sweep($this->household);

        // Silence must not be a way to keep the points once the work has been
        // reported done — there is no parent to arbitrate this.
        $this->assertSame(BountyStatus::Paid, $bounty->fresh()->status);
        $this->assertSame(600, $this->sibling->fresh()->points);
    }

    public function test_a_job_sent_back_before_the_clock_runs_out_does_not_auto_pay(): void
    {
        $bounty = $this->postWanted(100);

        $this->service()->claim($bounty, $this->sibling);
        $this->service()->markDone($bounty->fresh(), $this->sibling);
        $this->service()->sendBack($bounty->fresh(), $this->poster);

        $this->travel(Bounty::CONFIRM_HOURS + 1)->hours();
        $this->service()->sweep($this->household);

        $this->assertSame(500, $this->sibling->fresh()->points);
    }

    public function test_tickets_work_as_a_price_too(): void
    {
        // Balances below the `big_saver` threshold: settling evaluates badges,
        // and a badge mints a ticket of its own, which would land in the middle
        // of the count this test is making.
        $this->poster->update(['bonus_tickets' => 5, 'points' => 100]);
        $this->sibling->update(['bonus_tickets' => 0, 'points' => 100]);

        $bounty = $this->service()->post($this->poster, BountyKind::Wanted, TradeAsset::Tickets, 2, 'Feed the dog');

        $this->assertSame(3, $this->poster->fresh()->bonus_tickets);

        $this->service()->claim($bounty, $this->sibling);
        $this->service()->markDone($bounty->fresh(), $this->sibling);
        $this->service()->confirm($bounty->fresh(), $this->poster);

        $this->assertSame(2, $this->sibling->fresh()->bonus_tickets);
    }

    public function test_a_job_needs_saying_what_it_is(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->post($this->poster, BountyKind::Wanted, TradeAsset::Points, 100, '   ');
    }

    // --- A parent hiring an offer of work ---------------------------------

    public function test_a_parent_hiring_creates_a_one_time_chore_already_claimed(): void
    {
        $parent = Profile::factory()->for($this->household)->parent()->create();
        $bounty = $this->postOffered(200);

        $chore = $this->service()->hire($bounty, $parent);

        $this->assertSame('Wash the car', $chore->name);
        $this->assertSame(200, $chore->points);
        $this->assertSame(ChoreCadence::Once, $chore->cadence);
        // A deal struck with one kid must not land on everybody's board, and
        // must never be handed out as somebody else's quest.
        $this->assertFalse((bool) $chore->quest_eligible);
        $this->assertFalse((bool) $chore->wheel_eligible);
        $this->assertNotNull($chore->used_at);

        $completion = ChoreCompletion::where('chore_id', $chore->id)->firstOrFail();

        $this->assertSame($this->poster->id, $completion->profile_id);
        $this->assertSame(CompletionStatus::Pending, $completion->status);
        $this->assertSame(BountyStatus::Hired, $bounty->fresh()->status);
    }

    public function test_hiring_pays_nothing_until_the_work_is_approved(): void
    {
        $parent = Profile::factory()->for($this->household)->parent()->create();
        $bounty = $this->postOffered(200);

        $this->service()->hire($bounty, $parent);

        // Hiring is an agreement, not a payment. Points only exist once a
        // parent signs the work off, exactly like every other chore.
        $this->assertSame(500, $this->poster->fresh()->points);

        $completion = ChoreCompletion::where('profile_id', $this->poster->id)->firstOrFail();
        app(ChoreService::class)->approve($completion, $parent);

        $this->assertSame(700, $this->poster->fresh()->points);
    }

    public function test_a_hired_job_earns_xp_and_moves_the_family_goal_like_any_chore(): void
    {
        $parent = Profile::factory()->for($this->household)->parent()->create();
        $this->household->update(['goal_target' => 1000, 'goal_now' => 0]);

        // Starts at nothing, so the 200 points this earns stay under the
        // `big_saver` threshold and no badge XP lands on top of the chore's.
        $this->poster->update(['points' => 0]);

        $bounty = $this->postOffered(200);
        $this->service()->hire($bounty, $parent);

        $completion = ChoreCompletion::where('profile_id', $this->poster->id)->firstOrFail();
        app(ChoreService::class)->approve($completion, $parent);

        // The whole reason hiring makes a chore rather than paying directly:
        // there is only one way to earn, and everything hangs off it.
        $this->assertSame(ChoreService::XP_PER_CHORE, $this->poster->fresh()->xp);
        $this->assertSame(200, $this->household->fresh()->goal_now);
    }

    public function test_a_parent_can_hire_at_their_own_price(): void
    {
        $parent = Profile::factory()->for($this->household)->parent()->create();
        $bounty = $this->postOffered(500);

        $chore = $this->service()->hire($bounty, $parent, 250);

        $this->assertSame(250, $chore->points);
        $this->assertSame(250, $bounty->fresh()->reward_amount);
    }

    public function test_a_parent_cannot_hire_a_job_a_kid_wants_doing(): void
    {
        $parent = Profile::factory()->for($this->household)->parent()->create();
        $bounty = $this->postWanted();

        // That is just a chore, and chores already exist.
        $this->expectException(BountyUnavailableException::class);

        $this->service()->hire($bounty, $parent);
    }

    public function test_a_parent_cannot_hire_a_job_priced_in_tickets(): void
    {
        $parent = Profile::factory()->for($this->household)->parent()->create();
        $bounty = $this->service()->post($this->poster, BountyKind::Offered, TradeAsset::Tickets, 3, 'Wash the car');

        $this->expectException(BountyUnavailableException::class);

        $this->service()->hire($bounty, $parent);
    }

    public function test_a_kid_cannot_hire(): void
    {
        $bounty = $this->postOffered();

        $this->expectException(BountyUnavailableException::class);

        $this->service()->hire($bounty, $this->sibling);
    }

    public function test_a_job_a_sibling_already_took_cannot_be_hired(): void
    {
        $parent = Profile::factory()->for($this->household)->parent()->create();
        $bounty = $this->postOffered(200);

        $this->service()->claim($bounty, $this->sibling);

        $this->expectException(BountyUnavailableException::class);

        $this->service()->hire($bounty->fresh(), $parent);
    }
}
