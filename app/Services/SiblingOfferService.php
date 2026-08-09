<?php

namespace App\Services;

use App\Enums\LedgerKind;
use App\Enums\SiblingOfferStatus;
use App\Enums\TicketKind;
use App\Enums\TradeAsset;
use App\Exceptions\InsufficientPointsException;
use App\Exceptions\InsufficientTicketsException;
use App\Exceptions\OfferUnavailableException;
use App\Models\Household;
use App\Models\Profile;
use App\Models\SiblingOffer;
use App\Notifications\SiblingOfferReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Kid-to-kid **swaps**: one currency for another, between two named siblings,
 * settled the instant it is accepted. "100 points for 2 tickets."
 *
 * Work-for-pay used to live here too, as a trade with a favour on one side.
 * It doesn't any more — {@see BountyService} owns every deal where somebody
 * does something, whether it is aimed at one sibling or open to the household.
 * The reason is that the two behaved differently for no reason a kid could see:
 * a favour trade paid the moment it was accepted, before the dishes were
 * touched, while the same deal posted openly went through claim, done and
 * confirm. Now there is one answer, and this service is left doing the one
 * thing that genuinely settles immediately, because a swap has no work in it.
 *
 * The sender's side is escrowed at offer time, exactly as
 * {@see StoreService::redeem()} deducts up front, so three 100-point offers
 * can't be fired off on a 100-point balance. The recipient's side is checked
 * when they answer instead: they never agreed to hold anything, and their
 * balance can move between the offer landing and them reading it.
 */
class SiblingOfferService
{
    public function __construct(
        private LedgerService $ledger,
        private TicketService $tickets,
        private BadgeService $badges,
    ) {}

    public function offer(
        Profile $from,
        Profile $to,
        TradeAsset $giveAsset,
        int $giveAmount,
        TradeAsset $getAsset,
        int $getAmount,
    ): SiblingOffer {
        if (! $from->isKid() || ! $to->isKid()) {
            throw new InvalidArgumentException('Sibling trades are between kids.');
        }

        if ($from->household_id !== $to->household_id || $from->is($to)) {
            throw new InvalidArgumentException('Pick a sibling to send this to.');
        }

        // A swap moves two balances. Anything involving work belongs on the
        // bounty board, which is the only place a job is claimed and signed
        // off rather than paid on trust — see the class docblock.
        if (! $giveAsset->isCurrency() || ! $getAsset->isCurrency()) {
            throw new InvalidArgumentException('Swap points or tickets. To trade a job, post it on the board.');
        }

        if ($giveAsset === $getAsset) {
            throw new InvalidArgumentException('Trade for something different from what you are putting up.');
        }

        $this->assertAmountInRange($giveAsset, $giveAmount);
        $this->assertAmountInRange($getAsset, $getAmount);

        // Only the sender's side is held now — see the class docblock.
        $this->assertCanAfford($from, $giveAsset, $giveAmount);

        $offer = DB::transaction(function () use ($from, $to, $giveAsset, $giveAmount, $getAsset, $getAmount) {
            $offer = SiblingOffer::create([
                'household_id' => $from->household_id,
                'from_profile_id' => $from->id,
                'to_profile_id' => $to->id,
                'give_asset' => $giveAsset,
                'give_amount' => $giveAsset->isCurrency() ? $giveAmount : 0,
                'get_asset' => $getAsset,
                'get_amount' => $getAsset->isCurrency() ? $getAmount : 0,
                // Nothing to say about a swap that the two amounts don't
                // already say. The column stays for the favour trades written
                // before jobs moved to the bounty board.
                'description' => null,
                'status' => SiblingOfferStatus::Pending,
                'expires_at' => now()->addHours(SiblingOffer::LIFETIME_HOURS),
            ]);

            // Held, not spent: the recipient hasn't agreed to anything yet, so
            // this comes straight back on a decline, a withdrawal or a lapse.
            $this->move(
                $offer,
                $from,
                $offer->give_asset,
                -$offer->give_amount,
                "{$from->name} → {$to->name}: {$offer->summary()} (offered)",
            );

            return $offer;
        });

        $this->notifyRecipient($offer, $from, $to);

        return $offer;
    }

    /**
     * @throws OfferUnavailableException the offer was answered or lapsed first
     * @throws InsufficientPointsException|InsufficientTicketsException the
     *                                                                  recipient is short of the side they were asked for
     */
    public function accept(SiblingOffer $offer, Profile $responder): void
    {
        $this->assertAnswerableBy($offer, $responder, 'to_profile_id');

        $offer->loadMissing('fromProfile', 'toProfile', 'household');

        $sender = $offer->fromProfile;
        $recipient = $offer->toProfile;

        // Checked now rather than at offer time: the recipient's balance can
        // have moved since the offer landed, and unlike the sender they were
        // never asked to put anything aside.
        if ($offer->get_asset->isCurrency()) {
            $this->assertCanAfford($recipient, $offer->get_asset, $offer->get_amount);
        }

        DB::transaction(function () use ($offer, $sender, $recipient) {
            $label = "{$sender->name} → {$recipient->name}: {$offer->summary()}";

            // The sender's side is already out of their balance, so this is the
            // release half of the escrow rather than a second charge.
            $this->move($offer, $recipient, $offer->give_asset, $offer->give_amount, $label);

            $this->move($offer, $recipient, $offer->get_asset, -$offer->get_amount, $label);
            $this->move($offer, $sender, $offer->get_asset, $offer->get_amount, $label);

            $offer->status = SiblingOfferStatus::Accepted;
            $offer->responded_at = now();
            $offer->save();
        });

        // Both balances just moved, and `big_saver` is balance-based.
        $this->badges->evaluate($sender->refresh());
        $this->badges->evaluate($recipient->refresh());
    }

