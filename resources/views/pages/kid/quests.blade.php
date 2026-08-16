<?php

use App\Enums\CompletionStatus;
use App\Enums\MonsterTier;
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
use App\Services\ChestService;
use App\Services\ChoreService;
use App\Services\GratitudeService;
use App\Services\HouseholdClock;
use App\Services\MonsterService;
use App\Services\PerkInventoryService;
use App\Services\SleepService;
use App\Services\SpinService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * The kid's daily home, laid out as the "Loot Tray" handoff specifies: what to
 * do today, what extras are going spare, how the family's boss fight is going,
 * and what is still locked — one column, in that order.
 *
 * The tray at the top is the load-bearing idea. The bonus chest, the streak
 * chest and the wheel are all *extras*, and giving each of them a card of its
 * own put three things at the same visual weight as the one thing that gates
 * the board. Collected into three slots they read as a single row of "what's
 * spare today", which leaves the quest chest as the only hero on the page.
 */
new class extends Component
{
    public Profile $profile;

    /**
     * Snapshotted at mount — NOT recomputed in with() — so opening the
     * streak chest doesn't yank it out of the DOM mid-animation the moment
     * the server call resolves and clears the underlying pending flag.
     */
    public ?int $pendingChestDay = null;

    public ?int $pendingChestPoints = null;

    /**
     * Also snapshotted at mount. Arriving with the quest already cleared
     * collapses the hero so the chore board isn't pushed down the page on
     * every tab switch — but clearing it *during* this visit keeps the full
     * card on screen, so the moment still gets its celebration.
     */
    public bool $questDoneOnArrival = false;

    /**
     * Snapshotted for the same reason as the streak chest — the reveal
     * animation must not be yanked away the moment the roll resolves.
     */
    public bool $dailyChestAvailable = false;

    public ?string $dailyChestPrize = null;

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
     * 'locked' is deliberately absent: with require_quest_first on it covers
     * the entire board, so hiding it would leave a kid staring at nothing and
     * no clue why. 'pending' is absent too — their own claim waiting on a
     * parent is progress, and the card is the only proof the tap landed.
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

        $this->syncChests();

        $this->questDoneOnArrival = app(ChoreService::class)->isQuestDoneToday($this->profile);
    }

    /**
     * Points both chest snapshots at what's true right now. Only ever called
     * when nothing is mid-reveal — see the property docblocks for why these
     * are snapshots in the first place.
     */
    private function syncChests(): void
    {
        $this->pendingChestDay = $this->profile->pending_streak_chest;
        // Milestone bonuses are denominated in dollars, but every other number
        // a kid sees is points — so convert once here and never show dollars.
        // Read through streakBonusOn() rather than off the base map: past day
        // 30 the track repeats and the day no longer indexes it directly.
        $this->pendingChestPoints = $this->pendingChestDay
            ? (app(ChoreService::class)->streakBonusOn($this->pendingChestDay) ?? 0) * $this->profile->household->points_per_dollar
            : null;

        $this->dailyChestAvailable = app(ChestService::class)->isAvailable($this->profile);
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
        // The chest has to be opened before the quest can be claimed.
        if (! app(ChoreService::class)->isQuestRevealedToday($this->profile)) {
            return;
        }

        if ($this->shouldAim()) {
            $this->targetingQuest = true;

            return;
        }

        $this->completeQuest(null);
    }

    private function completeQuest(?MonsterTier $target): void
    {
        $service = app(ChoreService::class);

        if (! $service->isQuestRevealedToday($this->profile)) {
            return;
        }

        $quest = $service->questFor($this->profile);
        $wasDone = $service->isQuestDoneToday($this->profile);
        $boosted = app(SpinService::class)->multiplierFor($this->profile, $quest->chore) > 1;

        $service->claimQuest($this->profile, $target);

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

    public function openDailyChest(): void
    {
        $chest = app(ChestService::class)->open($this->profile);

        // Nothing to open means today's chest was already claimed elsewhere —
        // another tab, or a back-button visit to a page rendered before it was.
        // Re-snapshot instead of revealing a chest with nothing in it: an empty
        // prize card is the one outcome the chest must never show.
        if (! $chest) {
            $this->syncChests();

            return;
        }

        $this->dailyChestPrize = app(ChestService::class)->describe($chest);
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

    public function openStreakChest(): void
    {
        app(ChoreService::class)->openStreakChest($this->profile);
    }

    /**
     * The chore waiting on the kid to say which monster it hits, or null when
     * nothing is mid-choice.
     *
     * Held on the component rather than in the session: it exists for the two
     * seconds between "done" and picking a target, and a refresh in the middle
     * should drop the whole thing rather than resurrect a half-finished tap.
     */
    public ?int $targetingChoreId = null;

    /** True for the quest, which goes through its own claim path. */
    public bool $targetingQuest = false;

    /**
     * Whether finishing something should stop and ask which monster it hits.
     *
     * One monster standing is not a decision, and a picker with one answer on
     * it is how a good idea turns into an extra tap. None standing goes
     * straight through as well — the work still earns its points, it just has
     * nothing to land on.
     */
    private function shouldAim(): bool
    {
        return app(MonsterService::class)->live($this->profile->household)->count() > 1;
    }

    /** The kid choosing which monster the thing they just finished lands on. */
    public function aimAt(int $tier): void
    {
        $target = MonsterTier::tryFrom($tier);
        $choreId = $this->targetingChoreId;
        $wasQuest = $this->targetingQuest;

        $this->cancelAim();

        // A tier that emptied while the picker was open: a sibling can finish
        // the last monster off between the question and the answer.
        if ($target === null || app(MonsterService::class)->at($this->profile->household, $target) === null) {
            $this->boardMessage = 'That one just went down! Pick another and try again.';

            return;
        }

        // Remembered so the picker opens on it next time. A preference, not a
        // commitment — every claim still asks.
        $this->profile->last_monster_tier = $target;
        $this->profile->save();

        if ($wasQuest) {
            $this->completeQuest($target);
        } elseif ($choreId !== null) {
            $this->completeChore($choreId, $target);
        }
    }

    public function cancelAim(): void
    {
        $this->targetingChoreId = null;
        $this->targetingQuest = false;
    }

    public function claimChore(int $choreId): void
    {
        $this->boardMessage = null;

        if (! $this->choreIsClaimable($choreId)) {
            return;
        }

        if ($this->shouldAim()) {
            $this->targetingChoreId = $choreId;

            return;
        }

        $this->completeChore($choreId, null);
    }

    /**
     * Everything that has to be true before a chore can be claimed, re-checked
     * server-side — never trust a disabled button in the browser.
     *
     * Runs twice on a targeted claim: once to decide whether to open the
     * picker, and again when the kid answers. The window between the two is
     * small but it is exactly the window a sibling tapping the same chore lives
     * in, so the second pass is the one that matters.
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
        if (
            $service->boardIsGated($this->profile)
            || $chore->id === $quest->chore_id
            || ! $chore->isAppropriateFor($this->profile)
        ) {
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

    private function completeChore(int $choreId, ?MonsterTier $target): void
    {
        if (! $this->choreIsClaimable($choreId)) {
            return;
        }

        $chore = Chore::findOrFail($choreId);
        $boosted = app(SpinService::class)->multiplierFor($this->profile, $chore) > 1;
        $monster = $target ? app(MonsterService::class)->at($this->profile->household, $target) : null;

        // Nothing here says anything about the mystery chore, deliberately.
        // Announcing the find on the tap told a kid which chore carried the
        // bonus for the price of submitting it, so submitting everything on the
        // board was a way to be told the answer. It's announced when a parent
        // approves the work, by the card the kid shell queues.
        $aimed = $monster ? " Aimed at {$monster->displayName()}." : '';

        if ($boosted) {
            $this->dispatch('celebrate', message: "{$chore->name} claimed! Bonus wheel treat earned.{$aimed}", treat: 'cookie', motion: 'burst', origin: 'tap');
        } else {
            $this->dispatch('celebrate', message: "{$chore->name} claimed! Waiting on parent.{$aimed}", motion: 'burst', origin: 'tap');
        }

        app(ChoreService::class)->claim($this->profile, $chore, $target);
    }

    /**
     * The three monsters as the picker and the strip both want them, keyed by
     * tier.
     *
     * @return array<int, array<string, mixed>>
     */
    private function monsterStates(): array
    {
        $arena = app(MonsterService::class);

        return $arena->rotateWeaknesses($this->profile->household)
            ->map(fn ($monster) => $arena->stateFor($monster))
            ->all();
    }

    public function with(): array
    {
        $service = app(ChoreService::class);
        $spin = app(SpinService::class);
        $inventory = app(PerkInventoryService::class);

        // A Livewire round trip doesn't pass back through the route middleware
        // that expires a lapsed streak, and this is the page a kid is most
        // likely to be sitting on when the household day rolls over.
        $service->syncStreak($this->profile);

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

        $nextMilestone = $service->nextStreakMilestone($this->profile);

        $earnedToday = $service->pointsEarnedToday($this->profile);

        // One lap of the chest track — the one this kid is on. The track
        // repeats every 30 days, so showing all of it would be a rail with no
        // end rather than five chests with the next one lit.
        $streakTrack = $service->streakTrackFor($this->profile);

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
            'heldPerks' => collect([PerkEffect::QuestReroll, PerkEffect::MysteryHint, PerkEffect::StreakRestore, PerkEffect::QuestCharm])
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
            'nextMilestone' => $nextMilestone,
            'streakBonuses' => collect($streakTrack['milestones']),
            'streakLap' => $streakTrack['lap'],
            // Null unless a broken chain is still savable — which stops being
            // true the moment today's quest is cleared, so the offer has to be
            // on the page a kid is looking at when they decide.
            'streakRepair' => $service->repairPreview($this->profile),
            // A count rather than a list. The claim a kid is waiting on already
            // says so on its own card ("Pending approval"), and the number that
            // matters on this page is how much damage is still in the post —
            // which is why it rides on the boss caption.
            'pendingCount' => ChoreCompletion::where('profile_id', $this->profile->id)
                ->where('status', CompletionStatus::Pending)
                ->count(),
            'household' => $household,
            // Status only — no replay, and nothing marked seen. See
            // <x-monster-mini> for why the catch-up belongs to the Goal page.
            'monsterStates' => $this->monsterStates(),
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
            'allUnlocked' => ! $service->boardIsGated($this->profile),
            'questSkipped' => $service->hasSkippedQuestToday($this->profile),
        ];
    }
}; ?>

