<?php

use App\Enums\CompletionStatus;
use App\Enums\PerkEffect;
use App\Enums\SleepOutcome;
use App\Exceptions\BountyUnavailableException;
use App\Exceptions\InsufficientPointsException;
use App\Exceptions\InsufficientTicketsException;
use App\Exceptions\PerkUnavailableException;
use App\Models\BonusPerk;
use App\Models\Bounty;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Profile;
use App\Services\BonusShopService;
use App\Services\BountyService;
use App\Services\ChoreService;
use App\Services\GratitudeService;
use App\Services\HouseholdClock;
use App\Services\MonsterService;
use App\Services\PerkInventoryService;
use App\Services\SleepService;
use App\Services\SpinService;
use App\Services\StreakService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * The board: today's target, the main quest, and everything else there is to do.
 *
 * It used to be the kid's whole day — the loot tray, the chests, the spin and
 * the boss all sat on it too, which made it a long page a kid had to already
 * know their way around. Those moved to Home, which is organised by *when*
 * rather than by what kind of thing something is. What's left here is the work:
 * the main quest (<x-quest-hero>, shared with Home so there is one
 * implementation of the chest rather than two), the side quests, the gratitude
 * quest, the bounty board and the mystery chore.
 */
new class extends Component
{
    public Profile $profile;

    /**
     * Snapshotted at mount. Arriving with the quest already cleared
     * collapses the hero so the chore board isn't pushed down the page on
     * every tab switch — but clearing it *during* this visit keeps the full
     * card on screen, so the moment still gets its celebration.
     */
    public bool $questDoneOnArrival = false;

    public ?string $perkMessage = null;

    /**
     * Why a tap on the board didn't take. Cooldowns are household-wide, so
     * the board a kid is looking at can go stale between renders — without
     * this the claim just silently no-ops and reads as a broken button.
     */
    public ?string $boardMessage = null;

    /**
     * Why taking a job on the bounty board didn't work. Same reasoning as
     * boardMessage: a sibling can take the same job a second before you do, and
     * a button that silently does nothing explains none of it.
     */
    public ?string $bountyMessage = null;

    /**
     * Why a card couldn't be taken. Same reasoning as boardMessage — the hand
     * is dealt from chores the whole household shares, so a sibling can claim
     * one out from under a kid who is still deciding.
     */
    public ?string $questCardMessage = null;

    public string $search = '';

    /**
     * Board states a kid can't act on right now.
     *
     * 'pending' is deliberately absent — their own claim waiting on a parent
     * is progress, and the card is the only proof the tap landed.
     *
     * @var array<int, string>
     */
    private const UNAVAILABLE_STATES = ['done', 'expired'];

    /**
     * Transient, like the search beside it — a board with half of it taken
     * looks very different an hour later, so this defaults back to showing
     * everything rather than quietly hiding chores that have since reopened.
     */
    public bool $hideUnavailable = false;

    public function toggleUnavailable(): void
    {
        $this->hideUnavailable = ! $this->hideUnavailable;
    }

    /**
     * The three boxes of the gratitude quest. Deferred rather than live —
     * nothing on the page reacts to a half-typed answer, so there's no reason
     * to spend a round trip per keystroke.
     *
     * @var array<int, string>
     */
    public array $gratitude = ['', '', ''];

    public ?string $gratitudeMessage = null;

    public function clearSearch(): void
    {
        $this->search = '';
    }

    /**
     * Livewire re-renders after any action, so the refresh is the round trip
     * itself. Clearing the message matters though: the board is about to show
     * whoever took the chore, and leaving the older wording next to it just
     * says the same thing twice.
     */
    public function refreshBoard(): void
    {
        $this->boardMessage = null;
        $this->bountyMessage = null;
    }

    /**
     * Take a job off the bounty board without leaving the page.
     *
     * The card is a window onto Trades & Jobs, so it only ever fires the claim
     * — the rest of the lifecycle (report done, confirm, send back, cancel)
     * lives on the page built for it, which is what the header link is for.
     */
    public function takeJob(int $bountyId): void
    {
        $this->bountyMessage = null;

        $bounty = Bounty::where('household_id', $this->profile->household_id)->find($bountyId);

        if (! $bounty) {
            $this->bountyMessage = 'That job is no longer there.';

            return;
        }

        try {
            app(BountyService::class)->claim($bounty, $this->profile);
        } catch (BountyUnavailableException|InsufficientPointsException|InsufficientTicketsException $e) {
            // Losing the race is the ordinary outcome here, not an error page:
            // the row refreshes to its new state with the reason beside it.
            $this->bountyMessage = $e->getMessage();

            return;
        }

        // Paying for an offered job moves the balance in the header, so pull it
        // back before the response re-renders with a stale number on it.
        $this->profile->refresh();

        $this->dispatch(
            'celebrate',
            message: $bounty->kind->posterPays()
                ? "It's yours — go and do it!"
                : 'Hired! They will let you know when it is done.',
            motion: 'burst',
            origin: 'tap',
        );
    }

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();

        abort_unless($this->profile->isKid(), 403);

        $this->questDoneOnArrival = app(ChoreService::class)->isQuestDoneToday($this->profile);
    }

    /**
     * Opens the quest chest, putting three cards on the table.
     *
     * No celebration here: the chest no longer reveals anything, it deals. The
     * card that gets announced is the one they choose.
     */
    public function dealHand(): void
    {
        app(ChoreService::class)->dealQuestHand($this->profile);
    }

    /**
     * Takes one of today's cards. The other two have already burned client-side
     * by the time this runs — see <x-quest-cards>.
     */
    public function chooseQuest(int $choreId): void
    {
        $this->questCardMessage = null;

        $service = app(ChoreService::class);
        $quest = $service->chooseQuest($this->profile, $choreId);

        if (! $quest) {
            // Almost always a sibling getting there first. The cards re-render
            // with the claimant named on whichever one went, so the message
            // only has to explain why the tap bounced.
            $this->questCardMessage = "That one just went — pick another card.";

            return;
        }

        // The charm's hand-in roll is deliberately not part of this number:
        // it hasn't happened yet, and quoting a total that later grows is a
        // better surprise than one that appears to shrink.
        $bonus = $service->cardBonusesFor($this->profile)[$quest->chore_id] ?? 0;
        $points = $quest->chore->points * app(SpinService::class)->multiplierFor($this->profile, $quest->chore) + $bonus;

        // Dispatched from the server rather than from the card, so it can only
        // fire on a pick that actually landed.
        $this->dispatch(
            'celebrate',
            style: 'confetti',
            motion: 'burst',
            origin: 'tap',
            tier: 'big',
            hold: 2600,
            card: [
                'accent' => $bonus > 0 ? 'var(--fq-gold)' : 'var(--fq-lime)',
                'sub' => $bonus > 0 ? 'Bold Quest Taken' : "Today's Quest",
                'label' => $quest->chore->name,
                'note' => '+'.number_format($points).' PTS',
            ],
        );
    }

    /**
     * Kept for the paths that need a quest simply decided rather than chosen —
     * see ChoreService::revealQuest().
     */
    public function revealQuest(): void
    {
        app(ChoreService::class)->revealQuest($this->profile);
    }

    public function claimQuest(): void
    {
        $service = app(ChoreService::class);

        // The chest has to be opened before the quest can be claimed.
        if (! $service->isQuestRevealedToday($this->profile)) {
            return;
        }

        $quest = $service->questFor($this->profile);
        $wasDone = $service->isQuestDoneToday($this->profile);
        $boosted = app(SpinService::class)->multiplierFor($this->profile, $quest->chore) > 1;

        $service->claimQuest($this->profile);

        // Read after the claim, which is what settles it. This is the charm's
        // second chance and the reason it can't really be wasted — a hand that
        // looked ordinary can still pay here.
        $charmPayout = $service->charmPayoutFor($this->profile);

        // The streak (and any milestone bonus) now moves on a parent's
        // approval, so don't quote a day count here that hasn't been earned
        // yet — and when it is earned, the chest still does the reveal. A quest
        // that happens to be the mystery chore says nothing about it either,
        // for the same reason claimChore() doesn't.
        if (! $wasDone) {
            if ($boosted) {
                $this->dispatch('celebrate', message: 'Quest cleared! Bonus wheel treat earned.', treat: 'cookie', motion: 'burst', origin: 'tap');
            } else {
                $this->dispatch('celebrate', message: 'Quest cleared! Your streak grows once a parent approves.', motion: 'burst', origin: 'tap');
            }

            // Queued behind the clear rather than folded into it: it's a second
            // piece of news, and it is the whole reason the charm was bought.
            if ($charmPayout > 0) {
                $this->dispatch(
                    'celebrate',
                    message: 'The charm paid out — +'.number_format($charmPayout).' bonus points!',
                    style: 'star',
                    motion: 'burst',
                    origin: 'tap',
                );
            }
        }
    }

        /**
     * The gratitude quest. Both refusals are worth their own wording: one is
     * "you missed a box", the other is "you already did this today", and a
     * button that silently does nothing explains neither.
     */
    /**
     * Answer last night's own-bed card.
     *
     * Every outcome celebrates, including the two that don't light a star —
     * with hearts rather than coins, because a kid who came in at 3am and said
     * so honestly has done the thing this card is actually for.
     */
    public function answerSleep(string $outcome): void
    {
        $choice = SleepOutcome::tryFrom($outcome);

        if (! $choice) {
            return;
        }

        try {
            $result = app(SleepService::class)->record($this->profile, $choice);
        } catch (RuntimeException) {
            // Already answered, or switched off mid-visit. The card re-renders
            // showing what they said, which explains it better than a message.
            return;
        }

        $this->profile->refresh();

        $this->dispatch(
            'celebrate',
            // Finishing a picture is the bigger news and wins the headline; a
            // plain good night still says what it paid, because that is the
            // reward for pressing the button at all. A tapered-out household
            // pays nothing, and "+0 pts" would read as being shortchanged.
            message: match (true) {
                $result['constellation'] && $result['constellationPoints'] > 0 => $result['constellation']->label()
                    .' complete! +'.number_format($result['constellationPoints'] + $result['nightPoints']).' pts',
                (bool) $result['constellation'] => $result['constellation']->label().' complete!',
                // Every answer can pay now, so the headline follows the answer
                // rather than assuming a perfect night.
                $result['nightPoints'] > 0 => $choice->countsAsOwnBed()
                    ? 'A night in your own bed! +'.number_format($result['nightPoints']).' pts'
                    : $choice->response().' +'.number_format($result['nightPoints']).' pts',
                default => $choice->response(),
            },
            style: $result['constellation'] || $result['nightPoints'] > 0 ? 'money' : 'heart',
            motion: 'burst',
            origin: 'tap',
        );
    }

    public function openSleepChest(): void
    {
        $opened = app(SleepService::class)->openChest($this->profile);

        if (! $opened) {
            return;
        }

        $this->profile->refresh();

        $this->dispatch(
            'celebrate',
            message: $opened['nights'].' nights in a row! +'.$opened['tickets'].' tickets',
            style: 'star',
            motion: 'burst',
            origin: 'tap',
        );
    }

    public function logGratitude(): void
    {
        $service = app(GratitudeService::class);

        if ($service->record($this->profile, $this->gratitude)) {
            $this->gratitude = ['', '', ''];
            $this->gratitudeMessage = null;

            // Hearts, not coins — this is the one quest that isn't about
            // earning anything, and the tickets are a thank-you rather than
            // the point of it.
            $this->dispatch(
                'celebrate',
                message: 'Gratitude logged! +'.GratitudeService::TICKETS.' tickets.',
                style: 'heart',
                motion: 'burst',
                origin: 'tap',
            );

            return;
        }

        $this->gratitudeMessage = $service->isAvailable($this->profile)
            ? 'Fill in all three before you hand it in.'
            : "Today's gratitude quest is already done — back tomorrow!";
    }

    public function usePerk(string $effect): void
    {
        $case = PerkEffect::tryFrom($effect);

        if (! $case) {
            return;
        }

        try {
            $outcome = app(PerkInventoryService::class)->use($this->profile, $case);
            $this->perkMessage = null;
            // Same styles as the Bonus Shop's own copy of this — a Streak
            // Restore used from the board and one used from the shop are the
            // same moment and must not celebrate differently.
            $this->dispatch(
                'celebrate',
                message: $outcome,
                style: $case->celebrationStyle(),
                motion: 'burst',
                origin: 'tap',
            );
        } catch (PerkUnavailableException $e) {
            $this->perkMessage = $e->getMessage();
        }
    }

    /**
     * Buys a Quest Charm without leaving the page.
     *
     * The charm is the one perk whose whole value is spent in a window that
     * closes: it can only be cast on a chest that is still shut, and a kid who
     * has to go to the Bonus Shop to buy one comes back to a page they have
     * usually opened by then. Every other perk keeps until you need it, which
     * is why this shortcut exists here and not on all of them.
     *
     * Goes through BonusShopService like the shop does, so the ticket spend,
     * the refusals and the ledger entry are the same on both routes.
     */
    public function buyQuestCharm(): void
    {
        $perk = BonusPerk::where('household_id', $this->profile->household_id)
            ->enabled()
            ->where('effect', PerkEffect::QuestCharm)
            ->first();

        // A parent can switch the charm off from the console, in which case the
        // button isn't rendered and this is a stale tab.
        if (! $perk) {
            return;
        }

        try {
            app(BonusShopService::class)->purchase($this->profile, $perk);
            $this->perkMessage = null;
            $this->dispatch('celebrate', message: "{$perk->name} bought — cast it before you open the chest!", style: 'ticket', motion: 'burst', origin: 'tap');
        } catch (InsufficientTicketsException|PerkUnavailableException $e) {
            $this->perkMessage = $e->getMessage();
        }
    }

    public function claimChore(int $choreId): void
    {
        $this->boardMessage = null;

        $this->completeChore($choreId);
    }

    /**
     * Everything that has to be true before a chore can be claimed, re-checked
     * server-side — never trust a disabled button in the browser.
     *
     */
    private function choreIsClaimable(int $choreId): bool
    {
        $chore = Chore::find($choreId);

        if (! $chore || $chore->household_id !== $this->profile->household_id) {
            return false;
        }

        $service = app(ChoreService::class);
        $quest = $service->questFor($this->profile);

        // stateFor() already accounts for the mystery chore's household-wide
        // (not per-kid) exclusivity, so no special-casing is needed here.
        if ($chore->id === $quest->chore_id || ! $chore->isAppropriateFor($this->profile)) {
            return false;
        }

        // The one rejection worth explaining: nothing about this kid changed,
        // someone else in the house just got there first. Polling shrinks the
        // window but can never close it, so the message has to exist.
        if ($service->stateFor($this->profile, $chore) !== 'ready') {
            $claimant = $service->claimantFor($chore);

            $this->boardMessage = match (true) {
                $claimant && $claimant->profile_id !== $this->profile->id => "{$claimant->profile->name} got to {$chore->name} first!",
                // Worth its own wording: nobody beat them to it, the clock did,
                // and "isn't available" would leave them refreshing for a chore
                // that isn't coming back until tomorrow.
                $service->isExpired($chore) => "Time's up on {$chore->name} — a parent is taking that one. Back tomorrow!",
                default => "{$chore->name} isn't available right now.",
            };

            return false;
        }

        return true;
    }

    private function completeChore(int $choreId): void
    {
        if (! $this->choreIsClaimable($choreId)) {
            return;
        }

        $chore = Chore::findOrFail($choreId);
        $boosted = app(SpinService::class)->multiplierFor($this->profile, $chore) > 1;

        // Nothing here says anything about the mystery chore, deliberately.
        // Announcing the find on the tap told a kid which chore carried the
        // bonus for the price of submitting it, so submitting everything on the
        // board was a way to be told the answer. It's announced when a parent
        // approves the work, by the card the kid shell queues.
        if ($boosted) {
            $this->dispatch('celebrate', message: "{$chore->name} claimed! Bonus wheel treat earned.", treat: 'cookie', motion: 'burst', origin: 'tap');
        } else {
            $this->dispatch('celebrate', message: "{$chore->name} claimed! Waiting on parent.", motion: 'burst', origin: 'tap');
        }

        app(ChoreService::class)->claim($this->profile, $chore);
    }

    /** The monster standing, as the strip and the watcher want it. */
    private function monsterState(): ?array
    {
        $arena = app(MonsterService::class);
        $monster = $arena->rotateWeakness($this->profile->household);

        return $monster ? $arena->stateFor($monster) : null;
    }

    public function with(): array
    {
        $service = app(ChoreService::class);
        $spin = app(SpinService::class);
        $inventory = app(PerkInventoryService::class);

        // A Livewire round trip doesn't pass back through the route middleware
        // that expires a lapsed streak, and this is the page a kid is most
        // likely to be sitting on when the household day rolls over.
        app(StreakService::class)->syncStreak($this->profile);

        $board = $service->boardFor($this->profile);

        // Hidden before the search rather than after, so the search's "2 / 5"
        // counter is measured against the board actually on screen.
        $isUnavailable = fn (array $entry) => in_array($entry['state'], self::UNAVAILABLE_STATES, true);
        $shown = $this->hideUnavailable ? $board->reject($isUnavailable) : $board;

        $quest = $service->questFor($this->profile);
        $questRevealed = $quest->revealed_at !== null;
        $questDone = $quest->completed_at !== null;

        // The hand, and what each card is worth. Built here rather than in the
        // component so the card can name a claimant the same way the board
        // does — this is the one page that already has that lookup to hand.
        $hand = $service->offeredChoresFor($this->profile);
        $cardBonuses = $service->cardBonusesFor($this->profile);

        $questCards = $hand->map(fn (Chore $chore) => [
            'chore' => $chore,
            'points' => $chore->points,
            'bonus' => $cardBonuses[$chore->id] ?? 0,
            'bold' => isset($cardBonuses[$chore->id]),
            'takenBy' => $service->claimantOtherThan($chore, $this->profile)?->profile,
            'expired' => $service->isExpired($chore),
        ]);

        // Claiming the quest sets completed_at immediately, but the points
        // don't land until a parent approves — so "done" and "waiting" are
        // different things and the CTA has to say which one it is. Scoped to
        // today's household day rather than to completed_at, because a sent-back
        // quest clears that stamp and the attempt still has to be findable —
        // that's what turns the CTA into "have another go" instead of a bare
        // "Mark it done" with no hint anything happened.
        $clock = HouseholdClock::for($this->profile->household);

        $questCompletion = ChoreCompletion::where('profile_id', $this->profile->id)
            ->where('chore_id', $quest->chore_id)
            ->where('submitted_at', '>=', $clock->startOf($clock->today()))
            ->latest('submitted_at')
            ->first();

        $questApproved = $questCompletion?->status === CompletionStatus::Approved;
        $questPending = $questCompletion?->status === CompletionStatus::Pending;
        $questSentBack = $questCompletion?->status === CompletionStatus::Rejected;

        $boost = $spin->today($this->profile);
        $questBoosted = $boost && $boost->chore_id === $quest->chore_id;

        $household = $this->profile->household;

        $mysteryChore = $service->mysteryChoreFor($household);

        // Whoever a parent has actually signed off, not whoever tapped first —
        // a pending claim used to name the chore here, which handed the answer
        // to anyone willing to submit the whole board.
        $mysteryToday = $service->mysteryTodayFor($household);
        $mysteryFinder = $mysteryToday?->foundBy;

        $gratitude = app(GratitudeService::class);

        $earnedToday = $service->pointsEarnedToday($this->profile);

        return [
            'quest' => $quest,
            // A quest chore that expires is rerolled by questFor() rather than
            // left to dead-end the day, so this is only ever a live deadline.
            'questClosesAt' => $service->deadlineFor($quest->chore),
            'questRevealed' => $questRevealed,
            // The chest and the pick are separate stamps: the chest stays open
            // across a refresh while the kid is still deciding, which is what
            // stops the 2.6s rattle replaying every time they look at the page.
            'handDealt' => $quest->dealt_at !== null,
            'questCards' => $questCards,
            'questBoldBonus' => $cardBonuses[$quest->chore_id] ?? 0,
            // Null until the charm is cast, and its effect stays null until the
            // chest is opened — the two together are what the cards' charm
            // strip reads.
            'questCharm' => $quest->isCharmed() ? $quest->charm_effect : null,
            'questCharmed' => $quest->isCharmed(),
            'questCharmPayout' => $service->charmPayoutFor($this->profile),
            'questDone' => $questDone,
            'questApproved' => $questApproved,
            'questPending' => $questPending,
            'questSentBack' => $questSentBack,
            'boost' => $boost,
            'questBoosted' => $questBoosted,
            // The bold card's bonus rides on top of any wheel multiplier
            // rather than being multiplied by it — see BOLD_CARD_BONUS_PERCENT.
            'questPoints' => $quest->chore->points * ($questBoosted ? $boost->multiplier : 1)
                + ($cardBonuses[$quest->chore_id] ?? 0)
                + $service->charmPayoutFor($this->profile),
            // Filtered in PHP rather than re-queried — the board is already
            // loaded, and Chore::matches() is the in-memory twin of the
            // scope the parent admin searches with.
            'board' => $shown->filter(fn (array $entry) => $entry['chore']->matches($this->search))->values(),
            'boardTotal' => $shown->count(),
            // Counted off the whole board, never off $shown — it's the number
            // the toggle offers to bring back, so it has to survive being on.
            'unavailableCount' => $board->filter($isUnavailable)->count(),
            'mysteryChore' => $mysteryChore,
            'mysteryFinder' => $mysteryFinder,
            // Stamped on the card so "found" reads as a moment in the day
            // rather than as a state the page has always been in.
            'mysteryFoundAt' => $mysteryToday?->found_at,
            'mysteryHint' => $service->mysteryHintFor($this->profile),
            // Today's only. Everything older lives on the Journal tab — this
            // page is about the day in front of you.
            'gratitudeToday' => $gratitude->todayFor($this->profile),
            // Null unless both the household and this kid have it switched on,
            // which is what keeps the card off every other kid's page.
            'sleepCard' => app(SleepService::class)->cardFor($this->profile),
            // Contextual "use it here" buttons for the perks that act on this
            // page, so a kid doesn't have to go hunting in the shop.
            'heldPerks' => collect([PerkEffect::QuestReroll, PerkEffect::MysteryHint, PerkEffect::QuestCharm])
                ->filter(fn (PerkEffect $effect) => $inventory->holds($this->profile, $effect))
                ->mapWithKeys(fn (PerkEffect $effect) => [$effect->value => [
                    'effect' => $effect,
                    'count' => $inventory->countOf($this->profile, $effect),
                    'blocked' => $inventory->blockedReason($this->profile, $effect),
                ]]),
            // The catalogue row, only when they're holding none — that's the
            // whole condition for offering to sell one. Null when a parent has
            // switched the charm off, which takes the button with it.
            'charmForSale' => $inventory->holds($this->profile, PerkEffect::QuestCharm)
                ? null
                : BonusPerk::where('household_id', $household->id)
                    ->enabled()
                    ->where('effect', PerkEffect::QuestCharm)
                    ->first(),
            'household' => $household,
            // Only the watching monster in the background now — the boss card
            // itself moved to Home. Status only, no replay, nothing marked seen.
            'monsterState' => $this->monsterState(),
            // A window onto Trades & Jobs: only what this kid could take right
            // now, with the link carrying everything else.
            'bountyBoard' => app(BountyService::class)->boardFor($this->profile),
            'bountiesWaiting' => app(BountyService::class)->waitingOn($this->profile),
            // The plan made on the Goal Planner, reported back where the work
            // actually happens — a target you only see on the page you set it
            // on is a wish rather than something to play against.
            'dailyGoal' => $this->profile->daily_points_goal,
            'earnedToday' => $earnedToday,
            'dailyGoalPercent' => $this->profile->daily_points_goal > 0
                ? min(100, (int) round($earnedToday / $this->profile->daily_points_goal * 100))
                : 0,
        ];
    }
}; ?>

