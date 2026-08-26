<?php

use App\Enums\CompletionStatus;
use App\Enums\PerkEffect;
use App\Exceptions\InsufficientTicketsException;
use App\Exceptions\PerkUnavailableException;
use App\Models\BonusPerk;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyQuest;
use App\Models\Profile;
use App\Models\Spin;
use App\Services\ArenaService;
use App\Services\BadgeService;
use App\Services\BonusShopService;
use App\Services\ChestService;
use App\Services\ChoreService;
use App\Services\HouseholdClock;
use App\Services\LuckyBlockService;
use App\Services\MonsterService;
use App\Services\PerkInventoryService;
use App\Services\SpinService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * Home — the day, in the order it usually goes.
 *
 * The daily quest, the bonus chest, the streak chest and the spin — the four a
 * kid does themselves — then the two the house does together: the weekly prize
 * and the boss fight. The standings go last, because they are the only card
 * that ranks the kids against each other. Every other kid page is organised by
 * *what kind of thing* it holds, which works fine once you know what you're
 * looking for and is no help at all to a kid asking "what now?" — this one is
 * organised by when.
 *
 * Deliberately not numbered. The order is the habit, not a rule: nothing here is
 * gated on anything above it, and a kid who wants to spin before they open
 * anything is not doing it wrong.
 *
 * Everything acts in place. The quest chest deals, the cards are picked and the
 * quest is claimed from here exactly as they are from the Quests page, because
 * a landing page that can only point at things is a menu rather than a start.
 * The hero itself is <x-quest-hero>, shared with Quests rather than copied —
 * the charm can only be cast on a shut chest, and a second copy that quietly
 * dropped the charm buttons would cost a kid a ticket they had already spent.
 *
 * Quests keeps what this page doesn't: the rest of the board, the mystery chore,
 * the bounty board, gratitude, the sleep card.
 */
