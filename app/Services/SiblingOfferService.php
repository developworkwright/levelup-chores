<?php

namespace App\Services;

use App\Enums\LedgerKind;
use App\Enums\SiblingOfferKind;
use App\Enums\SiblingOfferStatus;
use App\Exceptions\InsufficientPointsException;
use App\Exceptions\OfferUnavailableException;
use App\Models\Household;
use App\Models\Profile;
use App\Models\SiblingOffer;
use App\Notifications\SiblingOfferReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Kid-to-kid trades for one-off favours. Two shapes, one table:
 *
 * - `Paying`  — the sender pays. Points come out of their balance the moment
 *   they make the offer, exactly as {@see StoreService::redeem()} deducts up
 *   front, so three 100-point offers can't be fired off on a 100-point
 *   balance. Accepting releases the held points to the recipient.
 * - `Earning` — the sender does the favour and the recipient pays. There is no
 *   escrow window at all: the payer *is* the accepter, so affordability is
 *   checked and both legs move in the same instant.
 */
class SiblingOfferService
{
    public const MIN_POINTS = 1;

    /** A ceiling low enough that a mistyped extra zero can't empty a balance. */
    public const MAX_POINTS = 1000;

    public const MAX_DESCRIPTION = 120;

    public function __construct(
        private LedgerService $ledger,
        private BadgeService $badges,
    ) {}

    public function offer(
        Profile $from,
        Profile $to,
        SiblingOfferKind $kind,
        string $description,
        int $points,
    ): SiblingOffer {
        $description = trim($description);

        if ($description === '') {
            throw new InvalidArgumentException('Say what the trade is first.');
        }

        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw new InvalidArgumentException('That is too long — keep it to one line.');
        }

        if ($points < self::MIN_POINTS || $points > self::MAX_POINTS) {
            throw new InvalidArgumentException('Offer between '.self::MIN_POINTS.' and '.self::MAX_POINTS.' points.');
        }

        if (! $from->isKid() || ! $to->isKid()) {
            throw new InvalidArgumentException('Sibling trades are between kids.');
        }

        if ($from->household_id !== $to->household_id || $from->is($to)) {
            throw new InvalidArgumentException('Pick a sibling to send this to.');
        }

        if ($kind === SiblingOfferKind::Paying && $from->points < $points) {
            throw new InsufficientPointsException($points - $from->points);
        }

        $offer = DB::transaction(function () use ($from, $to, $kind, $description, $points) {
            $offer = SiblingOffer::create([
                'household_id' => $from->household_id,
                'from_profile_id' => $from->id,
                'to_profile_id' => $to->id,
                'kind' => $kind,
                'description' => $description,
                'points' => $points,
                'status' => SiblingOfferStatus::Pending,
                'expires_at' => now()->addHours(SiblingOffer::LIFETIME_HOURS),
            ]);

            // Held, not spent: the recipient hasn't agreed to anything yet, so
            // this comes straight back on a decline, a withdrawal or a lapse.
            if ($kind === SiblingOfferKind::Paying) {
                $this->ledger->record(
                    $from->household,
                    $from,
                    LedgerKind::Transfer,
                    -$points,
                    "{$from->name} → {$to->name}: {$description} (offered)",
                    $offer,
                );
            }

            return $offer;
        });

        $this->notifyRecipient($offer, $from, $to);

        return $offer;
    }

    /**
     * @throws OfferUnavailableException the offer was answered or lapsed first
     * @throws InsufficientPointsException the accepter is the payer and is short
     */
    public function accept(SiblingOffer $offer, Profile $responder): void
    {
        $this->assertAnswerableBy($offer, $responder, 'to_profile_id');

        $offer->loadMissing('fromProfile', 'toProfile', 'household');

        $payer = $offer->payer();
        $worker = $offer->worker();

        // On an `Earning` offer the accepter pays, and their balance can have
        // moved since the offer landed — so this is checked now, not at offer
        // time. On a `Paying` offer the money is already held.
        if (! $offer->isEscrowed() && $payer->points < $offer->points) {
            throw new InsufficientPointsException($offer->points - $payer->points);
        }

        DB::transaction(function () use ($offer, $payer, $worker) {
            $label = "{$payer->name} → {$worker->name}: {$offer->description}";

            if (! $offer->isEscrowed()) {
                $this->ledger->record(
                    $offer->household,
                    $payer,
                    LedgerKind::Transfer,
                    -$offer->points,
                    $label,
                    $offer,
                );
            }

            $this->ledger->record(
                $offer->household,
                $worker,
                LedgerKind::Transfer,
                $offer->points,
                $label,
                $offer,
            );

            $offer->status = SiblingOfferStatus::Accepted;
            $offer->responded_at = now();
            $offer->save();
        });

        // Both balances just moved, and `big_saver` is balance-based.
        $this->badges->evaluate($payer->refresh());
        $this->badges->evaluate($worker->refresh());
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
     * Lapse every offer in the household that ran out of time, refunding any
     * points it was holding.
     *
     * The app has no scheduler, so this runs lazily off the Loot Shop. It
     * sweeps the whole household rather than one kid's offers, so whichever
     * sibling opens the shop first settles everybody's — and the kid with
     * points tied up is the one most motivated to look.
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
            if ($offer->isEscrowed()) {
                $sender = $offer->fromProfile;

                $this->ledger->record(
                    $offer->household,
                    $sender,
                    LedgerKind::Transfer,
                    $offer->points,
                    "{$sender->name} → {$offer->toProfile->name}: {$offer->description} ({$reason})",
                    $offer,
                );
            }

            $offer->status = $status;
            $offer->responded_at = now();
            $offer->save();
        });
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
     * Best-effort: the offer is already recorded and any points already held,
     * so a failed push must not fail the request.
     */
    private function notifyRecipient(SiblingOffer $offer, Profile $from, Profile $to): void
    {
        $body = $offer->kind === SiblingOfferKind::Paying
            ? "{$from->name} will pay you {$offer->points} to {$offer->description}."
            : "{$from->name} will {$offer->description} for {$offer->points}.";

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
