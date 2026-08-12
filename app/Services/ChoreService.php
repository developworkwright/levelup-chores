<?php

namespace App\Services;

use App\Enums\ChoreCadence;
use App\Enums\CompletionStatus;
use App\Enums\LedgerKind;
use App\Enums\MonsterTier;
use App\Enums\ProfileRole;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyMystery;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\MysteryHintPurchase;
use App\Models\Profile;
use App\Models\QuestSkip;
use App\Models\StreakRepair;
use App\Notifications\ChoreClosingSoon;
use App\Notifications\ParentApprovalNeeded;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use LogicException;
use RuntimeException;
use Throwable;

class ChoreService
{
    /**
     * Streak-day milestone => dollar bonus, credited the moment a kid hits it.
     *
     * This is one lap. The chests repeat every {@see self::STREAK_CYCLE_DAYS}
     * days rather than stopping at the last one — a kid who reached day 30 used
     * to find the track had simply run out, which is the worst possible moment
     * to stop paying attention to them.
     */
    public const STREAK_BONUSES = [
        3 => 1,
        5 => 3,
        7 => 5,
        14 => 15,
        30 => 40,
    ];

    /** Length of one lap of the chest track — the last milestone in the map. */
    public const STREAK_CYCLE_DAYS = 30;

    /**
     * What every lap after the first pays, against the base map above.
     *
     * Flat rather than compounding, deliberately. Doubling per lap is the
     * obvious reading of "the chests get bigger each time round" and it is a
     * money bug: points are backed by `points_per_dollar`, so a year-long
     * streak would reach five figures on a single chest. One step up, held
     * there, keeps the day-33 "it got bigger" moment without the tail.
     */
    public const STREAK_REPEAT_MULTIPLIER = 2;

    /** Bonus paid on top of whatever chore gets picked as the day's mystery. */
    public const MYSTERY_BONUS_POINTS = 500;

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

    /** Safety bound on the streak walk-back so odd data can't loop forever. */
    private const MAX_STREAK_DAYS = 366;

    /** How many finished household days a pace figure averages over. */
    public const PACE_DAYS = 7;

    public function __construct(
        private LedgerService $ledger,
        private SpinService $spin,
        private BadgeService $badges,
        private TicketService $tickets,
        private MonsterService $monsters,
    ) {}

    public function questFor(Profile $profile): DailyQuest
    {
        $today = HouseholdClock::for($profile->household)->today();

        $quest = DailyQuest::where('profile_id', $profile->id)
            ->whereDate('quest_date', $today)
            ->first();

        if ($quest) {
            return $this->rerollIfUnavailable($profile, $quest);
        }

        $candidates = $this->questCandidates($profile);

        if ($candidates->isEmpty()) {
            throw new RuntimeException('Household has no chores to assign as a quest.');
        }

        $free = $this->unclaimed($candidates);

        // Prefer something the kid can actually do, but fall back to the full
        // list so they always get a quest — on a day the family has already
        // cleared the board, a blocked quest beats no quest and a crash.
        $choreId = ($free->isNotEmpty() ? $free : $candidates)->random()->id;

        return DailyQuest::create([
            'household_id' => $profile->household_id,
            'profile_id' => $profile->id,
            'chore_id' => $choreId,
            'quest_date' => $today,
        ]);
    }

    /**
     * Swaps today's quest for a different chore. Shared by the kid's ticket
     * purchase and the parent's override button so both behave identically.
     *
     * Returns null when there's nothing to do — the quest is already cleared,
     * or the household has no other eligible chore to offer — which is how
     * callers know not to charge for it.
     */
    public function rerollQuest(Profile $profile): ?DailyQuest
    {
        $quest = $this->questFor($profile);

        if ($quest->completed_at !== null) {
            return null;
        }

        return $this->assignDifferentChore($profile, $quest);
    }

