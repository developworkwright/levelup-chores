<?php

use App\Enums\CompletionStatus;
use App\Enums\LedgerKind;
use App\Models\Badge;
use App\Models\ChoreCompletion;
use App\Models\DailyQuest;
use App\Models\Profile;
use App\Models\Redemption;
use App\Models\Spin;
use App\Models\StreakRepair;
use App\Services\HouseholdClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component
{
    /** How many household days the activity strip covers. */
    private const RECENT_DAYS = 14;

    /**
     * The window the middle "chores done" figure covers. A rolling seven days
     * rather than a calendar week on purpose: a Monday reset would wipe out a
     * good weekend and make the tile read as a bad start instead.
     */
    private const WEEK_DAYS = 7;

    /** How many entries the most-done list shows. */
    private const TOP_CHORES = 5;

    public Profile $profile;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isKid(), 403);
    }

    /**
     * Approved completions grouped by the household day the chore was
     * *claimed* on — the same day the quest and streak logic attributes it to,
     * rather than whenever a parent got round to approving it.
     *
     * @param  Collection<int, ChoreCompletion>  $completions
     * @return Collection<string, Collection<int, ChoreCompletion>>
     */
    private function groupByDay(Collection $completions): Collection
    {
        $clock = HouseholdClock::for($this->profile->household);

        return $completions->groupBy(
            fn (ChoreCompletion $completion) => $clock->dayFor($completion->submitted_at)->toDateString()
        );
    }

    /**
     * One entry per day for the activity strip, oldest first, including the
     * days nothing happened — a gap is as much of the picture as a tall bar.
     *
     * @param  Collection<string, Collection<int, ChoreCompletion>>  $byDay
     * @return Collection<int, array{date: \Illuminate\Support\Carbon, count: int, points: int, isToday: bool}>
     */
    private function recentDays(Collection $byDay): Collection
    {
        $today = HouseholdClock::for($this->profile->household)->today();

        return collect(range(self::RECENT_DAYS - 1, 0))
            ->map(function (int $daysAgo) use ($today, $byDay) {
                $date = $today->copy()->subDays($daysAgo);
                $day = $byDay->get($date->toDateString(), collect());

                return [
                    'date' => $date,
                    'count' => $day->count(),
                    'points' => (int) $day->sum('points_awarded'),
                    'isToday' => $daysAgo === 0,
                ];
            });
    }

    /**
     * The longest run of consecutive days the daily quest was cleared, counted
     * the way ChoreService walks the current streak — a day bought back with a
     * streak repair keeps the run alive. Floored at the streak the profile is
     * carrying now, which is the one number a kid can already see, so the
     * record can never read lower than it.
     */
    private function longestStreak(): int
    {
        $days = DailyQuest::where('profile_id', $this->profile->id)
            ->whereNotNull('completed_at')
            ->pluck('quest_date')
            ->merge(StreakRepair::where('profile_id', $this->profile->id)->pluck('repaired_date'))
            ->map(fn (Carbon $date) => $date->toDateString())
            ->unique()
            ->sort()
            ->values();

        $longest = 0;
        $run = 0;
        $previous = null;

        foreach ($days as $day) {
            $run = $previous !== null && Carbon::parse($previous)->addDay()->toDateString() === $day
                ? $run + 1
                : 1;

            $previous = $day;
            $longest = max($longest, $run);
        }

        return max($longest, $this->profile->streak);
    }

    /**
     * The chores done most often, with what each has paid out in total.
     *
     * @param  Collection<int, ChoreCompletion>  $completions
     * @return Collection<int, array{name: string, count: int, points: int}>
     */
    private function topChores(Collection $completions): Collection
    {
        return $completions
            ->groupBy('chore_id')
            ->map(fn (Collection $group) => [
                'name' => $group->first()->chore->name,
                'count' => $group->count(),
                'points' => (int) $group->sum('points_awarded'),
            ])
            ->sortByDesc('count')
            ->take(self::TOP_CHORES)
            ->values();
    }

    public function with(): array
    {
        $completions = ChoreCompletion::where('profile_id', $this->profile->id)
            ->where('status', CompletionStatus::Approved)
            ->with('chore:id,name')
            ->get(['id', 'chore_id', 'points_awarded', 'submitted_at']);

        $byDay = $this->groupByDay($completions);
        $dayCounts = $byDay->map(fn (Collection $day) => $day->count());
        $bestDayCount = (int) $dayCounts->max();

        $questsAssigned = DailyQuest::where('profile_id', $this->profile->id)->count();
        $questsCleared = DailyQuest::where('profile_id', $this->profile->id)
            ->whereNotNull('completed_at')
            ->count();

        $earned = (int) $this->profile->ledgerEntries()->where('kind', LedgerKind::Earn)->sum('amount');
        // Spends are stored negative, so this is flipped to read as a total.
        $spent = abs((int) $this->profile->ledgerEntries()->where('kind', LedgerKind::Spend)->sum('amount'));

        $spins = Spin::where('profile_id', $this->profile->id)->count();
        $tripleSpins = Spin::where('profile_id', $this->profile->id)->where('multiplier', 3)->count();

        $cashOutCosts = Redemption::where('profile_id', $this->profile->id)->pluck('cost_snapshot');
        $badgesEarned = $this->profile->badges()->count();

        $recent = $this->recentDays($byDay);

        return [
            'choreSplit' => [
                [
                    'label' => 'Today',
                    'count' => $recent->last()['count'],
                    'points' => $recent->last()['points'],
                    'color' => 'var(--fq-lime)',
                ],
                [
                    'label' => 'Last 7 days',
                    'count' => (int) $recent->take(-self::WEEK_DAYS)->sum('count'),
                    'points' => (int) $recent->take(-self::WEEK_DAYS)->sum('points'),
                    'color' => 'var(--fq-cyan)',
                ],
                [
                    'label' => 'Lifetime',
                    'count' => $completions->count(),
                    'points' => (int) $completions->sum('points_awarded'),
                    'color' => 'var(--fq-gold)',
                ],
            ],
            'tiles' => [
                [
                    'label' => 'Points earned',
                    'value' => number_format($earned),
                    'sub' => '≈ $'.number_format($earned / $this->profile->household->points_per_dollar, 2).' all time',
                    'color' => 'var(--fq-gold)',
                ],
                [
                    'label' => 'Points spent',
                    'value' => number_format($spent),
                    'sub' => $cashOutCosts->count().' '.Str::plural('cash-out', $cashOutCosts->count()),
                    'color' => 'var(--fq-coral)',
                ],
                [
                    'label' => 'Quests cleared',
                    'value' => number_format($questsCleared),
                    'sub' => $questsAssigned > 0
                        ? round($questsCleared / $questsAssigned * 100).'% of '.$questsAssigned.' handed out'
                        : 'none handed out yet',
                    'color' => 'var(--fq-violet)',
                ],
                [
                    'label' => 'Badges',
                    'value' => $badgesEarned.' / '.Badge::count(),
                    'sub' => 'trophy case',
                    'color' => 'var(--fq-magenta)',
                ],
                [
                    'label' => 'Days active',
                    'value' => number_format($dayCounts->count()),
                    'sub' => 'days with a chore done',
                    'color' => 'var(--fq-cyan)',
                ],
            ],
            'topChores' => $this->topChores($completions),
            'recent' => $recent,
            // Bars are scaled against the busiest day on the strip rather than
            // an all-time best, so a quiet fortnight still has shape.
            'recentMax' => max(1, (int) $recent->max('count')),
            'recentTotal' => (int) $recent->sum('count'),
            'records' => [
                [
                    'label' => 'Best day',
                    'value' => $bestDayCount.' '.Str::plural('chore', $bestDayCount),
                    'sub' => $bestDayCount > 0
                        ? Carbon::parse($dayCounts->search($bestDayCount))->toFormattedDateString()
                        : 'still to come',
                ],
                [
                    'label' => 'Longest streak',
                    'value' => $this->longestStreak().'d',
                    'sub' => 'best run of quest days',
                ],
                [
                    'label' => 'Current streak',
                    'value' => $this->profile->streak.'d',
                    'sub' => 'days in a row right now',
                ],
                [
                    'label' => 'Level',
                    'value' => 'LVL '.$this->profile->level(),
                    'sub' => number_format($this->profile->xp).' XP total',
                ],
                [
                    'label' => 'Wheel spins',
                    'value' => (string) $spins,
                    'sub' => $tripleSpins.' landed 3×',
                ],
                [
                    'label' => 'Cash-outs',
                    'value' => (string) $cashOutCosts->count(),
                    'sub' => $cashOutCosts->isNotEmpty()
                        ? 'biggest: '.number_format($cashOutCosts->max()).' pts'
                        : 'nothing cashed out yet',
                ],
            ],
        ];
    }
}; ?>