new class extends Component
{
    public Profile $profile;

    /**
     * Where the wheel is pointing, in degrees. Restored at mount from a spin
     * already taken today so a kid coming back finds the wheel parked on what
     * they landed on rather than reset to the top.
     */
    public float $wheelDeg = 0;

    public bool $spinning = false;

    public bool $spinRevealed = false;

    public ?string $perkMessage = null;

    /**
     * Why a tap on the boosted chore didn't take. Cooldowns are household-wide,
     * so a page left open goes stale, and a button that silently no-ops reads
     * as broken.
     */
    public ?string $boostMessage = null;

    /**
     * Snapshotted at mount, NOT recomputed in with(): opening banks the reward
     * and re-renders, and a `revealed` flag recomputed on that round trip would
     * yank the chest out from under its own 2.6s animation.
     */
    public bool $chestOpened = false;

    /** What the bonus chest held — carried into the reveal card and the overlay. */
    public ?string $dailyChestPrize = null;

    /**
     * The milestone waiting to be opened, and what it pays. Snapshotted at mount
     * for the same reason as the bonus chest — opening clears the underlying
     * flag, and recomputing would pull the chest out mid-animation.
     */
    public ?int $pendingChestDay = null;

    public ?int $pendingChestPoints = null;

    /**
     * Why a card couldn't be taken. The hand is dealt from chores the whole
     * household shares, so a sibling can claim one out from under a kid who is
     * still deciding, and a tap that silently does nothing explains none of it.
     */
    public ?string $questCardMessage = null;

    /**
     * Snapshotted at mount, like the chest. Arriving with the quest already
     * cleared collapses the hero to a line so the rest of the day isn't pushed
     * down the page — but clearing it *during* this visit keeps the full card
     * on screen, so the moment still gets its celebration.
     */
    public bool $questDoneOnArrival = false;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();

        abort_unless($this->profile->isKid(), 403);

        // Through questOrNull() rather than isQuestDoneToday(), which asks for a
        // quest and throws when the household has nothing to draw one from.
        $this->questDoneOnArrival = $this->questOrNull()?->completed_at !== null;

        $chests = app(ChestService::class);
        $openedChest = $chests->openedToday($this->profile);

        $this->chestOpened = $openedChest !== null;
        $this->dailyChestPrize = $openedChest ? $chests->describe($openedChest) : null;

        $this->pendingChestDay = $this->profile->pending_streak_chest;
        // Milestone bonuses are denominated in dollars, but every other number a
        // kid sees is points — so convert once here and never show dollars. Read
        // through streakBonusOn() rather than off the base map: past day 30 the
        // track repeats and the day no longer indexes it directly.
        $this->pendingChestPoints = $this->pendingChestDay
            ? (app(ChoreService::class)->streakBonusOn($this->pendingChestDay) ?? 0) * $this->profile->household->points_per_dollar
            : null;

        $spins = app(SpinService::class);
        $spinToday = $spins->today($this->profile);

        if ($spinToday) {
            $chores = $this->wheelChores();
            $slice = 360 / max(1, $chores->count());
            $index = $chores->search(fn ($chore) => $chore->id === $spinToday->chore_id);

            $this->wheelDeg = $this->restingDeg((int) $index, $slice);
            $this->spinRevealed = true;
        }
    }

    public function openDailyChest(): void
    {
        $chests = app(ChestService::class);

        // Nothing to open means today's chest went elsewhere — another tab, or
        // a back-button visit to a page rendered before it was. Describe the
        // one that actually exists rather than revealing an empty card: a prize
        // overlay with nothing on it is the one outcome a chest must never show.
        $chest = $chests->open($this->profile) ?? $chests->openedToday($this->profile);

        if ($chest) {
            $this->dailyChestPrize = $chests->describe($chest);
        }

        // Moves the snapshot on with the animation rather than against it. The
        // chest itself is already revealing client-side by the time this lands;
        // this is what keeps it revealed on the next full page load.
        $this->chestOpened = true;
    }

    public function openStreakChest(): void
    {
        app(ChoreService::class)->openStreakChest($this->profile);
    }

    /**
     * Opens the quest chest, putting the hand on the table.
     *
     * No celebration: the chest deals rather than reveals, and the card that
     * gets announced is the one they choose.
     */
    public function dealHand(): void
    {
        app(ChoreService::class)->dealQuestHand($this->profile);
    }

    /**
     * Takes one of today's cards. The others have already burned client-side by
     * the time this runs — see <x-quest-cards>.
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
            $this->questCardMessage = 'That one just went — pick another card.';

            return;
        }

        // The charm's hand-in roll is deliberately not part of this number: it
        // hasn't happened yet, and quoting a total that later grows is a better
        // surprise than one that appears to shrink.
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

        // The streak (and any milestone bonus) moves on a parent's approval, so
        // don't quote a day count here that hasn't been earned yet.
        if ($wasDone) {
            return;
        }

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

    /**
     * Sold from the hero rather than only from the shop because the window to
     * use a charm closes the moment the chest opens — and this page is where
     * the chest is.
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

    public function spin(): void
    {
        if ($this->spinning || $this->spinRevealed || ! $this->profile->household->spin_enabled) {
            return;
        }

        $spins = app(SpinService::class);

        if ($spins->hasSpunToday($this->profile)) {
            $this->spinRevealed = true;

            return;
        }

        // The family can clear the board before a kid gets round to spinning.
        // SpinService throws on an empty pool, and nothing here catches it, so
        // the guard has to be in front of the call rather than around it.
        if ($this->wheelChores()->isEmpty()) {
            return;
        }

        $result = $spins->spin($this->profile);

        $chores = $this->wheelChores();
        $slice = 360 / max(1, $chores->count());
        $index = $chores->search(fn ($chore) => $chore->id === $result->chore_id);

        $target = $this->restingDeg((int) $index, $slice);

        while ($target <= $this->wheelDeg) {
            $target += 360;
        }

        $this->wheelDeg = $target + 360 * 6;
        $this->spinning = true;
    }

    public function finishSpin(): void
    {
        $this->spinning = false;
        $this->spinRevealed = true;

        $result = app(SpinService::class)->today($this->profile);

        if ($result) {
            $this->dispatch(
                'celebrate',
                message: "{$result->multiplier}x boost on {$result->chore->name}!",
                style: 'confetti',
                big: $result->multiplier === 3,
            );
        }

        app(BadgeService::class)->evaluate($this->profile);
    }

    /**
     * The pointer sits at the top of the wheel. Segment 0 starts at local
     * angle 0° (3 o'clock, before any rotation) and runs clockwise — same
     * convention as the segment/label markup below — so resting here is
     * what actually brings a chore's slice under the pointer at the top.
     */
    private function restingDeg(int $index, float $slice): float
    {
        return -90 - ($index * $slice + $slice / 2);
    }

    /**
     * Every perk with a button on this page: the quest charm and reroll on the
     * hero, and the wheel respin beside the spin.
     */
    public function usePerk(string $effect): void
    {
        $case = PerkEffect::tryFrom($effect);

        if (! $case) {
            return;
        }

        try {
            $outcome = app(PerkInventoryService::class)->use($this->profile, $case);
            $this->perkMessage = null;
        } catch (PerkUnavailableException $e) {
            $this->perkMessage = $e->getMessage();

            return;
        }

        if ($case === PerkEffect::WheelRespin) {
            // Put the wheel back where it started so the second spin happens in
            // place rather than on a wheel still parked on the first result.
            $this->spinRevealed = false;
            $this->spinning = false;
            $this->wheelDeg = 0;

            $this->dispatch('celebrate', message: 'Wheel reset — take another spin!', style: $case->celebrationStyle());

            return;
        }

        // Same styles as the Bonus Shop's own copy of this — a perk used from
        // here and one used from the shop are the same moment and must not
        // celebrate differently.
        $this->dispatch('celebrate', message: $outcome, style: $case->celebrationStyle(), motion: 'burst', origin: 'tap');
    }

    /**
     * Today's quest, or null when the household has nothing eligible to draw
     * one from. Home has to render either way — it is the page a kid lands on,
     * so it is the one page that must never be the thing that breaks.
     */
    private function questOrNull(): ?DailyQuest
    {
        try {
            return app(ChoreService::class)->questFor($this->profile);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * What the wheel can land on, or nothing at all.
     *
     * Guarded for the same reason as questOrNull(): the eligible pool is built
     * by excluding today's quest hand, so asking for it *deals* one — and a
     * household with no chores at all makes that throw. Every entry point to
     * the wheel goes through here so none of them can be the one that breaks
     * the landing page.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Chore>
     */
    private function wheelChores(): \Illuminate\Support\Collection
    {
        try {
            return app(SpinService::class)->eligibleChoresFor($this->profile);
        } catch (\RuntimeException) {
            return collect();
        }
    }

    /**
     * What the Active Boost card can offer for the chore the wheel landed on:
     * the claim itself, or the reason it isn't available.
     *
     * @return ?array{claimable: bool, label: string, note: ?string, toQuests: bool}
     */
    private function boostClaim(?Spin $boost): ?array
    {
        if (! $boost) {
            return null;
        }

        $service = app(ChoreService::class);
        $chore = $boost->chore;
        $quest = $this->questOrNull();

        // The chest reveal is the whole ceremony of the main quest, so a boost
        // that landed on it gets pointed back there rather than quietly
        // claiming it from under an unopened chest.
        if ($quest && $quest->chore_id === $chore->id) {
            return [
                'claimable' => false,
                'label' => 'This is your main quest',
                'note' => 'Open the chest on the Quests page to claim it.',
                'toQuests' => true,
            ];
        }

        $state = $service->stateFor($this->profile, $chore);
        $claimant = $service->claimantFor($chore);

        return match (true) {
            $state === 'ready' => ['claimable' => true, 'label' => 'Mark it done', 'note' => null, 'toQuests' => false],
            $state === 'pending' => ['claimable' => false, 'label' => 'Waiting on a parent', 'note' => null, 'toQuests' => false],
            $state === 'expired' => ['claimable' => false, 'label' => "Time's up", 'note' => 'A parent is taking that one.', 'toQuests' => false],
            $claimant && $claimant->profile_id !== $this->profile->id => [
                'claimable' => false,
                'label' => $claimant->profile->name.' got this one',
                'note' => null,
                'toQuests' => false,
            ],
            default => ['claimable' => false, 'label' => 'Already done today', 'note' => null, 'toQuests' => false],
        };
    }

    /**
     * Claims the boosted chore without leaving the page. Every guard the Quests
     * board applies is re-run here — a disabled button in a browser is never
     * the thing standing between a kid and a double claim.
     */
    public function claimBoostedChore(): void
    {
        $this->boostMessage = null;

        $boost = app(SpinService::class)->today($this->profile);
        $claim = $this->boostClaim($boost);

        if (! $claim) {
            return;
        }

        if (! $claim['claimable']) {
            $this->boostMessage = $claim['note'] ?? $claim['label'].'.';

            return;
        }

        $chore = $boost->chore;

        if (! $chore->isAppropriateFor($this->profile)) {
            return;
        }

        // Silent about the mystery chore on purpose — the find is announced
        // once a parent approves the work, by the card the kid shell queues.
        $this->dispatch(
            'celebrate',
            message: "{$chore->name} claimed at {$boost->multiplier}x! Bonus wheel treat earned.",
            treat: 'cookie',
            motion: 'burst',
            origin: 'tap',
        );

        app(ChoreService::class)->claim($this->profile, $chore);
    }

    /** The monster standing, as the boss strip and the watcher want it. */
    private function monsterState(): ?array
    {
        $arena = app(MonsterService::class);
        $monster = $arena->rotateWeakness($this->profile->household);

        return $monster ? $arena->stateFor($monster) : null;
    }

    /**
     * The simplified standings: one row per kid, sorted by the run they're on.
     *
     * Deliberately a fraction of what the Arena draws. The full page has lanes,
     * flags, a monster and a ticker; this is the league table underneath all of
     * it, which is the part a kid can read in two seconds on the way past.
     *
     * Wrapped, because ArenaService::tonightFor() draws a quest for every kid in
     * the house and a household with nothing eligible makes that throw. On the
     * Arena that's the page; here it's one card of four, and losing it must not
     * take the landing page down with it.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function standings(): \Illuminate\Support\Collection
    {
        try {
            return app(ArenaService::class)
                ->tonightFor($this->profile->household)
                ->sortByDesc('streak')
                ->values();
        } catch (\RuntimeException) {
            return collect();
        }
    }

    public function with(): array
    {
        $service = app(ChoreService::class);
        $spins = app(SpinService::class);
        $inventory = app(PerkInventoryService::class);

        // A Livewire round trip doesn't pass back through the route middleware
        // that expires a lapsed streak, and a kid can sit on this page across
        // the household rollover.
        $service->syncStreak($this->profile);

        $quest = $this->questOrNull();
        $boost = $spins->today($this->profile);
        $wheelChores = $this->wheelChores();

        // Claiming sets completed_at immediately, but the points don't land
        // until a parent approves — so "done" and "waiting" are different
        // things and the step has to say which. Scoped to today's household day
        // rather than to completed_at, because a sent-back quest clears that
        // stamp and the attempt still has to be findable.
        $clock = HouseholdClock::for($this->profile->household);

        $completion = $quest
            ? ChoreCompletion::where('profile_id', $this->profile->id)
                ->where('chore_id', $quest->chore_id)
                ->where('submitted_at', '>=', $clock->startOf($clock->today()))
                ->latest('submitted_at')
                ->first()
            : null;

        $questBoosted = $quest && $boost && $boost->chore_id === $quest->chore_id;

        // The hand, and what each card is worth. Built here rather than in the
        // component so a card can name the sibling who took it.
        $cardBonuses = $quest ? $service->cardBonusesFor($this->profile) : [];

        $questCards = $quest
            ? $service->offeredChoresFor($this->profile)->map(fn (Chore $chore) => [
                'chore' => $chore,
                'points' => $chore->points,
                'bonus' => $cardBonuses[$chore->id] ?? 0,
                'bold' => isset($cardBonuses[$chore->id]),
                'takenBy' => $service->claimantOtherThan($chore, $this->profile)?->profile,
                'expired' => $service->isExpired($chore),
            ])
            : collect();

        $household = $this->profile->household;

        return [
            'household' => $household,
            'quest' => $quest,
            'questRevealed' => $quest?->revealed_at !== null,
            // The chest and the pick are separate stamps: the chest stays open
            // across a refresh while the kid is still deciding, which is what
            // stops the 2.6s rattle replaying every time they look at the page.
            'handDealt' => $quest?->dealt_at !== null,
            'questCards' => $questCards,
            'questBoldBonus' => $quest ? ($cardBonuses[$quest->chore_id] ?? 0) : 0,
            // Null until the charm is cast, and its effect stays null until the
            // chest is opened — the two together are what the cards' charm
            // strip reads.
            'questCharm' => $quest?->isCharmed() ? $quest->charm_effect : null,
            'questCharmed' => (bool) $quest?->isCharmed(),
            'questCharmPayout' => $quest ? $service->charmPayoutFor($this->profile) : 0,
            'questClosesAt' => $quest ? $service->deadlineFor($quest->chore) : null,
            'questDone' => $quest?->completed_at !== null,
            'questApproved' => $completion?->status === CompletionStatus::Approved,
            'questPending' => $completion?->status === CompletionStatus::Pending,
            'questSentBack' => $completion?->status === CompletionStatus::Rejected,
            'questBoosted' => $questBoosted,
            // The bold card's bonus rides on top of any wheel multiplier rather
            // than being multiplied by it — see BOLD_CARD_BONUS_PERCENT.
            'questPoints' => $quest
                ? $quest->chore->points * ($questBoosted ? $boost->multiplier : 1)
                    + ($cardBonuses[$quest->chore_id] ?? 0)
                    + $service->charmPayoutFor($this->profile)
                : 0,
            // Contextual "use it here" buttons for the perks that act on this
            // page, so a kid doesn't have to go hunting in the shop.
            // The streak chest's own card: the track, the rescue window, and
            // what a milestone is worth. It came off the Quests page with the
            // chest itself — the tray slot there was the only thing that could
            // open one, so leaving the track behind would have split the reward
            // from the explanation of it.
            'nextMilestone' => $quest ? $service->nextStreakMilestone($this->profile) : 0,
            'streakBonuses' => collect($quest ? $service->streakTrackFor($this->profile)['milestones'] : []),
            'streakLap' => $quest ? $service->streakTrackFor($this->profile)['lap'] : 1,
            // Null unless a broken chain is still savable — which stops being
            // true the moment today's quest is cleared, so the offer has to be
            // on the page a kid is looking at when they decide.
            'streakRepair' => $quest ? $service->repairPreview($this->profile) : null,
            // Only with a quest to act on: a blocked reason is worked out by
            // asking what today's quest is, which is the call that throws in a
            // household with nothing to draw one from.
            'heldPerks' => collect($quest ? [PerkEffect::QuestReroll, PerkEffect::QuestCharm, PerkEffect::StreakRestore] : [])
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
            // Recomputed rather than read off the mount snapshot: the snapshot's
            // job is to hold the *animation* still, and this decides whether
            // there is a chest to animate at all.
            'chestAvailable' => app(ChestService::class)->isAvailable($this->profile),
            'boost' => $boost,
            'boostClaim' => $this->boostClaim($boost),
            'wheelChores' => $wheelChores,
            'wheelSlice' => 360 / max(1, $wheelChores->count()),
            'respin' => $inventory->holds($this->profile, PerkEffect::WheelRespin)
                ? [
                    'effect' => PerkEffect::WheelRespin,
                    'count' => $inventory->countOf($this->profile, PerkEffect::WheelRespin),
                    'blocked' => $inventory->blockedReason($this->profile, PerkEffect::WheelRespin),
                ]
                : null,
            // Whether there is a Lucky Block to point at. The strip above the
            // run needs one boolean and the ticket count already on the
            // profile — the block itself, its rules and its prize list all
            // live in the Loot Shop, which is the point of it being a strip.
            'luckyOpen' => app(LuckyBlockService::class)->isOpenFor($this->profile),
            'standings' => $this->standings(),
            // The week's shared chore target and what hitting it pays. Null
            // when a parent hasn't set one, which takes the bar with it.
            'houseWeek' => app(ArenaService::class)->houseWeek($household),
            // Status only — no replay, and nothing marked seen. See
            // <x-monster-mini> for why the catch-up belongs to the Arena.
            'monsterState' => $this->monsterState(),
            // A count rather than a list. The claim a kid is waiting on already
            // says so on its own card over on Quests, and the number that
            // matters here is how much damage is still in the post — which is
            // why it rides on the boss caption.
            'pendingCount' => ChoreCompletion::where('profile_id', $this->profile->id)
                ->where('status', CompletionStatus::Pending)
                ->count(),
        ];
    }
}; ?>

<x-kid.shell :profile="$profile" active="home">
    {{-- The monster, watching the day get cleared. --}}
    @if ($monsterState)
        <x-monster-watcher :state="$monsterState" />
    @endif

    {{-- Above the run, and not a section: it points at something on another
         page rather than being something to do here. Renders nothing below two
         tickets, or with an empty pool. --}}
    <x-lucky-strip :tickets="$profile->bonus_tickets" :open="$luckyOpen" class="mb-[22px]" />

    <div class="flex flex-col gap-[22px]">
        {{-- The daily quest, opened right here. The hero is the same component
             the Quests page draws, so the chest, the hand, the charm window and
             the claim are one implementation rather than two that drift. --}}
        @php
            [$questStatus, $questStatusColor] = match (true) {
                ! $quest => ['Nothing today', 'var(--fq-text-4)'],
                $questApproved => ['Cleared', 'var(--fq-lime)'],
                $questPending => ['Waiting on a parent', 'var(--fq-gold)'],
                $questSentBack => ['Sent back', 'var(--fq-danger)'],
                ! $questRevealed => ['Not opened yet', 'var(--fq-gold)'],
                default => ['Still to do', 'var(--fq-gold)'],
            };
        @endphp

        <div class="flex flex-col gap-3">
            <x-home-section
                title="Daily Quest"
                accent="var(--fq-gold)"
                :done="$questApproved || $questPending"
                :status="$questStatus"
                :status-color="$questStatusColor"
            />

            @if (! $quest)
                <div
                    class="rounded-[24px] border p-5"
                    style="background: var(--fq-wash-gold); border-color: var(--fq-line-3)"
                >
                    <h3 class="font-baloo text-[22px] leading-[1.15] font-extrabold">No quest today</h3>
                    <p class="mt-[6px] text-[13.5px] text-fq-text-2">
                        There's nothing on the board a parent has set for you yet. Check back later.
                    </p>
                </div>
            @else
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

                {{-- Straight under the hero, which is the only thing on this
                     page that raises one. --}}
                @if ($perkMessage)
                    <div class="rounded-[16px] border border-fq-line-2 bg-fq-sunk px-4 py-3 text-sm text-fq-text-2">
                        {{ $perkMessage }}
                    </div>
                @endif
            @endif
        </div>

        {{-- The bonus chest. Opens right here — it is one tap and it has no page
             of its own, so sending a kid somewhere to take it would be the
             errand this whole page exists to remove. --}}
        <div class="flex flex-col gap-3">
            <x-home-section
                title="Bonus Chest"
                accent="var(--fq-chest-blue)"
                :done="! $chestAvailable"
                :status="$chestAvailable ? ($questDone ? 'Ready · OP' : 'Ready to open') : 'Opened today'"
                :status-color="$chestAvailable ? 'var(--fq-chest-blue)' : 'var(--fq-lime)'"
            />

            {{-- Always the chest, never a "come back tomorrow" panel in its
                 place. A chest already opened from the Quests tray still draws
                 here and still opens: openDailyChest() finds the one that
                 exists and describes it, so the tap tells the kid what they got
                 rather than dead-ending. --}}
            <x-chest
                wire-key="home-bonus-chest"
                :revealed="$chestOpened"
                open-action="openDailyChest"
                accent="var(--fq-chest-blue)"
                wash="var(--fq-chest-blue-bg)"
                fill="var(--fq-chest-blue-fill)"
                kicker="Free Every Single Day"
                :closed-title="$questDone ? 'Your chest is OP today' : 'Open today\'s bonus chest'"
                :closed-text="$questDone
                    ? 'Quest cleared, so this one rolls on the good table — more tickets, more perks.'
                    : 'Tickets, points, or a perk. Clear your quest first and it rolls on a much better table.'"
                cta="Open it"
                :prize-label="$dailyChestPrize ?? 'A prize!'"
                :prize-sub="$questDone ? 'Bonus Chest · OP' : 'Bonus Chest'"
                prize-property="dailyChestPrize"
                {{-- The kids were opening this first thing every morning
                     and never finding out the quest makes it better. So:
                     stop once and ask, exactly as the Quests tray does. --}}
                :confirm="$chestAvailable && ! $questDone"
            >
                @if ($chestAvailable && ! $questDone)
                    <x-slot:confirm-panel>
                        <p class="mb-2 font-mono-fq text-[10px] tracking-[0.16em] uppercase" style="color: var(--fq-lime)">
                            Hold on &mdash; OP loot
                        </p>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <a
                                href="{{ route('kid.quests') }}"
                                wire:navigate
                                class="rounded-[16px] px-[20px] py-[13px] text-center font-baloo text-[16px] font-extrabold transition hover:brightness-110"
                                style="background: var(--fq-lime); color: var(--fq-ink)"
                            >Do my quest first</a>

                            <button
                                type="button"
                                @click="begin()"
                                class="cursor-pointer rounded-[16px] border px-[20px] py-[13px] font-baloo text-[16px] font-bold transition hover:brightness-125"
                                style="border-color: var(--fq-line-3); color: var(--fq-text-3)"
                            >Open now anyway</button>
                        </div>
                    </x-slot:confirm-panel>
                @endif

                <div
                    class="rounded-[24px] border p-5"
                    style="animation: fq-pop .3s ease both; background: var(--fq-chest-blue-bg); border-color: var(--fq-chest-blue-line)"
                >
                    <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: var(--fq-chest-blue)">
                        Today's Bonus Chest
                    </p>
                    <p class="mt-[6px] font-baloo text-[22px] leading-[1.15] font-extrabold">
                        {{ $dailyChestPrize ?? 'Banked!' }}
                    </p>
                    <p class="mt-[6px] text-[13px] text-fq-text-4">Banked. There's another one tomorrow.</p>
                </div>
            </x-chest>
        </div>

        {{-- The streak chest, moved off the Quests page along with the loot
             tray that used to be the only thing able to open one. The chest and
             the track that explains what it pays belong together, and this is
             the page the rest of the daily loop is on. --}}
        @if ($streakBonuses->isNotEmpty())
            @php
                $daysToChest = max(0, $nextMilestone - $profile->streak);

                // No "all unlocked" any more: the track laps, so there is always
                // another chest ahead of whatever they're on.
                [$streakStatus, $streakStatusColor] = match (true) {
                    (bool) $pendingChestDay => ['Ready to open', 'var(--fq-streak)'],
                    (bool) $streakRepair => ['Streak ended', 'var(--fq-streak)'],
                    $profile->streak === 0 => ['Start a run', 'var(--fq-text-4)'],
                    default => [$daysToChest.' '.Str::plural('day', $daysToChest).' to go', 'var(--fq-text-4)'],
                };
            @endphp

            <div class="flex flex-col gap-3">
                <x-home-section
                    title="Streak Chest"
                    accent="var(--fq-streak)"
                    :done="(bool) $pendingChestDay"
                    :status="$streakStatus"
                    :status-color="$streakStatusColor"
                />

                {{-- Only when there is one to open. Unlike the bonus chest this
                     is earned rather than handed out, so on every other day the
                     card below is the whole of it — a track, not a shut box. --}}
                @if ($pendingChestDay)
                    <x-chest
                        wire-key="home-streak-chest"
                        :revealed="false"
                        open-action="openStreakChest"
                        accent="var(--fq-streak)"
                        wash="var(--fq-wash-streak)"
                        fill="var(--fq-chest-streak-fill)"
                        :kicker="$pendingChestDay.'-Day Streak · Earned'"
                        closed-title="Your streak chest is waiting"
                        :closed-text="'You cleared '.$pendingChestDay.' '.Str::plural('night', $pendingChestDay).' in a row. This one is yours.'"
                        cta="Open it"
                        :prize-label="'+'.number_format((int) $pendingChestPoints).' PTS'"
                        :prize-sub="$pendingChestDay.'-Day Streak Bonus!'"
                    >
                        <div
                            class="rounded-[24px] border p-5"
                            style="animation: fq-pop .3s ease both; background: var(--fq-wash-streak); border-color: color-mix(in srgb, var(--fq-streak) 55%, transparent)"
                        >
                            <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: var(--fq-streak)">
                                {{ $pendingChestDay }}-Day Streak Bonus
                            </p>
                            <p class="mt-[6px] font-baloo text-[22px] leading-[1.15] font-extrabold">
                                +{{ number_format((int) $pendingChestPoints) }} PTS banked
                            </p>
                        </div>
                    </x-chest>
                @endif

                <div
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
        @endif

        {{-- The spin. This is the whole of the old Bonus Wheel page: the
             wheel, the button, and what to do with what it lands on. --}}
        <div class="flex flex-col gap-3">
            <x-home-section
                title="Bonus Wheel"
                accent="var(--fq-magenta)"
                :done="$spinRevealed"
                :status="$spinRevealed ? 'Spun today' : 'One spin waiting'"
                :status-color="$spinRevealed ? 'var(--fq-lime)' : 'var(--fq-magenta)'"
            />

            <div
                x-data
                x-init="$watch('$wire.spinning', (value) => { if (value) setTimeout(() => $wire.finishSpin(), 6100) })"
                class="grid grid-cols-[repeat(auto-fit,minmax(300px,1fr))] items-stretch gap-4"
            >
                <div class="flex flex-col items-center justify-center gap-[18px] rounded-[24px] border border-fq-line bg-fq-panel p-[22px] text-center">
                    <p class="font-mono-fq text-[10px] tracking-[0.24em] text-fq-magenta uppercase">One Spin Per Day</p>

                    <div class="relative h-[290px] w-[290px]">
                        <div
                            class="absolute top-0 left-1/2 z-[3] -translate-x-1/2 drop-shadow-[0_3px_6px_#000]"
                            style="width:0;height:0;border-left:12px solid transparent;border-right:12px solid transparent;border-top:20px solid var(--fq-gold)"
                        ></div>

                        {{-- Shadow lives on this static wrapper — box-shadow rotates along with
                             its element, so it has to stay off the div that spins. --}}
                        <div class="absolute inset-0 rounded-full" style="box-shadow:0 0 0 3px var(--fq-wheel-ring), var(--fq-shadow-wheel);">
                            <div
                                class="absolute inset-0 rounded-full"
                                style="
                                    background: var(--fq-wheel-rim);
                                    transform: rotate({{ $wheelDeg }}deg);
                                    transition: transform 6s cubic-bezier(.11,.85,.1,1);
                                "
                            >
                                {{-- Colorful cookie face — a wedge per chore, cycling through the
                                     app's accent palette, muted under a dark cookie-toned overlay so
                                     it still reads as chocolatey rather than a plain rainbow. --}}
                                @php
                                    $wheelPalette = ['var(--fq-lime)', 'var(--fq-cyan)', 'var(--fq-gold)', 'var(--fq-magenta)', 'var(--fq-coral)', 'var(--fq-violet)', 'var(--fq-sky)'];
                                    $wheelStops = $wheelChores->values()->map(
                                        fn ($wc, $i) => $wheelPalette[$i % count($wheelPalette)] . ' ' . ($i * $wheelSlice) . 'deg ' . (($i + 1) * $wheelSlice) . 'deg'
                                    )->implode(', ');
                                @endphp
                                <div class="absolute inset-[13px] rounded-full" style="background: conic-gradient(from 90deg, {{ $wheelStops }})"></div>
                                <div class="absolute inset-[13px] rounded-full" style="background: radial-gradient(circle at 38% 32%, rgba(40,25,12,.45), rgba(20,12,6,.72) 75%)"></div>

                                {{-- Segment dividers — one per chore boundary, radiating from the
                                     center plate out to the rim, so it reads as an actual wheel
                                     of distinct outcomes instead of plain decoration. --}}
                                @for ($i = 0; $i < $wheelChores->count(); $i++)
                                    @php $boundaryDeg = $i * $wheelSlice; @endphp
                                    <div
                                        class="absolute"
                                        style="
                                            left:145px; top:145px; width:2px; height:132px;
                                            background: color-mix(in srgb, var(--fq-wheel-label) 40%, transparent);
                                            transform-origin: top center;
                                            transform: translateX(-1px) rotate({{ $boundaryDeg - 90 }}deg);
                                        "
                                    ></div>
                                @endfor

                                {{-- Chore name per segment, running from the center plate out to
                                     the rim along its slice — reads outward, tilt-your-head style,
                                     which fits far more of each name than a horizontal label would.

                                     The name and nothing else. The points were tried here and
                                     taken back out: a slice is 98px of 9px type, so a number on
                                     the end is bought with the name's last few characters — and
                                     the payout is stated in full the moment the wheel stops,
                                     which is the only point at which one of these numbers is the
                                     one that matters. --}}
                                @foreach ($wheelChores as $i => $wheelChore)
                                    @php $midDeg = $i * $wheelSlice + $wheelSlice / 2; @endphp
                                    <div
                                        class="absolute overflow-hidden text-ellipsis whitespace-nowrap font-mono-fq font-semibold"
                                        style="
                                            left:145px; top:145px; margin-top:-6px; width:98px; height:12px;
                                            transform-origin: left center;
                                            transform: rotate({{ $midDeg }}deg) translateX(30px);
                                            font-size:9px; letter-spacing:.01em; color: var(--fq-wheel-label);
                                            text-shadow: 0 1px 1px rgba(0,0,0,.5);
                                        "
                                    >{{ $wheelChore->name }}</div>
                                @endforeach

                                {{-- Small decorative hub — just the wheel's center pin, not a
                                     click target (the real spin action is the button below). --}}
                                <div
                                    class="absolute top-1/2 left-1/2 z-[2] rounded-full"
                                    style="width:22px; height:22px; transform: translate(-50%, -50%); background: var(--fq-wheel-hub); border: 2px solid var(--fq-wheel-hub-line); box-shadow: inset 0 1px 3px rgba(0,0,0,.5)"
                                ></div>
                            </div>
                        </div>
                    </div>

                    @php
                        // The landed panel and the Active Boost card are the same
                        // result stated twice, so they're tinted from one place.
                        $boostIsBig = $boost && $boost->multiplier === 3;
                        $boostTint = $boostIsBig
                            ? 'background: color-mix(in srgb, var(--fq-gold) 20%, transparent); border-color: color-mix(in srgb, var(--fq-gold) 55%, transparent)'
                            : 'background: color-mix(in srgb, var(--fq-magenta) 20%, transparent); border-color: color-mix(in srgb, var(--fq-magenta) 50%, transparent)';
                        $boostColor = $boostIsBig ? 'var(--fq-gold)' : 'var(--fq-magenta)';
                    @endphp

                    @if ($spinRevealed && $boost && ! $spinning)
                        <div
                            wire:key="landed-{{ $boost->id }}"
                            class="w-full max-w-[300px] rounded-[16px] border px-4 py-3 text-center"
                            style="animation: fq-pop .3s ease both; {{ $boostTint }}"
                        >
                            <p class="font-mono-fq text-[10px] tracking-[0.2em] uppercase" style="color: {{ $boostColor }}">You landed on</p>
                            <p class="mt-1 font-baloo text-lg font-extrabold">{{ $boost->chore->name }} &mdash; {{ $boost->multiplier }}x</p>
                            {{-- The multiplier stated as the number it actually pays.
                                 "3x" is arithmetic homework; the total is the thing
                                 worth getting off the sofa for. --}}
                            <p class="mt-1 font-mono-fq text-[11px] text-fq-text-3">
                                {{ number_format($boost->chore->points) }}
                                <span class="text-fq-text-5">&rarr;</span>
                                <span class="font-semibold" style="color: {{ $boostColor }}">{{ number_format($boost->chore->points * $boost->multiplier) }} PTS</span>
                            </p>
                        </div>
                    @endif
                </div>

                <div class="flex flex-col gap-4">
                    {{-- The spin lives here rather than under the wheel: on a phone the
                         wheel fills the screen, and the button a kid came for shouldn't
                         be the thing they have to scroll past it to find. --}}
                    <div class="flex flex-col gap-3 rounded-[24px] border border-fq-line bg-fq-panel p-5">
                        @if ($spinning)
                            <button type="button" disabled class="w-full cursor-default rounded-[18px] bg-fq-line-2 py-4 font-baloo text-[19px] font-extrabold text-fq-text-3">
                                Spinning&hellip;
                            </button>
                        @elseif ($spinRevealed)
                            <button type="button" disabled class="w-full cursor-default rounded-[18px] bg-fq-line-2 py-4 font-baloo text-[19px] font-extrabold text-fq-text-3">
                                Used today &mdash; back tomorrow
                            </button>
                        @else
                            <button
                                type="button"
                                wire:click="spin"
                                class="w-full rounded-[18px] py-4 font-baloo text-[19px] font-extrabold text-fq-bg transition hover:brightness-110"
                                style="background:var(--fq-magenta); box-shadow: var(--fq-shadow-glow-lg) var(--fq-magenta)"
                            >SPIN</button>
                        @endif

                        <p class="text-[13px] text-fq-text-4">
                            @if ($spinRevealed)
                                One spin a day. Your boost is locked in below.
                            @else
                                Land on a chore, get 2x or 3x its points — plus a sweet treat when you finish it. Do it today.
                            @endif
                        </p>

                        @if ($respin)
                            <div class="flex flex-col items-start gap-1">
                                <x-perk-button :entry="$respin" />
                                @if ($respin['blocked'])
                                    <span class="font-mono-fq text-[10px] text-fq-text-5">{{ $respin['blocked'] }}</span>
                                @endif
                            </div>
                        @endif

                        @if ($perkMessage)
                            <p class="text-[13px] text-fq-text-4">{{ $perkMessage }}</p>
                        @endif
                    </div>

                    <div class="flex flex-1 flex-col rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
                        <h3 class="font-baloo text-lg font-bold">Active Boost</h3>
                        @if ($spinRevealed && $boost)
                            <div class="mt-3 flex items-center justify-between gap-3 rounded-[16px] border p-[14px]" style="{{ $boostTint }}">
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold">{{ $boost->chore->name }}</span>
                                    <span class="font-mono-fq text-[10px] tracking-[0.1em] text-fq-text-4 uppercase">
                                        {{ number_format($boost->chore->points) }} &rarr; {{ number_format($boost->chore->points * $boost->multiplier) }} pts
                                    </span>
                                </span>
                                <span class="font-baloo text-[22px] font-extrabold whitespace-nowrap" style="color: {{ $boostColor }}">{{ $boost->multiplier }}x</span>
                            </div>

                            {{-- The claim, right here. The boosted chore is the one a
                                 kid came to the wheel for; sending them off to find it
                                 again on the board is a tab switch for no reason. --}}
                            @if ($boostClaim && $boostClaim['claimable'])
                                <button
                                    type="button"
                                    wire:click="claimBoostedChore"
                                    class="mt-3 w-full rounded-[14px] py-[11px] text-sm font-semibold text-fq-bg transition hover:brightness-110"
                                    style="background: var(--fq-lime)"
                                >{{ $boostClaim['label'] }}</button>
                            @elseif ($boostClaim)
                                <button
                                    type="button"
                                    disabled
                                    class="mt-3 w-full cursor-default rounded-[14px] bg-fq-panel-alt py-[11px] text-sm font-semibold text-fq-text-4"
                                >{{ $boostClaim['label'] }}</button>
                            @endif

                            @if ($boostClaim && $boostClaim['note'])
                                <p class="mt-2 text-[13px] text-fq-text-5">{{ $boostClaim['note'] }}</p>
                            @endif

                            @if ($boostClaim && $boostClaim['toQuests'])
                                <a
                                    href="{{ route('kid.quests') }}"
                                    wire:navigate
                                    class="mt-2 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase transition hover:text-fq-text"
                                >Go to Quests &rarr;</a>
                            @endif

                            @if ($boostMessage)
                                <p class="mt-2 text-[13px] font-semibold text-fq-gold">{{ $boostMessage }}</p>
                            @endif
                        @else
                            <p class="mt-3 text-[13px] text-fq-text-5">No boost yet today.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- The weekly prize. A card of its own rather than a strip inside the
             standings, which is where it started and where nobody found it:
             this is the only thing on the page the whole house is chasing
             together, and it has a deadline, so it can't be a footnote under
             something else.

             The bar is segmented per kid because the target is shared — one
             undivided bar would hide that somebody did most of it — and the
             number rides inside each segment so the colours need no legend. --}}
        @if ($houseWeek)
            @php
                $weekLeft = max(0, $houseWeek['target'] - $houseWeek['done']);
                $weekPrize = $household->weekly_prize ?: 'a house bonus';
                $weekDaysLeft = (int) ceil(now($household->timezone)->diffInDays($houseWeek['resetsAt'], absolute: true));
            @endphp

            <div wire:key="house-week" class="flex flex-col gap-3">
                <x-home-section
                    title="Weekly Prize"
                    accent="var(--fq-gold)"
                    :done="$weekLeft === 0"
                    :status="$weekLeft === 0
                        ? 'Won it'
                        : number_format($weekLeft).' '.Str::plural('chore', $weekLeft).' to go'"
                    :status-color="$weekLeft === 0 ? 'var(--fq-lime)' : 'var(--fq-gold)'"
                />

                <div
                    class="flex flex-col gap-[11px] rounded-[24px] border p-5"
                    style="background: var(--fq-wash-gold); border-color: {{ $weekLeft === 0 ? 'var(--fq-lime)' : 'var(--fq-gold)' }}"
                >
                    {{-- The prize is the headline. A bar promising an unnamed
                         reward is a bar nobody chases, and "80 chores" is the
                         price rather than the thing being bought. --}}
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="font-baloo text-[22px] leading-[1.15] font-extrabold sm:text-[26px]">{{ $weekPrize }}</h3>
                        <span class="font-mono-fq text-[10px] whitespace-nowrap text-fq-text-4">
                            {{ number_format($houseWeek['done']) }} / {{ number_format($houseWeek['target']) }} CHORES · SUN&ndash;SAT
                        </span>
                    </div>

                    <div class="flex h-[30px] overflow-hidden rounded-[10px]" style="background: var(--fq-sunk)">
                        @foreach ($houseWeek['segments'] as $segment)
                            @if ($segment['chores'] > 0)
                                <div
                                    wire:key="week-{{ $segment['profile']->id }}"
                                    title="{{ $segment['profile']->name }} — {{ $segment['chores'] }}"
                                    class="grid place-items-center font-mono-fq text-[11px] font-semibold"
                                    style="width: {{ min(100, $segment['chores'] / max(1, $houseWeek['target']) * 100) }}%;
                                           color: var(--fq-bg);
                                           background: linear-gradient(90deg, color-mix(in srgb, {{ $segment['profile']->color->cssVar() }} 62%, #000), {{ $segment['profile']->color->cssVar() }})"
                                >{{ $segment['chores'] }}</div>
                            @endif
                        @endforeach
                    </div>

                    <p class="text-[13px] leading-snug text-fq-text-2" style="text-wrap: pretty">
                        @if ($weekLeft === 0)
                            Target smashed — the whole house gets {{ $weekPrize }}. Nobody had to win it.
                        @else
                            {{ number_format($weekLeft) }} more {{ Str::plural('chore', $weekLeft) }} between all of you and
                            the whole house gets {{ $weekPrize }}. Everyone's chores count towards the same bar —
                            nobody has to win it.
                        @endif
                    </p>

                    <p class="font-mono-fq text-[10px] tracking-[0.12em] text-fq-text-5 uppercase">
                        @if ($weekDaysLeft <= 1)
                            Last day &mdash; the bar resets on Sunday
                        @else
                            {{ $weekDaysLeft }} days left &middot; the bar resets on Sunday
                        @endif
                    </p>
                </div>
            </div>
        @endif

        {{-- The boss fight, moved off the Quests page. It sits above the
             standings because it is the other thing the house is doing
             *together* — like the weekly prize above it, and unlike the
             standings, which are the one card on the page about who is beating
             whom. That one goes last. --}}
        @if ($monsterState)
            <div class="flex flex-col gap-3">
                <x-home-section title="The Fight" accent="var(--fq-coral)" />

                <div wire:key="family-boss">
                    <x-monster-mini :state="$monsterState" :pending="$pendingCount" />
                </div>
            </div>
        @endif

        {{-- Where the house stands, and the last thing on the page. The league
             table under the Arena and nothing else from it: no candles, no
             lanes, no monster. A kid glancing at this should get who is ahead
             and who still has work to do, and go to the Arena when they want
             the story.

             Last on purpose. Everything above it is something to go and do or
             something the house is doing together; this is the only card that
             ranks the kids against each other, and it shouldn't be what sits
             between a kid and the rest of their day. --}}
        <div class="flex flex-col gap-3">
            @php
                $mine = $standings->first(fn (array $row) => $row['profile']->is($profile));
                $openCount = $standings->where('state', '!==', App\Services\ArenaService::STATE_SAFE)->count();
            @endphp

            <x-home-section
                title="House Standings"
                accent="var(--fq-violet)"
                :done="$standings->isNotEmpty() && $openCount === 0"
                :status="$standings->isEmpty()
                    ? null
                    : ($openCount === 0
                        ? 'Everyone cleared'
                        : $openCount.' still open')"
                :status-color="$openCount === 0 ? 'var(--fq-lime)' : 'var(--fq-text-4)'"
            />

            <div class="rounded-[24px] border border-fq-line bg-fq-panel p-[18px]">

                @if ($standings->isEmpty())
                    <p class="text-[13px] text-fq-text-5">
                        Nothing to stand on yet — the table fills up once there are quests to clear.
                    </p>
                @else
                    <div class="flex flex-col gap-[9px]">
                        @foreach ($standings as $index => $row)
                            @php
                                $kid = $row['profile'];
                                $isMe = $kid->is($profile);

                                [$stateLabel, $stateInk] = match (true) {
                                    $row['state'] === App\Services\ArenaService::STATE_SAFE => ['Cleared', 'var(--fq-lime)'],
                                    $row['state'] === App\Services\ArenaService::STATE_AT_RISK => ['At risk', 'var(--fq-streak)'],
                                    $row['state'] === App\Services\ArenaService::STATE_BROKEN => ['Back to zero', 'var(--fq-text-4)'],
                                    default => ['Still open', 'var(--fq-text-3)'],
                                };
                            @endphp

                            <div
                                wire:key="standing-{{ $kid->id }}"
                                class="flex items-center gap-3 rounded-[16px] border p-[10px_12px]"
                                style="border-color: {{ $isMe ? $kid->color->cssVar() : 'var(--fq-line)' }};
                                       background: {{ $isMe ? 'var(--fq-tab-active)' : 'var(--fq-sunk)' }}"
                            >
                                <span class="w-[16px] shrink-0 text-center font-mono-fq text-[12px] text-fq-text-4">{{ $index + 1 }}</span>

                                <span
                                    class="grid h-[36px] w-[36px] shrink-0 place-items-center rounded-[12px] font-baloo text-[16px] font-extrabold"
                                    style="background: {{ $kid->color->cssVar() }}; color: var(--fq-bg)"
                                >{{ mb_substr($kid->name, 0, 1) }}</span>

                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-baloo text-[16px] font-bold">{{ $kid->name }}</span>
                                    <span class="font-mono-fq text-[10px] tracking-[0.12em] text-fq-text-4">
                                        {{ $row['streak'] }} {{ Str::plural('NIGHT', $row['streak']) }} IN A ROW
                                    </span>
                                </span>

                                <span
                                    class="shrink-0 rounded-full px-[10px] py-[4px] font-mono-fq text-[10px] font-semibold tracking-[0.12em] whitespace-nowrap uppercase"
                                    style="background: var(--fq-panel-alt); color: {{ $stateInk }}"
                                >{{ $stateLabel }}</span>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-[14px] flex flex-wrap items-center justify-between gap-3">
                        <p class="text-[13px] text-fq-text-4">
                            @if ($mine && $mine['state'] === App\Services\ArenaService::STATE_SAFE)
                                Your night is safe. Clear one every day and the run keeps climbing.
                            @else
                                Clear today's quest and tonight counts towards your run.
                            @endif
                        </p>

                        <a
                            href="{{ route('kid.arena') }}?world=house"
                            wire:navigate
                            class="rounded-[13px] border border-fq-line-3 bg-fq-sunk px-[16px] py-[10px] text-[13px] whitespace-nowrap text-fq-text-2-b transition hover:border-fq-lime hover:text-fq-text"
                        >See the Arena &rarr;</a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-kid.shell>
