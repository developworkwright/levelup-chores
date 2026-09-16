<?php

namespace App\Services;

use App\Enums\ProfileRole;
use App\Enums\TicketKind;
use App\Models\Profile;
use App\Models\SiblingGift;
use App\Notifications\GiftReceived;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The daily gift: once a household day, a kid picks a sibling and that sibling
 * gets a ticket.
 *
 * Minted by the house, never taken from the giver. A gift that cost something
 * would stay in the pocket of exactly the kid who is saving, and the point is
 * the pull between siblings, not a transfer. The only way to lose it is to not
 * open the app, which is the whole reason it doesn't bank: an unused gift is
 * gone at the rollover, like a charm.
 *
 * Kids to kids only. Parents neither give nor receive one — a grown-up in the
 * picker would be the safe choice that stops it being about a brother or sister.
 *
 * Two siblings trading gifts back and forth every day is fine: that is a ticket
 * each for turning up, which is what it is for.
 *
 * Public, at the user's call: each gift is a line in the family feed's
 * Everyone room. The recipient also gets a push naming the giver, and an alert
 * on their gift row until they open it.
 */
class GiftService
{
    /** What the house hands the chosen sibling. */
    public const TICKETS = 1;

    public function __construct(private TicketService $tickets) {}

    /** Today's gift from this kid, or null while it is still theirs to give. */
    public function givenToday(Profile $giver): ?SiblingGift
    {
        return SiblingGift::with('recipient')
            ->where('giver_id', $giver->id)
            ->whereDate('gift_date', HouseholdClock::for($giver->household)->today())
            ->first();
    }

    /**
     * Today's gifts to this kid, oldest first — the order they arrived in.
     *
     * @return Collection<int, SiblingGift>
     */
    public function receivedToday(Profile $recipient): Collection
    {
        return SiblingGift::with('giver')
            ->where('recipient_id', $recipient->id)
            ->whereDate('gift_date', HouseholdClock::for($recipient->household)->today())
            ->oldest('id')
            ->get();
    }

    /**
     * Who this kid can give to: every other kid in the house.
     *
     * Empty for a parent and for an only child, which is how the page knows to
     * leave the row off rather than offer a picker with nobody in it.
     *
     * @return Collection<int, Profile>
     */
    public function siblingsOf(Profile $profile): Collection
    {
        if (! $profile->isKid()) {
            return new Collection;
        }

        return Profile::where('household_id', $profile->household_id)
            ->where('role', ProfileRole::Kid)
            ->whereKeyNot($profile->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * Gives today's ticket to a sibling. Null when there is no gift left to
     * give or the recipient isn't one of this kid's siblings — re-checked here
     * rather than trusted from the button, since the id comes off the page.
     */
    public function give(Profile $giver, int $recipientId): ?SiblingGift
    {
        $recipient = $this->siblingsOf($giver)->firstWhere('id', $recipientId);

        if ($recipient === null || $this->givenToday($giver) !== null) {
            return null;
        }

        try {
            $gift = DB::transaction(function () use ($giver, $recipient) {
                $gift = SiblingGift::create([
                    'household_id' => $giver->household_id,
                    'giver_id' => $giver->id,
                    'recipient_id' => $recipient->id,
                    'gift_date' => HouseholdClock::for($giver->household)->today(),
                ]);

                $this->tickets->record(
                    $recipient,
                    TicketKind::Gift,
                    self::TICKETS,
                    "Gift from {$giver->name}",
                    $gift,
                );

                return $gift;
            });
        } catch (UniqueConstraintViolationException) {
            // Two taps racing past givenToday(). The first one gave the gift;
            // the second has nothing left to give, which is the honest answer.
            return null;
        }

        // The house hears about it, as one quiet line in Everyone. Events never
        // push, so the recipient's buzz is the GiftReceived below — one
        // notification, not two.
        app(FeedService::class)->event(
            $giver,
            '🎟 '.$giver->name.' gave «'.$recipient->name.'» a ticket',
            $gift,
        );

        $this->notify($giver, $recipient);

        return $gift->setRelation('recipient', $recipient);
    }

    /**
     * Marks today's gifts to this kid as seen, which is what takes the alert
     * off their gift row. Called when they open it.
     */
    public function markSeen(Profile $recipient): void
    {
        SiblingGift::where('recipient_id', $recipient->id)
            ->whereDate('gift_date', HouseholdClock::for($recipient->household)->today())
            ->whereNull('seen_at')
            ->update(['seen_at' => now()]);
    }

    /**
     * Best-effort: the ticket is already banked, so a failed push must not fail
     * the gift.
     */
    private function notify(Profile $giver, Profile $recipient): void
    {
        try {
            $recipient->notify(new GiftReceived(
                "{$giver->name} gave you a ticket 🎟️",
                'Open the app to give one back — everyone gets one to give each day.',
            ));
        } catch (Throwable $e) {
            Log::error('Sibling gift notification failed.', [
                'giver_id' => $giver->id,
                'recipient_id' => $recipient->id,
                'exception' => $e,
            ]);
        }
    }
}
