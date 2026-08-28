<?php

namespace App\Services;

use App\Enums\ChoreCadence;
use App\Enums\CompletionStatus;
use App\Enums\LedgerKind;
use App\Enums\ProfileRole;
use App\Enums\QuestCharmEffect;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyMystery;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\MysteryHintPurchase;
use App\Models\Profile;
use App\Notifications\ChoreClosingSoon;
use App\Notifications\ChoreReviewed;
use App\Notifications\ParentApprovalNeeded;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

class ChoreService
{
    /** Bonus paid on top of whatever chore gets picked as the day's mystery. */
    public const MYSTERY_BONUS_POINTS = 500;

    /**
     * Cards dealt for the daily quest.
     *
     * Three is the number that makes the deal a decision rather than a
     * formality: two reads as a coin flip and four is more reading than a
     * seven-year-old will do before tapping. A household with fewer than three
     * eligible chores deals a shorter hand rather than repeating one.
     */
    public const HAND_SIZE = 3;

    /**
     * What the biggest card in the hand pays on top of its own points, as a
     * percentage of them.
     *
     * The hand is spread deliberately across the pool's range (see
     * {@see self::dealHand()}), so the top card is always the most work on
     * offer. Without a thumb on the scale the rational move is to take the
     * cheap card every single day, and a choice with one right answer stops
     * being a choice by about the third morning. The bonus is what buys the
     * hard card a reason to exist.
     *
     * Computed off base points and added after any wheel multiplier rather
     * than multiplied by it — a 3x spin landing on a bold card would otherwise
     * pay four and a half times a chore's face value.
     */
    public const BOLD_CARD_BONUS_PERCENT = 50;

    /** What a charm's hand-in roll adds, as a percentage of the chore's points. */
    public const CHARM_PAYOUT_PERCENT = 25;

    /**
     * Odds the charm pays out at hand-in, per hundred, by whether the card
     * they took was already bold.
     *
     * Longer odds on a bold card, and that ordering is doing real work. It
     * consoles the kid who took a plain card — the case the charm most needs
     * to cover — while stopping the two bonuses stacking into a routine
     * double payout on the dearest chore in the hand. The incentive to be
     * brave survives it comfortably: a bold card pays +50% for certain
     * against a plain card's 60% shot at +25%.
     */
    private const CHARM_PAYOUT_ODDS_PLAIN = 60;

    private const CHARM_PAYOUT_ODDS_BOLD = 30;

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

    public function __construct(
        private LedgerService $ledger,
        private SpinService $spin,
        private BadgeService $badges,
        private TicketService $tickets,
        private MonsterService $monsters,
        private StreakService $streaks,
    ) {}

    public function questFor(Profile $profile): DailyQuest
    {
        $today = HouseholdClock::for($profile->household)->today();

        $quest = DailyQuest::where('profile_id', $profile->id)
            ->whereDate('quest_date', $today)
            ->first();

        if ($quest) {
            return $this->rerollIfUnavailable($profile, $this->dealHandIfMissing($profile, $quest));
        }

        $hand = $this->dealHand($profile);

        if ($hand->isEmpty()) {
            throw new RuntimeException('Household has no chores to assign as a quest.');
        }

        return DailyQuest::create([
            'household_id' => $profile->household_id,
            'profile_id' => $profile->id,
            // A placeholder until they pick, not a quest. It is the first card
            // rather than a random one so that anything reading the row before
            // the pick — the wheel's exclusion, a parent's page — is at least
            // reading a card that is genuinely on the table.
            'chore_id' => $hand->first()->id,
            'offered_chore_ids' => $hand->pluck('id')->all(),
            'quest_date' => $today,
        ]);
    }

