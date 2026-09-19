<?php

namespace App\Services;

use App\Enums\ChoreCadence;
use App\Enums\CompletionStatus;
use App\Enums\LedgerKind;
use App\Enums\ProfileRole;
use App\Enums\TicketKind;
use App\Models\CharmedChore;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyMystery;
use App\Models\Household;
use App\Models\MysteryHintPurchase;
use App\Models\Profile;
use App\Notifications\ChoreClosingSoon;
use App\Notifications\ChoreReviewed;
use App\Notifications\HelpWantedPosted;
use App\Notifications\ParentApprovalNeeded;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

class ChoreService
{
    /** Bonus paid on top of whatever chore gets picked as the day's mystery. */
    public const MYSTERY_BONUS_POINTS = 500;

    /**
     * Tickets paid for finishing a chore a parent had flagged Help Wanted.
     *
     * Deliberately a ticket rather than points. Points are backed by
     * `points_per_dollar` — real money — so a standing "anything I flag pays
     * more" would be a standing pay rise, and the flag would get used less
     * because of it. A ticket costs the household nothing and still buys the
     * things kids actually want out of the Bonus Shop, which makes the flag
     * cheap enough to use on a Tuesday night when the bins genuinely need
     * doing. It is also the fairer currency here: cooldowns are household-wide,
     * so a points bonus on a flagged chore is a race exactly one kid can win.
     */
    public const HELP_WANTED_TICKETS = 1;

    /**
     * XP for one approved chore, flat regardless of what the chore pays — a
     * level measures showing up, not payout size.
     *
     * Weighed against badge rewards (50–400 each): at 25 a kid's level was
     * ~75% badge luck, so sixteen chores and nine chores landed on the same
     * rung. ResetTodayCommand subtracts this same constant when it undoes a
     * day, so the two must never drift apart.
     */
    public const XP_PER_CHORE = 50;

    /** How many finished household days a pace figure averages over. */
    public const PACE_DAYS = 7;

    /**
     * How many chores a Quest Charm lights up.
     *
     * Five rather than one or two because the board is long — a house with
     * twenty open chores and a charm on a single row is a perk a kid has to go
     * hunting for, and most days would never find. Five is enough that the
     * charm changes what the board looks like the moment it is cast, which is
     * the whole reason it is worth a ticket.
     */
    public const CHARM_CHORES = 5;

    /**
     * What a charmed chore pays on top of its own points, as a percentage.
     *
     * Inherited from the quest's old bold card, deliberately: it was the one
     * number in the app kids already understood as "this one is worth more",
     * and half again is big enough to redirect a choice without making an
     * ordinary chore feel like a waste of an afternoon.
     *
     * Computed off base points and added after any wheel multiplier rather
     * than multiplied by it — a 3x spin on a charmed chore would otherwise pay
     * four and a half times face value.
     */
    public const CHARM_BONUS_PERCENT = 50;

    public function __construct(
        private LedgerService $ledger,
        private SpinService $spin,
        private BadgeService $badges,
        private TicketService $tickets,
        private MonsterService $monsters,
        private StreakService $streaks,
        private PetService $pets,
    ) {}

    /**
     * Boards already built this request, by profile id.
     *
     * {@see self::boardFor()} costs a claimantFor() query per chore, and it is
     * asked for far more often than it looks: the Quests page draws it, the
     * adding-up card narrows it, the Quest Charm's blocked reason counts what
     * is left to charm, and Home's Work row reads the cheapest job and the
     * board's span off it. On a twenty-chore household that was one 23-query
     * walk each, three deep on a single render.
     *
     * Safe to hold only because every method that changes what a board says
     * clears it — see {@see self::forgetBoards()} and its callers. The memo
     * lives as long as the instance, which is one `app()` resolution: the same
     * bargain {@see HouseholdService::tonightFor()} makes, for the same reason.
     *
     * @var array<int, Collection<int, array<string, mixed>>>
     */
    private array $boards = [];

    /**
     * Drops the memo. Called by everything that can change a board: a claim, a
     * charm, an approval or rejection, a parent reopening a chore, and the two
     * urgency controls.
     *
     * Deliberately blunt — it forgets every profile's board rather than one
     * kid's, because cooldowns are household-wide and one kid's claim changes
     * what every sibling's board says.
     *
     * Public because the kid shell's Refresh button means exactly this: a kid
     * who suspects a sibling has taken something is asking to be told again,
     * and a cached answer is the one thing that button must never give them.
     * Nothing else needs it — a write through this service clears it already,
     * and a write from another request gets a new instance.
     */
    public function forgetBoards(): void
    {
        $this->boards = [];
    }

