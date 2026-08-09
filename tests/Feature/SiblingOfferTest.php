<?php

namespace Tests\Feature;

use App\Enums\LedgerKind;
use App\Enums\SiblingOfferStatus;
use App\Enums\TicketKind;
use App\Enums\TradeAsset;
use App\Exceptions\InsufficientPointsException;
use App\Exceptions\InsufficientTicketsException;
use App\Exceptions\OfferUnavailableException;
use App\Models\BonusTicketEntry;
use App\Models\Household;
use App\Models\LedgerEntry;
use App\Models\Profile;
use App\Models\SiblingOffer;
use App\Notifications\SiblingOfferReceived;
use App\Services\SiblingOfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\TestCase;

class SiblingOfferTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SiblingOfferService
    {
        return app(SiblingOfferService::class);
    }

    /**
     * Both kids hold a few tickets by default. A trade is a swap now — work
     * for pay moved to the bounty board — so the other side of every offer
     * below is a real currency somebody has to actually have.
     *
     * @return array{0: Household, 1: Profile, 2: Profile}
     */
    private function twoKids(
        int $senderPoints = 500,
        int $recipientPoints = 500,
        int $senderTickets = 3,
        int $recipientTickets = 3,
    ): array {
        $household = Household::factory()->create();

        return [
            $household,
            Profile::factory()->for($household)->create([
                'name' => 'Alex',
                'points' => $senderPoints,
                'bonus_tickets' => $senderTickets,
            ]),
            Profile::factory()->for($household)->create([
                'name' => 'Sam',
                'points' => $recipientPoints,
                'bonus_tickets' => $recipientTickets,
            ]),
        ];
    }

    /** The sender puts points up and wants a ticket back. */
    private function payingOffer(Profile $from, Profile $to, int $points = 100): SiblingOffer
    {
        return $this->service()->offer($from, $to, TradeAsset::Points, $points, TradeAsset::Tickets, 1);
    }

    /** The mirror: the sender puts a ticket up and wants points back. */
    private function earningOffer(Profile $from, Profile $to, int $points = 100): SiblingOffer
    {
        return $this->service()->offer($from, $to, TradeAsset::Tickets, 1, TradeAsset::Points, $points);
    }

    public function test_a_paying_offer_holds_the_points_the_moment_it_is_made(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 500);

        $offer = $this->payingOffer($alex, $sam);

        // Held up front for the same reason a redemption deducts up front: it
        // stops three 100-point offers going out on a 100-point balance.
        $this->assertSame(400, $alex->refresh()->points);
        $this->assertSame(500, $sam->refresh()->points);
        $this->assertSame(SiblingOfferStatus::Pending, $offer->status);

        $entry = LedgerEntry::latest('id')->first();
        $this->assertSame(LedgerKind::Transfer, $entry->kind);
        $this->assertSame(-100, $entry->amount);
        $this->assertSame(SiblingOffer::class, $entry->related_type);
        $this->assertSame($offer->id, $entry->related_id);
    }

    public function test_an_earning_offer_holds_the_senders_side_and_nothing_else(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 0, recipientPoints: 500);

        $this->earningOffer($alex, $sam);

        // Whichever way round a swap runs, it is always the sender's side that
        // is held — here that is the ticket, not the points they want back.
        $this->assertSame(2, $alex->refresh()->bonus_tickets);
        $this->assertSame(0, $alex->points);
        $this->assertSame(500, $sam->refresh()->points);
        $this->assertSame(3, $sam->bonus_tickets);

        // Points are the other currency and must not have been touched.
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_accepting_a_paying_offer_pays_the_recipient_without_charging_the_sender_twice(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 500, recipientPoints: 20);

        $offer = $this->payingOffer($alex, $sam);
        $this->service()->accept($offer, $sam);

        $this->assertSame(400, $alex->refresh()->points);
        $this->assertSame(120, $sam->refresh()->points);
        $this->assertSame(SiblingOfferStatus::Accepted, $offer->refresh()->status);
        $this->assertNotNull($offer->responded_at);
    }

    public function test_accepting_an_earning_offer_charges_the_accepter_and_pays_the_sender(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 0, recipientPoints: 500);

        $offer = $this->earningOffer($alex, $sam);
        $this->service()->accept($offer, $sam);

        $this->assertSame(100, $alex->refresh()->points);
        $this->assertSame(400, $sam->refresh()->points);
        $this->assertSame(2, LedgerEntry::where('kind', LedgerKind::Transfer)->count());
    }

    public function test_offering_to_pay_more_than_you_have_throws_with_the_shortfall(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 60);

        try {
            $this->payingOffer($alex, $sam);
            $this->fail('Expected InsufficientPointsException.');
        } catch (InsufficientPointsException $e) {
            $this->assertSame(40, $e->shortfall);
        }

        $this->assertSame(60, $alex->refresh()->points);
        $this->assertSame(0, SiblingOffer::count());
    }

    public function test_accepting_an_earning_offer_you_cannot_afford_throws_and_moves_nothing(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 0, recipientPoints: 500);

        $offer = $this->earningOffer($alex, $sam);

        // The balance is checked at accept time, not offer time — Sam's points
        // can move between the offer landing and them answering it.
        $sam->update(['points' => 60]);

        try {
            $this->service()->accept($offer, $sam);
            $this->fail('Expected InsufficientPointsException.');
        } catch (InsufficientPointsException $e) {
            $this->assertSame(40, $e->shortfall);
        }

        $this->assertSame(0, $alex->refresh()->points);
        $this->assertSame(60, $sam->refresh()->points);
        $this->assertSame(SiblingOfferStatus::Pending, $offer->refresh()->status);
    }

    public function test_declining_gives_the_held_points_back(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 500);

        $offer = $this->payingOffer($alex, $sam);
        $this->service()->decline($offer, $sam);

        $this->assertSame(500, $alex->refresh()->points);
        $this->assertSame(500, $sam->refresh()->points);
        $this->assertSame(SiblingOfferStatus::Declined, $offer->refresh()->status);
    }

    public function test_the_sender_can_take_an_unanswered_offer_back_and_get_refunded(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 500);

        $offer = $this->payingOffer($alex, $sam);
        $this->service()->cancel($offer, $alex);

        $this->assertSame(500, $alex->refresh()->points);
        $this->assertSame(SiblingOfferStatus::Cancelled, $offer->refresh()->status);
    }

    public function test_an_offer_that_runs_out_of_time_is_swept_and_refunded(): void
    {
        [$household, $alex, $sam] = $this->twoKids(senderPoints: 500);

        $offer = $this->payingOffer($alex, $sam);
        $this->assertSame(400, $alex->refresh()->points);

        $offer->update(['expires_at' => now()->subMinute()]);
        $this->service()->expireStale($household);

        $this->assertSame(500, $alex->refresh()->points);
        $this->assertSame(SiblingOfferStatus::Expired, $offer->refresh()->status);
    }

    public function test_the_sweep_leaves_live_offers_alone(): void
    {
        [$household, $alex, $sam] = $this->twoKids(senderPoints: 500);

        $offer = $this->payingOffer($alex, $sam);
        $this->service()->expireStale($household);

        $this->assertSame(400, $alex->refresh()->points);
        $this->assertSame(SiblingOfferStatus::Pending, $offer->refresh()->status);
    }

    public function test_sweeping_twice_only_refunds_once(): void
    {
        [$household, $alex, $sam] = $this->twoKids(senderPoints: 500);

        $offer = $this->payingOffer($alex, $sam);
        $offer->update(['expires_at' => now()->subMinute()]);

        $this->service()->expireStale($household);
        $this->service()->expireStale($household);

        $this->assertSame(500, $alex->refresh()->points);
    }

    public function test_settling_an_earning_offer_gives_the_held_ticket_back(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 0, recipientPoints: 500);

        $offer = $this->earningOffer($alex, $sam);
        $this->service()->decline($offer, $sam);

        // Refunds follow the held side rather than the currency, so a declined
        // swap comes back whichever way round it ran.
        $this->assertSame(3, $alex->refresh()->bonus_tickets);
        $this->assertSame(0, $alex->points);
        $this->assertSame(500, $sam->refresh()->points);
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_a_kid_cannot_accept_an_offer_addressed_to_someone_else(): void
    {
        [$household, $alex, $sam] = $this->twoKids(senderPoints: 500);
        $riley = Profile::factory()->for($household)->create(['points' => 0]);

        $offer = $this->payingOffer($alex, $sam);

        $this->expectException(OfferUnavailableException::class);
        $this->service()->accept($offer, $riley);
    }

    public function test_an_offer_cannot_be_accepted_twice(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 500, recipientPoints: 0);

        $offer = $this->payingOffer($alex, $sam);
        $this->service()->accept($offer, $sam);

        try {
            $this->service()->accept($offer, $sam);
            $this->fail('Expected OfferUnavailableException.');
        } catch (OfferUnavailableException) {
            // Expected.
        }

        $this->assertSame(100, $sam->refresh()->points);
    }

    public function test_an_offer_past_its_expiry_cannot_be_accepted_even_before_the_sweep_runs(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 500);

        $offer = $this->payingOffer($alex, $sam);
        $offer->update(['expires_at' => now()->subMinute()]);

        $this->expectException(OfferUnavailableException::class);
        $this->service()->accept($offer, $sam);
    }

    public function test_an_offer_lives_for_a_day(): void
    {
        [, $alex, $sam] = $this->twoKids();

        $offer = $this->payingOffer($alex, $sam);

        $this->assertSame(24, (int) round(now()->diffInHours($offer->expires_at)));
    }

    public function test_a_kid_cannot_offer_across_households_or_to_themselves(): void
    {
        [, $alex] = $this->twoKids();
        $stranger = Profile::factory()->for(Household::factory()->create())->create();

        foreach ([$stranger, $alex] as $target) {
            try {
                $this->payingOffer($alex, $target, points: 10);
                $this->fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }

        $this->assertSame(0, SiblingOffer::count());
    }

    public function test_a_parent_is_not_a_valid_side_of_a_sibling_trade(): void
    {
        $household = Household::factory()->create();
        $alex = Profile::factory()->for($household)->create(['points' => 500]);
        $parent = Profile::factory()->parent()->for($household)->create();

        $this->expectException(InvalidArgumentException::class);
        $this->payingOffer($alex, $parent);
    }

    public function test_a_trade_cannot_carry_a_favour_on_either_side(): void
    {
        [, $alex, $sam] = $this->twoKids();

        // Work for pay used to be a trade with a favour on one side, which paid
        // the moment it was accepted — before any of the work. That whole shape
        // lives on the bounty board now, where a job is claimed, reported done
        // and confirmed. Nothing here may write one again.
        foreach ([
            [TradeAsset::Points, 100, TradeAsset::Favour, 0],
            [TradeAsset::Favour, 0, TradeAsset::Points, 100],
            [TradeAsset::Favour, 0, TradeAsset::Favour, 0],
        ] as [$giveAsset, $giveAmount, $getAsset, $getAmount]) {
            try {
                $this->service()->offer($alex, $sam, $giveAsset, $giveAmount, $getAsset, $getAmount);
                $this->fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }

        $this->assertSame(0, SiblingOffer::count());
        $this->assertSame(500, $alex->refresh()->points);
    }

    public function test_the_points_must_be_within_the_allowed_range(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 5000);

        foreach ([0, -50, 1001] as $points) {
            try {
                $this->payingOffer($alex, $sam, points: $points);
                $this->fail("Expected InvalidArgumentException for {$points}.");
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }

        $this->assertSame(5000, $alex->refresh()->points);
    }

    public function test_paying_a_sibling_does_not_count_toward_the_loot_shop_spending_badge(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 1000);

        // `big_spender` sums LedgerKind::Spend and is about the Loot Shop.
        // Transfers use their own kind so a kid can't farm it by paying a sibling.
        $offer = $this->payingOffer($alex, $sam, points: 1000);
        $this->service()->accept($offer, $sam);

        $this->assertFalse($alex->refresh()->badges()->where('key', 'big_spender')->exists());
        $this->assertSame(0, LedgerEntry::where('kind', LedgerKind::Spend)->count());
    }

    public function test_the_recipient_is_notified_but_nobody_else_is(): void
    {
        Notification::fake();

        [$household, $alex, $sam] = $this->twoKids(senderPoints: 500);
        $riley = Profile::factory()->for($household)->create();

        $this->payingOffer($alex, $sam);

        Notification::assertSentTo($sam, SiblingOfferReceived::class);
        Notification::assertNotSentTo($alex, SiblingOfferReceived::class);
        Notification::assertNotSentTo($riley, SiblingOfferReceived::class);
    }

    public function test_tickets_are_held_the_same_way_points_are(): void
    {
        [, $alex, $sam] = $this->twoKids(senderTickets: 5);

        $offer = $this->service()->offer($alex, $sam, TradeAsset::Tickets, 3, TradeAsset::Points, 50);

        $this->assertSame(2, $alex->refresh()->bonus_tickets);

        $entry = BonusTicketEntry::latest('id')->first();
        $this->assertSame(TicketKind::Trade, $entry->kind);
        $this->assertSame(-3, $entry->amount);
        $this->assertSame(SiblingOffer::class, $entry->related_type);
        $this->assertSame($offer->id, $entry->related_id);

        // Tickets and points are separate currencies; one must not touch the other.
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_points_can_be_swapped_for_tickets(): void
    {
        // Alex starts with no tickets, so the count below is exactly what the
        // swap and the badge put there.
        [, $alex, $sam] = $this->twoKids(senderPoints: 500, recipientPoints: 0, senderTickets: 0, recipientTickets: 4);

        $offer = $this->service()->offer($alex, $sam, TradeAsset::Points, 100, TradeAsset::Tickets, 2);

        // Only Alex's side is held up front — Sam has not agreed to anything.
        $this->assertSame(400, $alex->refresh()->points);
        $this->assertSame(4, $sam->refresh()->bonus_tickets);
        $this->assertNull($offer->description);

        $this->service()->accept($offer, $sam);

        // A settled trade also unlocks Dealmaker for both sides, and a badge
        // mints a ticket — so each balance carries one more than the swap.
        $this->assertSame(400, $alex->refresh()->points);
        $this->assertSame(2 + 1, $alex->bonus_tickets);
        $this->assertSame(100, $sam->refresh()->points);
        $this->assertSame(4 - 2 + 1, $sam->bonus_tickets);
    }

    public function test_a_swap_the_recipient_cannot_cover_throws_in_the_currency_they_were_asked_for(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 500, recipientTickets: 1);

        $offer = $this->service()->offer($alex, $sam, TradeAsset::Points, 100, TradeAsset::Tickets, 3);

        try {
            $this->service()->accept($offer, $sam);
            $this->fail('Expected InsufficientTicketsException.');
        } catch (InsufficientTicketsException $e) {
            $this->assertSame(2, $e->shortfall);
        }

        $this->assertSame(400, $alex->refresh()->points);
        $this->assertSame(1, $sam->refresh()->bonus_tickets);
        $this->assertSame(SiblingOfferStatus::Pending, $offer->refresh()->status);
    }

    public function test_offering_more_tickets_than_you_have_throws_with_the_shortfall(): void
    {
        [, $alex, $sam] = $this->twoKids(senderTickets: 1);

        try {
            $this->service()->offer($alex, $sam, TradeAsset::Tickets, 4, TradeAsset::Points, 50);
            $this->fail('Expected InsufficientTicketsException.');
        } catch (InsufficientTicketsException $e) {
            $this->assertSame(3, $e->shortfall);
        }

        $this->assertSame(1, $alex->refresh()->bonus_tickets);
        $this->assertSame(0, SiblingOffer::count());
    }

    public function test_declining_a_swap_gives_the_held_tickets_back(): void
    {
        [, $alex, $sam] = $this->twoKids(senderTickets: 5);

        $offer = $this->service()->offer($alex, $sam, TradeAsset::Tickets, 3, TradeAsset::Points, 200);
        $this->assertSame(2, $alex->refresh()->bonus_tickets);

        $this->service()->decline($offer, $sam);

        $this->assertSame(5, $alex->refresh()->bonus_tickets);
        $this->assertSame(SiblingOfferStatus::Declined, $offer->refresh()->status);
    }

    public function test_the_tickets_must_be_within_their_own_smaller_range(): void
    {
        [, $alex, $sam] = $this->twoKids(senderTickets: 200);

        // Tickets are minted one per level, so the points ceiling would be
        // absurd here — 26 is over the line even though 26 points is not.
        foreach ([0, -2, 26] as $tickets) {
            try {
                $this->service()->offer($alex, $sam, TradeAsset::Tickets, $tickets, TradeAsset::Points, 50);
                $this->fail("Expected InvalidArgumentException for {$tickets}.");
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }

        $this->assertSame(200, $alex->refresh()->bonus_tickets);
        $this->assertSame(0, SiblingOffer::count());
    }

    public function test_a_trade_cannot_ask_for_the_same_currency_it_puts_up(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 500);

        $this->expectException(InvalidArgumentException::class);
        $this->service()->offer($alex, $sam, TradeAsset::Points, 100, TradeAsset::Points, 50);
    }

    public function test_a_swap_carries_no_description(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 500);

        // There is nothing to say about a swap that the two amounts don't
        // already say, so the service no longer takes a line of text at all —
        // the only deals with words in them are jobs, and they live elsewhere.
        $offer = $this->service()->offer($alex, $sam, TradeAsset::Points, 100, TradeAsset::Tickets, 2);

        $this->assertNull($offer->refresh()->description);
        $this->assertSame('100 pts for 2 tickets', $offer->summary());
    }
}
