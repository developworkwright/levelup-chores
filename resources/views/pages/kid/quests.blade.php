<?php

use App\Enums\CompletionStatus;
use App\Models\Badge;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Profile;
use App\Services\ChoreService;
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

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();

        abort_unless($this->profile->isKid(), 403);

        $this->pendingChestDay = $this->profile->pending_streak_chest;
        // STREAK_BONUSES is denominated in dollars, but every other number a
        // kid sees is points — so convert once here and never show dollars.
        $this->pendingChestPoints = $this->pendingChestDay
            ? (ChoreService::STREAK_BONUSES[$this->pendingChestDay] ?? 0) * $this->profile->household->points_per_dollar
            : null;

        $this->questDoneOnArrival = app(ChoreService::class)->isQuestDoneToday($this->profile);
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
        $todaysMystery = $service->mysteryChoreFor($this->profile->household);

        $service->claimQuest($this->profile);

        // The streak (and any milestone bonus) now moves on a parent's
        // approval, so don't quote a day count here that hasn't been earned
        // yet — and when it is earned, the chest still does the reveal.
        if (! $wasDone) {
            if ($todaysMystery && $todaysMystery->id === $quest->chore_id) {
                $this->dispatch('celebrate', message: 'You found the Mystery Chore! +'.\App\Services\ChoreService::MYSTERY_BONUS_POINTS.' bonus!');
            } elseif ($boosted) {
                $this->dispatch('celebrate', message: 'Quest cleared! Bonus wheel treat earned.', treat: 'cookie');
            } else {
                $this->dispatch('celebrate', message: 'Quest cleared! Your streak grows once a parent approves.');
            }
        }
    }

    public function openStreakChest(): void
    {
        app(ChoreService::class)->openStreakChest($this->profile);
    }

    public function claimChore(int $choreId): void
    {
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
            || $service->stateFor($this->profile, $chore) !== 'ready'
        ) {
            return;
        }

        $boosted = app(SpinService::class)->multiplierFor($this->profile, $chore) > 1;
        $todaysMystery = $service->mysteryChoreFor($this->profile->household);

        if ($todaysMystery && $todaysMystery->id === $chore->id) {
            $this->dispatch('celebrate', message: 'You found the Mystery Chore! +'.\App\Services\ChoreService::MYSTERY_BONUS_POINTS.' bonus!');
        } elseif ($boosted) {
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

        $quest = $service->questFor($this->profile);
        $questRevealed = $quest->revealed_at !== null;
        $questDone = $quest->completed_at !== null;
        $boost = $spin->today($this->profile);
        $questBoosted = $boost && $boost->chore_id === $quest->chore_id;

        $household = $this->profile->household;

        $mysteryChore = $service->mysteryChoreFor($household);
        $mysteryClaimant = $mysteryChore ? $service->mysteryClaimant($mysteryChore) : null;

        $nextMilestone = $service->nextStreakMilestone($this->profile);

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
            'questRevealed' => $questRevealed,
            'questDone' => $questDone,
            'boost' => $boost,
            'questBoosted' => $questBoosted,
            'questPoints' => $quest->chore->points * ($questBoosted ? $boost->multiplier : 1),
            'board' => $service->boardFor($this->profile),
            'mysteryChore' => $mysteryChore,
            'mysteryClaimant' => $mysteryClaimant,
            'mysteryHint' => $service->mysteryHintFor($this->profile),
            'nextMilestone' => $nextMilestone,
            'streakBonuses' => $streakBonuses,
            'pending' => ChoreCompletion::where('profile_id', $this->profile->id)
                ->where('status', CompletionStatus::Pending)
                ->with('chore')
                ->latest('submitted_at')
                ->get(),
            'household' => $household,
            'goalPercent' => $household->goal_target > 0
                ? min(100, round($household->goal_now / $household->goal_target * 100))
                : 0,
            'badges' => Badge::all(),
            'earnedBadgeKeys' => $this->profile->badges->pluck('key'),
            'allUnlocked' => ! $household->require_quest_first || $questDone,
        ];
    }
}; ?>