    /**
     * Swaps a quest that's been taken out from under the kid — by a sibling
     * claiming it, or by a parent's deadline closing it.
     *
     * Cooldowns are household-wide, so another kid finishing your quest chore
     * would otherwise leave you unable to clear your quest at all — no streak,
     * and a board that stays gated. Rerolling keeps the day recoverable
     * without anyone having to intervene.
     */
    private function rerollIfUnavailable(Profile $profile, DailyQuest $quest): DailyQuest
    {
        if ($quest->completed_at !== null) {
            return $quest;
        }

        // Checked ahead of the cadence shortcut below: a deadline closes an
        // unlimited chore just as firmly as any other, so an expired one still
        // has to move off the kid's quest.
        if ($this->isExpired($quest->chore)) {
            return $this->assignDifferentChore($profile, $quest) ?? $quest;
        }

        // Unlimited chores never lock, so a claim on one blocks nobody.
        if ($quest->chore->cadence === ChoreCadence::Unlimited) {
            return $quest;
        }

        $claimant = $this->claimantFor($quest->chore);

        // Nobody holds it, or the kid holds it themselves — nothing to fix.
        if (! $claimant || $claimant->profile_id === $profile->id) {
            return $quest;
        }

        return $this->assignDifferentChore($profile, $quest) ?? $quest;
    }

    /** Moves a quest onto a different, actually-doable chore. Null when there isn't one. */
    private function assignDifferentChore(Profile $profile, DailyQuest $quest): ?DailyQuest
    {
        // Swapping onto a chore someone else has already claimed would leave
        // the kid exactly as stuck, so only free chores count here.
        $free = $this->unclaimed($this->questCandidates($profile, $quest->chore_id));

        if ($free->isEmpty()) {
            return null;
        }

        $quest->chore_id = $free->random()->id;
        // Re-hidden on purpose — a new quest deserves the chest animation
        // again, so the swap lands as a fresh reveal rather than a silent
        // relabel. Also means the board stays gated until they open it.
        $quest->revealed_at = null;
        $quest->save();

        return $quest->refresh();
    }

    /** @return Collection<int, Chore> */
    private function questCandidates(Profile $profile, ?int $excludeChoreId = null): Collection
    {
        $clock = HouseholdClock::for($profile->household);

        return $profile->household->chores()
            ->appropriateFor($profile)
            ->questEligible()
            // A spent one-time chore never reopens on its own, so handing one
            // out as a quest — even as the fallback pick below — would dead-end
            // the kid's whole day rather than just their morning.
            ->available()
            // Same reasoning for a chore whose deadline has already passed: it
            // won't reopen today, and a quest that can't be cleared costs a
            // streak day and leaves a gated board gated.
            ->notExpiredAt(now(), $clock->startOf($clock->today()))
            ->when($excludeChoreId !== null, fn ($query) => $query->where('id', '!=', $excludeChoreId))
            ->get();
    }

    /**
     * @param  Collection<int, Chore>  $chores
     * @return Collection<int, Chore>
     */
    private function unclaimed(Collection $chores): Collection
    {
        return $chores->reject(fn (Chore $chore) => $chore->cadence !== ChoreCadence::Unlimited
            && $this->claimantFor($chore) !== null);
    }

    public function isQuestRevealedToday(Profile $profile): bool
    {
        return $this->questFor($profile)->revealed_at !== null;
    }

    public function revealQuest(Profile $profile): DailyQuest
    {
        $quest = $this->questFor($profile);

        if ($quest->revealed_at === null) {
            $quest->revealed_at = now();
            $quest->save();
        }

        return $quest;
    }

    public function isQuestDoneToday(Profile $profile): bool
    {
        return $this->questFor($profile)->completed_at !== null;
    }

    /** Whether this kid has bought today off. */
    public function hasSkippedQuestToday(Profile $profile): bool
    {
        return $this->questSkippedOn($profile, HouseholdClock::for($profile->household)->today());
    }

    private function questSkippedOn(Profile $profile, Carbon $date): bool
    {
        return QuestSkip::where('profile_id', $profile->id)
            ->whereDate('skip_date', $date)
            ->exists();
    }