<x-kid.shell :profile="$profile" active="quests" refresh-action="refreshBoard">
    {{-- The monster, watching the board being cleared. --}}
    @if ($monsterState)
        <x-monster-watcher :state="$monsterState" />
    @endif

    {{-- One column: what you owe today, the quest that gates everything, and
         only then the board itself and the things hanging off it. --}}
    <div class="flex flex-col gap-4">
        {{-- 1. Today's Target --}}
        <div class="flex flex-wrap items-center gap-4 rounded-[18px] border border-fq-line bg-fq-panel px-4 py-[13px]">
            <h3 class="font-baloo text-[17px] font-bold whitespace-nowrap">Today's Target</h3>

            @if ($dailyGoal)
                <div class="h-[12px] min-w-[180px] flex-1 overflow-hidden rounded-full bg-fq-track">
                    <div
                        class="h-full rounded-full transition-[width] duration-500"
                        style="width:{{ $dailyGoalPercent }}%;background:linear-gradient(90deg, var(--fq-cyan), var(--fq-lime))"
                    ></div>
                </div>

                <span class="font-mono-fq text-[11px] whitespace-nowrap {{ $earnedToday >= $dailyGoal ? 'text-fq-lime' : 'text-fq-text-4' }}">
                    {{ number_format($earnedToday) }} / {{ number_format($dailyGoal) }} PTS
                    @if ($earnedToday >= $dailyGoal)
                        · SMASHED
                    @else
                        · {{ number_format($dailyGoal - $earnedToday) }} TO GO
                    @endif
                </span>
            @else
                <p class="min-w-[180px] flex-1 text-[13px] text-fq-text-5">
                    Pick a points-a-day target and see when you'll get what you're saving for.
                </p>
                <a
                    href="{{ route('kid.goal') }}"
                    wire:navigate
                    class="rounded-[12px] border border-fq-line-3 bg-fq-sunk px-[14px] py-[9px] text-[13px] whitespace-nowrap text-fq-text-2-b transition hover:border-fq-lime hover:text-fq-text"
                >Make a plan</a>
            @endif

            @if ($mysteryChore)
                {{-- The entry point to the mystery card, which used to hang off
                     the loot tray. The tray went to Home with the chests; the
                     mystery chore did not, so its pointer moved up here where a
                     kid still passes it on the way into the board.

                     It scrolls rather than jumps: the card's position is fixed
                     (always under the side quests) and the point is to show a
                     kid where it lives, not to teleport them. --}}
                <button
                    type="button"
                    x-data
                    @click="
                        const card = document.getElementById('mystery-card');
                        if (card) window.scrollTo({ top: card.getBoundingClientRect().top + window.scrollY - 16, behavior: 'smooth' });
                    "
                    class="inline-flex cursor-pointer items-center gap-[7px] rounded-full border border-fq-badge-line px-[11px] py-[5px] font-mono-fq text-[10px] tracking-[0.12em] whitespace-nowrap uppercase transition hover:brightness-125"
                    style="background: var(--fq-tab-active); color: var(--fq-magenta)"
                >
                    <span
                        class="h-[7px] w-[7px] shrink-0 rounded-full"
                        style="background: var(--fq-magenta){{ $mysteryFinder ? '' : '; animation: fq-pulse 1.8s ease-in-out infinite' }}"
                    ></span>
                    {{ $mysteryFinder ? 'Mystery chore found →' : 'Mystery chore live' }}
                </button>
            @endif
        </div>
        {{-- 2. Main quest chest. There is exactly one place on this page to
             tap it, and the card itself is shared with Home, which opens the
             same chest at the top of its own page — see <x-quest-hero>. --}}
        <x-quest-hero
            :profile="$profile"
            :household="$household"
            :quest="$quest"
            :quest-done-on-arrival="$questDoneOnArrival"
            :quest-revealed="$questRevealed"
            :hand-dealt="$handDealt"
            :quest-cards="$questCards"
            :quest-charm="$questCharm"
            :quest-charmed="$questCharmed"
            :quest-charm-payout="$questCharmPayout"
            :quest-bold-bonus="$questBoldBonus"
            :quest-points="$questPoints"
            :quest-closes-at="$questClosesAt"
            :quest-done="$questDone"
            :quest-approved="$questApproved"
            :quest-pending="$questPending"
            :quest-sent-back="$questSentBack"
            :quest-card-message="$questCardMessage"
            :boost="$boost"
            :quest-boosted="$questBoosted"
            :charm-for-sale="$charmForSale"
            :held-perks="$heldPerks"
        />
        @if ($perkMessage)
            <div class="rounded-[16px] border border-fq-line-2 bg-fq-sunk px-4 py-3 text-sm text-fq-text-2">
                {{ $perkMessage }}
            </div>
        @endif

        {{-- The own-bed card, above gratitude because it asks about last night
             and the morning is when it makes sense to answer. Absent entirely
             unless a parent has switched it on for this kid.

             The Night Chest used to sit below it as a flat rectangle of its
             own, separated from the run that earns it. It is a rail inside the
             card now, drawn as the actual chest mark. --}}
        @if ($sleepCard)
            <x-sleep-card :card="$sleepCard" />
        @endif

        {{-- 3. Gratitude quest. The one quest that isn't work — nothing for a
             parent to approve, so the tickets land on hand-in. --}}
        <div
            wire:key="gratitude-quest"
            class="rounded-[20px] border p-4"
            style="background: var(--fq-wash-cleared); border-color: color-mix(in srgb, var(--fq-magenta) 40%, transparent)"
        >
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: var(--fq-magenta)">Gratitude Quest</p>
                <span class="inline-flex items-center gap-2 whitespace-nowrap">
                    <span
                        class="rounded-full border border-fq-ticket-line px-[10px] py-1 font-mono-fq text-[10px] text-fq-lime"
                        style="background: var(--fq-ticket-bg)"
                    >+{{ \App\Services\GratitudeService::TICKETS }} TICKETS</span>
                    <span class="font-mono-fq text-[10px] text-fq-text-4 uppercase">{{ $gratitudeToday ? 'Done today' : 'Not done today' }}</span>
                </span>
            </div>

            <h2 class="mt-[6px] font-baloo text-xl font-bold">Today you were grateful for&hellip;</h2>

            @if ($gratitudeToday)
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($gratitudeToday->items as $index => $item)
                        <div class="min-w-[150px] flex-1 rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[13px] py-[11px]">
                            <span class="font-baloo text-[13px] font-extrabold" style="color: var(--fq-magenta)">{{ $index + 1 }}</span>
                            <span class="ml-2 text-sm text-fq-text-2">{{ $item }}</span>
                        </div>
                    @endforeach
                </div>

                <p class="mt-3 text-[13px] text-fq-text-5">
                    A new one opens up tomorrow. Everything you've written is kept in your
                    <a href="{{ route('kid.journal') }}" wire:navigate class="font-semibold underline" style="color: var(--fq-magenta)">Journal</a>.
                </p>
            @else
                @php $slotHints = ['1 · something', '2 · someone', '3 · anything']; @endphp

                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach (range(0, \App\Services\GratitudeService::ITEMS - 1) as $index)
                        <input
                            type="text"
                            wire:model="gratitude.{{ $index }}"
                            wire:keydown.enter="logGratitude"
                            maxlength="{{ \App\Services\GratitudeService::MAX_LENGTH }}"
                            placeholder="{{ $slotHints[$index] ?? 'Something good…' }}"
                            aria-label="Grateful for, number {{ $index + 1 }}"
                            class="min-w-[150px] flex-1 rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[13px] py-[11px] text-sm outline-none focus:border-fq-magenta"
                        >
                    @endforeach
                </div>

                <button
                    type="button"
                    wire:click="logGratitude"
                    wire:loading.attr="disabled"
                    wire:target="logGratitude"
                    class="mt-3 rounded-[14px] px-5 py-[11px] font-baloo text-[15px] font-bold transition hover:brightness-110 disabled:opacity-60"
                    style="background: var(--fq-magenta); color: var(--fq-ink)"
                >Hand it in</button>
            @endif

            @if ($gratitudeMessage)
                <p class="mt-3 text-[13px]" style="color: var(--fq-gold)">{{ $gratitudeMessage }}</p>
            @endif
        </div>

        {{-- 4. Side quests --}}
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-baloo text-xl font-bold">Side Quests</h3>
            {{-- The board no longer waits on the main quest, so there is no
                 lock state left to report — this says what is true instead of
                 labelling the only condition there is. --}}
            <span class="font-mono-fq text-[10px] tracking-[0.14em] uppercase" style="color: var(--fq-lime)">
                Any of them, any order
            </span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Find a chore"
                class="min-w-[160px] flex-1 rounded-[14px] border border-fq-line-2 bg-fq-sunk px-4 py-[10px] text-sm outline-none focus:border-fq-cyan"
            >
            @if (trim($search) !== '')
                <span class="font-mono-fq text-[10px] whitespace-nowrap text-fq-text-4">
                    {{ $board->count() }} / {{ $boardTotal }}
                </span>
                <button
                    type="button"
                    wire:click="clearSearch"
                    class="rounded-[14px] border border-fq-line-3 bg-fq-sunk px-3 py-[10px] text-xs text-fq-text-3"
                >Clear</button>
            @endif

            {{-- Only offered when there's actually something to hide. An
                 always-on switch that does nothing on a clear board is one
                 more control to wonder about. --}}
            @if ($unavailableCount > 0)
                <button
                    type="button"
                    wire:click="toggleUnavailable"
                    class="rounded-[14px] border px-3 py-[10px] text-xs whitespace-nowrap transition"
                    style="{{ $hideUnavailable
                        ? 'border-color: var(--fq-lime); color: var(--fq-lime); background: var(--fq-sunk)'
                        : 'border-color: var(--fq-line-3); color: var(--fq-text-3); background: var(--fq-sunk)' }}"
                >
                    {{ $hideUnavailable
                        ? 'Show '.$unavailableCount.' more'
                        : "Hide {$unavailableCount} I can't do" }}
                </button>
            @endif
        </div>

        @if ($board->isEmpty() && trim($search) !== '')
            <div class="rounded-[18px] border border-dashed border-fq-line-3 bg-fq-panel p-6 text-center text-sm text-fq-text-5">
                Nothing matches "{{ $search }}".
            </div>
        @elseif ($board->isEmpty() && $hideUnavailable)
            {{-- Hiding everything leaves a blank column that reads as a bug.
                 Say where the chores went, and how to get them back. --}}
            <div class="rounded-[18px] border border-dashed border-fq-line-3 bg-fq-panel p-6 text-center text-sm text-fq-text-5">
                Everything else is taken or closed for today.
            </div>
        @endif

        {{-- The backstop for the seconds between polls. Polling narrows that
             window but can't close it, so the tap still has to explain itself
             rather than silently doing nothing. --}}
        @if ($boardMessage)
            <div
                class="flex items-center gap-[10px] rounded-[16px] border px-[14px] py-3"
                style="border-color: var(--fq-ticket-line); background: var(--fq-ticket-bg)"
            >
                <span class="text-[15px] text-fq-lime">&#8635;</span>
                <span class="flex-1 text-sm" style="color: var(--fq-notice-text)">{{ $boardMessage }}</span>
                <button
                    type="button"
                    wire:click="refreshBoard"
                    class="rounded-[11px] border bg-fq-sunk px-[13px] py-[7px] text-xs font-semibold text-fq-lime transition hover:brightness-115"
                    style="border-color: var(--fq-ticket-line)"
                >Refresh</button>
            </div>
        @endif

        {{-- Refreshed when the kid comes back to the page, not on a timer. The
             server scales to zero when idle, so a poll on a tablet left open all
             afternoon would keep it awake and billing for nothing. This fires
             one request at the only moment a stale board can actually mislead
             someone: when they look at it. --}}
        <div
            x-data="{
                last: 0,
                refresh() {
                    if (document.visibilityState !== 'visible') return;

                    // Returning to a tab fires focus and visibilitychange
                    // together; one refresh covers both.
                    if (Date.now() - this.last < 2000) return;

                    this.last = Date.now();
                    $wire.$refresh();
                },
            }"
            x-on:visibilitychange.window="refresh()"
            x-on:focus.window="refresh()"
            class="grid grid-cols-1 gap-3 sm:grid-cols-2"
        >
            @foreach ($board as $entry)
                @php
                    $chore = $entry['chore'];
                    $state = $entry['state'];
                    $takenBy = $entry['takenBy'];
                    $closesAt = $entry['closesAt'];
                    $boosted = $questBoosted === false && $boost && $boost->chore_id === $chore->id;
                    $payout = $chore->points * ($boosted ? $boost->multiplier : 1);
                    $boostColor = $boosted && $boost->multiplier >= 3 ? 'var(--fq-gold)' : 'var(--fq-magenta)';
                    $labels = [
                        'ready' => 'Mark it done',
                        'pending' => 'Pending approval',
                        // "Back tomorrow" on a chore a sibling took reads as
                        // "you already did this" — the one wording that could
                        // send a kid off to redo it. Name them instead.
                        'done' => $takenBy
                            ? $takenBy->name.' got this one'
                            : match ($chore->cadence) {
                                \App\Enums\ChoreCadence::Weekly => 'Back in 7 days',
                                \App\Enums\ChoreCadence::Once => 'Gone for now',
                                default => 'Back tomorrow',
                            },
                        // Not "Locked" and not "Back tomorrow" — the clock ran
                        // out and a parent has it now. Saying so is what makes
                        // the countdown mean anything next time.
                        'expired' => "Time's up",
                    ];
                @endphp
                <div
                    wire:key="chore-{{ $chore->id }}"
                    class="flex flex-col rounded-[18px] border bg-fq-panel p-[15px] {{ $takenBy || $state === 'expired' ? 'opacity-70' : '' }} {{ $chore->isOneTime() || $closesAt ? 'border-2' : 'border border-fq-line' }}"
                    style="{{ $state === 'pending' ? 'border-color: var(--fq-success-border)' : ($closesAt ? 'border-color: color-mix(in srgb, var(--fq-cyan) 55%, transparent)' : ($chore->isOneTime() ? 'border-color: color-mix(in srgb, var(--fq-gold) 55%, transparent); background: var(--fq-wash-gold)' : '')) }}"
                >
                    <div class="flex items-start justify-between gap-2">
                        {{-- The same face the chore wears everywhere else. A
                             board of fourteen identical text rows is unusable
                             to a kid who can't read them; a picture per row is
                             the only thing that makes it scannable. --}}
                        @if ($chore->icon)
                            <span
                                class="mt-[2px] grid h-[34px] w-[34px] shrink-0 place-items-center rounded-[10px] border"
                                style="border-color: var(--fq-line-3);
                                       background: var(--fq-sunk);
                                       color: {{ $takenBy || $state === 'expired' ? 'var(--fq-text-5)' : 'var(--fq-text-3)' }}"
                            >
                                <x-chore-icon :icon="$chore->icon" class="text-[17px]" />
                            </span>
                        @endif

                        <div class="min-w-0 flex-1">
                            {{-- Flagged, not just sorted: a card sitting at the
                                 top of the list only reads as urgent if you can
                                 see why it's there. --}}
                            @if ($chore->isOneTime())
                                <span class="mb-1 inline-block rounded-[8px] px-[10px] py-1 font-mono-fq text-[10px] tracking-[0.14em] uppercase" style="background: color-mix(in srgb, var(--fq-gold) 22%, transparent); color: var(--fq-gold)">
                                    &#9889; One-time
                                </span>
                            @endif
                            <p class="text-[15px] font-semibold {{ $takenBy || $state === 'expired' ? 'line-through decoration-2' : '' }}">{{ $chore->name }}</p>
                            <p class="font-mono-fq text-[10px] text-fq-text-4 uppercase">
                                {{ $chore->cadence->kidLabel() }}
                            </p>
                        </div>
                        <span class="font-baloo text-[17px] font-extrabold whitespace-nowrap" style="color: {{ $takenBy ? 'var(--fq-text-5)' : ($boosted ? $boostColor : 'var(--fq-lime)') }}">
                            +{{ $payout }}
                        </span>
                    </div>

                    {{-- Loud on purpose: this has to be readable at a glance,
                         from across the room, before any work starts. --}}
                    @if ($takenBy)
                        <span class="mt-2 inline-block self-start rounded-[8px] px-[10px] py-1 font-mono-fq text-[10px]" style="background: color-mix(in srgb, var(--fq-gold) 22%, transparent); color: var(--fq-gold)">
                            Taken by {{ $takenBy->name }}
                        </span>
                    @elseif ($closesAt)
                        {{-- The race, spelled out. It has to be the first thing
                             read on the card, because the whole point is
                             deciding to go and do it right now. --}}
                        <x-chore-countdown wire:key="closes-{{ $chore->id }}" :closes-at="$closesAt" class="mt-2" />
                    @elseif ($state === 'expired')
                        <span class="mt-2 inline-block self-start rounded-[8px] px-[10px] py-1 font-mono-fq text-[10px]" style="background: color-mix(in srgb, var(--fq-danger) 20%, transparent); color: var(--fq-danger)">
                            A parent took this one
                        </span>
                    @elseif ($boosted)
                        <span class="mt-2 inline-block self-start rounded-[8px] px-[10px] py-1 font-mono-fq text-[10px]" style="background: color-mix(in srgb, {{ $boostColor }} 28%, transparent); color: {{ $boostColor }}">
                            {{ $boost->multiplier }}x wheel boost
                        </span>
                    @endif

                    <button
                        type="button"
                        @if ($state === 'ready') wire:click="claimChore({{ $chore->id }})" @else disabled @endif
                        class="mt-auto w-full rounded-[12px] pt-[10px] pb-[10px] text-[13px] font-semibold {{ $state === 'ready' ? 'text-fq-bg transition hover:brightness-110' : 'cursor-default text-fq-text-5' }}"
                        style="margin-top: 12px; background: {{ $state === 'ready' ? 'var(--fq-lime)' : 'var(--fq-panel-alt)' }}"
                    >{{ $labels[$state] }}</button>
                </div>
            @endforeach
        </div>

        {{-- 5. Bounty board — a window onto Trades & Jobs showing only what
             this kid could take right now. --}}
        <div wire:key="bounty-board" class="rounded-[20px] border border-fq-line bg-fq-panel p-[18px]">
            <div class="flex flex-wrap items-center justify-between gap-[10px]">
                <h3 class="font-baloo text-lg font-bold">Bounty Board</h3>

                <span class="inline-flex flex-wrap items-center gap-[10px] whitespace-nowrap">
                    @if ($bountiesWaiting > 0)
                        <span
                            class="rounded-full border px-[10px] py-1 font-mono-fq text-[10px] uppercase"
                            style="border-color: color-mix(in srgb, var(--fq-coral) 50%, transparent); background: var(--fq-line); color: var(--fq-coral)"
                        >
                            <span class="sm:hidden">{{ $bountiesWaiting }} waiting</span>
                            <span class="hidden sm:inline">{{ $bountiesWaiting }} waiting on you</span>
                        </span>
                    @endif

                    <a
                        href="{{ route('kid.trades') }}"
                        wire:navigate
                        class="font-mono-fq text-[10px] tracking-[0.12em] uppercase transition hover:text-fq-text"
                        style="color: var(--fq-magenta)"
                    >All Trades &amp; Jobs &rarr;</a>
                </span>
            </div>

            <p class="mt-1 text-sm text-fq-text-2">
                Jobs the others put up. Take one for extra points, or hire someone to do yours.
            </p>

            @if ($bountyMessage)
                <div
                    class="mt-3 rounded-[14px] border px-[14px] py-3 text-sm"
                    style="border-color: var(--fq-ticket-line); background: var(--fq-ticket-bg); color: var(--fq-notice-text)"
                >{{ $bountyMessage }}</div>
            @endif

            <div class="mt-[14px] flex flex-col gap-[10px]">
                @forelse ($bountyBoard as $job)
                    {{-- On an offered job the taker is the one paying, so the
                         button has to know whether they can afford it. On a
                         wanted one the poster's side was held at post. --}}
                    @php $shortfall = $job->kind->posterPays() ? 0 : $job->shortfallFor($profile); @endphp

                    <div wire:key="bounty-{{ $job->id }}" class="rounded-[16px] border border-fq-line bg-fq-sunk px-4 py-[14px]">
                        <div class="flex flex-wrap items-center gap-[9px]">
                            <span class="h-[10px] w-[10px] shrink-0 rounded-full" style="background: {{ $job->poster->color->cssVar() }}"></span>
                            <span class="font-baloo text-[16px] font-bold">{{ $job->poster->name }}</span>
                            <span class="rounded-full border border-fq-line-2 px-[10px] py-1 font-mono-fq text-[9.5px] tracking-[0.1em] whitespace-nowrap text-fq-text-4 uppercase">
                                {{ $job->kind->headline() }}
                            </span>
                            @if ($job->isTargeted())
                                {{-- Only ever rendered to the kid it is aimed
                                     at, so "just for you" is the whole point. --}}
                                <span class="rounded-full px-[10px] py-1 font-mono-fq text-[9.5px] tracking-[0.1em] whitespace-nowrap uppercase" style="background: var(--fq-tab-active); color: var(--fq-magenta)">
                                    Just for you
                                </span>
                            @endif
                            <span class="ml-auto font-mono-fq text-[10px] whitespace-nowrap text-fq-text-5">
                                {{ $job->expires_at->diffForHumans(['parts' => 1, 'syntax' => Carbon\Carbon::DIFF_ABSOLUTE]) }} left
                            </span>
                        </div>

                        <p class="mt-2 text-[15px] leading-[1.35]">{{ $job->description }}</p>

                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <div class="rounded-[14px] border border-fq-line bg-fq-panel px-[14px] py-2">
                                <p class="font-mono-fq text-[9.5px] tracking-[0.14em] text-fq-text-5 uppercase">
                                    {{ $job->kind->posterPays() ? 'You get' : 'You pay' }}
                                </p>
                                <p class="mt-[2px] font-baloo text-[20px] leading-none font-extrabold" style="color: {{ $job->reward_asset->cssVar() }}">
                                    {{ $job->rewardText() }}
                                </p>
                            </div>

                            <div class="ml-auto">
                                @if ($shortfall > 0)
                                    <button type="button" disabled class="cursor-default rounded-[13px] bg-fq-panel-alt px-5 py-[11px] text-[13.5px] font-bold text-fq-text-4">
                                        Need {{ $job->reward_asset->format($shortfall) }}
                                    </button>
                                @else
                                    <button
                                        type="button"
                                        wire:click="takeJob({{ $job->id }})"
                                        class="rounded-[13px] px-5 py-[11px] text-[13.5px] font-bold whitespace-nowrap text-fq-bg transition hover:brightness-110"
                                        style="background: var(--fq-lime)"
                                    >{{ $job->kind->takeLabel() }}</button>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="rounded-[16px] border border-dashed border-fq-line-4 p-5 text-center text-sm text-fq-text-4">
                        Nothing up for grabs. Post a job and see who takes it.
                    </p>
                @endforelse
            </div>
        </div>

        {{-- 6. Mystery chore. Always in this slot, live or found, so the pill
             on Today's Target always scrolls to the same place. --}}
        @if ($mysteryChore)
            <div
                id="mystery-card"
                wire:key="mystery-status"
                class="rounded-[20px] border p-[18px]"
                style="background: var(--fq-wash-violet); border-color: var(--fq-badge-line)"
            >
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: var(--fq-magenta)">Mystery Chore</p>
                    <span class="font-mono-fq text-[10px] whitespace-nowrap text-fq-text-4 uppercase">
                        @if ($mysteryFinder && $mysteryFoundAt)
                            Found · {{ $mysteryFoundAt->copy()->setTimezone($household->timezone)->format('g:i A') }}
                        @elseif ($mysteryFinder)
                            Found
                        @else
                            Live · Unclaimed
                        @endif
                    </span>
                </div>

                @if ($mysteryFinder === null)
                    <h2 class="mt-2 font-baloo text-xl font-bold">One of today's chores is worth a bonus</h2>
                    <p class="mt-1 max-w-[420px] text-sm text-fq-text-2">
                        Nobody knows which one until a parent approves it. First to get it signed off
                        earns +{{ number_format(\App\Services\ChoreService::MYSTERY_BONUS_POINTS) }} pts.
                    </p>

                    @if ($mysteryHint)
                        <div class="mt-3 rounded-[14px] border px-4 py-3" style="border-color: var(--fq-badge-line); background: var(--fq-sunk)">
                            <p class="font-mono-fq text-[10px] tracking-[0.2em] uppercase" style="color: var(--fq-magenta)">Your Hint</p>
                            <p class="mt-1 text-sm text-fq-text-2">{{ $mysteryHint }}</p>
                        </div>
                    @elseif (isset($heldPerks['mystery_hint']))
                        <div class="mt-[14px] flex flex-col items-start gap-1">
                            <x-perk-button :entry="$heldPerks['mystery_hint']" />
                            @if ($heldPerks['mystery_hint']['blocked'])
                                <span class="font-mono-fq text-[10px] text-fq-text-5">{{ $heldPerks['mystery_hint']['blocked'] }}</span>
                            @endif
                        </div>
                    @endif
                @else
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        <div
                            class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[10px] font-baloo text-[15px] font-extrabold text-fq-bg sm:h-[34px] sm:w-[34px] sm:rounded-[11px]"
                            style="background: {{ $mysteryFinder->color->cssVar() }}"
                        >{{ mb_substr($mysteryFinder->name, 0, 1) }}</div>

                        <div class="min-w-[220px] flex-1">
                            {{-- Named for everyone once it's found: the secret
                                 is spent, and knowing which chore it was is half
                                 the fun of losing. Naming it also matters to the
                                 winner, who may have several claims in and no
                                 way to tell which one carried the bonus. --}}
                            <h2 class="font-baloo text-[17px] leading-[1.15] font-bold sm:text-xl">
                                {{ $mysteryFinder->id === $profile->id ? 'You' : $mysteryFinder->name }} found it — {{ $mysteryChore->name }}
                            </h2>
                            <p class="mt-[3px] text-[13px] text-fq-text-2 sm:text-sm">
                                {{ $mysteryFinder->id === $profile->id ? 'You' : 'They' }} banked a
                                +{{ number_format(\App\Services\ChoreService::MYSTERY_BONUS_POINTS) }} pt bonus.
                                A new one hides tomorrow.
                            </p>
                        </div>

                        <span class="font-baloo text-[17px] font-extrabold whitespace-nowrap sm:text-xl" style="color: var(--fq-magenta)">
                            +{{ number_format(\App\Services\ChoreService::MYSTERY_BONUS_POINTS) }}
                        </span>
                    </div>
                @endif
            </div>
        @endif

    </div>
</x-kid.shell>
