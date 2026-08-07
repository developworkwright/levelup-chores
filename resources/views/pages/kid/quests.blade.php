<?php

use App\Enums\CompletionStatus;
use App\Enums\PerkEffect;
use App\Exceptions\PerkUnavailableException;
use App\Models\Badge;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Profile;
use App\Services\BossService;
use App\Services\ChestService;
use App\Services\ChoreService;
use App\Services\GratitudeService;
use App\Services\HouseholdClock;
use App\Services\PerkInventoryService;
use App\Services\SpinService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

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
        // STREAK_BONUSES is denominated in dollars, but every other number a
        // kid sees is points — so convert once here and never show dollars.
        $this->pendingChestPoints = $this->pendingChestDay
            ? (ChoreService::STREAK_BONUSES[$this->pendingChestDay] ?? 0) * $this->profile->household->points_per_dollar
            : null;

        $this->dailyChestAvailable = app(ChestService::class)->isAvailable($this->profile);
    }

    public function revealQuest(): void
    {
        // The celebration itself fires client-side when the chest's own
        // suspense animation finishes — not here, before it's even started.
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

        // The streak (and any milestone bonus) now moves on a parent's
        // approval, so don't quote a day count here that hasn't been earned
        // yet — and when it is earned, the chest still does the reveal. A quest
        // that happens to be the mystery chore says nothing about it either,
        // for the same reason claimChore() doesn't.
        if (! $wasDone) {
            if ($boosted) {
                $this->dispatch('celebrate', message: 'Quest cleared! Bonus wheel treat earned.', treat: 'cookie');
            } else {
                $this->dispatch('celebrate', message: 'Quest cleared! Your streak grows once a parent approves.');
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
    public function logGratitude(): void
    {
        $service = app(GratitudeService::class);

        if ($service->record($this->profile, $this->gratitude)) {
            $this->gratitude = ['', '', ''];
            $this->gratitudeMessage = null;

            $this->dispatch('celebrate', message: 'Gratitude logged! +'.GratitudeService::TICKETS.' tickets.');

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
            $this->dispatch('celebrate', message: $outcome);
        } catch (PerkUnavailableException $e) {
            $this->perkMessage = $e->getMessage();
        }
    }

    public function openStreakChest(): void
    {
        app(ChoreService::class)->openStreakChest($this->profile);
    }

    public function claimChore(int $choreId): void
    {
        $this->boardMessage = null;

        $chore = Chore::find($choreId);

        if (! $chore || $chore->household_id !== $this->profile->household_id) {
            return;
        }

        $service = app(ChoreService::class);
        $quest = $service->questFor($this->profile);
        $gated = $this->profile->household->require_quest_first && $quest->completed_at === null;

        // Re-check server-side — never trust a disabled button in the browser.
        // stateFor() already accounts for the mystery chore's household-wide
        // (not per-kid) exclusivity, so no special-casing is needed here.
        if (
            $gated
            || $chore->id === $quest->chore_id
            || ! $chore->isAppropriateFor($this->profile)
        ) {
            return;
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

            return;
        }

        $boosted = app(SpinService::class)->multiplierFor($this->profile, $chore) > 1;

        // Nothing here says anything about the mystery chore, deliberately.
        // Announcing the find on the tap told a kid which chore carried the
        // bonus for the price of submitting it, so submitting everything on the
        // board was a way to be told the answer. It's announced when a parent
        // approves the work, by the card the kid shell queues.
        if ($boosted) {
            $this->dispatch('celebrate', message: "{$chore->name} claimed! Bonus wheel treat earned.", treat: 'cookie');
        } else {
            $this->dispatch('celebrate', message: "{$chore->name} claimed! Waiting on parent.");
        }

        $service->claim($this->profile, $chore);
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
        $mysteryFinder = $service->mysteryFinderFor($household);

        $gratitude = app(GratitudeService::class);

        $nextMilestone = $service->nextStreakMilestone($this->profile);

        $earnedToday = $service->pointsEarnedToday($this->profile);

        $pointsPerDollar = $this->profile->household->points_per_dollar;

        $streakBonuses = collect(ChoreService::STREAK_BONUSES)
            ->map(fn ($dollars, $day) => [
                'day' => $day,
                'points' => $dollars * $pointsPerDollar,
                'reached' => $this->profile->streak >= $day,
            ])
            ->values();

        return [
            'quest' => $quest,
            // A quest chore that expires is rerolled by questFor() rather than
            // left to dead-end the day, so this is only ever a live deadline.
            'questClosesAt' => $service->deadlineFor($quest->chore),
            'questRevealed' => $questRevealed,
            'questDone' => $questDone,
            'questApproved' => $questApproved,
            'questPending' => $questPending,
            'questSentBack' => $questSentBack,
            'boost' => $boost,
            'questBoosted' => $questBoosted,
            'questPoints' => $quest->chore->points * ($questBoosted ? $boost->multiplier : 1),
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
            'mysteryHint' => $service->mysteryHintFor($this->profile),
            // Today's only. Everything older lives on the Journal tab — this
            // page is about the day in front of you.
            'gratitudeToday' => $gratitude->todayFor($this->profile),
            // Contextual "use it here" buttons for the perks that act on this
            // page, so a kid doesn't have to go hunting in the shop.
            'heldPerks' => collect([PerkEffect::QuestReroll, PerkEffect::MysteryHint, PerkEffect::StreakRestore])
                ->filter(fn (PerkEffect $effect) => $inventory->holds($this->profile, $effect))
                ->mapWithKeys(fn (PerkEffect $effect) => [$effect->value => [
                    'effect' => $effect,
                    'count' => $inventory->countOf($this->profile, $effect),
                    'blocked' => $inventory->blockedReason($this->profile, $effect),
                ]]),
            'nextMilestone' => $nextMilestone,
            'streakBonuses' => $streakBonuses,
            // Null unless a broken chain is still savable — which stops being
            // true the moment today's quest is cleared, so the offer has to be
            // on the page a kid is looking at when they decide.
            'streakRepair' => $service->repairPreview($this->profile),
            'pending' => ChoreCompletion::where('profile_id', $this->profile->id)
                ->where('status', CompletionStatus::Pending)
                ->with('chore')
                ->latest('submitted_at')
                ->get(),
            'household' => $household,
            'goalPercent' => $household->goal_target > 0
                ? min(100, round($household->goal_now / $household->goal_target * 100))
                : 0,
            'goalContributors' => $service->goalContributors($household),
            // Status only — no replay, and nothing marked seen. See
            // <x-boss-mini> for why the catch-up belongs to the Goal page.
            'bossState' => app(BossService::class)->stateFor($household),
            // The plan made on the Goal Planner, reported back where the work
            // actually happens — a target you only see on the page you set it
            // on is a wish rather than something to play against.
            'dailyGoal' => $this->profile->daily_points_goal,
            'earnedToday' => $earnedToday,
            'dailyGoalPercent' => $this->profile->daily_points_goal > 0
                ? min(100, (int) round($earnedToday / $this->profile->daily_points_goal * 100))
                : 0,
            'badges' => Badge::all(),
            'earnedBadgeKeys' => $this->profile->badges->pluck('key'),
            'allUnlocked' => ! $household->require_quest_first || $questDone,
        ];
    }
}; ?>

<x-kid.shell :profile="$profile" active="quests" refresh-action="refreshBoard">
    <div class="grid grid-cols-1 gap-[14px] lg:grid-cols-[minmax(0,1.6fr)_minmax(260px,1fr)]">
        {{-- Left column --}}
        <div class="flex flex-col gap-[14px]">
            {{-- The two chests are independent, and on a milestone day both
                 stack here — the daily one turns up regardless, the streak one
                 is earned. The milestone track below stays put either way; it's
                 useful every day, not only when a chest is waiting. --}}
            @if ($dailyChestAvailable)
                <x-chest
                    wire-key="daily-chest"
                    :revealed="$dailyChestPrize !== null"
                    open-action="openDailyChest"
                    accent="var(--fq-magenta)"
                    wash="var(--fq-wash-violet)"
                    closed-title="Daily Chest"
                    closed-text="A prize is waiting — clear today's quest first for better odds!"
                    opening-text="Something's rattling inside..."
                    :prize-label="$dailyChestPrize ?? ''"
                    prize-sub="Daily Chest"
                    prize-property="dailyChestPrize"
                >
                    <div
                        wire:key="daily-chest-opened"
                        class="flex flex-col items-center rounded-[24px] border p-8 text-center"
                        style="animation: fq-pop .3s ease both; background: var(--fq-wash-violet); border-color: color-mix(in srgb, var(--fq-magenta) 55%, transparent)"
                    >
                        <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: var(--fq-magenta)">Daily Chest</p>
                        <h2 class="mt-2 font-baloo text-xl font-bold">{{ $dailyChestPrize }}</h2>
                        <p class="mt-1 max-w-[320px] text-sm text-fq-text-2">
                            Come back tomorrow for another one.
                        </p>
                    </div>
                </x-chest>
            @endif

            @if ($pendingChestDay)
                <x-chest
                    wire-key="streak-chest"
                    :revealed="false"
                    open-action="openStreakChest"
                    accent="var(--fq-streak)"
                    wash="var(--fq-wash-streak)"
                    closed-title="Streak Chest"
                    closed-text="Your streak paid off — tap to open your reward!"
                    opening-text="Something's rattling inside..."
                    :prize-label="'+' . $pendingChestPoints . ' PTS'"
                    :prize-sub="$pendingChestDay . '-Day Streak Bonus!'"
                >
                    <div
                        wire:key="streak-chest-opened"
                        class="flex flex-col items-center rounded-[24px] border p-8 text-center"
                        style="animation: fq-pop .3s ease both; background: var(--fq-wash-streak); border-color: color-mix(in srgb, var(--fq-streak) 55%, transparent)"
                    >
                        <p class="font-mono-fq text-[10px] tracking-[0.24em] text-fq-streak uppercase">Streak Chest</p>
                        <h2 class="mt-2 font-baloo text-xl font-bold">+{{ $pendingChestPoints }} pts banked!</h2>
                        <p class="mt-1 max-w-[320px] text-sm text-fq-text-2">
                            {{ $pendingChestDay }}-day streak bonus.
                            @if ($nextMilestone)
                                Keep it going for the next chest at day {{ $nextMilestone }}.
                            @endif
                        </p>
                    </div>
                </x-chest>
            @else
                <div
                    wire:key="streak-progress"
                    class="rounded-[24px] border bg-fq-panel p-6"
                    style="border-color: color-mix(in srgb, var(--fq-streak) 35%, transparent)"
                >
                    <div class="flex items-center justify-between gap-[10px]">
                        <h3 class="font-baloo text-lg font-bold">Streak Chest</h3>
                        <span class="font-mono-fq text-[11px] text-fq-streak">{{ $profile->streak }}-day streak</span>
                    </div>
                    <p class="mt-1 text-sm text-fq-text-2">
                        @if ($streakRepair)
                            Your streak ran out — but it isn't gone yet.
                        @elseif ($nextMilestone && $profile->streak + 1 === $nextMilestone && ! $questDone)
                            Complete today's quest and come back tomorrow to open the chest!
                        @elseif ($nextMilestone)
                            Keep your streak alive — a chest unlocks at day {{ $nextMilestone }}.
                        @else
                            You've unlocked every streak chest. Amazing!
                        @endif
                    </p>

                    {{-- The rescue window, and it really is a window: clearing
                         today's quest starts a fresh chain and closes it, so
                         the copy has to say that before a kid taps past it. --}}
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

                    {{-- Struck-metal markers rather than purple ones: the chest
                         at the end pays out in cash, so the track reads like a
                         coin run. --}}
                    <div class="mt-4 flex items-center gap-1 overflow-x-auto pb-1">
                        @foreach ($streakBonuses as $milestone)
                            <div class="flex flex-shrink-0 flex-col items-center gap-1">
                                <div
                                    class="flex h-9 w-9 items-center justify-center rounded-full border-2 font-baloo text-[11px] font-extrabold"
                                    style="background: {{ $milestone['reached'] ? 'var(--fq-streak-fill)' : 'var(--fq-steel-dim)' }}; border-color: {{ $milestone['reached'] ? 'var(--fq-streak)' : 'var(--fq-steel-dim-line)' }}; color: {{ $milestone['reached'] ? 'var(--fq-streak-ink)' : 'var(--fq-steel-text)' }}"
                                >{{ $milestone['reached'] ? '✓' : $milestone['day'] }}</div>
                                <span
                                    class="font-mono-fq text-[9px] whitespace-nowrap"
                                    style="color: {{ $milestone['reached'] ? 'var(--fq-streak)' : 'var(--fq-steel-dim-label)' }}"
                                >D{{ $milestone['day'] }} · {{ $milestone['points'] }}</span>
                            </div>
                            @unless ($loop->last)
                                <div class="mt-[-14px] h-[2px] w-5 flex-shrink-0" style="background: {{ $milestone['reached'] ? 'var(--fq-streak-fill)' : 'var(--fq-steel-dim-link)' }}"></div>
                            @endunless
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($questDoneOnArrival)
                {{-- Already cleared before this visit — shrink it to a line so
                     hopping between tabs doesn't mean scrolling past a hero
                     card for a quest that's finished. --}}
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
            <x-chest
                wire-key="quest-chest"
                :revealed="$questRevealed"
                open-action="revealQuest"
                accent="var(--fq-lime)"
                closed-title="Today's Main Quest"
                closed-text="Tap the chest to reveal today's main quest — every side quest below stays locked until you do."
                opening-text="The chest is rattling..."
                :prize-label="$quest->chore->name"
                :prize-sub="'+' . $questPoints . ' PTS · Today\'s Quest'"
            >
                <div
                    wire:key="hero"
                    class="rounded-[24px] border p-5"
                    style="animation: fq-pop .3s ease both; background: var(--fq-wash-gold); border-color: rgba(255,225,77,{{ $questDone ? '0.4' : '0.65' }})"
                >
                    <p class="font-mono-fq text-[10px] tracking-[0.24em] text-fq-lime uppercase">Today's Main Quest</p>
                    <h2 class="mt-2 font-baloo text-[30px] leading-[1.1] font-extrabold">{{ $quest->chore->name }}</h2>

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

                        {{-- Pushed to the far edge — it's an escape hatch, not
                             the thing to reach for first. --}}
                        @if (isset($heldPerks['quest_reroll']))
                            <span class="ml-auto">
                                <x-perk-button :entry="$heldPerks['quest_reroll']" />
                            </span>
                        @endif
                    </div>
                </div>
            </x-chest>
            @endif

            @if ($perkMessage)
                <div class="rounded-[16px] border border-fq-line-2 bg-fq-sunk px-4 py-3 text-sm text-fq-text-2">
                    {{ $perkMessage }}
                </div>
            @endif

            {{-- No button here on purpose. The rescue card above owns every
                 case where a restore can actually be spent, so anything
                 reaching this branch is a perk with nothing to fix — and a
                 permanently greyed-out "Use Streak Restore" under a healthy
                 streak reads as the app being broken rather than as the kid
                 having nothing to repair. --}}
            @if (isset($heldPerks['streak_restore']) && ! $streakRepair)
                @php
                    $restoresHeld = $heldPerks['streak_restore']['count'];
                    // Built here rather than across template lines, so the
                    // sentence renders as one run of text instead of picking
                    // up the indentation between its clauses.
                    $restoreNote = $restoresHeld > 1
                        ? "{$restoresHeld} Streak Restores are in your pocket. Nothing to fix right now — they'll be here if you ever miss a day."
                        : "A Streak Restore is in your pocket. Nothing to fix right now — it'll be here if you ever miss a day.";
                @endphp

                <div class="flex flex-wrap items-center gap-3 rounded-[18px] border border-fq-steel-line p-[14px]" style="background: var(--fq-panel)">
                    <span class="font-baloo text-sm" style="color: var(--fq-steel-text)">
                        {{ App\Enums\PerkEffect::StreakRestore->defaults()['glyph'] }}
                    </span>
                    <p class="min-w-0 flex-1 text-sm text-fq-text-2">{{ $restoreNote }}</p>
                </div>
            @endif

            @if ($mysteryChore)
                <div
                    wire:key="mystery-status"
                    class="rounded-[24px] border p-5"
                    style="background: var(--fq-wash-violet); border-color: color-mix(in srgb, var(--fq-magenta) 50%, transparent)"
                >
                    <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: var(--fq-magenta)">Mystery Chore</p>
                    @if ($mysteryFinder === null)
                        <h2 class="mt-2 font-baloo text-xl font-bold">Still not completed</h2>
                        <p class="mt-1 max-w-[420px] text-sm text-fq-text-2">
                            One of today's chores is secretly worth a bonus — nobody knows which one until a parent approves it. First to get it signed off earns +{{ \App\Services\ChoreService::MYSTERY_BONUS_POINTS }} pts!
                        </p>

                        @if ($mysteryHint)
                            <div class="mt-3 rounded-[14px] border px-4 py-3" style="border-color: color-mix(in srgb, var(--fq-magenta) 50%, transparent); background: var(--fq-sunk)">
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
                    @elseif ($mysteryFinder->id === $profile->id)
                        {{-- Naming the chore matters here: a kid with several
                             claims waiting on a parent has no way to tell which
                             one carried the bonus. --}}
                        <h2 class="mt-2 font-baloo text-xl font-bold">You found it — {{ $mysteryChore->name }}!</h2>
                        <p class="mt-1 max-w-[420px] text-sm text-fq-text-2">
                            Nice work — you banked a +{{ \App\Services\ChoreService::MYSTERY_BONUS_POINTS }} pt bonus.
                        </p>
                    @else
                        {{-- Named for everyone: once it's found the secret is
                             spent, and knowing which chore it was is half the
                             fun of losing. --}}
                        <h2 class="mt-2 font-baloo text-xl font-bold">Completed by {{ $mysteryFinder->name }} — {{ $mysteryChore->name }}!</h2>
                        <p class="mt-1 max-w-[420px] text-sm text-fq-text-2">
                            They found it and banked a +{{ \App\Services\ChoreService::MYSTERY_BONUS_POINTS }} pt bonus. Better luck next time.
                        </p>
                    @endif
                </div>
            @endif

            {{-- The one quest that isn't work. It sits with the chests rather
                 than on the board because there's nothing for a parent to
                 approve — the tickets land the moment it's handed in. --}}
            <div
                wire:key="gratitude-quest"
                class="rounded-[24px] border p-5"
                style="background: var(--fq-wash-blue); border-color: var(--fq-line-cool)"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: var(--fq-cyan)">Gratitude Quest</p>
                    <span class="font-mono-fq text-[11px]" style="color: {{ $gratitudeToday ? 'var(--fq-text-4)' : 'var(--fq-lime)' }}">
                        {{ $gratitudeToday ? 'Done today' : '+'.\App\Services\GratitudeService::TICKETS.' tickets' }}
                    </span>
                </div>

                @if ($gratitudeToday)
                    <h2 class="mt-2 font-baloo text-xl font-bold">Today you were grateful for&hellip;</h2>

                    <ol class="mt-3 flex flex-col gap-2">
                        @foreach ($gratitudeToday->items as $index => $item)
                            <li class="flex items-start gap-[10px] rounded-[14px] border border-fq-line-2 bg-fq-sunk px-[13px] py-[10px]">
                                <span class="font-baloo text-[13px] font-extrabold" style="color: var(--fq-cyan)">{{ $index + 1 }}</span>
                                <span class="min-w-0 flex-1 text-sm text-fq-text-2">{{ $item }}</span>
                            </li>
                        @endforeach
                    </ol>

                    <p class="mt-3 text-[13px] text-fq-text-5">
                        A new one opens up tomorrow. Everything you've written is kept in your
                        <a href="{{ route('kid.journal') }}" wire:navigate class="font-semibold underline" style="color: var(--fq-cyan)">Journal</a>.
                    </p>
                @else
                    <h2 class="mt-2 font-baloo text-xl font-bold">Name three good things</h2>
                    <p class="mt-1 max-w-[420px] text-sm text-fq-text-2">
                        Tell us three things you're grateful for today. No chores, no waiting on a
                        parent — hand it in and {{ \App\Services\GratitudeService::TICKETS }} tickets are yours.
                    </p>

                    <div class="mt-4 flex flex-col gap-2">
                        @foreach (range(0, \App\Services\GratitudeService::ITEMS - 1) as $index)
                            <div class="flex items-center gap-[10px]">
                                <span class="w-4 shrink-0 text-center font-baloo text-[13px] font-extrabold" style="color: var(--fq-cyan)">{{ $index + 1 }}</span>
                                <input
                                    type="text"
                                    wire:model="gratitude.{{ $index }}"
                                    wire:keydown.enter="logGratitude"
                                    maxlength="{{ \App\Services\GratitudeService::MAX_LENGTH }}"
                                    placeholder="Something good&hellip;"
                                    aria-label="Grateful for, number {{ $index + 1 }}"
                                    class="min-w-0 flex-1 rounded-[14px] border border-fq-line-2 bg-fq-sunk px-4 py-[10px] text-sm outline-none focus:border-fq-cyan"
                                >
                            </div>
                        @endforeach
                    </div>

                    <button
                        type="button"
                        wire:click="logGratitude"
                        wire:loading.attr="disabled"
                        wire:target="logGratitude"
                        class="mt-4 rounded-[16px] px-[22px] py-[13px] font-baloo text-[16px] font-bold transition hover:brightness-110 disabled:opacity-60"
                        style="background: var(--fq-cyan); color: var(--fq-ink)"
                    >Hand it in</button>
                @endif

                @if ($gratitudeMessage)
                    <p class="mt-3 text-[13px]" style="color: var(--fq-gold)">{{ $gratitudeMessage }}</p>
                @endif
            </div>

            <div class="flex items-center justify-between">
                <h3 class="font-baloo text-xl font-bold">Side Quests</h3>
                <span class="font-mono-fq text-[10px] tracking-[0.14em] uppercase" style="color: {{ $allUnlocked ? 'var(--fq-lime)' : 'var(--fq-gold)' }}">
                    {{ $allUnlocked ? 'All Unlocked' : 'Locked Until Main Quest Is Done' }}
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
                {{-- Hiding everything leaves a blank column that reads as a
                     bug. Say where the chores went, and how to get them back. --}}
                <div class="rounded-[18px] border border-dashed border-fq-line-3 bg-fq-panel p-6 text-center text-sm text-fq-text-5">
                    Everything else is taken or closed for today.
                </div>
            @endif

            {{-- The backstop for the seconds between polls. Polling narrows
                 that window but can't close it, so the tap still has to
                 explain itself rather than silently doing nothing. --}}
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

            {{-- Refreshed when the kid comes back to the page, not on a timer.
                 The server scales to zero when idle, so a poll on a tablet
                 left open all afternoon would keep it awake and billing for
                 nothing. This fires one request at the only moment a stale
                 board can actually mislead someone: when they look at it. --}}
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
                class="flex flex-col gap-3"
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
                            // Not "Locked" and not "Back tomorrow" — the clock
                            // ran out and a parent has it now. Saying so is what
                            // makes the countdown mean anything next time.
                            'expired' => "Time's up",
                        ];
                    @endphp
                    <div
                        wire:key="chore-{{ $chore->id }}"
                        class="rounded-[20px] border bg-fq-panel p-4 {{ $state === 'locked' ? 'opacity-50' : '' }} {{ $takenBy || $state === 'expired' ? 'opacity-70' : '' }} {{ $chore->isOneTime() || $closesAt ? 'border-2' : 'border border-fq-line' }}"
                        style="{{ $state === 'pending' ? 'border-color: var(--fq-success-border)' : ($closesAt ? 'border-color: color-mix(in srgb, var(--fq-cyan) 55%, transparent)' : ($chore->isOneTime() ? 'border-color: color-mix(in srgb, var(--fq-gold) 55%, transparent); background: var(--fq-wash-gold)' : '')) }}"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                {{-- Flagged, not just sorted: a card sitting at
                                     the top of the list only reads as urgent if
                                     you can see why it's there. --}}
                                @if ($chore->isOneTime())
                                    <span class="mb-1 inline-block rounded-[8px] px-[10px] py-1 font-mono-fq text-[10px] tracking-[0.14em] uppercase" style="background: color-mix(in srgb, var(--fq-gold) 22%, transparent); color: var(--fq-gold)">
                                        &#9889; One-time
                                    </span>
                                @endif
                                <p class="text-[16px] font-semibold {{ $takenBy || $state === 'expired' ? 'line-through decoration-2' : '' }}">{{ $chore->name }}</p>
                                <p class="font-mono-fq text-[10px] text-fq-text-4 uppercase">
                                    {{ $chore->cadence->kidLabel() }}
                                </p>
                            </div>
                            <span class="font-baloo text-[19px] font-extrabold" style="color: {{ $takenBy ? 'var(--fq-text-5)' : ($boosted ? $boostColor : 'var(--fq-lime)') }}">
                                +{{ $payout }} pts
                            </span>
                        </div>

                        {{-- Loud on purpose: this has to be readable at a glance,
                             from across the room, before any work starts. --}}
                        @if ($takenBy)
                            <span class="mt-2 inline-block rounded-[8px] px-[10px] py-1 font-mono-fq text-[10px]" style="background: color-mix(in srgb, var(--fq-gold) 22%, transparent); color: var(--fq-gold)">
                                Taken by {{ $takenBy->name }}
                            </span>
                        @elseif ($closesAt)
                            {{-- The race, spelled out. It has to be the first
                                 thing read on the card, because the whole point
                                 is deciding to go and do it right now. --}}
                            <x-chore-countdown wire:key="closes-{{ $chore->id }}" :closes-at="$closesAt" class="mt-2" />
                        @elseif ($state === 'expired')
                            <span class="mt-2 inline-block rounded-[8px] px-[10px] py-1 font-mono-fq text-[10px]" style="background: color-mix(in srgb, var(--fq-danger) 20%, transparent); color: var(--fq-danger)">
                                A parent took this one
                            </span>
                        @elseif ($boosted)
                            <span class="mt-2 inline-block rounded-[8px] px-[10px] py-1 font-mono-fq text-[10px]" style="background: color-mix(in srgb, {{ $boostColor }} 28%, transparent); color: {{ $boostColor }}">
                                {{ $boost->multiplier }}x wheel boost
                            </span>
                        @endif

                        <button
                            type="button"
                            @if ($state === 'ready') wire:click="claimChore({{ $chore->id }})" @else disabled @endif
                            class="mt-3 w-full rounded-[14px] py-[11px] text-sm font-semibold {{ $state === 'ready' ? 'text-fq-bg transition hover:brightness-110' : 'cursor-default text-fq-text-4' }}"
                            style="background: {{ $state === 'ready' ? 'var(--fq-lime)' : 'var(--fq-panel-alt)' }}"
                        >{{ $labels[$state] }}</button>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Right column --}}
        <div class="flex flex-col gap-[14px]">
            {{-- Above the family goal on purpose: this is the one number on the
                 page a kid controls entirely on their own today. --}}
            <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
                <div class="flex items-center justify-between">
                    <h3 class="font-baloo text-lg font-bold">Today's Target</h3>
                    @if ($dailyGoal)
                        <span class="font-mono-fq text-[10px] {{ $earnedToday >= $dailyGoal ? 'text-fq-gold' : 'text-fq-lime' }}">
                            {{ $dailyGoalPercent }}%
                        </span>
                    @endif
                </div>

                @if ($dailyGoal)
                    <div class="mt-3 h-4 overflow-hidden rounded-full border border-fq-line bg-fq-track">
                        <div
                            class="h-full rounded-full transition-[width] duration-500"
                            style="width:{{ $dailyGoalPercent }}%;background:linear-gradient(90deg, var(--fq-cyan), var(--fq-lime))"
                        ></div>
                    </div>
                    <p class="mt-2 font-mono-fq text-[11px] text-fq-text-4">
                        {{ number_format($earnedToday) }} / {{ number_format($dailyGoal) }} PTS
                    </p>
                    <p class="mt-[6px] text-[13px] {{ $earnedToday >= $dailyGoal ? 'font-semibold text-fq-lime' : 'text-fq-text-5' }}">
                        @if ($earnedToday >= $dailyGoal)
                            Target smashed. Anything else today is bonus.
                        @else
                            {{ number_format($dailyGoal - $earnedToday) }} more points to hit today's target.
                        @endif
                    </p>
                @else
                    <p class="mt-2 text-[13px] text-fq-text-5">
                        Pick a points-a-day target and see when you'll get what you're saving for.
                    </p>
                    <a
                        href="{{ route('kid.goal') }}"
                        wire:navigate
                        class="mt-3 inline-block rounded-[12px] border border-fq-line-3 bg-fq-sunk px-[14px] py-[9px] text-[13px] text-fq-text-2-b transition hover:border-fq-lime hover:text-fq-text"
                    >Make a plan</a>
                @endif
            </div>

            <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
                {{-- The family goal, as the thing it is fighting. Falls back to
                     the plain bar for a household with no target set, which has
                     no monster to draw. --}}
                {{-- Keyed so a Livewire morph matches this block to itself
                     rather than to whatever the plain-bar branch left behind.
                     The two branches render different shapes, and this panel
                     re-renders on every refresh-on-focus. --}}
                @if ($bossState)
                    <x-boss-mini :state="$bossState" wire:key="family-boss" />

                    <p class="mt-3 text-sm text-fq-text-2">{{ $household->goal_name }}</p>
                    <p class="mt-1 font-mono-fq text-[11px] text-fq-text-4">
                        BEAT IT TOGETHER · EVERYONE'S POINTS COUNT
                    </p>
                @else
                    <div class="flex items-center justify-between">
                        <h3 class="font-baloo text-lg font-bold">Family Goal</h3>
                        <span class="font-mono-fq text-[10px] text-fq-lime">{{ $goalPercent }}%</span>
                    </div>
                    <p class="mt-1 text-sm text-fq-text-2">{{ $household->goal_name }}</p>
                    <div class="mt-3 h-4 overflow-hidden rounded-full border border-fq-line bg-fq-track">
                        <div
                            class="h-full rounded-full transition-[width] duration-500"
                            style="width:{{ $goalPercent }}%;background:linear-gradient(90deg, var(--fq-cyan), var(--fq-lime), var(--fq-gold))"
                        ></div>
                    </div>
                    <p class="mt-2 font-mono-fq text-[11px] text-fq-text-4">
                        {{ $household->goal_now }} / {{ $household->goal_target }} PTS · EVERYONE'S POINTS COUNT
                    </p>
                @endif

                <div class="mt-4 border-t border-fq-divider pt-[14px]">
                    <x-goal-mvp :contributors="$goalContributors" />
                </div>

                <a
                    href="{{ route('kid.goal') }}"
                    wire:navigate
                    class="mt-3 inline-block font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase transition hover:text-fq-text"
                >Plan it out &rarr;</a>
            </div>

            <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
                <h3 class="font-baloo text-lg font-bold">Badges</h3>
                <div class="mt-3 grid grid-cols-[repeat(auto-fit,minmax(88px,1fr))] gap-[10px]">
                    @foreach ($badges as $badge)
                        @php
                            $earned = $earnedBadgeKeys->contains($badge->key);
                            $mystery = $badge->hidden && ! $earned;
                        @endphp
                        <div class="flex flex-col items-center gap-[6px] rounded-[16px] bg-fq-sunk px-[6px] py-3 text-center {{ $earned ? '' : 'opacity-50' }}">
                            <x-badge-star :badge="$badge" :earned="$earned" :secret="$mystery" />
                            <span class="text-[11px] font-semibold">{{ $mystery ? '???' : $badge->name }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
                <h3 class="font-baloo text-lg font-bold">Waiting on Parent</h3>
                <div class="mt-3 flex flex-col gap-2">
                    @forelse ($pending as $item)
                        <div class="flex items-center justify-between rounded-[14px] border border-dashed border-fq-line-4 bg-fq-sunk px-[13px] py-[11px]">
                            <span class="text-sm">{{ $item->chore->name }}</span>
                            <span class="font-mono-fq text-[11px] text-fq-gold">+{{ $item->points_awarded }}</span>
                        </div>
                    @empty
                        <p class="text-[13px] text-fq-text-5">Nothing pending. Go earn something.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-kid.shell>
