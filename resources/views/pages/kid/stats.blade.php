<?php

use App\Enums\CompletionStatus;
use App\Enums\LedgerKind;
use App\Models\Badge;
use App\Models\ChoreCompletion;
use App\Models\DailyQuest;
use App\Models\LedgerEntry;
use App\Models\Profile;
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

    /**
     * How far back the activity strip is scrolled, in days. Always a multiple
     * of RECENT_DAYS, so paging moves a whole strip at a time and no day is
     * ever shown twice or skipped between windows.
     */
    public int $daysBack = 0;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isKid(), 403);
    }

    public function showEarlier(): void
    {
        $this->daysBack += self::RECENT_DAYS;
    }

    public function showLater(): void
    {
        $this->daysBack = max(0, $this->daysBack - self::RECENT_DAYS);
    }

    public function showToday(): void
    {
        $this->daysBack = 0;
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
     * One entry per day for a window ending on $endDate, oldest first,
     * including the days nothing happened — a gap is as much of the picture as
     * a tall bar.
     *
     * @param  Collection<string, Collection<int, ChoreCompletion>>  $byDay
     * @return Collection<int, array{date: Carbon, count: int, points: int, isToday: bool}>
     */
    private function dayWindow(Collection $byDay, Carbon $endDate, int $length): Collection
    {
        $today = HouseholdClock::for($this->profile->household)->today()->toDateString();

        return collect(range($length - 1, 0))
            ->map(function (int $daysAgo) use ($endDate, $byDay, $today) {
                $date = $endDate->copy()->subDays($daysAgo);
                $day = $byDay->get($date->toDateString(), collect());

                return [
                    'date' => $date,
                    'count' => $day->count(),
                    'points' => (int) $day->sum('points_awarded'),
                    'isToday' => $date->toDateString() === $today,
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

    /** Points in the household's own currency — the same conversion the header uses. */
    private function dollars(int $points): string
    {
        return '$'.number_format($points / $this->profile->household->points_per_dollar, 2);
    }

    /**
     * Points in and points out per ledger kind. Both directions are needed per
     * kind rather than a net sum, because adjustments and sibling trades run
     * both ways and netting them would hide half of each.
     *
     * @return Collection<string, array{in: int, out: int, entries: int}>
     */
    private function ledgerFlows(): Collection
    {
        return $this->profile->ledgerEntries()
            ->selectRaw('kind, count(*) as entries')
            ->selectRaw('sum(case when amount > 0 then amount else 0 end) as points_in')
            ->selectRaw('sum(case when amount < 0 then -amount else 0 end) as points_out')
            ->groupBy('kind')
            ->get()
            ->mapWithKeys(fn (LedgerEntry $row) => [$row->kind->value => [
                'in' => (int) $row->points_in,
                'out' => (int) $row->points_out,
                'entries' => (int) $row->entries,
            ]]);
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

        $flows = $this->ledgerFlows();
        $earned = (int) $flows->sum('in');

        /*
         * Derived from the balance rather than summed out of the ledger, which
         * is the only definition that can't drift from the number in the
         * header: points only ever leave a kid by being cashed out, so
         * whatever they've earned and no longer hold has been paid out. It
         * also survives LedgerService clamping a balance at zero, where the
         * negative side of the ledger would overstate what actually left.
         */
        $cashedOut = max(0, $earned - $this->profile->points);

        // Both a Loot Shop redemption and a parent payout land here — the
        // ledger is the only place that sees both.
        $cashOutAmounts = $this->profile->ledgerEntries()
            ->whereIn('kind', [LedgerKind::Spend, LedgerKind::CashOut])
            ->pluck('amount')
            ->map(fn (int $amount) => abs($amount));

        $spins = Spin::where('profile_id', $this->profile->id)->count();
        $tripleSpins = Spin::where('profile_id', $this->profile->id)->where('multiplier', 3)->count();

        $badgesEarned = $this->profile->badges()->count();

        $today = HouseholdClock::for($this->profile->household)->today();

        /*
         * The furthest back paging can go: the day of the first chore this kid
         * ever got approved, rounded out to the end of the strip it sits in.
         * Without the clamp a kid could page into an empty century.
         */
        $firstDay = $dayCounts->keys()->min();
        $maxDaysBack = $firstDay === null
            ? 0
            : intdiv((int) Carbon::parse($firstDay)->diffInDays($today), self::RECENT_DAYS) * self::RECENT_DAYS;

        // Clamped here rather than in the click handlers, which would have to
        // load the whole history again to know where the wall is.
        $this->daysBack = max(0, min($this->daysBack, $maxDaysBack));

        // Anchored to today no matter where the strip is scrolled: "Today" and
        // "Last 7 days" answer what a kid did today, not what the window shows.
        $thisWeek = $this->dayWindow($byDay, $today, self::WEEK_DAYS);
        $strip = $this->dayWindow($byDay, $today->copy()->subDays($this->daysBack), self::RECENT_DAYS);

        return [
            'choreSplit' => [
                [
                    'label' => 'Today',
                    'count' => $thisWeek->last()['count'],
                    'points' => $thisWeek->last()['points'],
                    'color' => 'var(--fq-lime)',
                ],
                [
                    'label' => 'Last 7 days',
                    'count' => (int) $thisWeek->sum('count'),
                    'points' => (int) $thisWeek->sum('points'),
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
                    'sub' => '≈ '.$this->dollars($earned).' all time',
                    'color' => 'var(--fq-gold)',
                ],
                [
                    'label' => 'Cashed out',
                    'value' => number_format($cashedOut),
                    'sub' => '≈ '.$this->dollars($cashedOut).' collected',
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
            'strip' => $strip,
            // Bars are scaled against the busiest day on the strip rather than
            // an all-time best, so a quiet fortnight still has shape.
            'stripMax' => max(1, (int) $strip->max('count')),
            'stripTotal' => (int) $strip->sum('count'),
            'stripLabel' => $this->daysBack === 0
                ? 'Last '.self::RECENT_DAYS.' days'
                : $strip->first()['date']->toFormattedDateString().' – '.$strip->last()['date']->toFormattedDateString(),
            'canGoEarlier' => $this->daysBack < $maxDaysBack,
            'canGoLater' => $this->daysBack > 0,
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
                    'value' => (string) $cashOutAmounts->count(),
                    'sub' => $cashOutAmounts->isNotEmpty()
                        ? 'biggest: '.number_format($cashOutAmounts->max()).' pts'
                        : 'nothing cashed out yet',
                ],
            ],
            'balance' => $this->profile->points,
            'balanceDollars' => $this->dollars($this->profile->points),
            'earned' => $earned,
            'cashedOut' => $cashedOut,
            // Only the lines that actually happened: a kid who has never
            // traded shouldn't have to wonder what an empty "traded away" row
            // is telling them.
            'flowRows' => collect([
                ['label' => 'Chores, bonuses & chests', 'points' => $flows->get('earn')['in'] ?? 0, 'direction' => 'in'],
                ['label' => 'Cash turned in', 'points' => $flows->get('cash_in')['in'] ?? 0, 'direction' => 'in'],
                ['label' => 'Parent top-ups', 'points' => $flows->get('adjustment')['in'] ?? 0, 'direction' => 'in'],
                ['label' => 'Traded from a sibling', 'points' => $flows->get('transfer')['in'] ?? 0, 'direction' => 'in'],
                ['label' => 'Loot Shop rewards', 'points' => $flows->get('spend')['out'] ?? 0, 'direction' => 'out'],
                ['label' => 'Paid out by a parent', 'points' => $flows->get('cash_out')['out'] ?? 0, 'direction' => 'out'],
                ['label' => 'Traded to a sibling', 'points' => $flows->get('transfer')['out'] ?? 0, 'direction' => 'out'],
                ['label' => 'Parent take-backs', 'points' => $flows->get('adjustment')['out'] ?? 0, 'direction' => 'out'],
            ])->reject(fn (array $row) => $row['points'] === 0)->values(),
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
                    <h3 class="font-baloo text-xl font-bold">{{ $stripLabel }}</h3>
                    <span class="font-mono-fq text-[10px] text-fq-text-4">{{ $stripTotal }} CHORES</span>
                </div>

                <div class="mt-4 flex h-[110px] items-end gap-[4px]">
                    @foreach ($strip as $day)
                        @php
                            // A no-chore day keeps a 3px stub, so the strip
                            // still reads as a row of days rather than a hole.
                            $height = max(3, round($day['count'] / $stripMax * 88));
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

                {{-- Only rendered once there's history to walk back into, so a
                     kid on their first week isn't offered a dead button. --}}
                @if ($canGoEarlier || $canGoLater)
                    <div class="mt-4 flex items-center justify-between gap-2 border-t border-fq-divider pt-3">
                        <button
                            type="button"
                            wire:click="showEarlier"
                            @disabled(! $canGoEarlier)
                            class="rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[13px] py-[8px] text-[13px] text-fq-text-2-b transition hover:border-fq-line-4 hover:text-fq-text disabled:cursor-default disabled:opacity-35 disabled:hover:border-fq-line-2"
                        >&larr; Earlier</button>

                        @if ($canGoLater)
                            <button
                                type="button"
                                wire:click="showToday"
                                class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase transition hover:text-fq-lime"
                            >Back to today</button>
                        @endif

                        <button
                            type="button"
                            wire:click="showLater"
                            @disabled(! $canGoLater)
                            class="rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[13px] py-[8px] text-[13px] text-fq-text-2-b transition hover:border-fq-line-4 hover:text-fq-text disabled:cursor-default disabled:opacity-35 disabled:hover:border-fq-line-2"
                        >Later &rarr;</button>
                    </div>
                @endif
            </div>
        </div>

        {{-- Every point in and out, so the two tiles above can be checked
             against each other: earned − cashed out is the balance in the
             header, and nothing moves without showing up on a line here. --}}
        <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
            <div class="flex items-baseline justify-between gap-3">
                <h3 class="font-baloo text-xl font-bold">Every point</h3>
                <span class="font-mono-fq text-[10px] text-fq-text-4">IN AND OUT</span>
            </div>

            <div class="mt-2">
                @forelse ($flowRows as $row)
                    @php $isIn = $row['direction'] === 'in'; @endphp

                    <div wire:key="flow-{{ $row['label'] }}" class="flex items-center gap-3 border-b border-fq-divider py-[11px]">
                        <span
                            class="h-2 w-2 shrink-0 rounded-full"
                            style="background: {{ $isIn ? 'var(--fq-lime)' : 'var(--fq-coral)' }}"
                        ></span>
                        <span class="flex-1 text-sm">{{ $row['label'] }}</span>
                        <span
                            class="font-mono-fq text-[11px] whitespace-nowrap"
                            style="color: {{ $isIn ? 'var(--fq-text)' : 'var(--fq-negative-2)' }}"
                        >{{ $isIn ? '+' : '−' }}{{ number_format($row['points']) }}</span>
                    </div>
                @empty
                    <p class="py-4 text-sm text-fq-text-5">No points have moved yet.</p>
                @endforelse

                <div class="flex items-center gap-3 pt-[13px]">
                    <span class="flex-1 font-baloo text-[15px] font-bold">In the bank right now</span>
                    <span class="font-baloo text-[19px] font-extrabold whitespace-nowrap text-fq-lime">
                        {{ number_format($balance) }}
                    </span>
                    <span class="font-mono-fq text-[11px] text-fq-text-4">{{ $balanceDollars }}</span>
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