    /**
     * Deals a hand to a quest row that never had one.
     *
     * Rows written before the hand existed carry a null `offered_chore_ids`,
     * and {@see DailyQuest::offeredChoreIds()} reads that as a one-card hand of
     * whatever they were assigned. That is the right reading for a day already
     * spent — but not for a day still in front of the kid, where it means the
     * chest opens onto a single card and a page that says "pick your quest"
     * offers nothing to pick. Every household that has this ship mid-morning
     * would spend the rest of that day with the mechanic switched off.
     *
     * Deliberately not done in the migration: the deal is age-, cadence- and
     * claim-aware, and a migration that reached into service logic to work all
     * that out would be recording today's rules against a schema change that
     * has to keep meaning the same thing in a year's time.
     *
     * Guarded on the pick, not just on the column. A kid who has already taken
     * their card — or already finished it — has a quest, and re-dealing under
     * them would move it. Those rows keep the single-card reading forever, and
     * with it a null bold bonus, which is what a day dealt before bold cards
     * existed actually paid.
     */
    private function dealHandIfMissing(Profile $profile, DailyQuest $quest): DailyQuest
    {
        if ($quest->offered_chore_ids !== null || $quest->isPicked() || $quest->completed_at !== null) {
            return $quest;
        }

        $hand = $this->dealHand($profile);

        if ($hand->isEmpty()) {
            return $quest;
        }

        $quest->chore_id = $hand->first()->id;
        $quest->offered_chore_ids = $hand->pluck('id')->all();
        // Shut again, so the cards arrive out of a chest rather than appearing
        // under one already open — which is exactly what a kid mid-transition
        // is looking at.
        $quest->dealt_at = null;
        $quest->save();

        return $quest->refresh();
    }

    /**
     * The hand of cards to offer, cheapest first.
     *
     * Spread across the pool's range rather than drawn at random: the pool is
     * sorted by points and split into {@see self::HAND_SIZE} bands, and one
     * chore comes out of each. Three random draws routinely produce three
     * near-identical chores, and a hand of three identical chores is a choice
     * in name only — the spread is what guarantees the kid is always weighing
     * "quick and cheap" against "big and paid for", which is the whole point
     * of dealing cards instead of assigning one.
     *
     * Prefers chores nobody has claimed, but falls back to the full candidate
     * list rather than dealing short — on a day the family has already cleared
     * the board, a blocked quest beats no quest and a crash.
     *
     * @return Collection<int, Chore>
     */
    private function dealHand(Profile $profile, ?int $excludeChoreId = null): Collection
    {
        $candidates = $this->questCandidates($profile, $excludeChoreId);

        if ($candidates->isEmpty()) {
            return collect();
        }

        $free = $this->unclaimed($candidates);
        $pool = $free->isNotEmpty() ? $free : $candidates;

        if ($pool->count() <= self::HAND_SIZE) {
            return $pool->sortBy('points')->values();
        }

        return $pool
            ->sortBy('points')
            ->values()
            ->split(self::HAND_SIZE)
            ->map(fn (Collection $band) => $band->random())
            ->sortBy('points')
            ->values();
    }

    /**
     * Today's cards as chores, cheapest first — what the kid is choosing
     * between.
     *
     * Ids that no longer resolve are dropped, so a chore a parent deleted
     * mid-morning leaves a shorter hand rather than a hole.
     *
     * Still returns the whole hand after the pick, burned cards included. The
     * page renders one card by then, but the bold bonus is a property of the
     * hand the chosen card came out of — resolving it at claim time means
     * knowing what it was up against.
     *
     * @return Collection<int, Chore>
     */
    public function offeredChoresFor(Profile $profile): Collection
    {
        return $this->handFor($profile, $this->questFor($profile));
    }

    /**
     * Every chore today's quest could still turn out to be — the whole hand
     * before the pick, the one chosen card after it.
     *
     * The bonus wheel excludes these. It has always excluded the quest chore,
     * on the grounds that a 3x boost belongs on work the kid took on top of
     * their quest rather than on the quest itself; a hand of three just means
     * there are three answers to "what might the quest be" for as long as the
     * chest is shut.
     *
     * @return array<int, int>
     */
    public function possibleQuestChoreIds(Profile $profile): array
    {
        $quest = $this->questFor($profile);

        return $quest->isPicked() ? [$quest->chore_id] : $quest->offeredChoreIds();
    }

    /** @return Collection<int, Chore> */
    private function handFor(Profile $profile, DailyQuest $quest): Collection
    {
        $byId = $profile->household->chores->keyBy('id');

        return collect($quest->offeredChoreIds())
            ->map(fn (int $id) => $byId->get($id))
            ->filter()
            ->sortBy('points')
            ->values();
    }