    /**
     * Point chores for the board, each annotated with
     * ['chore' => Chore, 'state' => string]. The mystery chore (if any) stays
     * in this list, indistinguishable from the rest — that's the whole point.
     *
     * Nothing is held back any more. The board used to have the day's quest
     * hand cut out of it, which meant the page a kid opens to find work was
     * quietly missing up to five of the jobs on offer.
     */
    public function boardFor(Profile $profile): Collection
    {
        return $this->boards[$profile->id] ??= $this->buildBoardFor($profile);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function buildBoardFor(Profile $profile): Collection
    {
        // Resolved here even though the board no longer needs it for state:
        // this is the call that lazily assigns the day's mystery chore, and
        // dropping it would leave that to whichever page happened to ask first.
        $this->mysteryChoreFor($profile->household);

        $charmed = $this->charmedChoreIdsFor($profile);

        return $profile->household->chores
            ->filter(fn (Chore $chore) => $chore->isAppropriateFor($profile))
            ->map(function (Chore $chore) use ($profile, $charmed) {
                $claimant = $chore->cadence === ChoreCadence::Unlimited
                    ? null
                    : $this->claimantFor($chore);

                return [
                    'chore' => $chore,
                    'state' => $this->stateFrom($profile, $claimant, $this->isExpired($chore)),
                    // Who took it, when that someone isn't this kid. The board
                    // names them so nobody starts scrubbing a bathtub a sibling
                    // already claimed — finding that out at submit time means
                    // the work is already done, for nothing.
                    'takenBy' => $claimant && $claimant->profile_id !== $profile->id
                        ? $claimant->profile
                        : null,
                    // Resolved here so the card can render a countdown without
                    // each one working out the household day for itself.
                    'closesAt' => $this->deadlineFor($chore),
                    // Same again for the flag: the row needs it for both its
                    // badge and its colour, and neither should be re-deriving
                    // the household day per render.
                    'helpWanted' => $this->isHelpWanted($chore),
                    // Per-kid, unlike everything else on this row: a charm is
                    // bought and cast by one child, and a sibling looking at
                    // the same chore sees an ordinary job paying ordinary
                    // points. Passed in as a resolved set rather than queried
                    // per chore — the board is twenty rows and this is one
                    // lookup for all of them.
                    'charmed' => in_array($chore->id, $charmed, true),
                    // What that charm is worth on this row, in points. Resolved
                    // here so the rule lives in one place: the row, the confirm
                    // sheet and claim() all have to quote the same number, and
                    // three copies of `points * percent / 100` is how one of
                    // them ends up rounding differently from the ledger.
                    'charmBonus' => in_array($chore->id, $charmed, true)
                        ? (int) round($chore->points * self::CHARM_BONUS_PERCENT / 100)
                        : 0,
                ];
            })
            // A taken one-time chore leaves the board outright — that's the
            // whole cadence. The exception is the kid whose claim is still
            // pending: they'd otherwise watch the card vanish the instant they
            // tapped it, with nothing to say it went through.
            ->reject(fn (array $entry) => $entry['chore']->isUsedUp() && $entry['state'] !== 'pending')
            // Urgency first, then payout.
            //
            // The top three tiers are the chores that won't wait: one a parent
            // has asked for outright, a one-time chore the first kid to tap
            // takes for good, and anything on a clock. Burying any of them
            // under the daily regulars would hide the very cards worth hurrying
            // for. Everything below is ordered by what it pays, which is the
            // only question left once nothing is urgent.
            //
            // Help wanted outranks the other two because it is the only one a
            // parent aimed: a one-time chore is urgent to whoever wants the
            // points and a deadline is urgent to the clock, but this row is
            // urgent because somebody in the house said so.
            //
            // Sorted after the map, not before it, so the deadline tier can
            // read the 'closesAt' the map already resolved rather than working
            // the household day out a second time.
            ->sortBy(fn (array $entry) => [
                match (true) {
                    $entry['helpWanted'] => 0,
                    $entry['chore']->isOneTime() => 1,
                    $entry['closesAt'] !== null => 2,
                    default => 3,
                },
                // Negated for a descending sort — biggest payout first.
                -$entry['chore']->points,
            ])
            ->values();
    }

    /**
     * Everything this kid has handed in today, newest first.
     *
     * The tally Home's Work row is built from, and it counts a claim rather
     * than an approval: the kid did the job, and a row that only appeared once
     * a parent got round to it would be the app telling them they had done
     * nothing all afternoon. A rejected one stays, because "sent back" is
     * something they need to see.
     *
     * @return Collection<int, ChoreCompletion>
     */
    public function workTodayFor(Profile $profile): Collection
    {
        $clock = HouseholdClock::for($profile->household);

        return ChoreCompletion::where('profile_id', $profile->id)
            ->where('submitted_at', '>=', $clock->startOf($clock->today()))
            ->with('chore')
            ->latest('submitted_at')
            ->get();
    }

    /**
     * One job to point a kid at when they have done nothing yet: the cheapest
     * thing they can still claim.
     *
     * This is the quest's replacement, and deliberately the smallest possible
     * version of it. A board of forty jobs is a decision, and the six-year-old
     * is the one who cannot make it — so something has to say *this one, now*.
     * Cheapest rather than cleverest because cheap is predictable and never
     * intimidating: the answer to "what now" should be the easiest thing in the
     * house, not the most valuable.
     *
     * It suggests and nothing more. Nothing is assigned, nothing expires, and
     * it pays exactly what it says on the board — which is the whole difference
     * between this and the quest.
     */
    public function suggestedChoreFor(Profile $profile): ?Chore
    {
        return $this->boardFor($profile)
            ->filter(fn (array $entry) => $entry['state'] === 'ready')
            ->map(fn (array $entry) => $entry['chore'])
            ->sortBy([['points', 'asc'], ['id', 'asc']])
            ->first();
    }

    /**
     * How much is up for grabs right now: how many claimable chores, and what
     * the cheapest and dearest pay.
     *
     * The footnote under Home's Work row. It is the one line on that page that
     * says the board is bigger than the two jobs they did — read live, so a
     * house that added ten chores this morning says so.
     *
     * @return array{count: int, min: int, max: int}
     */
    public function boardSpanFor(Profile $profile): array
    {
        $points = $this->boardFor($profile)
            ->filter(fn (array $entry) => $entry['state'] === 'ready')
            ->map(fn (array $entry) => (int) $entry['chore']->points);

        return [
            'count' => $points->count(),
            'min' => (int) ($points->min() ?? 0),
            'max' => (int) ($points->max() ?? 0),
        ];
    }

    /**
     * Casts a Quest Charm over the board: up to {@see self::CHARM_CHORES}
     * chores this kid can still claim today start paying
     * {@see self::CHARM_BONUS_PERCENT} more, for this kid alone.
     *
     * Returns the chores it landed on, or an empty collection when there was
     * nothing to charm — which is how the perk knows to refuse and keep the
     * ticket.
     *
     * Random rather than chosen, and that is the whole mechanic. A kid picking
     * which five chores pay half again is not gambling, they are giving
     * themselves a pay rise on the five they were going to do anyway; the
     * charm is worth a ticket precisely because it might light up the bins.
     *
     * Only `'ready'` chores are candidates. Charming a job a sibling has
     * already claimed spends a ticket on a row that can't be tapped, and
     * charming an expired one is worse — it pays out tomorrow, when the chore
     * reopens and the charm has lapsed.
     *
     * @return Collection<int, Chore>
     */
    public function charmBoard(Profile $profile, int $count = self::CHARM_CHORES): Collection
    {
        $already = $this->charmedChoreIdsFor($profile);

        $candidates = $this->boardFor($profile)
            ->filter(fn (array $entry) => $entry['state'] === 'ready')
            ->map(fn (array $entry) => $entry['chore'])
            // A second charm widens the spread rather than doubling up on a
            // chore already lit: nothing stacks, so re-charming the same row
            // would be a ticket that bought nothing.
            ->reject(fn (Chore $chore) => in_array($chore->id, $already, true))
            ->values();

        if ($candidates->isEmpty()) {
            return collect();
        }

        // $count is fewer than a whole charm for a young pet's Good Luck
        // Charm, which lights one chore — see KnackService::charm().
        $picked = $candidates->count() <= $count
            ? $candidates
            : $candidates->random($count);

        $today = HouseholdClock::for($profile->household)->today();
        $now = now();

        // insertOrIgnore rather than create(): the table's unique index on
        // (profile, chore, date) is the no-stacking rule, and a kid who taps
        // "use charm" twice before the first round trip lands would otherwise
        // meet it as a 500. Ignoring the collision is the right answer — the
        // chore is already charmed, which is what they asked for.
        CharmedChore::insertOrIgnore($picked->map(fn (Chore $chore) => [
            'profile_id' => $profile->id,
            'chore_id' => $chore->id,
            'charm_date' => $today->toDateString(),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        // The board this was picked from is now out of date by exactly these
        // rows.
        $this->forgetBoards();

        return $picked->sortByDesc('points')->values();
    }

    /**
     * The chores charmed for this kid today.
     *
     * Keyed to the household day, so a charm lapses at the rollover like the
     * Help Wanted flag and a deadline do — there is nothing to clear and no
     * scheduled job. That expiry is the price of the mechanic: a charm that
     * kept until it was spent would make the ticket a savings account, and the
     * point of it is that today's board looks different.
     *
     * @return array<int, int>
     */
    public function charmedChoreIdsFor(Profile $profile): array
    {
        return CharmedChore::where('profile_id', $profile->id)
            ->whereDate('charm_date', HouseholdClock::for($profile->household)->today())
            ->pluck('chore_id')
            ->all();
    }

    public function isCharmed(Profile $profile, Chore $chore): bool
    {
        return in_array($chore->id, $this->charmedChoreIdsFor($profile), true);
    }

    /**
     * Whether this kid has already been paid the charm on this chore today.
     *
     * Read off the completion's own `charm_bonus` rather than inferred from
     * "have they done this chore today", so a charm cast *after* an ordinary
     * claim still pays on the next one — the first claim recorded a zero, and
     * a zero is not a payment.
     *
     * A rejected claim doesn't count. Nothing was earned by work a parent sent
     * back, so redoing it is owed the bonus the board promised the first time.
     */
    private function charmPaidToday(Profile $profile, Chore $chore): bool
    {
        $clock = HouseholdClock::for($profile->household);

        return ChoreCompletion::where('profile_id', $profile->id)
            ->where('chore_id', $chore->id)
            ->where('status', '!=', CompletionStatus::Rejected)
            ->where('charm_bonus', '>', 0)
            ->where('submitted_at', '>=', $clock->startOf($clock->today()))
            ->exists();
    }

    /**
     * What a charm adds to this chore for this kid, in points, or zero.
     *
     * Resolved from the chore's live points rather than stored when the charm
     * was cast, so a parent repricing a chore mid-afternoon can't leave a bonus
     * behind that matches nothing on screen. It stops being live the moment the
     * work is claimed — see {@see self::claim()}, which freezes it into
     * `points_awarded` like every other bonus paid at claim time.
     */
    public function charmBonusFor(Profile $profile, Chore $chore): int
    {
        if (! $this->isCharmed($profile, $chore)) {
            return 0;
        }

        return (int) round($chore->points * self::CHARM_BONUS_PERCENT / 100);
    }

    /**
     * The chore randomly picked as today's household-wide mystery bonus —
     * lazily assigned (like the wheel's spin result) the first time it's
     * needed each day, then persisted so it stays the
     * same chore for everyone, all day, no matter how many times it's
     * looked up.
     *
     * Picked only from chores open to any age (so the youngest kid always
     * has a fair shot at it) that don't already have a claimant today —
     * picking one that's already been completed would make the "reveal"
     * moment meaningless before it even started.
     *
     * Chores with a parent-written hint win the draw outright, so the Bonus
     * Shop's mystery hint always has something to sell. Only when none of the
     * eligible chores has a hint does the pick fall back to the whole pool.
     */
    public function mysteryChoreFor(Household $household): ?Chore
    {
        $today = HouseholdClock::for($household)->today();

        $existing = $this->mysteryOn($household, $today);

        if ($existing) {
            return $existing->chore;
        }

        $chore = $this->drawMysteryChore($household);

        if (! $chore) {
            return null;
        }

        DailyMystery::create([
            'household_id' => $household->id,
            'mystery_date' => $today,
            'chore_id' => $chore->id,
        ]);

        return $chore;
    }

    /**
     * The mystery drawn for a given household day, if one has been. Unlike
     * mysteryChoreFor() it never draws one itself — approval reads the day the
     * work was submitted against, and a lookup that far after the fact must not
     * conjure a pick for a day that never had one.
     */
    private function mysteryOn(Household $household, Carbon $date): ?DailyMystery
    {
        return DailyMystery::where('household_id', $household->id)
            ->whereDate('mystery_date', $date)
            ->first();
    }

    /**
     * Who won today's mystery bonus, or null while it's still up for grabs.
     *
     * Reads the settled winner rather than whoever holds a claim: a pending
     * claim is a kid saying they did it, and the whole point of moving the
     * award to approval is that saying so isn't enough.
     */
    public function mysteryFinderFor(Household $household): ?Profile
    {
        return $this->mysteryTodayFor($household)?->foundBy;
    }

    /**
     * Today's draw itself, for callers that need more than who won it — the
     * Quests page stamps the card with the moment it was found. Never draws one:
     * see mysteryOn(). A page that wants the chore calls mysteryChoreFor().
     */
    public function mysteryTodayFor(Household $household): ?DailyMystery
    {
        return $this->mysteryOn($household, HouseholdClock::for($household)->today());
    }

    /**
     * Swaps today's mystery for a different eligible chore. Returns null when
     * there's nothing to swap to, or when the swap would be unfair to someone.
     */
    public function rerollMysteryChore(Household $household): ?Chore
    {
        $this->forgetBoards();

        $today = HouseholdClock::for($household)->today();

        $existing = $this->mysteryOn($household, $today);

        if ($existing) {
            // The race is over and the bonus is paid. Moving the finish line
            // now would hang a second +500 on a different chore the same day.
            if ($existing->isFound()) {
                return null;
            }

            $claimant = $this->claimantFor($existing->chore);

            // Only a *pending* claim blocks the swap: that kid has done the
            // work and is waiting on a parent, and swapping the chore out from
            // under them would take a bonus they've already earned. An approved
            // claim that won nothing is the opposite case — the chore is on
            // cooldown for the whole household, so nobody can win today's bonus
            // on it any more, and refusing here would leave the parent stuck
            // with a dead mystery for the rest of the day.
            if ($claimant?->status === CompletionStatus::Pending) {
                return null;
            }
        }

        $chore = $this->drawMysteryChore($household, $existing?->chore_id);

        if (! $chore) {
            return null;
        }

        if ($existing) {
            $existing->chore_id = $chore->id;
            $existing->save();
        } else {
            DailyMystery::create([
                'household_id' => $household->id,
                'mystery_date' => $today,
                'chore_id' => $chore->id,
            ]);
        }

        return $chore;
    }

    /**
     * Picks a chore that may serve as the mystery, applying every fairness
     * rule. Shared by the daily draw and the parent's reroll so the two can't
     * drift apart on what counts as eligible.
     */
    private function drawMysteryChore(Household $household, ?int $excludeChoreId = null): ?Chore
    {
        $eligible = $this->mysteryCandidates($household)
            ->reject(fn (Chore $chore) => $excludeChoreId !== null && $chore->id === $excludeChoreId);

        $hinted = $eligible->filter(fn (Chore $chore) => filled($chore->hint));

        $choreId = ($hinted->isNotEmpty() ? $hinted : $eligible)->pluck('id')->all();

        return empty($choreId) ? null : Chore::find(Arr::random($choreId));
    }

    /**
     * Every chore that could be the mystery chore right now, by the draw's own
     * fairness rules — the one list both the draw and a pet's Sniffer read,
     * so a sniff can never rule in a chore the draw would have ruled out.
     *
     * Whether a chore has a hint is left out on purpose. The draw prefers
     * hinted chores, and a sniff that only ever kept hinted ones would give
     * the mystery away to anyone who noticed.
     *
     * @return Collection<int, Chore>
     */
    public function mysteryCandidates(Household $household): Collection
    {
        return $household->chores
            ->filter(fn (Chore $chore) => $chore->min_age === null)
            // Unlimited-cadence chores are always freely repeatable by
            // everyone — that's fundamentally at odds with "first one to
            // find it wins," so they're never in the running.
            ->reject(fn (Chore $chore) => $chore->cadence === ChoreCadence::Unlimited)
            // A spent one-time chore isn't on anyone's board to find.
            ->reject(fn (Chore $chore) => $chore->isUsedUp())
            // Nor is a closed one — hiding the bonus behind a chore nobody can
            // claim any more means nobody wins it today.
            ->reject(fn (Chore $chore) => $this->isExpired($chore))
            ->reject(fn (Chore $chore) => $this->claimantFor($chore) !== null)
            ->values();
    }

    /** Whether this kid has already bought today's mystery hint. */
    public function hasBoughtMysteryHint(Profile $profile): bool
    {
        return MysteryHintPurchase::where('profile_id', $profile->id)
            ->whereDate('hint_date', HouseholdClock::for($profile->household)->today())
            ->exists();
    }

    /**
     * The hint for today's mystery chore, but only for a kid who has paid for
     * it — hints are per-kid so one sibling buying doesn't clue in the rest.
     */
    public function mysteryHintFor(Profile $profile): ?string
    {
        if (! $this->hasBoughtMysteryHint($profile)) {
            return null;
        }

        return $this->mysteryChoreFor($profile->household)?->hint;
    }

    /** Records the purchase. Returns the revealed hint, or null if there's nothing to reveal. */
    public function buyMysteryHint(Profile $profile): ?string
    {
        $chore = $this->mysteryChoreFor($profile->household);

        if (! $chore || blank($chore->hint)) {
            return null;
        }

        MysteryHintPurchase::firstOrCreate([
            'profile_id' => $profile->id,
            'hint_date' => HouseholdClock::for($profile->household)->today(),
        ]);

        return $chore->hint;
    }

    /**
     * The completion that currently "holds" a chore for its cadence window.
     *
     * Household-wide, not per-kid: the dishes only need doing once, so whoever
     * claims a daily chore first takes it off everyone's board until the
     * cadence resets. Pending and approved both count, since claiming — not
     * approval — is what wins the race. A rejected claim doesn't, so the chore
     * reopens on its own.
     *
     * A one-time chore is the exception to the clock: its boundary is the
     * used_at stamp rather than a cadence window, so it stays held for as long
     * as it takes a parent to put it back rather than reopening overnight.
     *
     * A parent reopening the chore releases everything claimed before they did
     * it — see reopen().
     */
    public function claimantFor(Chore $chore): ?ChoreCompletion
    {
        $clock = HouseholdClock::for($chore->household);
        $boundary = match ($chore->cadence) {
            ChoreCadence::Weekly => $clock->startOf($clock->today()->subDays(6)),
            ChoreCadence::Once => $chore->used_at,
            default => $clock->startOf($clock->today()),
        };

        // An unused one-time chore is free by definition — and clearing the
        // stamp is exactly how a rejection or a reactivation releases it,
        // without having to reach back and rewrite old completions.
        if ($boundary === null) {
            return null;
        }

        return ChoreCompletion::where('chore_id', $chore->id)
            ->where(function ($query) use ($boundary) {
                $query->where('status', CompletionStatus::Pending)
                    ->orWhere(function ($approved) use ($boundary) {
                        $approved->where('status', CompletionStatus::Approved)
                            ->where('decided_at', '>=', $boundary);
                    });
            })
            // Putting a chore back on the board means exactly this: whoever
            // did it last no longer holds it. Applied to pending claims too —
            // a claim still waiting on approval keeps its points either way,
            // it just stops being the reason nobody else can vacuum.
            //
            // Strictly after, because these stamps only carry to the second: a
            // parent approving a chore and reopening it in the same breath is
            // ordinary, and the reopen has to win that tie or it does nothing.
            ->when(
                $chore->reopened_at !== null,
                fn ($query) => $query->where('submitted_at', '>', $chore->reopened_at),
            )
            ->with('profile')
            ->oldest('submitted_at')
            ->first();
    }

    /**
     * The last time anyone actually did this chore, whatever its cadence says
     * about availability now. Rejected claims don't count — nothing was done.
     */
    public function lastCompletionFor(Chore $chore): ?ChoreCompletion
    {
        return ChoreCompletion::where('chore_id', $chore->id)
            ->where('status', '!=', CompletionStatus::Rejected)
            ->with('profile')
            ->latest('submitted_at')
            ->first();
    }

    /**
     * Whether a parent's deadline has closed this chore for the rest of the
     * household day. The clock lives here rather than on the model for the
     * same reason claimantFor() does — the model shouldn't have to know how a
     * household's day is drawn.
     */
    /**
     * The completion holding a chore when it belongs to somebody else.
     *
     * The board row and the confirm sheet both need "who took it, if it wasn't
     * you" and neither wants the Unlimited special case spelled out again — an
     * unlimited chore is never held by anyone, however many people have done
     * it today.
     */
    public function claimantOtherThan(Chore $chore, Profile $profile): ?ChoreCompletion
    {
        if ($chore->cadence === ChoreCadence::Unlimited) {
            return null;
        }

        $claimant = $this->claimantFor($chore);

        return $claimant && $claimant->profile_id !== $profile->id ? $claimant : null;
    }

    /**
     * Whether a parent is currently asking for this job — the flag, resolved
     * against the household day rather than the calendar one.
     */
    public function isHelpWanted(Chore $chore): bool
    {
        $clock = HouseholdClock::for($chore->household);

        return $chore->isHelpWantedAt($clock->startOf($clock->today()));
    }

    public function isExpired(Chore $chore): bool
    {
        $clock = HouseholdClock::for($chore->household);

        return $chore->hasExpiredAt(now(), $clock->startOf($clock->today()));
    }

    /** The live deadline to count down to, or null when the chore has none. */
    public function deadlineFor(Chore $chore): ?Carbon
    {
        $clock = HouseholdClock::for($chore->household);

        return $chore->closesAt(now(), $clock->startOf($clock->today()));
    }

    /**
     * Why a chore is or isn't claimable right now, for the parent's board.
     *
     * The kids' side answers this per-kid — see stateFor() — because a chore
     * someone else is holding reads differently to one you're holding
     * yourself. A parent is asking about the household: is this job up for
     * grabs, who took it, and when does it come back.
     *
     * @return array{
     *     available: bool,
     *     reason: 'ready'|'claimed'|'pending'|'expired'|'used_up',
     *     claimant: ?ChoreCompletion,
     *     freesAt: ?Carbon,
     *     lastDone: ?ChoreCompletion,
     * }
     */
    public function availabilityFor(Chore $chore): array
    {
        $lastDone = $this->lastCompletionFor($chore);

        $claimant = $chore->cadence === ChoreCadence::Unlimited
            ? null
            : $this->claimantFor($chore);

        if ($claimant !== null) {
            return [
                'available' => false,
                // A one-time chore reads as spent rather than as on cooldown:
                // nothing but a parent brings it back, and saying "claimed"
                // would imply a clock that isn't running.
                'reason' => match (true) {
                    $chore->isOneTime() => 'used_up',
                    $claimant->status === CompletionStatus::Pending => 'pending',
                    default => 'claimed',
                },
                'claimant' => $claimant,
                'freesAt' => $this->cooldownEndsAt($chore, $claimant),
                'lastDone' => $lastDone,
            ];
        }

        // Same precedence as stateFrom(): a claim outranks a deadline, so this
        // only reports a closed chore once nobody is holding it.
        if ($this->isExpired($chore)) {
            $clock = HouseholdClock::for($chore->household);

            return [
                'available' => false,
                'reason' => 'expired',
                'claimant' => null,
                // Deadlines bind for the household day they land in, so an
                // expired one lifts on its own at the next rollover.
                'freesAt' => $clock->startOf($clock->today()->copy()->addDay()),
                'lastDone' => $lastDone,
            ];
        }

        return [
            'available' => true,
            'reason' => 'ready',
            'claimant' => null,
            'freesAt' => null,
            'lastDone' => $lastDone,
        ];
    }

    /**
     * When a held chore comes back on its own, or null when nothing but a
     * parent will bring it back.
     *
     * Measured from the claim that's holding it rather than from today, so a
     * weekly chore approved on Tuesday says Tuesday-plus-seven however long
     * anyone stares at the screen.
     */
    private function cooldownEndsAt(Chore $chore, ChoreCompletion $claimant): ?Carbon
    {
        // One-time chores have no cadence to reopen them; unlimited ones never
        // hold in the first place, so neither has a cooldown to end.
        if ($chore->cadence === ChoreCadence::Once || $chore->cadence === ChoreCadence::Unlimited) {
            return null;
        }

        // A pending claim is held by the claim, not by the clock — it lifts
        // when a parent decides on it, whenever that turns out to be.
        if ($claimant->status === CompletionStatus::Pending) {
            return null;
        }

        $clock = HouseholdClock::for($chore->household);

        // decided_at, because that's the stamp claimantFor() measures the
        // cadence window against.
        $day = $clock->dayFor($claimant->decided_at ?? $claimant->submitted_at);

        return $clock->startOf($day->copy()->addDays($chore->cooldownDays()));
    }

    /**
     * Puts a deadline on a chore and tells the kids it's running.
     *
     * The point of a deadline is the race — a parent who needs a job done
     * tonight offering it up for one last shot first — so setting one is
     * pointless if nobody hears about it until they next happen to open the
     * board.
     */
    public function setDeadline(Chore $chore, Carbon $at): void
    {
        $this->forgetBoards();

        $chore->expires_at = $at;
        $chore->save();

        $local = $at->copy()->setTimezone($chore->household->timezone)->format('g:i A');

        $kids = Profile::where('household_id', $chore->household_id)
            ->where('role', ProfileRole::Kid)
            ->get();

        // Best-effort, exactly as in claim(): a parent setting a deadline must
        // never fail because a push couldn't be queued or delivered.
        try {
            Notification::send($kids, new ChoreClosingSoon(
                'Beat the clock!',
                "{$chore->name} closes at {$local} — grab it before it's gone.",
            ));
        } catch (Throwable $e) {
            Log::error('Closing-soon notification failed for chore deadline.', [
                'chore_id' => $chore->id,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Flags a chore as the one that needs doing, and tells the kids.
     *
     * The board can only ever say "here is everything you could do". This is
     * the one control that says "here is what we actually need", which is the
     * thing a parent standing in a messy kitchen wants to be able to say — and
     * saying it silently, to a page nobody has open, says nothing at all. Same
     * reasoning as {@see self::setDeadline()}, and the same best-effort
     * handling: flagging must never fail because a push couldn't be sent.
     *
     * Re-flagging an already-flagged chore re-stamps it and sends again. That
     * is deliberate — it is how a parent renews the ask the following day, and
     * the alternative (a silent no-op) would look like a broken button.
     */
    public function flagHelpWanted(Chore $chore): void
    {
        $this->forgetBoards();

        $chore->help_wanted_at = now();
        $chore->save();

        $kids = Profile::where('household_id', $chore->household_id)
            ->where('role', ProfileRole::Kid)
            ->get();

        $tickets = self::HELP_WANTED_TICKETS;
        $reward = $tickets === 1 ? 'a bonus ticket' : "{$tickets} bonus tickets";

        try {
            Notification::send($kids, new HelpWantedPosted(
                'Help wanted!',
                "{$chore->name} needs doing — first one to finish it earns {$reward}.",
            ));
        } catch (Throwable $e) {
            Log::error('Help-wanted notification failed for chore flag.', [
                'chore_id' => $chore->id,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Takes the flag off — the job got done another way, or it turned out not
     * to be urgent after all.
     *
     * This never claws back a ticket. A claim already in flight was made while
     * the flag was up and carries its own `help_wanted` stamp (see
     * {@see self::claim()}), so clearing here changes what the board asks for
     * next, not what work already done was worth.
     */
    public function clearHelpWanted(Chore $chore): void
    {
        $this->forgetBoards();

        $chore->help_wanted_at = null;
        $chore->save();
    }

    /** Lifts a deadline, putting the chore back on its ordinary cadence. */
    public function clearDeadline(Chore $chore): void
    {
        $this->forgetBoards();

        $chore->expires_at = null;
        $chore->save();
    }

    /**
     * How a chore reads on a kid's board.
     *
     * Cooldowns are household-wide: a once-a-day chore is done once by the
     * family, not once per kid. That makes the mystery chore's "first one to
     * find it wins" exclusivity the ordinary rule rather than a special case,
     * which is why there's no separate branch for it here.
     */
    public function stateFor(Profile $profile, Chore $chore): string
    {
        // No cooldown and no waiting on a prior claim — several kids can do
        // an unlimited chore, repeatedly, on the same day. A deadline still
        // applies, which is why this no longer short-circuits to 'ready'.
        $claimant = $chore->cadence === ChoreCadence::Unlimited
            ? null
            : $this->claimantFor($chore);

        return $this->stateFrom($profile, $claimant, $this->isExpired($chore));
    }

    /**
     * Shared by stateFor() and boardFor() so the board can name the claimant
     * without looking it up a second time — and so the two can't drift.
     *
     * A claim outranks a deadline: someone who got there before it landed has
     * earned the chore, and telling them "time's up" over their own pending
     * claim would read as the work being thrown away.
     */
    private function stateFrom(Profile $profile, ?ChoreCompletion $claimant, bool $expired): string
    {
        if ($claimant === null) {
            return $expired ? 'expired' : 'ready';
        }

        // 'pending' is the kid's own claim awaiting approval; anyone else
        // holding it just means the chore is spoken for.
        return $claimant->profile_id === $profile->id && $claimant->status === CompletionStatus::Pending
            ? 'pending'
            : 'done';
    }

    /**
     * The mystery bonus is deliberately absent here — see awardMysteryBonus().
     * points_awarded is what the kid has earned so far, and until a parent has
     * signed the work off that is the chore's own points and nothing more.
     */
    public function claim(Profile $profile, Chore $chore): ChoreCompletion
    {
        $this->forgetBoards();

        $multiplier = $this->spin->multiplierFor($profile, $chore);
        $aim = $this->aimFor($profile->household, $chore);

        // Added after the multiplier rather than multiplied by it, and frozen
        // into points_awarded here: the charm was cast on the board this kid
        // was looking at when they decided which job to do, so a charm that
        // lapses overnight must not change what today's work turned out to be
        // worth. Same rule as `help_wanted` below and `struck_weak_point`.
        //
        // Paid once per chore per household day, and that guard is not optional:
        // ChoreCadence::Unlimited has no cooldown at all, so without it a
        // charmed unlimited chore pays half again on every submission, all day,
        // for one ticket. Exactly the hole awardHelpWantedTicket() closes for
        // the flag.
        $charmBonus = $this->charmPaidToday($profile, $chore)
            ? 0
            : $this->charmBonusFor($profile, $chore);

        // Not for the bonus — for the assignment. The draw excludes chores that
        // already have a claimant, so a day whose first mystery lookup happened
        // *after* this claim could never pick this chore, and the kid would be
        // racing for something they'd already ruled themselves out of. Making
        // sure the pick exists before the completion does keeps them in it.
        $this->mysteryChoreFor($profile->household);

        $completion = ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $profile->id,
            'status' => CompletionStatus::Pending,
            'points_awarded' => $chore->points * $multiplier + $charmBonus,
            // Frozen here for the same reason struck_weak_point is: the flag
            // is why this chore may have been picked over another, so a parent
            // clearing it before they get round to approving must not reach
            // back and cancel the ticket the work had already earned.
            'help_wanted' => $this->isHelpWanted($chore),
            // Inside points_awarded already; recorded on its own so the next
            // claim of the same chore today can see that the charm has been
            // spent. See charmPaidToday().
            'charm_bonus' => $charmBonus,
            'submitted_at' => now(),
            ...$aim,
        ]);

        // First come, first served: the chore is spoken for the moment it's
        // tapped. Stamped with the completion's own timestamp rather than a
        // second now(), so claimantFor() can't miss the claim it's marking by
        // a microsecond and read the chore as used-up-by-nobody.
        if ($chore->isOneTime()) {
            $chore->used_at = $completion->submitted_at;
            $chore->save();

            // Claiming is the only thing that edits a chore mid-request, and
            // anything already holding the household's chores is holding the
            // pre-claim copy of this one. Dropping the cached relation is what
            // makes the re-render straight after a claim read the chore as
            // taken rather than still up for grabs.
            $profile->household->unsetRelation('chores');
        }

        $parents = Profile::where('household_id', $profile->household_id)
            ->where('role', ProfileRole::Parent)
            ->get();

        // Best-effort: a kid's claim must never fail because the parent's
        // push notification couldn't be queued or delivered.
        try {
            Notification::send($parents, new ParentApprovalNeeded(
                'Chore ready for approval',
                "{$profile->name} finished {$chore->name}.",
            ));
        } catch (Throwable $e) {
            Log::error('Parent approval notification failed for chore claim.', [
                'completion_id' => $completion->id,
                'exception' => $e,
            ]);
        }

        return $completion;
    }

    /**
     * Whether this claim caught the monster's weak point, settled here at the
     * moment the kid commits rather than later when a parent gets round to it.
     *
     * Deliberately frozen. The weak point is the reason a kid may have picked
     * this chore over another, so one a parent swaps this evening must not
     * reach back and halve what the work was worth when it was chosen. Same
     * rule {@see self::awardMysteryBonus()} follows, for the same reason.
     *
     * @return array{struck_weak_point: bool}
     */
    private function aimFor(Household $household, Chore $chore): array
    {
        // Rolls this week's weak point if nobody has looked yet, so the first
        // kid through the door plays by the same board as the last.
        $monster = $this->monsters->rotateWeakness($household);

        return [
            'struck_weak_point' => $monster !== null && $this->monsters->isWeakPoint($monster, $chore),
        ];
    }

    /**
     * Chores this kid has finished before, for the board's "Done before" chip.
     *
     * A query, not a column — `chore_completions` already knows. Scoped to
     * **approved** completions on purpose: a pending claim is work a parent
     * hasn't looked at yet, and a chip promising "you've done this one" should
     * mean somebody agreed you had. Ever, not today; the point of the chip is
     * a kid reaching for something familiar.
     *
     * @return array<int, int> Chore ids
     */
    public function choresDoneBefore(Profile $profile): array
    {
        return ChoreCompletion::where('profile_id', $profile->id)
            ->where('status', CompletionStatus::Approved)
            ->distinct()
            ->pluck('chore_id')
            ->all();
    }

    /**
     * Points this kid has banked so far today — what the daily target on the
     * Quests page is measured against.
     *
     * Pending completions count. The work is done as far as the kid is
     * concerned, and a bar that slid backwards while a parent hadn't got round
     * to approving would punish them for someone else's inbox. A rejected one
     * drops back out, which is what sending something back means everywhere.
     */
    public function pointsEarnedToday(Profile $profile): int
    {
        $clock = HouseholdClock::for($profile->household);

        return (int) ChoreCompletion::where('profile_id', $profile->id)
            ->where('status', '!=', CompletionStatus::Rejected)
            ->where('submitted_at', '>=', $clock->startOf($clock->today()))
            ->sum('points_awarded');
    }

    /** Average points a day this kid has actually been banking lately. */
    public function dailyPace(Profile $profile, int $days = self::PACE_DAYS): float
    {
        return $this->paceFor([$profile->id], $profile->household, $days);
    }

    /** The same figure for every kid in the household added together. */
    public function householdDailyPace(Household $household, int $days = self::PACE_DAYS): float
    {
        $kidIds = $household->profiles()
            ->where('role', ProfileRole::Kid)
            ->pluck('id')
            ->all();

        return $this->paceFor($kidIds, $household, $days);
    }

    /**
     * Approved points per day over the last $days *finished* household days.
     *
     * Today is deliberately outside the window: it is a partial day, and a
     * planner that told a kid at breakfast they were averaging nothing would
     * be wrong in the discouraging direction. Approved only — a pace is what
     * has really landed, not what has been asked for.
     *
     * @param  array<int, int>  $profileIds
     */
    private function paceFor(array $profileIds, Household $household, int $days): float
    {
        if ($profileIds === [] || $days < 1) {
            return 0.0;
        }

        $clock = HouseholdClock::for($household);
        $today = $clock->today();

        $points = (int) ChoreCompletion::whereIn('profile_id', $profileIds)
            ->where('status', CompletionStatus::Approved)
            ->where('submitted_at', '>=', $clock->startOf($today->copy()->subDays($days)))
            ->where('submitted_at', '<', $clock->startOf($today))
            ->sum('points_awarded');

        return $points / $days;
    }

    public function approve(ChoreCompletion $completion, Profile $approver): void
    {
        $this->forgetBoards();

        // The approvals screen only ever lists pending items, so this is a
        // guard rather than a real path — but approving twice would credit
        // the ledger twice, which is not something to leave to chance.
        if ($completion->status === CompletionStatus::Approved) {
            return;
        }

        $completion->status = CompletionStatus::Approved;
        $completion->decided_at = now();
        $completion->decided_by_profile_id = $approver->id;
        $completion->save();

        $profile = $completion->profile;
        $household = $profile->household;

        // Before the ledger and before the goal math below, both of which read
        // points_awarded — the bonus has to be part of the single entry this
        // approval writes, not a second one bolted on afterwards.
        $this->awardMysteryBonus($completion, $profile, $household);

        // Its own currency, so unlike the mystery bonus this touches nothing
        // the ledger or the goal maths below will read — but it belongs beside
        // it all the same: both settle what this particular chore turned out
        // to be worth, and both are decided by a parent signing off rather
        // than by a kid tapping.
        $ticketed = $this->awardHelpWantedTicket($completion, $profile, $household);

        $this->ledger->record(
            $household,
            $profile,
            LedgerKind::Earn,
            $completion->points_awarded,
            "{$profile->name} — {$completion->chore->name}",
            $completion,
        );

        $profile->xp += self::XP_PER_CHORE;
        $profile->save();

        // Whichever pet is out grows with the kid's work — or their egg cracks.
        // Only the one out: a pet put away stays exactly as big as it was left.
        $petNews = $this->pets->grow($profile);

        // The whole of the family-goal side of an approval. Damage, the
        // leaderboard under each bar, the kill and the cards announcing it all
        // come out of this one call — there is no second tally kept alongside
        // it, which is the point.
        $this->monsters->strike($household, $completion);

        // Before badges, not after — the streak_3/7/14 badges read the
        // profile's streak, so it has to be current by the time they run.
        //
        // Every approval is offered: any approved chore earns the day, so
        // whichever one a kid happens to hand in is a reason to recompute.
        // StreakService decides whether it actually changes anything — see
        // StreakService::recordApproval().
        $this->streaks->recordApproval($completion, $profile);

        $this->badges->evaluate($profile);
        $this->badges->evaluateHouseholdGoal($household);

        // After badges, so a level crossed by badge XP is caught in the same
        // pass. Idempotent, so the badge path having already synced is fine.
        $this->tickets->syncLevelTickets($profile);

        // Last, and reading points_awarded after awardMysteryBonus() has had
        // its say — the number in the kid's pocket is the number they should
        // be told about. Best-effort, like every other push in the app: an
        // approval must never fail because a notification couldn't be sent.
        try {
            $profile->notify(new ChoreReviewed(
                'Signed off!',
                "+{$completion->points_awarded} points for {$completion->chore->name}."
                    .($ticketed ? ' Plus '.self::HELP_WANTED_TICKETS.' bonus '.Str::plural('ticket', self::HELP_WANTED_TICKETS).' for helping out!' : '')
                    .($petNews ? ' '.$petNews : ''),
            ));
        } catch (Throwable $e) {
            Log::error('Chore reviewed notification failed for approval.', [
                'completion_id' => $completion->id,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Pays the Help Wanted ticket, if this approval earned one.
     *
     * Read off the completion's own `help_wanted` stamp rather than the chore's
     * current flag, so the answer is "was this asked for when they took it"
     * rather than "is it still being asked for now" — a parent clearing the
     * flag between the claim and the sign-off must not quietly cancel a reward
     * the kid was already promised on the board.
     *
     * @return bool whether a ticket was actually paid, so the approval's
     *              notification can say so
     */
    private function awardHelpWantedTicket(ChoreCompletion $completion, Profile $profile, Household $household): bool
    {
        if (! $completion->help_wanted) {
            return false;
        }

        $clock = HouseholdClock::for($household);
        $day = $clock->dayFor($completion->submitted_at);

        // One ticket per chore per household day, whoever gets there first.
        //
        // Household-wide cooldowns make this a guard on most cadences, but
        // ChoreCadence::Unlimited has no cooldown at all — without this, a
        // flagged unlimited chore is a ticket printer for anyone willing to
        // submit it repeatedly. The flag is an ask for one job to get done, and
        // it is answered once.
        $alreadyPaid = ChoreCompletion::where('chore_id', $completion->chore_id)
            ->where('id', '!=', $completion->id)
            ->where('status', CompletionStatus::Approved)
            ->where('help_wanted', true)
            ->where('submitted_at', '>=', $clock->startOf($day))
            ->where('submitted_at', '<', $clock->startOf($day->copy()->addDay()))
            ->exists();

        if ($alreadyPaid) {
            return false;
        }

        $this->tickets->record(
            $profile,
            TicketKind::HelpWanted,
            self::HELP_WANTED_TICKETS,
            "Helped out — {$completion->chore->name}",
            $completion,
        );

        return true;
    }

    /**
     * Settles the mystery race, if this approval is what won it.
     *
     * The bonus used to be baked into points_awarded by claim(), and the kid's
     * page called the race off claimantFor() — which counts a *pending* claim.
     * Between them that meant tapping "Mark it done" was enough: a kid could
     * submit every chore on the board and read straight off their own screen
     * which one carried the bonus, having had none of it checked by anyone. The
     * race is now decided by a parent signing the work off, which is the only
     * event in the app that means the chore actually got done.
     *
     * Resolved against the household day the work was *submitted* in, not the
     * one the approval lands in. A chore found at bedtime and approved over
     * breakfast is still that day's find — keying it to the approval would let
     * a parent's timing quietly cost a kid the bonus they won.
     */
    private function awardMysteryBonus(ChoreCompletion $completion, Profile $profile, Household $household): void
    {
        $clock = HouseholdClock::for($household);
        $mystery = $this->mysteryOn($household, $clock->dayFor($completion->submitted_at));

        if (! $mystery || $mystery->chore_id !== $completion->chore_id) {
            return;
        }

        // A guard rather than a real path — cooldowns are household-wide, so a
        // second completion of the same chore can't reach approval inside the
        // same day. Paying the bonus twice is not worth leaving to that.
        if ($mystery->isFound()) {
            return;
        }

        $mystery->found_by_profile_id = $profile->id;
        $mystery->found_at = now();
        $mystery->save();

        $completion->points_awarded += self::MYSTERY_BONUS_POINTS;
        $completion->save();

        // Queued rather than dispatched: the kid isn't looking at the parent's
        // approvals screen, so the celebration has to wait on their profile
        // until they next open the app. Saved by approve() along with the XP
        // and goal contribution it's about to write.
        $profile->pending_mystery_celebration = $completion->chore->name;
    }

    public function sendBack(ChoreCompletion $completion, Profile $approver): void
    {
        $this->forgetBoards();

        $completion->status = CompletionStatus::Rejected;
        $completion->decided_at = now();
        $completion->decided_by_profile_id = $approver->id;
        $completion->save();

        // Rejecting reopens any other chore on its own; a one-time chore has
        // no cadence to reopen it, so release it here. A parent shouldn't have
        // to go reactivate a chore they just sent back.
        if ($completion->chore->isOneTime()) {
            $completion->chore->used_at = null;
            $completion->chore->save();
        }

        // Pointed at the board rather than Home: this one comes with something
        // to do, and the whole point of sending work back is that the kid is
        // meant to go and do it again.
        try {
            $completion->profile->notify(new ChoreReviewed(
                'Sent back',
                "{$completion->chore->name} needs another go.",
                '/kid/quests',
            ));
        } catch (Throwable $e) {
            Log::error('Chore reviewed notification failed for send-back.', [
                'completion_id' => $completion->id,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Puts a chore back up for grabs whatever is currently holding it —
     * a spent one-time claim, a cadence cooldown, or a deadline that has
     * already passed.
     *
     * The completion that took it is deliberately left alone: it was real
     * work, it has already paid out, and it still belongs in the history. All
     * that changes is that it stops being the reason nobody else can claim —
     * we only need vacuuming once a week until someone tips over the chips.
     */
    public function reopen(Chore $chore): void
    {
        $this->forgetBoards();

        $chore->used_at = null;
        $chore->reopened_at = now();

        // A deadline that has already bitten would otherwise close the chore
        // straight back up, making the button look broken. One still ahead of
        // us is left running — the race a parent set up is still worth having.
        if ($this->isExpired($chore)) {
            $chore->expires_at = null;
        }

        $chore->save();
    }
}
