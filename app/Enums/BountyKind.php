<?php

namespace App\Enums;

/**
 * Which way round a deal on the board runs.
 *
 * Every bounty has a *worker* and a *payer*, and the kind is the only thing
 * that says which end the poster is standing at. Everything downstream — who
 * escrows, who marks it done, who confirms — falls out of those two.
 *
 * "Worker" is the vocabulary rather than the whole truth: on a sale nobody
 * works, somebody hands a thing over. The two are the same shape — one side
 * delivers, the other pays on delivery — so they run the one state machine,
 * and the words that differ live on this enum rather than in the page.
 *
 * The one thing a sale genuinely does not share with an offer of work is
 * {@see self::hireable()}. A parent hiring an offer of work turns it into a
 * one-time chore; doing that to "my blue Lego set" would mint a chore by that
 * name worth real points. That is why selling is its own case and not a label
 * on top of Offered.
 */
enum BountyKind: string
{
    /** "I'll pay 100 pts if someone makes my bed." The poster pays. */
    case Wanted = 'wanted';

    /** "I'll wash the car for 200 pts." The poster does the work. */
    case Offered = 'offered';

    /** "Selling my blue Lego set, 300 pts." The poster hands something over. */
    case Selling = 'selling';

    public function label(): string
    {
        return match ($this) {
            self::Wanted => 'Job wanted',
            self::Offered => 'Job offered',
            self::Selling => 'For sale',
        };
    }

    /** How the board headlines it. */
    public function headline(): string
    {
        return match ($this) {
            self::Wanted => 'Wants this done',
            self::Offered => 'Will do this',
            self::Selling => 'Is selling',
        };
    }

    /** What the taker is signing up for. */
    public function takeLabel(): string
    {
        return match ($this) {
            self::Wanted => "I'll do it",
            self::Offered => 'Hire them',
            self::Selling => 'Buy it',
        };
    }

    /** The heading on the compose card, and the button that opens it. */
    public function composeTitle(): string
    {
        return match ($this) {
            self::Wanted => 'Pay for a job',
            self::Offered => 'Do a job',
            self::Selling => 'Sell something',
        };
    }

    public function composeBlurb(): string
    {
        return match ($this) {
            self::Wanted => 'Get something done for you.',
            self::Offered => 'Offer to work for points.',
            self::Selling => 'Sell something you own.',
        };
    }

    /** What the typed line is asking for. */
    public function subjectPrompt(): string
    {
        return match ($this) {
            self::Wanted => 'What do you want done?',
            self::Offered => 'What will you do?',
            self::Selling => 'What are you selling?',
        };
    }

    public function subjectPlaceholder(): string
    {
        return match ($this) {
            self::Wanted => 'Make my bed',
            self::Offered => 'Wash the car',
            self::Selling => 'My blue Lego set',
        };
    }

    public function priceLabel(): string
    {
        return match ($this) {
            self::Wanted => "You'll pay",
            self::Offered => 'You want paid',
            self::Selling => 'You want for it',
        };
    }

    /** How the deliverer's side of a live deal reads to them. */
    public function workerRole(): string
    {
        return match ($this) {
            self::Wanted, self::Offered => 'You do it',
            self::Selling => "You're selling",
        };
    }

    /** The button the deliverer presses to say their end is done. */
    public function deliverLabel(): string
    {
        return match ($this) {
            self::Wanted, self::Offered => "I've done it",
            self::Selling => 'Handed it over',
        };
    }

    /** The push notification that goes out when one is posted. */
    public function announceTitle(): string
    {
        return match ($this) {
            self::Wanted => 'New job on the board',
            self::Offered => 'Someone is offering to work',
            self::Selling => 'Something is up for sale',
        };
    }

    /** Whether the kid who posted it is the one who pays out. */
    public function posterPays(): bool
    {
        return $this === self::Wanted;
    }

    /**
     * Whether a parent can take this on. Only an offer of *work*: a parent
     * cannot answer "someone please make my bed" by paying a kid to do it,
     * which is just a chore and chores already exist — and hiring a sale would
     * turn the thing being sold into a chore named after it.
     */
    public function hireable(): bool
    {
        return $this === self::Offered;
    }

    /**
     * The kinds a parent is shown on the approvals screen.
     *
     * @return array<int, self>
     */
    public static function hireableCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $kind) => $kind->hireable()));
    }

    /**
     * The kinds where the poster is the one paying out.
     *
     * These two exist so queries can group by role rather than listing cases:
     * a query that names Wanted and Offered by hand silently forgets any kind
     * added later, which is exactly how Selling would have gone missing from
     * {@see BountyService::waitingOn()}.
     *
     * @return array<int, self>
     */
    public static function posterPaysCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $kind) => $kind->posterPays()));
    }

    /**
     * The kinds where the poster is the one delivering — doing the work, or
     * handing the thing over.
     *
     * @return array<int, self>
     */
    public static function posterDeliversCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $kind) => ! $kind->posterPays()));
    }
}