    /**
     * What each card in today's hand pays on top of its own points.
     *
     * One card is bold by default — the dearest — at
     * {@see self::BOLD_CARD_BONUS_PERCENT}. A charm can widen that to two
     * cards or the whole hand, or double what the one bold card pays; see
     * {@see QuestCharmEffect}.
     *
     * A hand whose cards all pay the same has no bold card *by default*:
     * nothing in it is braver than anything else, and a bonus nobody chose is
     * just a bonus. A charm overrides that — the tickets were spent, and an
     * arbitrary bold card beats a fizzle a kid paid for.
     *
     * @return array<int, int> chore id => bonus points
     */
    public function cardBonusesFor(Profile $profile): array
    {
        $quest = $this->questFor($profile);
        $hand = $this->handFor($profile, $quest);

        if ($hand->isEmpty()) {
            return [];
        }

        $charm = $quest->charm_effect;
        $flat = $hand->min('points') === $hand->max('points');

        $boldCards = match (true) {
            $charm !== null => $charm->boldCards() ?? $hand->count(),
            $hand->count() < 2, $flat => 0,
            default => 1,
        };

        if ($boldCards < 1) {
            return [];
        }

        $percent = self::BOLD_CARD_BONUS_PERCENT * ($charm?->bonusMultiplier() ?? 1);

        // The hand is already sorted cheapest-first, so reversing gives
        // dearest-first without a second sort deciding ties differently.
        return $hand
            ->reverse()
            ->take($boldCards)
            ->mapWithKeys(fn (Chore $chore) => [
                $chore->id => (int) round($chore->points * $percent / 100),
            ])
            ->all();
    }

    /**
     * The bonus riding on the card this kid actually took, in points — the
     * card's own bold bonus plus whatever the charm settled at hand-in.
     *
     * Resolved from the hand rather than stored on the quest so that a parent
     * editing a chore's points mid-morning can't leave a bonus behind that no
     * longer matches anything on screen.
     */
    public function questBonusFor(Profile $profile): int
    {
        $quest = $this->questFor($profile);

        return ($this->cardBonusesFor($profile)[$quest->chore_id] ?? 0)
            + $this->charmPayoutFor($profile);
    }

    /** The charm's hand-in bonus on the chosen card, in points. */
    public function charmPayoutFor(Profile $profile): int
    {
        $quest = $this->questFor($profile);

        if (! $quest->charm_payout_percent) {
            return 0;
        }

        return (int) round($quest->chore->points * $quest->charm_payout_percent / 100);
    }

    /**
     * Opens the chest and puts the cards on the table.
     *
     * Separate from the pick, and persisted, because they are two taps with a
     * refresh-shaped gap between them: without a stamp of its own the chest
     * would re-close on any re-render before the kid had chosen, and replay a
     * 2.6s animation they had already sat through.
     */
    public function dealQuestHand(Profile $profile): DailyQuest
    {
        $quest = $this->questFor($profile);

        if ($quest->dealt_at === null) {
            $quest->dealt_at = now();
            // The charm resolves as the lid comes up, not when it was cast —
            // the cards flipping over is the moment it has to be visible in.
            if ($quest->isCharmed() && $quest->charm_effect === null) {
                $quest->charm_effect = QuestCharmEffect::roll();
            }

            $quest->save();
        }

        return $quest;
    }

    /**
     * Puts a charm on today's quest. Null when there is nothing to charm.
     *
     * Refuses once the chest is open: a charm bought against cards the kid has
     * already read is not a gamble, it is a purchase. The effect itself is
     * rolled later, by {@see self::dealQuestHand()}.
     */
    public function charmQuest(Profile $profile): ?DailyQuest
    {
        $quest = $this->questFor($profile);

        if ($quest->isCharmed() || $quest->dealt_at !== null || $quest->completed_at !== null) {
            return null;
        }

        $quest->charmed_at = now();
        $quest->save();

        return $quest->refresh();
    }

    /**
     * Settles the charm's second roll, once, as the quest is handed in.
     *
     * Stored rather than recomputed because it is a coin toss: asking twice
     * would give two different answers, and the number a kid was shown on the
     * hero has to be the number that reaches the ledger.
     */
    private function rollCharmPayout(Profile $profile, DailyQuest $quest): void
    {
        if (! $quest->isCharmed() || $quest->charm_payout_percent !== null) {
            return;
        }

        $wasBold = isset($this->cardBonusesFor($profile)[$quest->chore_id]);
        $odds = $wasBold ? self::CHARM_PAYOUT_ODDS_BOLD : self::CHARM_PAYOUT_ODDS_PLAIN;

        // Zero rather than null on a miss: null means "not rolled yet", and
        // the two have to stay tellable apart or a refresh would re-roll it.
        $quest->charm_payout_percent = random_int(1, 100) <= $odds ? self::CHARM_PAYOUT_PERCENT : 0;
        $quest->save();
    }