    /**
     * The day this kid may next buy off, or null when they can do it now.
     *
     * One a week, counted from the household's own week start — the same
     * boundary the monsters' weak points rotate on, so "a new week" means one
     * thing across the app. A day off is meant to be a day off; without the cap
     * a kid with tickets to spare can hold a streak having done nothing for a
     * fortnight, and the streak chests pay real money.
     */
    public function nextQuestSkipDate(Profile $profile): ?Carbon
    {
        $weekStart = HouseholdClock::for($profile->household)->today()->startOfWeek();

        $usedThisWeek = QuestSkip::where('profile_id', $profile->id)
            ->whereDate('skip_date', '>=', $weekStart->toDateString())
            ->exists();

        return $usedThisWeek ? $weekStart->copy()->addWeek() : null;
    }

    /**
     * Buys today off: the board opens without the quest, and the streak counts
     * the day as kept.
     *
     * Returns false when there is nothing to buy — the quest is already
     * cleared, or this week's day off has been taken — so the perk can refuse
     * without being spent. Nothing here pays out: skipping the work skips the
     * points with it, which is the whole trade.
     *
     * The weekly cap is enforced here and not only in `blockedReason()`. A rule
     * that lives only in the thing that greys out a button is a rule that any
     * second caller quietly ignores.
     */
    public function skipQuestToday(Profile $profile): bool
    {
        if ($this->isQuestDoneToday($profile) || $this->nextQuestSkipDate($profile) !== null) {
            return false;
        }

        QuestSkip::create([
            'profile_id' => $profile->id,
            'skip_date' => HouseholdClock::for($profile->household)->today(),
        ]);

        // The chain moves on the day being kept, not on a chore being approved,
        // so this is where a bought day joins the run.
        $this->refreshStreak($profile);

        return true;
    }

    /**
     * Whether the rest of the board is still locked behind today's quest.
     *
     * One place, because three callers ask it — the board itself, the claim
     * path behind it, and the page's own copy — and a gate that three people
     * calculate is a gate that eventually disagrees with itself.
     */
    public function boardIsGated(Profile $profile): bool
    {
        if (! $profile->household->require_quest_first) {
            return false;
        }

        return ! $this->isQuestDoneToday($profile) && ! $this->hasSkippedQuestToday($profile);
    }

    /**
     * Point chores for the board, excluding the assigned quest, each
     * annotated with ['chore' => Chore, 'state' => string]. The mystery
     * chore (if any) stays in this list, indistinguishable from the rest —
     * that's the whole point.
     */
    public function boardFor(Profile $profile): Collection
    {
        $quest = $this->questFor($profile);
        $gated = $this->boardIsGated($profile);

        // Resolved here even though the board no longer needs it for state:
        // this is the call that lazily assigns the day's mystery chore, and
        // dropping it would leave that to whichever page happened to ask first.
        $this->mysteryChoreFor($profile->household);

        return $profile->household->chores
            ->filter(fn (Chore $chore) => $chore->isAppropriateFor($profile))
            ->reject(fn (Chore $chore) => $chore->id === $quest->chore_id)
            ->map(function (Chore $chore) use ($profile, $gated) {
                $claimant = $chore->cadence === ChoreCadence::Unlimited
                    ? null
                    : $this->claimantFor($chore);

                $state = $this->stateFrom($profile, $claimant, $this->isExpired($chore));

                return [
                    'chore' => $chore,
                    // Gating hides the ordinary states behind the main quest,
                    // but never a closed one: "Locked" promises the chore is
                    // yours once the quest is done, which is precisely the
                    // wrong thing to say about one that has already run out.
                    'state' => $gated && $state !== 'expired' ? 'locked' : $state,
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
                ];
            })
            // A taken one-time chore leaves the board outright — that's the
            // whole cadence. The exception is the kid whose claim is still
            // pending: they'd otherwise watch the card vanish the instant they
            // tapped it, with nothing to say it went through.
            ->reject(fn (array $entry) => $entry['chore']->isUsedUp() && $entry['state'] !== 'pending')
            // Urgency first, then payout.
            //
            // The two top tiers are the chores that won't wait: a one-time
            // chore the first kid to tap takes for good, and anything a parent
            // has put on a clock. Burying either under the daily regulars would
            // hide the very cards worth hurrying for. Everything below them is
            // ordered by what it pays, which is the only question left once
            // nothing is expiring.
            //
            // Sorted after the map, not before it, so the deadline tier can
            // read the 'closesAt' the map already resolved rather than working
            // the household day out a second time.
            ->sortBy(fn (array $entry) => [
                match (true) {
                    $entry['chore']->isOneTime() => 0,
                    $entry['closesAt'] !== null => 1,
                    default => 2,
                },
                // Negated for a descending sort — biggest payout first.
                -$entry['chore']->points,
            ])
            ->values();
    }