    public function decline(SiblingOffer $offer, Profile $responder): void
    {
        $this->assertAnswerableBy($offer, $responder, 'to_profile_id');

        $this->settle($offer, SiblingOfferStatus::Declined, 'turned down');
    }

    /** The sender withdrawing an offer nobody has answered yet. */
    public function cancel(SiblingOffer $offer, Profile $sender): void
    {
        $this->assertAnswerableBy($offer, $sender, 'from_profile_id');

        $this->settle($offer, SiblingOfferStatus::Cancelled, 'taken back');
    }

    /**
     * Lapse every offer in the household that ran out of time, refunding
     * whatever it was holding.
     *
     * The app has no scheduler, so this runs lazily off the Trades & Jobs
     * page, alongside {@see BountyService::sweep()} — the two settle together
     * because they now share a page and a kid's held points can be in either. It
     * sweeps the whole household rather than one kid's offers, so whichever
     * sibling opens the shop first settles everybody's — and the kid with
     * something tied up is the one most motivated to look.
     */
    public function expireStale(Household $household): void
    {
        $stale = SiblingOffer::where('household_id', $household->id)
            ->where('status', SiblingOfferStatus::Pending)
            ->where('expires_at', '<=', now())
            ->with(['fromProfile', 'toProfile', 'household'])
            ->get();

        foreach ($stale as $offer) {
            $this->settle($offer, SiblingOfferStatus::Expired, 'ran out of time');
        }
    }

    /**
     * The one place an offer ends without a trade. Every such path routes
     * through here so an escrowed offer can never be closed without its refund.
     */
    private function settle(SiblingOffer $offer, SiblingOfferStatus $status, string $reason): void
    {
        $offer->loadMissing('fromProfile', 'toProfile', 'household');

        DB::transaction(function () use ($offer, $status, $reason) {
            $sender = $offer->fromProfile;

            $this->move(
                $offer,
                $sender,
                $offer->give_asset,
                $offer->give_amount,
                "{$sender->name} → {$offer->toProfile->name}: {$offer->summary()} ({$reason})",
            );

            $offer->status = $status;
            $offer->responded_at = now();
            $offer->save();
        });
    }

    /**
     * Move one side of a trade in or out of a kid's balance. The single place
     * that knows which service owns which currency, so adding a third would not
     * mean auditing escrow, accept and refund separately.
     *
     * A favour side, or a zero amount, moves nothing — callers can hand every
     * side to this without first asking whether there is anything to do.
     */
    private function move(SiblingOffer $offer, Profile $profile, TradeAsset $asset, int $amount, string $label): void
    {
        if (! $asset->isCurrency() || $amount === 0) {
            return;
        }

        match ($asset) {
            TradeAsset::Points => $this->ledger->record(
                $offer->household,
                $profile,
                LedgerKind::Transfer,
                $amount,
                $label,
                $offer,
            ),
            TradeAsset::Tickets => $this->tickets->record(
                $profile,
                TicketKind::Trade,
                $amount,
                $label,
                $offer,
            ),
            // Unreachable: guarded above. Here so a new asset can't slip
            // through as a silent no-op.
            TradeAsset::Favour => throw new LogicException('A favour has no balance to move.'),
        };
    }

    /**
     * @throws InsufficientPointsException|InsufficientTicketsException
     */
    private function assertCanAfford(Profile $profile, TradeAsset $asset, int $amount): void
    {
        $shortfall = $amount - $profile->balanceOf($asset);

        if ($shortfall <= 0) {
            return;
        }

        throw $asset === TradeAsset::Tickets
            ? new InsufficientTicketsException($shortfall)
            : new InsufficientPointsException($shortfall);
    }

    private function assertAmountInRange(TradeAsset $asset, int $amount): void
    {
        if (! $asset->isCurrency()) {
            return;
        }

        if ($amount < $asset->minAmount() || $amount > $asset->maxAmount()) {
            throw new InvalidArgumentException(
                'Offer between '.$asset->minAmount().' and '.$asset->maxAmount().' '.strtolower($asset->label()).'.'
            );
        }
    }

    /**
     * @param  'to_profile_id'|'from_profile_id'  $column  which side of the trade may take this action
     *
     * @throws OfferUnavailableException
     */
    private function assertAnswerableBy(SiblingOffer $offer, Profile $actor, string $column): void
    {
        if ($offer->{$column} !== $actor->id) {
            throw new OfferUnavailableException('That trade is not yours to answer.');
        }

        if (! $offer->isLive()) {
            throw new OfferUnavailableException('That trade is no longer up for grabs.');
        }
    }

    /**
     * Best-effort: the offer is already recorded and anything it holds already
     * held, so a failed push must not fail the request.
     */
    private function notifyRecipient(SiblingOffer $offer, Profile $from, Profile $to): void
    {
        $body = "{$from->name} offers {$offer->giveText()} for {$offer->getText()}.";

        try {
            $to->notify(new SiblingOfferReceived('New trade offered', $body));
        } catch (Throwable $e) {
            Log::error('Sibling offer notification failed.', [
                'sibling_offer_id' => $offer->id,
                'exception' => $e,
            ]);
        }
    }
}