    /**
     * Takes one of today's cards as the quest, burning the rest.
     *
     * Returns null when the card isn't takeable, which is how the page knows
     * to say why rather than silently doing nothing. Two ways that happens,
     * and they need different wording:
     *
     * - the id isn't in today's hand at all (a stale tab, or a poked request)
     * - a sibling claimed that chore between the deal and the tap, which is
     *   the same race the board already has to explain
     *
     * The two burned cards stay on the side-quest board. They were never
     * withdrawn from it — the board only ever excludes the quest chore — so
     * choosing costs the household nothing in available work, and the burn is
     * drama rather than a penalty.
     */
    public function chooseQuest(Profile $profile, int $choreId): ?DailyQuest
    {
        $quest = $this->questFor($profile);

        // Already chosen. Idempotent for the same card so a double-tap or a
        // replayed request lands on the same quest instead of failing.
        if ($quest->isPicked()) {
            return $quest->chore_id === $choreId ? $quest : null;
        }

        if (! in_array($choreId, $quest->offeredChoreIds(), true)) {
            return null;
        }

        $chore = $profile->household->chores->firstWhere('id', $choreId);

        if (! $chore || $this->isExpired($chore)) {
            return null;
        }

        $claimant = $this->claimantFor($chore);

        if ($chore->cadence !== ChoreCadence::Unlimited && $claimant && $claimant->profile_id !== $profile->id) {
            return null;
        }

        $quest->chore_id = $choreId;
        // Always already set by the time a kid gets here — the cards can't be
        // tapped until the chest has been opened. Stamped defensively anyway:
        // the page keys the chest open on dealt_at, so a pick that somehow
        // arrived without one would leave the hero rendered inside a chest
        // drawn shut.
        $quest->dealt_at ??= now();
        $quest->revealed_at = now();
        $quest->save();

        return $quest->refresh();
    }