<x-kid.shell :profile="$profile" active="quests" refresh-action="refreshBoard">
    {{-- The long game, watching the board being cleared. --}}
    @if ($monsterStates[App\Enums\MonsterTier::Three->value] ?? null)
        <x-monster-watcher :state="$monsterStates[App\Enums\MonsterTier::Three->value]" />
    @endif

    {{-- The one question the board stops to ask: which of the three does this
         one land on?

         A full-screen overlay rather than something inline, because it is the
         moment the work turns into a choice — and because the cards have to be
         big enough to compare a reward against a health bar on a phone. It only
         ever opens when more than one monster is standing; see shouldAim(). --}}
    @if ($targetingChoreId !== null || $targetingQuest)
        {{-- The scroller is this plain block, and the centring happens on the
             wrapper inside it. A `fixed` element that is both the flex
             container *and* the thing that scrolls loses whatever overflows
             past its top edge: the browser will not scroll back to content
             pushed out by `align-items`, so on a phone the picker opened
             showing the last card with the heading unreachable above it.
             `min-h-full` on the wrapper is what lets a short panel sit centred
             while a tall one simply grows and scrolls. --}}
        <div
            class="fixed inset-0 z-50 overflow-y-auto overscroll-contain"
            style="background: rgba(8, 4, 16, 0.82)"
            wire:key="monster-picker"
        >
            <div class="flex min-h-full items-end justify-center p-4 sm:items-center">
                <div class="w-full max-w-[880px] rounded-[24px] border border-fq-line-2 bg-fq-panel p-5 sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-mono-fq text-[10px] tracking-[0.24em] text-fq-coral uppercase">Nice work</p>
                            <h2 class="mt-1 font-baloo text-2xl font-extrabold">Who takes the hit?</h2>
                            <p class="mt-1 max-w-[420px] text-[13px] text-fq-text-4">
                                All your points from this one go to the monster you pick.
                            </p>
                        </div>

                        <button
                            type="button"
                            wire:click="cancelAim"
                            class="rounded-[12px] border border-fq-line-3 bg-fq-sunk px-4 py-[9px] text-[13px] text-fq-text-2-b transition hover:text-fq-text"
                        >Not yet</button>
                    </div>

                    {{-- Compact cards, not the arena's. Three full cards stack
                         to well over a phone screen, which is what made the
                         overflow above matter in the first place — and this is
                         a decision to make in one glance, not a gallery. The
                         big artwork lives on the Goal page. --}}
                    <div class="mt-4 flex flex-wrap items-stretch gap-[14px]">
                        @foreach ($monsterStates as $tierValue => $state)
                            <x-monster-card
                                :state="$state"
                                :compact="true"
                                :selected="$profile->last_monster_tier?->value === $tierValue"
                                wire:key="pick-{{ $tierValue }}"
                                wire:click="aimAt({{ $tierValue }})"
                                class="min-w-0 flex-[1_1_260px] cursor-pointer hover:border-fq-lime"
                                role="button"
                                tabindex="0"
                            />
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- One column, in the order the handoff fixes: what you owe today, what's
         going spare, the quest that gates everything, the fight it feeds, and
         only then the board itself. --}}
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
        </div>

        {{-- 2. Loot tray. Three slots, and it never collapses below three —
             it's the one row that has to be scannable at a glance. --}}
        <div class="rounded-[22px] border border-fq-line-2 p-[14px]" style="background: var(--fq-tray-bg)">
            <div class="mb-3 flex items-center justify-between gap-2">
                <span class="font-mono-fq text-[10px] tracking-[0.24em] text-fq-text-4 uppercase">Loot Tray</span>

                @if ($mysteryChore)
                    {{-- The entry point to the mystery card in both states. It
                         scrolls rather than jumps: the card's position is fixed
                         (always under the side quests) and the point is to show
                         a kid where it lives, not to teleport them. --}}
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

            <div class="grid grid-cols-3 gap-[12px]">
                {{-- Free once a day, and unrelated to the wheel spin beside it. --}}
                <x-loot-slot
                    wire-key="tray-bonus-chest"
                    name="Bonus chest"
                    icon="chest"
                    accent="var(--fq-chest-blue)"
                    chest-fill="var(--fq-chest-blue-fill)"
                    :border="$dailyChestAvailable ? 'var(--fq-chest-blue-line)' : 'var(--fq-line)'"
                    :background="$dailyChestAvailable ? 'var(--fq-chest-blue-bg)' : 'var(--fq-panel)'"
                    :dim="! $dailyChestAvailable"
                    :status="$dailyChestAvailable ? 'Ready to open' : 'Back tomorrow'"
                    :status-color="$dailyChestAvailable ? 'var(--fq-chest-blue)' : 'var(--fq-text-4)'"
                    :open-action="$dailyChestAvailable ? 'openDailyChest' : null"
                    opened-status="Banked"
                    prize-sub="Bonus Chest"
                    :prize-label="$dailyChestPrize ?? 'A prize!'"
                    prize-property="dailyChestPrize"
                    action-label="Open today's bonus chest"
                />

                @php
                    // What the kid can do about it, in order: open one, rescue
                    // the run, start one, keep going, or nothing left to unlock.
                    //
                    // The mock read "Day 14 · 6 to go", which works at an 8-day
                    // streak because 14 is obviously still ahead. At a 0-day
                    // streak the same shape says "Day 3" beside a header
                    // reading 0d, and it reads as the day you are *on*. So the
                    // milestone number stays on the streak track at the bottom,
                    // where the whole rail makes clear it's a target, and the
                    // slot says only how far away the chest is.
                    $daysToChest = max(0, $nextMilestone - $profile->streak);

                    // No "all unlocked" any more: the track laps, so there is
                    // always another chest ahead of whatever they're on.
                    $streakStatus = match (true) {
                        (bool) $pendingChestDay => 'Ready to open',
                        (bool) $streakRepair => 'Streak ended',
                        $profile->streak === 0 => 'Start a streak',
                        default => $daysToChest.' '.Str::plural('day', $daysToChest).' to go',
                    };

                    // With nothing to open, the slot's job is to point at the
                    // track that shows what's coming — so it says so.
                    if (! $pendingChestDay) {
                        $streakStatus .= ' →';
                    }
                @endphp

                <x-loot-slot
                    wire-key="tray-streak-chest"
                    name="Streak chest"
                    icon="chest"
                    accent="var(--fq-streak)"
                    chest-fill="var(--fq-chest-streak-fill)"
                    :border="$pendingChestDay ? 'var(--fq-streak)' : 'var(--fq-line)'"
                    :background="$pendingChestDay ? 'var(--fq-wash-streak)' : 'var(--fq-panel)'"
                    :dim="! $pendingChestDay"
                    :status="$streakStatus"
                    :status-color="$pendingChestDay || $streakRepair ? 'var(--fq-streak)' : 'var(--fq-text-4)'"
                    :open-action="$pendingChestDay ? 'openStreakChest' : null"
                    :scroll-to="$pendingChestDay ? null : 'streak-card'"
                    :opened-status="'+'.number_format((int) $pendingChestPoints).' pts banked'"
                    :prize-sub="$pendingChestDay.'-Day Streak Bonus!'"
                    :prize-label="'+'.number_format((int) $pendingChestPoints).' PTS'"
                    :action-label="$pendingChestDay ? 'Open your streak chest' : 'See the streak chest track'"
                />

                <x-loot-slot
                    wire-key="tray-wheel"
                    name="Bonus wheel"
                    icon="wheel"
                    :dim="(bool) $boost"
                    :pulse="! $boost"
                    :border="$boost ? 'var(--fq-line)' : 'var(--fq-badge-line)'"
                    :background="$boost ? 'var(--fq-panel)' : 'var(--fq-badge-bg)'"
                    {{-- The chore can be gone if a parent deleted it after the
                         spin landed, and a dead relation must not take the
                         whole page down over a status line. --}}
                    :status="$boost ? $boost->multiplier.'x on '.($boost->chore?->name ?? 'a chore') : 'Not spun yet'"
                    :status-color="$boost ? 'var(--fq-text-4)' : 'var(--fq-magenta)'"
                    :href="$boost ? null : route('kid.wheel')"
                    action-label="Go and spin the bonus wheel"
                />
            </div>
        </div>

        {{-- 3. Main quest chest. Deliberately not duplicated in the tray:
             there is exactly one place to tap it. --}}
        @if ($questDoneOnArrival)
            {{-- Already cleared before this visit — shrink it to a line so
                 hopping between tabs doesn't mean scrolling past a hero card
                 for a quest that's finished. --}}
            <div
                wire:key="quest-cleared"
                class="flex items-center gap-3 rounded-[18px] border p-[14px]"
                style="background: var(--fq-wash-cleared); border-color: color-mix(in srgb, {{ $questPending ? 'var(--fq-gold)' : ($questApproved ? 'var(--fq-lime)' : 'var(--fq-danger)') }} 40%, transparent)"
            >
                @php
                    // The strip has to draw the same distinctions the hero's
                    // CTA does, or hopping tabs turns "waiting" into "done".
                    [$stripLabel, $stripGlyph, $stripColor] = match (true) {
                        $questPending => ['Waiting on parent', '⋯', 'var(--fq-gold)'],
                        $questApproved => ['Cleared', '✓', 'var(--fq-lime)'],
                        default => ['Sent back', '↺', 'var(--fq-danger)'],
                    };
                @endphp

                <div
                    class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-[12px] font-baloo text-lg font-extrabold text-fq-bg"
                    style="background: {{ $stripColor }}"
                >{{ $stripGlyph }}</div>
                <div class="min-w-0 flex-1">
                    <p class="font-mono-fq text-[10px] tracking-[0.2em] text-fq-text-4 uppercase">Today's Main Quest</p>
                    <p class="truncate text-[15px] font-semibold">{{ $quest->chore->name }}</p>
                </div>
                <span
                    class="font-mono-fq text-[11px] whitespace-nowrap"
                    style="color: {{ $stripColor }}"
                >{{ $stripLabel }}</span>
            </div>
        @else
            {{-- Sits above the chest and only while it is shut, because that is
                 the only moment a charm can be cast — see
                 PerkInventoryService::questCharmReason(). Once one is on, the
                 button gives way to the mark, so a kid can't be left wondering
                 whether the tickets took. --}}
            @php
                // A charm can only be cast on a shut chest, so everything in
                // this block is meaningless the instant one starts opening —
                // and the server doesn't find out until the animation ends.
                // See <x-chest>'s begin(), which announces it immediately.
                $charmable = ! $handDealt && ! $questCharmed;
                $holdsUsableCharm = isset($heldPerks['quest_charm']) && ! $heldPerks['quest_charm']['blocked'];
            @endphp

            @if ($charmable && isset($heldPerks['quest_charm']))
                <div
                    x-data="{ opening: false }"
                    x-on:chest-opening.window="opening = true"
                    x-show="! opening"
                    class="flex justify-end"
                >
                    <x-perk-button :entry="$heldPerks['quest_charm']" />
                </div>
            @elseif ($charmable && $charmForSale)
                {{-- Sold from here rather than only from the shop because the
                     window to use one closes the moment the chest opens — see
                     buyQuestCharm(). Same brushed steel as the use button, so
                     the two read as the same control at different stages. --}}
                @php
                    $canAffordCharm = $profile->bonus_tickets >= $charmForSale->cost;
                @endphp

                <div
                    x-data="{ opening: false }"
                    x-on:chest-opening.window="opening = true"
                    x-show="! opening"
                    class="flex items-center justify-end gap-2"
                >
                    <button
                        type="button"
                        wire:click="buyQuestCharm"
                        @disabled(! $canAffordCharm)
                        title="{{ $charmForSale->description }}"
                        class="inline-flex h-[42px] items-center gap-2 rounded-[12px] border px-[14px] text-xs font-semibold whitespace-nowrap transition hover:brightness-125 disabled:opacity-40"
                        style="border-color: var(--fq-steel-edge); color: var(--fq-steel-text); background: var(--fq-steel-panel)"
                    >
                        <span class="font-baloo text-sm">{{ $charmForSale->glyph }}</span>
                        <span>Buy a {{ $charmForSale->name }}</span>
                        <span class="font-mono-fq text-[10px]" style="color: {{ $canAffordCharm ? 'var(--fq-lime)' : 'var(--fq-text-5)' }}">
                            {{ $charmForSale->cost }}&#127903;
                        </span>
                    </button>

                    {{-- A disabled button with no reason on it is the thing the
                         board messages exist to stop. --}}
                    @if (! $canAffordCharm)
                        <span class="font-mono-fq text-[10px] text-fq-text-5">
                            {{ $charmForSale->cost - $profile->bonus_tickets }} more
                        </span>
                    @endif
                </div>
            @elseif ($questCharmed && ! $questRevealed)
                <div class="flex items-center justify-end gap-2">
                    <span class="font-baloo text-[15px]" style="color: var(--fq-violet)">✧</span>
                    <span class="font-mono-fq text-[10px] tracking-[0.18em] uppercase" style="color: var(--fq-violet)">
                        {{ $questCharm?->label() ?? 'Charmed · Open the chest to see' }}
                    </span>
                </div>
            @endif

            {{-- The chest deals rather than reveals: it bursts open onto three
                 cards and the kid takes one. Its own celebration is off,
                 because "here are some cards" is not news — the pick is, and
                 chooseQuest() fires that once it knows the pick landed. --}}
            <x-chest
                wire-key="quest-chest"
                :revealed="$handDealt"
                open-action="dealHand"
                :celebrate-on-open="false"
                accent="var(--fq-lime)"
                kicker="Quest Chest · Open It"
                :closed-title="$questCards->count() > 1 ? 'Choose your quest' : 'Today\'s main quest is inside'"
                :closed-text="($questCards->count() > 1
                    ? $questCards->count().' cards are inside. Take one, burn the rest.'
                    : 'Worth +'.number_format($questPoints).' pts.')
                    .($household->require_quest_first ? ' Side quests unlock the moment it\'s cleared.' : '')"
                opening-text="The chest is rattling..."
                cta="Open"
                {{-- Only when a charm is sitting unused. Buying one changes a
                     button's label and nothing else, which is very easy to read
                     as "bought it, so it's on" — this is the stop that catches
                     that before the chest opens and the chance is gone. --}}
                :confirm="$charmable && $holdsUsableCharm"
            >
                @if ($charmable && $holdsUsableCharm)
                    <x-slot:confirm-panel>
                        <p class="mb-2 font-mono-fq text-[10px] tracking-[0.16em] uppercase" style="color: var(--fq-violet)">
                            ✧ You have a charm
                        </p>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            {{-- Charms and opens in one tap. The perk call
                                 finishes before begin() starts the 2.6s
                                 animation, and dealHand() lands at the end of
                                 it — so the effect is rolled on a chest that
                                 was already charmed. --}}
                            <button
                                type="button"
                                @click="$wire.usePerk('quest_charm').then(() => begin())"
                                class="cursor-pointer rounded-[16px] px-[20px] py-[13px] font-baloo text-[16px] font-extrabold transition hover:brightness-110"
                                style="background: var(--fq-violet); color: var(--fq-bg)"
                            >Charm it first</button>

                            <button
                                type="button"
                                @click="begin()"
                                class="cursor-pointer rounded-[16px] border px-[20px] py-[13px] font-baloo text-[16px] font-bold transition hover:brightness-125"
                                style="border-color: var(--fq-line-3); color: var(--fq-text-3)"
                            >Open without it</button>
                        </div>
                    </x-slot:confirm-panel>
                @endif

            @if (! $questRevealed)
                <x-quest-cards
                    :cards="$questCards"
                    choose-action="chooseQuest"
                    :charm="$questCharm"
                    :message="$questCardMessage"
                />
            @else
                <div
                    wire:key="hero"
                    class="rounded-[24px] border p-5"
                    style="animation: fq-pop .3s ease both; background: var(--fq-wash-gold); border-color: rgba(255,225,77,{{ $questDone ? '0.4' : '0.65' }})"
                >
                    <p class="font-mono-fq text-[10px] tracking-[0.24em] text-fq-lime uppercase">Today's Main Quest</p>
                    <h2 class="mt-2 font-baloo text-[26px] leading-[1.1] font-extrabold sm:text-[30px]">{{ $quest->chore->name }}</h2>

                    {{-- A deadline on the main quest is the sharpest version of
                         this: miss it and the quest rerolls, so the countdown
                         belongs right under the name where it can't be missed. --}}
                    @if ($questClosesAt && ! $questDone)
                        <x-chore-countdown wire:key="quest-closes" :closes-at="$questClosesAt" class="mt-2" />
                    @endif

                    <p class="mt-2 max-w-[420px] text-sm text-fq-text-2">
                        @if ($questDone)
                            Quest cleared. Every side quest below is unlocked for today.
                        @elseif ($questSentBack)
                            A parent sent this one back — finish it off and mark it done again.
                        @else
                            Clear this one first — the side quests stay locked until it's done.
                        @endif
                    </p>

                    <div class="mt-4 flex w-full flex-wrap items-center gap-3">
                        @if ($questPending)
                            <button type="button" disabled class="cursor-default rounded-[16px] bg-fq-line-2 px-[22px] py-[14px] font-baloo text-[17px] font-bold text-fq-text-3">
                                Waiting on parent
                            </button>
                        @elseif ($questApproved)
                            <button type="button" disabled class="cursor-default rounded-[16px] bg-fq-line-2 px-[22px] py-[14px] font-baloo text-[17px] font-bold text-fq-text-3">
                                Cleared &#10003;
                            </button>
                        @else
                            {{-- Live even after a rejection: "do it again" is
                                 the entire point of sending something back, so
                                 the button has to work. The label carries the
                                 bad news instead of a dead control. --}}
                            <button
                                type="button"
                                wire:click="claimQuest"
                                class="rounded-[16px] px-[22px] py-[14px] font-baloo text-[17px] font-bold transition hover:brightness-110"
                                style="background: var(--fq-fill-gold); color: var(--fq-ink); box-shadow: var(--fq-shadow-glow-sm) var(--fq-lime)"
                            >{{ $questSentBack ? 'Mark it done again' : 'Mark it done' }}</button>

                            @if ($questSentBack)
                                <span class="font-mono-fq text-xs" style="color: var(--fq-danger)">Sent back</span>
                            @endif
                        @endif
                        <span class="font-mono-fq text-xs" style="color: {{ $questBoosted ? ($boost->multiplier === 3 ? 'var(--fq-gold)' : 'var(--fq-magenta)') : 'var(--fq-lime)' }}">
                            +{{ $questPoints }} PTS
                        </span>

                        {{-- Says where the extra came from. Without it the
                             number on the hero doesn't match the chore's own
                             points anywhere else in the app, and the card that
                             explained it has burned. --}}
                        @if ($questBoldBonus)
                            <span
                                class="rounded-full px-[7px] py-[2px] font-mono-fq text-[9px] leading-none tracking-[0.16em] uppercase"
                                style="background: var(--fq-gold); color: var(--fq-bg)"
                            >Bold +{{ number_format($questBoldBonus) }}</span>
                        @endif

                        {{-- Only ever present after the claim, which is what
                             rolls it. Its own chip rather than folded into the
                             bold one: they are two different bets. --}}
                        @if ($questCharmPayout)
                            <span
                                class="rounded-full px-[7px] py-[2px] font-mono-fq text-[9px] leading-none tracking-[0.16em] uppercase"
                                style="background: var(--fq-violet); color: var(--fq-bg)"
                            >✧ Charm +{{ number_format($questCharmPayout) }}</span>
                        @endif

                        {{-- Pushed to the far edge — it's an escape hatch, not
                             the thing to reach for first. --}}
                        @if (isset($heldPerks['quest_reroll']))
                            <span class="ml-auto">
                                <x-perk-button :entry="$heldPerks['quest_reroll']" />
                            </span>
                        @endif
                    </div>
                </div>
            @endif
            </x-chest>
        @endif

        @if ($perkMessage)
            <div class="rounded-[16px] border border-fq-line-2 bg-fq-sunk px-4 py-3 text-sm text-fq-text-2">
                {{ $perkMessage }}
            </div>
        @endif

        {{-- 4. Boss fight, promoted out of the old sidebar to sit directly
             under the quest that feeds it. --}}
        <div wire:key="family-goal">
            @if ($monsterStates)
                <x-monster-mini :states="$monsterStates" :pending="$pendingCount" wire:key="family-boss" />
            @endif
        </div>

        {{-- The own-bed card, above gratitude because it asks about last night
             and the morning is when it makes sense to answer. Absent entirely
             unless a parent has switched it on for this kid.

             The Night Chest used to sit below it as a flat rectangle of its
             own, separated from the run that earns it. It is a rail inside the
             card now, drawn as the actual chest mark. --}}
        @if ($sleepCard)
            <x-sleep-card :card="$sleepCard" />
        @endif

        {{-- 5. Gratitude quest. The one quest that isn't work — nothing for a
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

        {{-- 6. Side quests --}}
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-baloo text-xl font-bold">Side Quests</h3>
            {{-- A bought day says so rather than reading as "All Unlocked":
                 the kid spent eight tickets on it and should see where the
                 open board came from. --}}
            <span class="font-mono-fq text-[10px] tracking-[0.14em] uppercase" style="color: {{ $allUnlocked ? 'var(--fq-lime)' : 'var(--fq-gold)' }}">
                @if ($questSkipped)
                    Day Off &middot; Board Open
                @else
                    {{ $allUnlocked ? 'All Unlocked' : 'Locked Until Quest Is Done' }}
                @endif
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
                    $boostColor = $boosted && $boost->multiplier === 3 ? 'var(--fq-gold)' : 'var(--fq-magenta)';
                    $labels = [
                        'ready' => 'Mark it done',
                        'locked' => 'Locked',
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
                    class="flex flex-col rounded-[18px] border bg-fq-panel p-[15px] {{ $state === 'locked' ? 'opacity-55' : '' }} {{ $takenBy || $state === 'expired' ? 'opacity-70' : '' }} {{ $chore->isOneTime() || $closesAt ? 'border-2' : 'border border-fq-line' }}"
                    style="{{ $state === 'pending' ? 'border-color: var(--fq-success-border)' : ($closesAt ? 'border-color: color-mix(in srgb, var(--fq-cyan) 55%, transparent)' : ($chore->isOneTime() ? 'border-color: color-mix(in srgb, var(--fq-gold) 55%, transparent); background: var(--fq-wash-gold)' : '')) }}"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
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

        {{-- 7. Bounty board — a window onto Trades & Jobs showing only what
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

        {{-- 8. Mystery chore. Always in this slot, live or found, so the tray
             pill above always scrolls to the same place. --}}
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

        {{-- 9. Streak chest track. Useful every day, not only on a milestone —
             it's the one place that says what each chest is actually worth. --}}
        <div
            id="streak-card"
            wire:key="streak-track"
            class="rounded-[20px] border bg-fq-panel p-[18px]"
            style="border-color: color-mix(in srgb, var(--fq-streak) 35%, transparent)"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="font-baloo text-lg font-bold">Streak Chest</h3>

                    {{-- Only from the second lap on. On the first it would be
                         labelling a thing that has no other version yet. --}}
                    @if ($streakLap > 1)
                        <span
                            class="rounded-full border px-[10px] py-1 font-mono-fq text-[9.5px] tracking-[0.1em] whitespace-nowrap uppercase"
                            style="border-color: color-mix(in srgb, var(--fq-streak) 55%, transparent); background: var(--fq-wash-streak); color: var(--fq-streak)"
                        >Round {{ $streakLap }} · Double payouts</span>
                    @endif
                </div>

                <span class="font-mono-fq text-[11px] whitespace-nowrap text-fq-streak uppercase">
                    {{ $profile->streak }}-day streak · Next chest at day {{ $nextMilestone }}
                </span>
            </div>

            <p class="mt-1 text-sm text-fq-text-2">
                @if ($streakRepair)
                    Your streak ran out — but it isn't gone yet.
                @elseif ($profile->streak + 1 === $nextMilestone && ! $questDone)
                    {{-- The one day the general advice isn't the useful thing to
                         say: the chest is one cleared quest away, so say that
                         instead of explaining how streaks work. --}}
                    Complete today's quest and come back tomorrow to open the chest!
                @elseif ($profile->streak === 0)
                    {{-- Nothing to keep alive yet. "Keep the streak alive" to
                         somebody on nought days is advice about a thing they
                         don't have. --}}
                    Clear today's quest to start a streak. Keep it going and the chests get bigger —
                    the first one is day {{ $nextMilestone }}.
                @elseif ($streakLap > 1)
                    {{-- The reason the numbers on the track just changed. Worth
                         saying outright: a kid who cleared day 30 and found a
                         fresh row of chests deserves to know they're worth more
                         rather than having to remember last month's figures. --}}
                    You went all the way round — every chest on this lap pays double.
                    Miss a day and you drop back to the last one you cleared.
                @else
                    Keep the streak alive and the chests get bigger — miss a day and you drop back to
                    the last one you cleared.
                @endif
            </p>

            {{-- The rescue window, and it really is a window: clearing today's
                 quest starts a fresh chain and closes it, so the copy has to say
                 that before a kid taps past it. --}}
            @if ($streakRepair)
                <div
                    wire:key="streak-repair"
                    class="mt-4 rounded-[18px] border p-4"
                    style="background: var(--fq-wash-streak); border-color: color-mix(in srgb, var(--fq-streak) 55%, transparent)"
                >
                    <p class="font-mono-fq text-[10px] tracking-[0.24em] text-fq-streak uppercase">Streak Rescue</p>

                    <p class="mt-2 text-sm text-fq-text-2">
                        You missed {{ $streakRepair['date']->toFormattedDateString() }}. A Streak Restore buys that day
                        back and puts you on a
                        <span class="font-bold text-fq-streak">{{ $streakRepair['restoresTo'] }}-day streak</span>.
                    </p>

                    <p class="mt-1 font-mono-fq text-[11px] text-fq-text-4">
                        Use it before you clear today's quest — after that the day is gone for good.
                    </p>

                    <div class="mt-3">
                        @if (isset($heldPerks['streak_restore']))
                            <x-perk-button :entry="$heldPerks['streak_restore']" />
                        @else
                            <a
                                href="{{ route('kid.bonus') }}"
                                wire:navigate
                                class="inline-flex items-center gap-2 rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[13px] py-[8px] text-[13px] text-fq-text-2-b transition hover:border-fq-line-4 hover:text-fq-text"
                            >Get a Streak Restore &rarr;</a>
                        @endif
                    </div>
                </div>
            @endif

            {{-- No button in the branch below on purpose. The rescue card above
                 owns every case where a restore can actually be spent, so
                 anything reaching it is a perk with nothing to fix — and a
                 permanently greyed-out "Use Streak Restore" under a healthy
                 streak reads as the app being broken rather than as the kid
                 having nothing to repair. --}}
            @if (isset($heldPerks['streak_restore']) && ! $streakRepair)
                @php
                    $restoresHeld = $heldPerks['streak_restore']['count'];
                    // Built here rather than across template lines, so the
                    // sentence renders as one run of text instead of picking up
                    // the indentation between its clauses.
                    $restoreNote = $restoresHeld > 1
                        ? "{$restoresHeld} Streak Restores are in your pocket. Nothing to fix right now — they'll be here if you ever miss a day."
                        : "A Streak Restore is in your pocket. Nothing to fix right now — it'll be here if you ever miss a day.";
                @endphp

                <div class="mt-4 flex flex-wrap items-center gap-3 rounded-[14px] border border-fq-steel-line bg-fq-sunk p-[13px]">
                    <span class="font-baloo text-sm" style="color: var(--fq-steel-text)">
                        {{ App\Enums\PerkEffect::StreakRestore->defaults()['glyph'] }}
                    </span>
                    <p class="min-w-0 flex-1 text-[13px] text-fq-text-2">{{ $restoreNote }}</p>
                </div>
            @endif

            {{-- Chests rather than numbered circles, growing along the rail.
                 "Day 30 pays 4000 and day 3 pays 100" is a sentence you have to
                 read and compare; a row of chests getting bigger is the same
                 fact at a glance, which is the half of the audience that can't
                 comfortably do the first. The numbers stay underneath for the
                 kids who do want them. --}}
            {{-- The rail spans the card rather than huddling on the left. It's
                 a track, so the connectors stretch to fill whatever width there
                 is and the chests space themselves out along it; on a narrow
                 screen they fall back to their own widths and it scrolls. --}}
            <div class="mt-4 flex items-start gap-2 overflow-x-auto pb-1 sm:gap-3">
                @php
                    // Chests are sized off position in the run rather than off
                    // the payout: the amounts are set per household and a
                    // generous day-3 bonus shouldn't draw a bigger chest than
                    // the day-30 one. The rail is a sequence, and that's what
                    // the sizes have to say.
                    $rungs = max(1, $streakBonuses->count() - 1);
                @endphp

                @foreach ($streakBonuses as $milestone)
                    @php
                        // Three states, not two: reached, the one being worked
                        // towards, and the ones after it. The middle one is what
                        // makes the rail a track rather than a scoreboard.
                        $isNext = $nextMilestone === $milestone['day'];
                        $reached = $milestone['reached'];

                        $chestWidth = (int) round(26 + $loop->index / $rungs * 26);
                        $chestHeight = (int) round($chestWidth * 0.78);

                        [$chestFill, $labelColour] = match (true) {
                            $reached => ['var(--fq-chest-streak-fill)', 'var(--fq-streak)'],
                            $isNext => ['var(--fq-chest-streak-next)', 'var(--fq-text-2)'],
                            default => ['var(--fq-chest-locked-fill)', 'var(--fq-text-5)'],
                        };
                    @endphp

                    {{-- Node and connector are siblings rather than a nested
                         pair, so the connector is a flex child of the rail and
                         can grow into the space left over. --}}
                    <div class="flex flex-shrink-0 flex-col items-center gap-[6px]">
                            {{-- Fixed-height box with the chests bottom-aligned,
                                 so they grow upwards off a shared line instead
                                 of drifting around their own centres. --}}
                            <div class="relative flex h-[46px] items-end justify-center">
                                <x-chest-block
                                    :fill="$chestFill"
                                    :width="$chestWidth.'px'"
                                    :height="$chestHeight.'px'"
                                    radius="8px"
                                    class="{{ $reached ? '' : ($isNext ? 'ring-2 ring-fq-streak' : 'opacity-45') }}"
                                />

                                @if ($reached)
                                    <span
                                        class="absolute -top-[2px] -right-[4px] flex h-[15px] w-[15px] items-center justify-center rounded-full font-baloo text-[10px] font-extrabold"
                                        style="background: var(--fq-streak); color: var(--fq-streak-ink)"
                                        title="Opened"
                                    >&#10003;</span>
                                @endif
                            </div>

                        <span class="font-mono-fq text-[9px] whitespace-nowrap text-fq-text-4">Day {{ $milestone['day'] }}</span>
                        <span
                            class="font-baloo text-[11px] leading-none font-extrabold whitespace-nowrap"
                            style="color: {{ $labelColour }}"
                        >{{ number_format($milestone['points']) }} pts</span>
                    </div>

                    @unless ($loop->last)
                        {{-- Grows into the leftover width, and sits just above
                             the shared baseline so it reads as a rail the chests
                             stand on. --}}
                        <div class="mt-[38px] h-[2px] min-w-[10px] flex-1" style="background: {{ $reached ? 'var(--fq-streak)' : 'var(--fq-line-2)' }}"></div>
                    @endunless
                @endforeach
            </div>
        </div>

    </div>
</x-kid.shell>