<x-kid.shell :profile="$profile" active="quests">
    <div class="grid grid-cols-1 gap-[14px] lg:grid-cols-[minmax(0,1.6fr)_minmax(260px,1fr)]">
        {{-- Left column --}}
        <div class="flex flex-col gap-[14px]">
            @if ($pendingChestDay)
                <x-chest
                    wire-key="streak-chest"
                    :revealed="false"
                    open-action="openStreakChest"
                    accent="var(--fq-violet)"
                    closed-title="Streak Chest"
                    closed-text="Your streak paid off — tap to open your reward!"
                    opening-text="Something's rattling inside..."
                    :prize-label="'+' . $pendingChestPoints . ' PTS'"
                    :prize-sub="$pendingChestDay . '-Day Streak Bonus!'"
                >
                    <div
                        wire:key="streak-chest-opened"
                        class="flex flex-col items-center rounded-[24px] border p-8 text-center"
                        style="animation: fq-pop .3s ease both; background:linear-gradient(135deg, #2a2050, #171c38); border-color: oklch(0.65 0.19 320 / .5)"
                    >
                        <p class="font-mono-fq text-[10px] tracking-[0.24em] text-fq-violet uppercase">Streak Chest</p>
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
                <div wire:key="streak-progress" class="rounded-[24px] border border-fq-line bg-fq-panel p-6">
                    <div class="flex items-center justify-between">
                        <h3 class="font-baloo text-lg font-bold">Streak Chest</h3>
                        <span class="font-mono-fq text-[11px] text-fq-violet">{{ $profile->streak }}-day streak</span>
                    </div>
                    <p class="mt-1 text-sm text-fq-text-2">
                        @if ($nextMilestone && $profile->streak + 1 === $nextMilestone && ! $questDone)
                            Complete today's quest and come back tomorrow to open the chest!
                        @elseif ($nextMilestone)
                            Keep your streak alive — a chest unlocks at day {{ $nextMilestone }}.
                        @else
                            You've unlocked every streak chest. Amazing!
                        @endif
                    </p>

                    <div class="mt-4 flex items-center gap-1 overflow-x-auto pb-1">
                        @foreach ($streakBonuses as $milestone)
                            <div class="flex flex-shrink-0 flex-col items-center gap-1">
                                <div
                                    class="flex h-9 w-9 items-center justify-center rounded-full border-2 font-baloo text-[11px] font-extrabold"
                                    style="background: {{ $milestone['reached'] ? 'var(--fq-violet)' : 'var(--fq-sunk)' }}; border-color: {{ $milestone['reached'] ? 'var(--fq-violet)' : 'var(--fq-line-3)' }}; color: {{ $milestone['reached'] ? 'var(--fq-bg)' : 'var(--fq-text-4)' }}"
                                >{{ $milestone['reached'] ? '✓' : $milestone['day'] }}</div>
                                <span class="font-mono-fq text-[9px] whitespace-nowrap text-fq-text-5">D{{ $milestone['day'] }} · {{ $milestone['points'] }}</span>
                            </div>
                            @unless ($loop->last)
                                <div class="mt-[-14px] h-[2px] w-5 flex-shrink-0" style="background: {{ $milestone['reached'] ? 'var(--fq-violet)' : 'var(--fq-line-3)' }}"></div>
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
                    style="background:linear-gradient(135deg, #1c2a4d, var(--fq-panel)); border-color: oklch(0.7 0.16 140 / 0.4)"
                >
                    <div
                        class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-[12px] font-baloo text-lg font-extrabold text-fq-bg"
                        style="background:var(--fq-lime)"
                    >&#10003;</div>
                    <div class="min-w-0 flex-1">
                        <p class="font-mono-fq text-[10px] tracking-[0.2em] text-fq-text-4 uppercase">Today's Main Quest</p>
                        <p class="truncate text-[15px] font-semibold">{{ $quest->chore->name }}</p>
                    </div>
                    <span class="font-mono-fq text-[11px] whitespace-nowrap text-fq-lime">Cleared</span>
                </div>
            @else
            <x-chest
                wire-key="quest-chest"
                :revealed="$questRevealed"
                open-action="revealQuest"
                accent="var(--fq-gold)"
                closed-title="Today's Main Quest"
                closed-text="Tap the chest to reveal today's main quest — every side quest below stays locked until you do."
                opening-text="The chest is rattling..."
                :prize-label="$quest->chore->name"
                :prize-sub="'+' . $questPoints . ' PTS · Today\'s Quest'"
            >
                <div
                    wire:key="hero"
                    class="rounded-[24px] border p-5"
                    style="animation: fq-pop .3s ease both; background:linear-gradient(135deg, #23306b, #171c38); border-color: {{ $questDone ? 'oklch(0.7 0.16 140 / 0.6)' : 'oklch(0.8 0.15 85 / 0.55)' }}"
                >
                    <p class="font-mono-fq text-[10px] tracking-[0.24em] text-fq-gold uppercase">Today's Main Quest</p>
                    <h2 class="mt-2 font-baloo text-[30px] leading-[1.1] font-extrabold">{{ $quest->chore->name }}</h2>
                    <p class="mt-2 max-w-[420px] text-sm text-fq-text-2">
                        @if ($questDone)
                            Quest cleared. Every side quest below is unlocked for today.
                        @else
                            Clear this one first — the side quests stay locked until it's done.
                        @endif
                    </p>

                    <div class="mt-4 flex items-center gap-3">
                        @if ($questDone)
                            <button type="button" disabled class="cursor-default rounded-[16px] bg-fq-line-2 px-[22px] py-[14px] font-baloo text-[17px] font-bold text-fq-text-3">
                                Cleared &#10003;
                            </button>
                        @else
                            <button
                                type="button"
                                wire:click="claimQuest"
                                class="rounded-[16px] px-[22px] py-[14px] font-baloo text-[17px] font-bold text-fq-bg shadow-[0_10px_24px_-12px_var(--fq-gold)]"
                                style="background:var(--fq-gold)"
                            >Mark it done</button>
                        @endif
                        <span class="font-mono-fq text-xs" style="color: {{ $questBoosted ? ($boost->multiplier === 3 ? 'var(--fq-gold)' : 'var(--fq-magenta)') : 'var(--fq-lime)' }}">
                            +{{ $questPoints }} PTS
                        </span>
                    </div>
                </div>
            </x-chest>
            @endif

            @if ($mysteryChore)
                <div
                    wire:key="mystery-status"
                    class="rounded-[24px] border p-5"
                    style="background:linear-gradient(135deg, #3a1f4d, #171c38); border-color: oklch(0.65 0.19 320 / .5)"
                >
                    <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: var(--fq-magenta)">Mystery Chore</p>
                    @if ($mysteryClaimant === null)
                        <h2 class="mt-2 font-baloo text-xl font-bold">Still not completed</h2>
                        <p class="mt-1 max-w-[420px] text-sm text-fq-text-2">
                            One of today's chores is secretly worth a bonus — nobody knows which one until someone finishes it. First to find it earns +{{ \App\Services\ChoreService::MYSTERY_BONUS_POINTS }} pts!
                        </p>

                        @if ($mysteryHint)
                            <div class="mt-3 rounded-[14px] border px-4 py-3" style="border-color: oklch(0.65 0.19 320 / .5); background: var(--fq-sunk)">
                                <p class="font-mono-fq text-[10px] tracking-[0.2em] uppercase" style="color: var(--fq-magenta)">Your Hint</p>
                                <p class="mt-1 text-sm text-fq-text-2">{{ $mysteryHint }}</p>
                            </div>
                        @endif
                    @elseif ($mysteryClaimant->profile_id === $profile->id)
                        <h2 class="mt-2 font-baloo text-xl font-bold">You found it!</h2>
                        <p class="mt-1 max-w-[420px] text-sm text-fq-text-2">
                            Nice work — you banked a +{{ \App\Services\ChoreService::MYSTERY_BONUS_POINTS }} pt bonus.
                        </p>
                    @else
                        <h2 class="mt-2 font-baloo text-xl font-bold">Completed by {{ $mysteryClaimant->profile->name }}!</h2>
                        <p class="mt-1 max-w-[420px] text-sm text-fq-text-2">
                            They found it and banked a +{{ \App\Services\ChoreService::MYSTERY_BONUS_POINTS }} pt bonus. Better luck next time.
                        </p>
                    @endif
                </div>
            @endif

            <div class="flex items-center justify-between">
                <h3 class="font-baloo text-xl font-bold">Side Quests</h3>
                <span class="font-mono-fq text-[10px] tracking-[0.14em] uppercase" style="color: {{ $allUnlocked ? 'var(--fq-lime)' : 'var(--fq-gold)' }}">
                    {{ $allUnlocked ? 'All Unlocked' : 'Locked Until Main Quest Is Done' }}
                </span>
            </div>

            <div class="flex flex-col gap-3">
                @foreach ($board as $entry)
                    @php
                        $chore = $entry['chore'];
                        $state = $entry['state'];
                        $boosted = $questBoosted === false && $boost && $boost->chore_id === $chore->id;
                        $payout = $chore->points * ($boosted ? $boost->multiplier : 1);
                        $boostColor = $boosted && $boost->multiplier === 3 ? 'var(--fq-gold)' : 'var(--fq-magenta)';
                        $cadenceLabels = [
                            'weekly' => 'Once a week',
                            'unlimited' => 'Anytime · No limit',
                            'daily' => 'Once a day',
                        ];
                        $labels = [
                            'ready' => 'Mark it done',
                            'locked' => 'Locked',
                            'pending' => 'Pending approval',
                            'done' => $chore->cadence->value === 'weekly' ? 'Back in 7 days' : 'Back tomorrow',
                        ];
                    @endphp
                    <div
                        wire:key="chore-{{ $chore->id }}"
                        class="rounded-[20px] border border-fq-line bg-fq-panel p-4 {{ $state === 'locked' ? 'opacity-50' : '' }}"
                        style="{{ $state === 'pending' ? 'border-color: var(--fq-success-border)' : '' }}"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <p class="text-[16px] font-semibold">{{ $chore->name }}</p>
                                <p class="font-mono-fq text-[10px] text-fq-text-4 uppercase">
                                    {{ $cadenceLabels[$chore->cadence->value] ?? 'Once a day' }}
                                </p>
                            </div>
                            <span class="font-baloo text-[19px] font-extrabold" style="color: {{ $boosted ? $boostColor : 'var(--fq-lime)' }}">
                                +{{ $payout }} pts
                            </span>
                        </div>

                        @if ($boosted)
                            <span class="mt-2 inline-block rounded-[8px] px-[10px] py-1 font-mono-fq text-[10px]" style="background: color-mix(in oklch, {{ $boostColor }} 28%, transparent); color: {{ $boostColor }}">
                                {{ $boost->multiplier }}x wheel boost
                            </span>
                        @endif

                        <button
                            type="button"
                            @if ($state === 'ready') wire:click="claimChore({{ $chore->id }})" @else disabled @endif
                            class="mt-3 w-full rounded-[14px] py-[11px] text-sm font-semibold {{ $state === 'ready' ? 'text-fq-bg' : 'cursor-default text-fq-text-4' }}"
                            style="background: {{ $state === 'ready' ? 'var(--fq-lime)' : 'var(--fq-panel-alt-2)' }}"
                        >{{ $labels[$state] }}</button>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Right column --}}
        <div class="flex flex-col gap-[14px]">
            <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
                <div class="flex items-center justify-between">
                    <h3 class="font-baloo text-lg font-bold">Family Goal</h3>
                    <span class="font-mono-fq text-[10px] text-fq-lime">{{ $goalPercent }}%</span>
                </div>
                <p class="mt-1 text-sm text-fq-text-2">{{ $household->goal_name }}</p>
                <div class="mt-3 h-4 overflow-hidden rounded-full border" style="background:#242c4d; border-color:#2f3960">
                    <div
                        class="h-full rounded-full transition-[width] duration-500"
                        style="width:{{ $goalPercent }}%;background:linear-gradient(90deg, var(--fq-cyan), var(--fq-lime), var(--fq-gold))"
                    ></div>
                </div>
                <p class="mt-2 font-mono-fq text-[11px] text-fq-text-4">
                    {{ $household->goal_now }} / {{ $household->goal_target }} PTS · EVERYONE'S POINTS COUNT
                </p>
            </div>

            <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
                <h3 class="font-baloo text-lg font-bold">Badges</h3>
                <div class="mt-3 grid grid-cols-[repeat(auto-fit,minmax(88px,1fr))] gap-[10px]">
                    @foreach ($badges as $badge)
                        @php
                            $earned = $earnedBadgeKeys->contains($badge->key);
                            $mystery = $badge->hidden && ! $earned;
                        @endphp
                        <div class="flex flex-col items-center gap-1 rounded-[16px] bg-fq-sunk px-[6px] py-3 text-center {{ $earned ? '' : 'opacity-45' }}">
                            <div
                                class="flex h-[34px] w-[34px] items-center justify-center rounded-[11px] font-baloo text-[15px] font-extrabold"
                                style="background: {{ $earned ? $badge->color->cssVar() : '#39426d' }}; color: {{ $earned ? 'var(--fq-bg)' : '#39426d' }}"
                            >{{ $mystery ? '?' : $badge->glyph }}</div>
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