    /**
     * Whether any card in today's hand can still be taken.
     *
     * A hand every card of which has been claimed out from under the kid is
     * the unpicked twin of a blocked quest, and needs the same rescue — see
     * {@see self::rerollIfUnavailable()}.
     */
    private function handIsDead(Profile $profile, DailyQuest $quest): bool
    {
        $byId = $profile->household->chores->keyBy('id');

        foreach ($quest->offeredChoreIds() as $id) {
            $chore = $byId->get($id);

            if (! $chore || $this->isExpired($chore)) {
                continue;
            }

            if ($chore->cadence === ChoreCadence::Unlimited) {
                return false;
            }

            $claimant = $this->claimantFor($chore);

            if (! $claimant || $claimant->profile_id === $profile->id) {
                return false;
            }
        }

        return true;
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

        // Before the pick, `chore_id` is a placeholder and the hand is what
        // matters: a sibling taking the placeholder card leaves two perfectly
        // good cards on the table, and re-dealing over it would yank a hand
        // the kid may already be looking at. Only a hand with nothing left in
        // it is stuck, and that is the same stuck a blocked quest is.
        if (! $quest->isPicked()) {
            return $this->handIsDead($profile, $quest)
                ? $this->assignDifferentChore($profile, $quest) ?? $quest
                : $quest;
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

    /**
     * Deals a whole new hand, excluding the chore the quest is currently on.
     * Null when the household has nothing else to offer.
     *
     * This is a re-deal rather than a swap because the quest is a hand now:
     * handing back a single replacement chore would turn the ticket-priced
     * reroll — and the silent rescue of a blocked quest — into the one path
     * that takes the choice away, which is the thing being bought back.
     */
    private function assignDifferentChore(Profile $profile, DailyQuest $quest): ?DailyQuest
    {
        // dealHand() falls back to chores someone else holds when nothing is
        // free. That is right on a fresh deal — a blocked quest beats no quest
        // — and wrong here, where it would leave the kid exactly as stuck as
        // they already were. Checked first so this path refuses instead, which
        // is what tells rerollQuest() to keep the kid's ticket.
        if ($this->unclaimed($this->questCandidates($profile, $quest->chore_id))->isEmpty()) {
            return null;
        }

        $hand = $this->dealHand($profile, $quest->chore_id);

        $quest->chore_id = $hand->first()->id;
        $quest->offered_chore_ids = $hand->pluck('id')->all();
        // Both stamps cleared on purpose — a new hand deserves the chest
        // animation again, so the re-deal lands as a fresh reveal rather than
        // a silent relabel. Also means the board stays gated until they pick.
        //
        // The charm columns are deliberately left alone. A charm survives a
        // re-deal, since it was paid for and the reroll isn't its fault — but
        // an effect already rolled is *not* rolled again, or a kid holding
        // rerolls could spin the charm until it came up "every card bold".
        // dealQuestHand() only rolls into a null effect, so that falls out
        // without a guard here.
        $quest->dealt_at = null;
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

    /**
     * Takes whichever card the quest is currently sitting on, without going
     * through the deal.
     *
     * Kids don't reach this — the page deals and then picks. It is the path
     * for everything that needs a quest simply *decided*: a household with one
     * eligible chore has a one-card hand and nothing to choose between, and
     * tests that only care about a revealed quest shouldn't have to stage a
     * card pick to get one.
     */
    public function revealQuest(Profile $profile): DailyQuest
    {
        $quest = $this->questFor($profile);

        if ($quest->revealed_at === null) {
            $quest->dealt_at ??= now();
            $quest->revealed_at = now();
            $quest->save();
        }

        return $quest;
    }

    public function isQuestDoneToday(Profile $profile): bool
    {
        return $this->questFor($profile)->completed_at !== null;
    }

    /**
     * Point chores for the board, excluding the assigned quest, each
     * annotated with ['chore' => Chore, 'state' => string]. The mystery
     * chore (if any) stays in this list, indistinguishable from the rest —
     * that's the whole point.
     */
    public function boardFor(Profile $profile): Collection
    {
        // Every card still on the table comes off the board, not just the one
        // the quest row currently points at. Before the pick that row holds a
        // placeholder, so keying on it alone would leave two of the three cards
        // sitting below as ordinary side quests — claimable, out from under a
        // kid who is still deciding, and duplicated on screen while they do.
        // After the pick the two burned cards drop back in, which is exactly
        // what the copy on the cards promises.
        $questChoreIds = $this->possibleQuestChoreIds($profile);

        // Resolved here even though the board no longer needs it for state:
        // this is the call that lazily assigns the day's mystery chore, and
        // dropping it would leave that to whichever page happened to ask first.
        $this->mysteryChoreFor($profile->household);

        return $profile->household->chores
            ->filter(fn (Chore $chore) => $chore->isAppropriateFor($profile))
            ->reject(fn (Chore $chore) => in_array($chore->id, $questChoreIds, true))
            ->map(function (Chore $chore) use ($profile) {
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
    /**
     * The completion holding a chore when it belongs to somebody else.
     *
     * The board and the quest cards both need "who took it, if it wasn't you"
     * and neither wants the Unlimited special case spelled out again — an
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
     * @param  int  $bonusPoints  Paid on top of the chore's own points, after
     *                            any wheel multiplier. Only the daily quest's
     *                            bold card uses it — see
     *                            {@see self::BOLD_CARD_BONUS_PERCENT}.
     */
    public function claim(Profile $profile, Chore $chore, int $bonusPoints = 0): ChoreCompletion
    {
        $multiplier = $this->spin->multiplierFor($profile, $chore);
        $aim = $this->aimFor($profile->household, $chore);

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
            'points_awarded' => $chore->points * $multiplier + $bonusPoints,
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
     * Claiming (not approval) is what unlocks the rest of the board —
     * deliberate, so a kid isn't blocked by a parent's response time. The
     * streak is not touched here; it only moves once a parent approves.
     */
    public function claimQuest(Profile $profile): DailyQuest
    {
        $quest = $this->questFor($profile);

        if ($quest->completed_at === null) {
            // Settled before the stamp, in this order: the payout roll reads
            // the card bonuses to know whether the chosen card was bold, and
            // questBonusFor() then has to see the number it wrote. Both go
            // back through questFor(), and a completed quest is one
            // rerollIfUnavailable() stops rescuing — so asking afterwards
            // would be asking about a different quest than the one claimed.
            $this->rollCharmPayout($profile, $quest);

            $bonus = $this->questBonusFor($profile);

            $quest->completed_at = now();
            $quest->save();

            $this->claim($profile, $quest->chore, $bonus);
        }

        return $quest;
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
        //
        // Every approval is offered, not just the quest's: any approved chore
        // earns the day now, so a side quest signed off is as much a reason to
        // recompute as the main one. StreakService decides whether it actually
        // changes anything — see StreakService::recordApproval().
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
                "+{$completion->points_awarded} points for {$completion->chore->name}.",
            ));
        } catch (Throwable $e) {
            Log::error('Chore reviewed notification failed for approval.', [
                'completion_id' => $completion->id,
                'exception' => $e,
            ]);
        }
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

        // Pointed at the board rather than Home: this one comes with something
        // to do, and the whole reason the quest was just reopened above is that
        // the kid is meant to go and do it again.
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