    /**
     * The chore randomly picked as today's household-wide mystery bonus —
     * lazily assigned (like the daily quest and the wheel's spin result)
     * the first time it's needed each day, then persisted so it stays the
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
     * quest page stamps the card with the moment it was found. Never draws one:
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
        $eligible = $household->chores
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
            ->reject(fn (Chore $chore) => $excludeChoreId !== null && $chore->id === $excludeChoreId);

        $hinted = $eligible->filter(fn (Chore $chore) => filled($chore->hint));

        $choreId = ($hinted->isNotEmpty() ? $hinted : $eligible)->pluck('id')->all();

        return empty($choreId) ? null : Chore::find(Arr::random($choreId));
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

    /** Lifts a deadline, putting the chore back on its ordinary cadence. */
    public function clearDeadline(Chore $chore): void
    {
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
    /**
     * @param  ?MonsterTier  $target  which of the three monsters this is aimed at,
     *                                or null to let the arena pick the obvious one
     */
    public function claim(Profile $profile, Chore $chore, ?MonsterTier $target = null): ChoreCompletion
    {
        $multiplier = $this->spin->multiplierFor($profile, $chore);
        $aim = $this->aimFor($profile->household, $chore, $target);

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
            'points_awarded' => $chore->points * $multiplier,
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
     * What this claim is aiming at, settled here at the moment the kid commits
     * rather than later when a parent gets round to it.
     *
     * Both halves are deliberately frozen. The tier is the kid's answer to
     * "which of the three", and the weak point is the reason they may have
     * picked it — so a monster beaten by a sibling this afternoon, or a weak
     * chore a parent swaps this evening, must not reach back and change what
     * this was worth when it was chosen. Same rule {@see self::awardMysteryBonus()}
     * follows for the same reason.
     *
     * @return array{target_tier: ?MonsterTier, struck_weak_point: bool}
     */
    private function aimFor(Household $household, Chore $chore, ?MonsterTier $target): array
    {
        // Rolls this week's weak points if nobody has looked yet, so the first
        // kid through the door plays by the same board as the last.
        $live = $this->monsters->rotateWeaknesses($household);

        $tier = $target ?? $this->monsters->defaultTier($household);
        $monster = $tier !== null ? $live->get($tier->value) : null;

        return [
            // Kept even when that tier is standing empty: approval spills the
            // hit up to whatever *is* alive, and the kid's choice is a better
            // record of intent than the tier we'd substitute for it here.
            'target_tier' => $tier,
            'struck_weak_point' => $monster !== null && $this->monsters->isWeakPoint($monster, $chore),
        ];
    }

    /**
     * Claiming (not approval) is what unlocks the rest of the board —
     * deliberate, so a kid isn't blocked by a parent's response time. The
     * streak is not touched here; it only moves once a parent approves.
     */
    public function claimQuest(Profile $profile, ?MonsterTier $target = null): DailyQuest
    {
        $quest = $this->questFor($profile);

        if ($quest->completed_at === null) {
            $quest->completed_at = now();
            $quest->save();

            $this->claim($profile, $quest->chore, $target);
        }

        return $quest;
    }

    /**
     * Drops a cached streak that has quietly died.
     *
     * `profiles.streak` is a cache, and only an approval used to refresh it —
     * so a kid who skipped a day carried yesterday's number on their header
     * until they next got something signed off, and a repair bought at that
     * point stapled the old run onto the new one.
     *
     * This is the read-side half, and it's O(1) on purpose so it can run on
     * every page load: a live chain always ends on today or yesterday, so if
     * neither day counts there is nothing left to walk back through.
     *
     * The milestone high-water mark is deliberately left alone — it is what
     * stops a lapse-and-repair cycle from paying every bonus twice.
     */
    public function syncStreak(Profile $profile): void
    {
        if ($profile->streak === 0) {
            return;
        }

        $today = HouseholdClock::for($profile->household)->today();

        if (
            $this->questApprovedOn($profile, $today)
            || $this->questApprovedOn($profile, $today->copy()->subDay())
        ) {
            return;
        }

        $profile->streak = 0;
        $profile->save();
    }

    /**
     * The day a streak repair would actually buy back, or null when there's
     * nothing worth fixing — yesterday already counts, today's quest has
     * already been cleared, or there was no live chain to save.
     */
    public function repairableStreakDate(Profile $profile): ?Carbon
    {
        // A restore is a rescue, not a top-up. Once today's quest is in, the
        // kid is on a fresh one-day streak and the broken day sits behind it;
        // buying it back there would splice a finished run onto a new one and
        // hand over days that were never saved.
        if ($this->isQuestDoneToday($profile)) {
            return null;
        }

        $yesterday = HouseholdClock::for($profile->household)->today()->subDay();

        if ($this->questApprovedOn($profile, $yesterday)) {
            return null;
        }

        // Only a break in a running chain is worth buying back; repairing a
        // day with nothing behind it just manufactures a one-day streak.
        return $this->questApprovedOn($profile, $yesterday->copy()->subDay())
            ? $yesterday
            : null;
    }

    /**
     * What a Streak Restore is worth right now: the day it buys back and the
     * streak the kid would be left holding, so the offer can say so before
     * they spend a perk on it.
     *
     * @return array{date: Carbon, restoresTo: int}|null
     */
    public function repairPreview(Profile $profile): ?array
    {
        $date = $this->repairableStreakDate($profile);

        if (! $date) {
            return null;
        }

        // The bought-back day, plus the unbroken run behind it. Today's quest
        // is undone — that's a precondition for offering this at all — so the
        // restored chain ends on the day being repaired.
        $restoresTo = 1;
        $cursor = $date->copy()->subDay();

        while ($restoresTo < self::MAX_STREAK_DAYS && $this->questApprovedOn($profile, $cursor)) {
            $restoresTo++;
            $cursor = $cursor->copy()->subDay();
        }

        return ['date' => $date, 'restoresTo' => $restoresTo];
    }

    /** Buys back the missed day and recomputes. Null when there was nothing to repair. */
    public function repairStreak(Profile $profile): ?Carbon
    {
        $date = $this->repairableStreakDate($profile);

        if (! $date) {
            return null;
        }

        StreakRepair::create([
            'profile_id' => $profile->id,
            'repaired_date' => $date,
        ]);

        $this->refreshStreak($profile);

        return $date;
    }

    /**
     * Recomputes the streak and pays out any milestone bonus newly crossed.
     * Driven by approval, not by claiming, so a kid can't bank a bonus for
     * work a parent hasn't signed off on.
     *
     * Deliberately a recompute rather than an increment: a parent working
     * through several days of backlog can approve them in any order, and
     * every one of those approvals still has to land on the same number.
     */
    private function refreshStreak(Profile $profile): void
    {
        // A high-water mark, not the current streak. Gating on the live value
        // would let a kid lapse a streak and buy a repair to collect every
        // milestone a second time.
        $paidThrough = $profile->streak_milestone_paid_through;
        $profile->streak = $this->currentStreak($profile);

        $reached = null;

        // Walked day by day rather than over a fixed map, because the track
        // repeats and the milestone days are unbounded. The high-water mark is
        // still what gates a payout, so recomputing — or repairing — can never
        // double-credit one already banked.
        for ($day = $paidThrough + 1; $day <= $profile->streak; $day++) {
            $bonusDollars = $this->streakBonusOn($day);

            if ($bonusDollars === null) {
                continue;
            }

            $this->ledger->record(
                $profile->household,
                $profile,
                LedgerKind::Earn,
                $bonusDollars * $profile->household->points_per_dollar,
                "{$profile->name} — {$day}-day streak bonus (\${$bonusDollars})",
            );

            $reached = $day;
        }

        if ($reached !== null) {
            // Credited immediately above, but the reveal waits for the kid
            // to open the streak chest — that's the surprise moment.
            $profile->pending_streak_chest = $reached;
            $profile->streak_milestone_paid_through = $reached;
        }

        $profile->save();
    }

    /**
     * Consecutive household-days ending today (or yesterday, if today's
     * quest isn't approved yet) that have an approved quest completion.
     */
    private function currentStreak(Profile $profile): int
    {
        $cursor = HouseholdClock::for($profile->household)->today();

        // Today being unapproved doesn't end a streak — it just means the
        // chain is still anchored on yesterday.
        if (! $this->questApprovedOn($profile, $cursor)) {
            $cursor = $cursor->copy()->subDay();
        }

        $streak = 0;

        while ($streak < self::MAX_STREAK_DAYS && $this->questApprovedOn($profile, $cursor)) {
            $streak++;
            $cursor = $cursor->copy()->subDay();
        }

        return $streak;
    }

    /**
     * Whether the quest assigned for a given household-day counts toward the
     * streak — either it was approved, or the day was bought back with a
     * streak repair, which the walk-back treats identically.
     */
    private function questApprovedOn(Profile $profile, Carbon $date): bool
    {
        $repaired = StreakRepair::where('profile_id', $profile->id)
            ->whereDate('repaired_date', $date)
            ->exists();

        // A bought day counts exactly as a repaired one does. Both answer the
        // same question — does this day count without the quest being done —
        // and the two perks differ only in when they are bought: a restore
        // rescues yesterday, a day off is taken in advance.
        if ($repaired || $this->questSkippedOn($profile, $date)) {
            return true;
        }

        $quest = DailyQuest::where('profile_id', $profile->id)
            ->whereDate('quest_date', $date)
            ->first();

        if (! $quest) {
            return false;
        }

        $clock = HouseholdClock::for($profile->household);

        return ChoreCompletion::where('profile_id', $profile->id)
            ->where('chore_id', $quest->chore_id)
            ->where('status', CompletionStatus::Approved)
            ->where('submitted_at', '>=', $clock->startOf($date))
            ->where('submitted_at', '<', $clock->startOf($date->copy()->addDay()))
            ->exists();
    }

    /** Whether this completion is the one that clears its day's main quest. */
    private function isQuestCompletion(ChoreCompletion $completion, Profile $profile): bool
    {
        return $this->questForCompletion($completion, $profile) !== null;
    }

    /** The quest this completion clears, if it clears one. */
    private function questForCompletion(ChoreCompletion $completion, Profile $profile): ?DailyQuest
    {
        $questDate = HouseholdClock::for($profile->household)->dayFor($completion->submitted_at);

        $quest = DailyQuest::where('profile_id', $profile->id)
            ->whereDate('quest_date', $questDate)
            ->first();

        return $quest?->chore_id === $completion->chore_id ? $quest : null;
    }

    /**
     * Marks the pending streak-milestone chest as opened. The bonus was
     * already credited to the ledger when the milestone was reached — this
     * just unlocks the reveal animation and returns what to show for it.
     *
     * @return array{day: int, dollars: int}|null
     */
    public function openStreakChest(Profile $profile): ?array
    {
        $day = $profile->pending_streak_chest;

        if ($day === null) {
            return null;
        }

        $profile->pending_streak_chest = null;
        $profile->save();

        return ['day' => $day, 'dollars' => $this->streakBonusOn($day) ?? 0];
    }

    /**
     * The dollar bonus paid for reaching exactly this streak day, or null on a
     * day that isn't a milestone.
     *
     * Days are absolute across every lap — day 33 is the second lap's first
     * chest — which is what lets `streak_milestone_paid_through` stay a plain
     * high-water mark and keep doing its job unchanged.
     */
    public function streakBonusOn(int $day): ?int
    {
        if ($day < 1) {
            return null;
        }

        // Day 30 closes the first lap rather than opening the second, so a day
        // landing exactly on the boundary belongs to the lap behind it.
        $offset = $day % self::STREAK_CYCLE_DAYS;
        $lap = $offset === 0
            ? intdiv($day, self::STREAK_CYCLE_DAYS)
            : intdiv($day, self::STREAK_CYCLE_DAYS) + 1;

        $offset = $offset === 0 ? self::STREAK_CYCLE_DAYS : $offset;

        $base = self::STREAK_BONUSES[$offset] ?? null;

        if ($base === null) {
            return null;
        }

        return $lap === 1 ? $base : $base * self::STREAK_REPEAT_MULTIPLIER;
    }

    /**
     * Smallest streak-bonus milestone day still ahead of the profile's current
     * streak. Never null now that the track repeats — there is always another
     * chest inside the next lap.
     */
    public function nextStreakMilestone(Profile $profile): int
    {
        $streak = max(0, $profile->streak);

        for ($day = $streak + 1; $day <= $streak + self::STREAK_CYCLE_DAYS; $day++) {
            if ($this->streakBonusOn($day) !== null) {
                return $day;
            }
        }

        // Unreachable while the base map has any entry in it, but a silent
        // wrong answer here would show up as a nonsense number on the track.
        throw new LogicException('No streak milestone found within a full cycle.');
    }

    /**
     * The lap of the chest track this kid is currently working through, and the
     * five milestones on it.
     *
     * Only the current lap is ever shown. The track is otherwise endless, and a
     * rail of fifteen chests says far less about "keep going" than five chests
     * with the next one lit.
     *
     * The lap turns over when the *chest* is opened rather than when the streak
     * ticks past the boundary: hitting day 30 and finding the track already
     * showing days 33-60 would swap the reward out from under the moment that
     * earned it.
     *
     * @return array{lap: int, milestones: array<int, array{day: int, dollars: int, points: int, reached: bool}>}
     */
    public function streakTrackFor(Profile $profile): array
    {
        $streak = max(0, $profile->streak);
        $lap = intdiv($streak, self::STREAK_CYCLE_DAYS) + 1;

        $closingDay = ($lap - 1) * self::STREAK_CYCLE_DAYS;

        if ($lap > 1 && $profile->pending_streak_chest === $closingDay) {
            $lap--;
        }

        $pointsPerDollar = $profile->household->points_per_dollar;
        $offsetToDay = ($lap - 1) * self::STREAK_CYCLE_DAYS;

        $milestones = [];

        foreach (array_keys(self::STREAK_BONUSES) as $offset) {
            $day = $offsetToDay + $offset;
            $dollars = (int) $this->streakBonusOn($day);

            $milestones[] = [
                'day' => $day,
                'dollars' => $dollars,
                'points' => $dollars * $pointsPerDollar,
                'reached' => $streak >= $day,
            ];
        }

        return ['lap' => $lap, 'milestones' => $milestones];
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

        // The whole of the family-goal side of an approval. Damage, the
        // leaderboard under each bar, the kill and the cards announcing it all
        // come out of this one call — there is no second tally kept alongside
        // it, which is the point.
        $this->monsters->strike($household, $completion);

        // Before badges, not after — the streak_3/7/14 badges read the
        // profile's streak, so it has to be current by the time they run.
        if ($this->isQuestCompletion($completion, $profile)) {
            $this->refreshStreak($profile);
        }

        $this->badges->evaluate($profile);
        $this->badges->evaluateHouseholdGoal($household);

        // After badges, so a level crossed by badge XP is caught in the same
        // pass. Idempotent, so the badge path having already synced is fine.
        $this->tickets->syncLevelTickets($profile);
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

        // "Do it again" is the whole point of sending something back, so the
        // quest has to become claimable again too. Leaving completed_at stamped
        // left the kid staring at a dead "Sent back" button with no way to
        // resubmit — a side quest reopened on rejection but the main one, the
        // only one that feeds the streak, was the one that couldn't.
        $quest = $this->questForCompletion($completion, $completion->profile);

        if ($quest) {
            // revealed_at is deliberately left alone — they've already seen
            // which chore it is, and replaying the chest to redo work they
            // just got told off for would read as mockery.
            $quest->completed_at = null;
            $quest->save();
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
