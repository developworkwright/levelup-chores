<?php

namespace Tests\Feature;

use App\Enums\LedgerKind;
use App\Enums\SiblingOfferKind;
use App\Enums\SiblingOfferStatus;
use App\Exceptions\InsufficientPointsException;
use App\Exceptions\OfferUnavailableException;
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

    /** @return array{0: Household, 1: Profile, 2: Profile} */
    private function twoKids(int $senderPoints = 500, int $recipientPoints = 500): array
    {
        $household = Household::factory()->create();

        return [
            $household,
            Profile::factory()->for($household)->create(['name' => 'Alex', 'points' => $senderPoints]),
            Profile::factory()->for($household)->create(['name' => 'Sam', 'points' => $recipientPoints]),
        ];
    }

    public function test_a_paying_offer_holds_the_points_the_moment_it_is_made(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 500);

        $offer = $this->service()->offer($alex, $sam, SiblingOfferKind::Paying, 'Play a game for 30 min', 100);

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

    public function test_an_earning_offer_moves_nothing_until_it_is_accepted(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 0, recipientPoints: 500);

        // Alex has nothing, but Alex is the one doing the work here.
        $this->service()->offer($alex, $sam, SiblingOfferKind::Earning, 'I will do your dishes', 100);

        $this->assertSame(0, $alex->refresh()->points);
        $this->assertSame(500, $sam->refresh()->points);
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_accepting_a_paying_offer_pays_the_recipient_without_charging_the_sender_twice(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 500, recipientPoints: 20);

        $offer = $this->service()->offer($alex, $sam, SiblingOfferKind::Paying, 'Play a game for 30 min', 100);
        $this->service()->accept($offer, $sam);

        $this->assertSame(400, $alex->refresh()->points);
        $this->assertSame(120, $sam->refresh()->points);
        $this->assertSame(SiblingOfferStatus::Accepted, $offer->refresh()->status);
        $this->assertNotNull($offer->responded_at);
    }

    public function test_accepting_an_earning_offer_charges_the_accepter_and_pays_the_sender(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 0, recipientPoints: 500);

        $offer = $this->service()->offer($alex, $sam, SiblingOfferKind::Earning, 'I will do your dishes', 100);
        $this->service()->accept($offer, $sam);

        $this->assertSame(100, $alex->refresh()->points);
        $this->assertSame(400, $sam->refresh()->points);
        $this->assertSame(2, LedgerEntry::where('kind', LedgerKind::Transfer)->count());
    }

    public function test_offering_to_pay_more_than_you_have_throws_with_the_shortfall(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 60);

        try {
            $this->service()->offer($alex, $sam, SiblingOfferKind::Paying, 'Play a game', 100);
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

        $offer = $this->service()->offer($alex, $sam, SiblingOfferKind::Earning, 'I will do your dishes', 100);

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

        $offer = $this->service()->offer($alex, $sam, SiblingOfferKind::Paying, 'Play a game', 100);
        $this->service()->decline($offer, $sam);

        $this->assertSame(500, $alex->refresh()->points);
        $this->assertSame(500, $sam->refresh()->points);
        $this->assertSame(SiblingOfferStatus::Declined, $offer->refresh()->status);
    }

    public function test_the_sender_can_take_an_unanswered_offer_back_and_get_refunded(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 500);

        $offer = $this->service()->offer($alex, $sam, SiblingOfferKind::Paying, 'Play a game', 100);
        $this->service()->cancel($offer, $alex);

        $this->assertSame(500, $alex->refresh()->points);
        $this->assertSame(SiblingOfferStatus::Cancelled, $offer->refresh()->status);
    }

    public function test_an_offer_that_runs_out_of_time_is_swept_and_refunded(): void
    {
        [$household, $alex, $sam] = $this->twoKids(senderPoints: 500);

        $offer = $this->service()->offer($alex, $sam, SiblingOfferKind::Paying, 'Play a game', 100);
        $this->assertSame(400, $alex->refresh()->points);

        $offer->update(['expires_at' => now()->subMinute()]);
        $this->service()->expireStale($household);

        $this->assertSame(500, $alex->refresh()->points);
        $this->assertSame(SiblingOfferStatus::Expired, $offer->refresh()->status);
    }

    public function test_the_sweep_leaves_live_offers_alone(): void
    {
        [$household, $alex, $sam] = $this->twoKids(senderPoints: 500);

        $offer = $this->service()->offer($alex, $sam, SiblingOfferKind::Paying, 'Play a game', 100);
        $this->service()->expireStale($household);

        $this->assertSame(400, $alex->refresh()->points);
        $this->assertSame(SiblingOfferStatus::Pending, $offer->refresh()->status);
    }

    public function test_sweeping_twice_only_refunds_once(): void
    {
        [$household, $alex, $sam] = $this->twoKids(senderPoints: 500);

        $offer = $this->service()->offer($alex, $sam, SiblingOfferKind::Paying, 'Play a game', 100);
        $offer->update(['expires_at' => now()->subMinute()]);

        $this->service()->expireStale($household);
        $this->service()->expireStale($household);

        $this->assertSame(500, $alex->refresh()->points);
    }

    public function test_settling_an_earning_offer_refunds_nothing_because_nothing_was_held(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 0, recipientPoints: 500);

        $offer = $this->service()->offer($alex, $sam, SiblingOfferKind::Earning, 'I will do your dishes', 100);
        $this->service()->decline($offer, $sam);

        $this->assertSame(0, $alex->refresh()->points);
        $this->assertSame(500, $sam->refresh()->points);
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_a_kid_cannot_accept_an_offer_addressed_to_someone_else(): void
    {
        [$household, $alex, $sam] = $this->twoKids(senderPoints: 500);
        $riley = Profile::factory()->for($household)->create(['points' => 0]);

        $offer = $this->service()->offer($alex, $sam, SiblingOfferKind::Paying, 'Play a game', 100);

        $this->expectException(OfferUnavailableException::class);
        $this->service()->accept($offer, $riley);
    }

    public function test_an_offer_cannot_be_accepted_twice(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 500, recipientPoints: 0);

        $offer = $this->service()->offer($alex, $sam, SiblingOfferKind::Paying, 'Play a game', 100);
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

        $offer = $this->service()->offer($alex, $sam, SiblingOfferKind::Paying, 'Play a game', 100);
        $offer->update(['expires_at' => now()->subMinute()]);

        $this->expectException(OfferUnavailableException::class);
        $this->service()->accept($offer, $sam);
    }

    public function test_an_offer_lives_for_a_day(): void
    {
        [, $alex, $sam] = $this->twoKids();

        $offer = $this->service()->offer($alex, $sam, SiblingOfferKind::Paying, 'Play a game', 100);

        $this->assertSame(24, (int) round(now()->diffInHours($offer->expires_at)));
    }

    public function test_a_kid_cannot_offer_across_households_or_to_themselves(): void
    {
        [, $alex] = $this->twoKids();
        $stranger = Profile::factory()->for(Household::factory()->create())->create();

        foreach ([$stranger, $alex] as $target) {
            try {
                $this->service()->offer($alex, $target, SiblingOfferKind::Paying, 'Play a game', 10);
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
        $this->service()->offer($alex, $parent, SiblingOfferKind::Paying, 'Play a game', 100);
    }

    public function test_an_empty_or_oversized_trade_is_rejected(): void
    {
        [, $alex, $sam] = $this->twoKids();

        foreach (['', '   ', str_repeat('a', 121)] as $description) {
            try {
                $this->service()->offer($alex, $sam, SiblingOfferKind::Paying, $description, 100);
                $this->fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }

        $this->assertSame(0, SiblingOffer::count());
    }

    public function test_the_points_must_be_within_the_allowed_range(): void
    {
        [, $alex, $sam] = $this->twoKids(senderPoints: 5000);

        foreach ([0, -50, 1001] as $points) {
            try {
                $this->service()->offer($alex, $sam, SiblingOfferKind::Paying, 'Play a game', $points);
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
        $offer = $this->service()->offer($alex, $sam, SiblingOfferKind::Paying, 'Play a game', 1000);
        $this->service()->accept($offer, $sam);

        $this->assertFalse($alex->refresh()->badges()->where('key', 'big_spender')->exists());
        $this->assertSame(0, LedgerEntry::where('kind', LedgerKind::Spend)->count());
    }

    public function test_the_recipient_is_notified_but_nobody_else_is(): void
    {
        Notification::fake();

        [$household, $alex, $sam] = $this->twoKids(senderPoints: 500);
        $riley = Profile::factory()->for($household)->create();

        $this->service()->offer($alex, $sam, SiblingOfferKind::Paying, 'Play a game', 100);

        Notification::assertSentTo($sam, SiblingOfferReceived::class);
        Notification::assertNotSentTo($alex, SiblingOfferReceived::class);
        Notification::assertNotSentTo($riley, SiblingOfferReceived::class);
    }
}