<x-kid.shell :profile="$profile" active="stats">
    <div class="flex flex-col gap-[14px]">
        <div class="rounded-[24px] border border-fq-line bg-fq-panel p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="font-mono-fq text-[10px] tracking-[0.24em] text-fq-gold uppercase">Scoreboard</p>
                    <h2 class="mt-1 font-baloo text-2xl font-extrabold">Your Stats</h2>
                </div>
                <span class="font-mono-fq text-[11px] text-fq-text-4">
                    PLAYING SINCE {{ strtoupper($profile->created_at->toFormattedDateString()) }}
                </span>
            </div>
            <p class="mt-2 max-w-[520px] text-sm text-fq-text-2">
                Everything you've racked up so far — chores cleared, points banked, and the jobs
                you've done more than anyone would believe.
            </p>
        </div>

        {{-- Chores get the wide card rather than a tile in the grid below:
             "how am I doing today" is the question this page is opened with,
             and a lifetime total on its own can't answer it. --}}
        <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
            <p class="font-mono-fq text-[10px] tracking-[0.16em] text-fq-text-4 uppercase">Chores done</p>

            <div class="mt-3 grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-3">
                @foreach ($choreSplit as $window)
                    <div
                        wire:key="window-{{ $window['label'] }}"
                        class="rounded-[16px] border border-fq-line-2 bg-fq-sunk px-4 py-[14px]"
                    >
                        <p class="font-baloo text-[34px] leading-none font-extrabold" style="color: {{ $window['color'] }}">
                            {{ number_format($window['count']) }}
                        </p>
                        <p class="mt-2 font-mono-fq text-[10px] tracking-[0.16em] text-fq-text-4 uppercase">
                            {{ $window['label'] }}
                        </p>
                        <p class="mt-[3px] text-[12px] text-fq-text-5">
                            {{ number_format($window['points']) }} pts from chores
                        </p>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="grid grid-cols-[repeat(auto-fill,minmax(168px,1fr))] gap-3">
            @foreach ($tiles as $tile)
                <div wire:key="tile-{{ $tile['label'] }}" class="rounded-[20px] border border-fq-line bg-fq-panel p-4">
                    <p class="font-mono-fq text-[10px] tracking-[0.16em] text-fq-text-4 uppercase">{{ $tile['label'] }}</p>
                    <p class="mt-2 font-baloo text-[28px] leading-none font-extrabold" style="color: {{ $tile['color'] }}">
                        {{ $tile['value'] }}
                    </p>
                    <p class="mt-[6px] text-[12px] text-fq-text-5">{{ $tile['sub'] }}</p>
                </div>
            @endforeach
        </div>

        <div class="grid grid-cols-[repeat(auto-fit,minmax(300px,1fr))] gap-[14px]">
            <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
                <div class="flex items-baseline justify-between gap-3">
                    <h3 class="font-baloo text-xl font-bold">Most done</h3>
                    <span class="font-mono-fq text-[10px] text-fq-text-4">TOP {{ $topChores->count() }}</span>
                </div>

                <div class="mt-3 flex flex-col gap-[14px]">
                    @forelse ($topChores as $chore)
                        <div wire:key="top-{{ $loop->index }}">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="truncate text-sm font-semibold">{{ $chore['name'] }}</span>
                                <span class="font-mono-fq text-[11px] whitespace-nowrap text-fq-text-4">
                                    ×{{ $chore['count'] }} · {{ number_format($chore['points']) }} pts
                                </span>
                            </div>
                            <div class="mt-[6px] h-[10px] overflow-hidden rounded-full bg-fq-track">
                                <div
                                    class="h-full rounded-full"
                                    style="width: {{ round($chore['count'] / $topChores->max('count') * 100) }}%; background: linear-gradient(90deg, var(--fq-cyan), var(--fq-lime))"
                                ></div>
                            </div>
                        </div>
                    @empty
                        <p class="py-2 text-sm text-fq-text-5">
                            Nothing approved yet. Clear a chore and it'll show up here.
                        </p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
                <div class="flex items-baseline justify-between gap-3">
                    <h3 class="font-baloo text-xl font-bold">Last {{ $recent->count() }} days</h3>
                    <span class="font-mono-fq text-[10px] text-fq-text-4">{{ $recentTotal }} CHORES</span>
                </div>

                <div class="mt-4 flex h-[110px] items-end gap-[4px]">
                    @foreach ($recent as $day)
                        @php
                            // A no-chore day keeps a 3px stub, so the strip
                            // still reads as a row of days rather than a hole.
                            $height = max(3, round($day['count'] / $recentMax * 88));
                            $fill = match (true) {
                                $day['count'] === 0 => 'var(--fq-line-2)',
                                $day['isToday'] => 'var(--fq-fill-gold)',
                                default => 'linear-gradient(180deg, var(--fq-cyan), var(--fq-violet))',
                            };
                        @endphp

                        <div wire:key="day-{{ $day['date']->toDateString() }}" class="flex h-full flex-1 flex-col justify-end gap-[6px]">
                            <div
                                class="w-full rounded-[4px]"
                                title="{{ $day['date']->toFormattedDateString() }} — {{ $day['count'] }} {{ Str::plural('chore', $day['count']) }}, {{ $day['points'] }} pts"
                                style="height: {{ $height }}px; background: {{ $fill }}"
                            ></div>
                            <span
                                class="text-center font-mono-fq text-[9px]"
                                style="color: {{ $day['isToday'] ? 'var(--fq-lime)' : 'var(--fq-text-5)' }}"
                            >{{ mb_substr($day['date']->format('D'), 0, 1) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
            <h3 class="font-baloo text-xl font-bold">Records</h3>

            <div class="mt-2 grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-x-4">
                @foreach ($records as $record)
                    <div wire:key="record-{{ $record['label'] }}" class="flex items-center gap-3 border-b border-fq-divider py-[12px]">
                        <div class="min-w-0 flex-1">
                            <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">{{ $record['label'] }}</p>
                            <p class="mt-[2px] text-[13px] text-fq-text-5">{{ $record['sub'] }}</p>
                        </div>
                        <span class="font-baloo text-[19px] font-extrabold whitespace-nowrap text-fq-text">{{ $record['value'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-kid.shell>
