<?php

namespace Tests\Feature;

use App\Enums\LedgerKind;
use App\Enums\RedemptionStatus;
use App\Models\Household;
use App\Models\LedgerEntry;
use App\Models\Profile;
use App\Models\Redemption;
use App\Models\StoreItem;
use App\Services\StoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Turning down a redemption, and putting the points back.
 *
 * Points leave a balance the moment a kid asks, so a request nobody meant to
 * grant has already been paid for.
 */
class RedemptionRejectionTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    private Profile $parent;

    private StoreItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create(['points_per_dollar' => 100]);
        $this->kid = Profile::factory()->for($this->household)->create(['name' => 'Nova', 'points' => 1000]);
        $this->parent = Profile::factory()->parent()->for($this->household)->create();
        $this->item = StoreItem::factory()->for($this->household)->create([
            'name' => 'Extra screen time',
            'cost' => 250,
        ]);
    }

    private function store(): StoreService
    {
        return app(StoreService::class);
    }

    private function request(): Redemption
    {
        return $this->store()->redeem($this->kid, $this->item);
    }

    public function test_asking_takes_the_points_straight_away(): void
    {
        $this->request();

        // The premise of the whole feature: the charge is up front, so a
        // rejection has something to give back.
        $this->assertSame(750, $this->kid->refresh()->points);
    }

    public function test_rejecting_hands_the_points_back(): void
    {
        $redemption = $this->request();

        $this->assertTrue($this->store()->reject($redemption, $this->parent));

        $this->assertSame(1000, $this->kid->refresh()->points);
        $this->assertSame(RedemptionStatus::Rejected, $redemption->fresh()->status);
        $this->assertSame($this->parent->id, $redemption->fresh()->rejected_by_profile_id);
        $this->assertNotNull($redemption->fresh()->rejected_at);
    }

    public function test_the_refund_is_its_own_ledger_kind_not_an_adjustment(): void
    {
        $this->store()->reject($this->request(), $this->parent);

        $refund = LedgerEntry::where('profile_id', $this->kid->id)
            ->where('kind', LedgerKind::Refund)
            ->first();

        // An adjustment is a grown-up moving a number; this is the app undoing
        // something it charged for, and the ledger has to say which.
        $this->assertNotNull($refund);
        $this->assertSame(250, $refund->amount);
        $this->assertSame(
            0,
            LedgerEntry::where('profile_id', $this->kid->id)->where('kind', LedgerKind::Adjustment)->count(),
        );
    }

    public function test_a_refund_nets_out_of_what_counts_as_spent(): void
    {
        // BadgeService::BIG_SPENDER_THRESHOLD, which is private.
        $threshold = 1000;

        $spendy = StoreItem::factory()->for($this->household)->create([
            'name' => 'Big one',
            'cost' => $threshold,
        ]);

        $this->kid->update(['points' => $threshold * 2]);
        $redemption = $this->store()->redeem($this->kid, $spendy);

        $this->assertTrue($this->kid->refresh()->badges()->where('key', 'big_spender')->exists());

        $this->store()->reject($redemption, $this->parent);

        // The badge already awarded stays — taking one back is its own kind of
        // cruelty — but the total behind it is corrected, so the *next*
        // threshold isn't reached on money that came back.
        $spent = (int) $this->kid->refresh()->ledgerEntries()
            ->whereIn('kind', [LedgerKind::Spend, LedgerKind::Refund])
            ->sum('amount');

        $this->assertSame(0, $spent);
    }

    public function test_the_reason_reaches_the_kid_on_the_refund_line(): void
    {
        $this->store()->reject($this->request(), $this->parent, 'you already had one today');

        $refund = LedgerEntry::where('kind', LedgerKind::Refund)->first();

        // Nothing on the kid's side lists their own requests, so this line is
        // the only place they find out it didn't happen — and why.
        $this->assertStringContainsString('Extra screen time refunded', $refund->description);
        $this->assertStringContainsString('you already had one today', $refund->description);
    }

    public function test_a_blank_reason_leaves_the_line_clean(): void
    {
        $this->store()->reject($this->request(), $this->parent, '   ');

        $this->assertStringNotContainsString('()', LedgerEntry::where('kind', LedgerKind::Refund)->first()->description);
    }

    public function test_a_fulfilled_redemption_cannot_be_rejected(): void
    {
        $redemption = $this->request();
        $this->store()->fulfill($redemption, $this->parent);

        // It has been handed over. Refunding it would pay the kid for keeping
        // the reward.
        $this->assertFalse($this->store()->reject($redemption->fresh(), $this->parent));
        $this->assertSame(750, $this->kid->refresh()->points);
    }

    public function test_rejecting_twice_only_refunds_once(): void
    {
        $redemption = $this->request();

        $this->assertTrue($this->store()->reject($redemption, $this->parent));
        $this->assertFalse($this->store()->reject($redemption->fresh(), $this->parent));

        $this->assertSame(1000, $this->kid->refresh()->points);
        $this->assertSame(1, LedgerEntry::where('kind', LedgerKind::Refund)->count());
    }

    public function test_a_parent_rejects_from_the_approvals_page_and_is_told_what_happened(): void
    {
        $redemption = $this->request();

        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.home')
            ->assertSee('Reject')
            ->set('rejectReasons.'.$redemption->id, 'not before dinner')
            ->call('reject', $redemption->id)
            // The card simply vanishing says nothing about the refund.
            ->assertSee('250 points back to Nova');

        $this->assertSame(1000, $this->kid->refresh()->points);
        $this->assertSame('not before dinner', $redemption->fresh()->reject_reason);
    }

    public function test_a_redemption_links_straight_to_the_thing_that_was_asked_for(): void
    {
        $this->item->update(['url' => 'https://lego.com/the-set']);
        $this->request();

        Auth::guard('profile')->login($this->parent);

        // The request is a shopping errand: the page you need is the one they
        // were looking at, not one to go and find again in the shop admin.
        Volt::test('parent.home')
            ->assertSee('https://lego.com/the-set')
            ->assertSee('Extra screen time');
    }

    public function test_a_redemption_with_nothing_to_link_to_stays_plain_text(): void
    {
        $this->item->update(['url' => null]);
        $this->request();

        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.home')
            ->assertSee('Extra screen time')
            ->assertDontSee('fa-arrow-up-right-from-square');
    }

    public function test_a_parent_cannot_reject_another_households_redemption(): void
    {
        $other = Household::factory()->create();
        $otherKid = Profile::factory()->for($other)->create(['points' => 500]);
        $otherItem = StoreItem::factory()->for($other)->create(['cost' => 100]);
        $theirs = $this->store()->redeem($otherKid, $otherItem);

        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.home')->call('reject', $theirs->id);

        $this->assertSame(RedemptionStatus::Pending, $theirs->fresh()->status);
        $this->assertSame(400, $otherKid->refresh()->points);
    }
}
