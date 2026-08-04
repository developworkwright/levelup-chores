<?php

use App\Enums\CompletionStatus;
use App\Enums\LedgerKind;
use App\Enums\PerkEffect;
use App\Enums\SiblingOfferStatus;
use App\Models\Badge;
use App\Models\ChoreCompletion;
use App\Models\DailyQuest;
use App\Models\LedgerEntry;
use App\Models\OwnedPerk;
use App\Models\Profile;
use App\Models\SiblingOffer;
use App\Models\Spin;
use App\Models\StreakRepair;
use App\Services\HouseholdClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    /** How many household days the activity strip covers. */
    private const RECENT_DAYS = 14;

    /** History rows per page — a short page, because it's a scroll not a table. */
    private const HISTORY_PER_PAGE = 8;

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

    /**
     * The chip a ledger row wears. Kinds share a trio by direction rather than
     * each getting its own colour: what a kid wants off a wall of rows is which
     * way the points went, and six palettes would say that six ways.
     *
     * @return array{label: string, tone: string}
     */
    private function tagFor(LedgerKind $kind): array
    {
        return match ($kind) {
            LedgerKind::Earn => ['label' => 'EARNED', 'tone' => 'in'],
            LedgerKind::CashIn => ['label' => 'CASH IN', 'tone' => 'in'],
            LedgerKind::Spend => ['label' => 'SPENT', 'tone' => 'out'],
            LedgerKind::CashOut => ['label' => 'CASH OUT', 'tone' => 'out'],
            LedgerKind::Adjustment => ['label' => 'ADJUSTED', 'tone' => 'side'],
            LedgerKind::Transfer => ['label' => 'TRADE', 'tone' => 'side'],
        };
    }

    /**
     * How long ago, in the shortest form that still says it: today and
     * yesterday by name, this week by weekday, anything older by date.
     */
    private function whenLabel(Carbon $date, Carbon $today): string
    {
        $daysAgo = (int) $date->copy()->startOfDay()->diffInDays($today->copy()->startOfDay());

        return match (true) {
            $daysAgo <= 0 => 'TODAY',
            $daysAgo === 1 => 'YEST',
            $daysAgo < 7 => strtoupper($date->format('D')),
            default => strtoupper($date->format('M j')),
        };
    }

    /**
     * The description with the kid's own name taken out of it.
     *
     * Ledger text is written for the parent's household-wide log, where naming
     * the kid is the whole point. On a kid's own history every row is theirs
     * already, so the name is a prefix that costs the description the width it
     * needs to be readable on a phone.
     */
    private function withoutOwnName(string $description): string
    {
        $name = preg_quote($this->profile->name, '/');

        // A trade names both sides. Dropping one would leave a dangling arrow,
        // so the pair collapses to a direction and the sibling.
        if (preg_match('/^(.+) → (.+): (.+)$/u', $description, $trade) === 1) {
            return $trade[1] === $this->profile->name
                ? "To {$trade[2]}: {$trade[3]}"
                : "From {$trade[1]}: {$trade[3]}";
        }

        // One replacement only: a chore whose name happens to start with the
        // kid's would otherwise lose a word to the second pass.
        return Str::ucfirst(
            preg_replace('/^'.$name.' (?:— )?|( (?:for|to) '.$name.')$/u', '', $description, 1)
        );
    }

    /**
     * One ledger entry flattened for display.
     *
     * @return array{id: int, tag: string, tone: string, text: string, amount: string, amountColor: string, when: string}
     */
    private function ledgerRow(LedgerEntry $entry, Carbon $today): array
    {
        $tag = $this->tagFor($entry->kind);

        return [
            'id' => $entry->id,
            'tag' => $tag['label'],
            'tone' => $tag['tone'],
            'text' => $this->withoutOwnName($entry->description),
            'amount' => match (true) {
                $entry->amount > 0 => '+'.number_format($entry->amount),
                $entry->amount < 0 => '−'.number_format(abs($entry->amount)),
                default => '—',
            },
            'amountColor' => match (true) {
                $entry->amount > 0 => 'var(--fq-lime)',
                $entry->amount < 0 => 'var(--fq-coral)',
                default => 'var(--fq-text-6)',
            },
            'when' => $this->whenLabel($entry->created_at, $today),
        ];
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
        $spent = (int) $flows->sum('out');

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

        $rerolls = OwnedPerk::where('profile_id', $this->profile->id)
            ->where('effect', PerkEffect::QuestReroll)
            ->whereNotNull('consumed_at')
            ->count();

        // Both directions of a trade: one this kid offered and one they were
        // offered are the same event to them, and only the deals that actually
        // settled count as done.
        $trades = SiblingOffer::where(fn ($query) => $query
            ->where('from_profile_id', $this->profile->id)
            ->orWhere('to_profile_id', $this->profile->id))
            ->selectRaw('count(*) as asked')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as done', [SiblingOfferStatus::Accepted->value])
            ->first();

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

        $history = $this->profile->ledgerEntries()
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::HISTORY_PER_PAGE, pageName: 'history');

        $bestDayOn = $bestDayCount > 0
            ? Carbon::parse($dayCounts->search($bestDayCount))->toFormattedDateString()
            : 'still to come';

        return [
            // The one number the page is opened for, at the size that says so —
            // with today and the week beside it, because a lifetime total on
            // its own can't answer "how am I doing right now".
            'lifetimeChores' => $completions->count(),
            'heroTiles' => [
                ['label' => 'Today', 'count' => $thisWeek->last()['count'], 'color' => 'var(--fq-lime)'],
                ['label' => 'Last 7 days', 'count' => (int) $thisWeek->sum('count'), 'color' => 'var(--fq-cyan)'],
                ['label' => 'Pts banked', 'count' => $earned, 'color' => 'var(--fq-coral)'],
            ],
            /*
             * One cell per stat, all the same shape. The old layout paired two
             * stats to a line and made the small print carry a value as well as
             * a qualifier, which is more work than a caption can do.
             */
            'grid' => [
                [
                    'label' => 'Points earned',
                    'value' => number_format($earned),
                    'suffix' => '≈ '.$this->dollars($earned),
                    'color' => 'var(--fq-gold)',
                ],
                [
                    'label' => 'Points spent',
                    'value' => number_format($spent),
                    'suffix' => '≈ '.$this->dollars($spent),
                    'color' => 'var(--fq-coral)',
                ],
                [
                    'label' => 'Quests cleared',
                    'value' => number_format($questsCleared),
                    'suffix' => $questsAssigned > 0 ? 'of '.$questsAssigned.' handed out' : 'none handed out yet',
                    'color' => 'var(--fq-violet)',
                ],
                [
                    'label' => 'Best day',
                    'value' => (string) $bestDayCount,
                    'suffix' => $bestDayCount > 0 ? 'chores · '.$bestDayOn : $bestDayOn,
                    'color' => 'var(--fq-text)',
                ],
                [
                    'label' => 'Longest streak',
                    'value' => (string) $this->longestStreak(),
                    'suffix' => 'days',
                    'color' => 'var(--fq-streak)',
                ],
                [
                    'label' => 'Current streak',
                    'value' => (string) $this->profile->streak,
                    'suffix' => 'days right now',
                    'color' => 'var(--fq-lime)',
                ],
                [
                    'label' => 'Level',
                    'value' => 'LVL '.$this->profile->level(),
                    'suffix' => number_format($this->profile->xp).' XP',
                    'color' => 'var(--fq-cyan)',
                ],
                [
                    'label' => 'Badges earned',
                    'value' => $badgesEarned.' / '.Badge::count(),
                    'suffix' => 'trophy case',
                    'color' => 'var(--fq-magenta)',
                ],
                [
                    'label' => 'Days active',
                    'value' => number_format($dayCounts->count()),
                    'suffix' => 'with a chore done',
                    'color' => 'var(--fq-cyan)',
                ],
                [
                    'label' => 'Wheel spins',
                    'value' => (string) $spins,
                    'suffix' => $tripleSpins.' landed 3×',
                    'color' => 'var(--fq-lime)',
                ],
                [
                    'label' => 'Quest rerolls',
                    'value' => (string) $rerolls,
                    'suffix' => 'used',
                    'color' => 'var(--fq-cyan)',
                ],
                [
                    'label' => 'Trades done',
                    'value' => (string) (int) $trades->done,
                    'suffix' => 'of '.(int) $trades->asked.' asked',
                    'color' => 'var(--fq-text)',
                ],
                [
                    'label' => 'Cash-outs',
                    'value' => (string) $cashOutAmounts->count(),
                    'suffix' => $cashOutAmounts->isNotEmpty()
                        ? 'biggest '.number_format($cashOutAmounts->max())
                        : 'nothing cashed out yet',
                    'color' => 'var(--fq-coral)',
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
            'history' => $history,
            'historyRows' => collect($history->items())
                ->map(fn (LedgerEntry $entry) => $this->ledgerRow($entry, $today)),
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

        {{-- Chores get the hero rather than a cell in the grid below: "how am I
             doing" is the question this page is opened with, and the lifetime
             count is the answer, with today and the week beside it. --}}
        <div class="flex flex-wrap items-end gap-5 rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
            <div>
                <p class="font-mono-fq text-[10px] tracking-[0.16em] text-fq-text-4 uppercase">Chores done, all time</p>
                <p class="font-baloo text-[76px] leading-[0.9] font-extrabold text-fq-gold">
                    {{ number_format($lifetimeChores) }}
                </p>
            </div>

            <div class="ml-auto flex flex-wrap justify-end gap-[10px] pb-2">
                @foreach ($heroTiles as $tile)
                    <div
                        wire:key="hero-{{ $tile['label'] }}"
                        class="rounded-[14px] border border-fq-line-2 bg-fq-sunk px-[15px] py-[11px]"
                    >
                        <p class="font-baloo text-[26px] leading-none font-extrabold" style="color: {{ $tile['color'] }}">
                            {{ number_format($tile['count']) }}
                        </p>
                        <p class="mt-[5px] font-mono-fq text-[9px] tracking-[0.14em] text-fq-text-4 uppercase">
                            {{ $tile['label'] }}
                        </p>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-3">
            @foreach ($grid as $cell)
                <div wire:key="cell-{{ $cell['label'] }}" class="rounded-[18px] border border-fq-line bg-fq-panel px-4 py-[14px]">
                    <p class="font-mono-fq text-[9px] tracking-[0.14em] text-fq-text-4 uppercase">{{ $cell['label'] }}</p>
                    <div class="mt-[7px] flex flex-wrap items-baseline gap-[7px]">
                        <span class="font-baloo text-2xl leading-none font-extrabold" style="color: {{ $cell['color'] }}">
                            {{ $cell['value'] }}
                        </span>
                        <span class="font-mono-fq text-[10px] text-fq-text-5">{{ $cell['suffix'] }}</span>
                    </div>
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

        {{-- No separate "recent" list above this one: the first page of the
             history is the recent list, and printing the same five rows twice
             only pushed the rest of it further down. --}}
        <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
            <div class="flex items-baseline justify-between gap-3">
                <h3 class="font-baloo text-xl font-bold">History</h3>
                <span class="font-mono-fq text-[10px] text-fq-text-4">
                    {{ number_format($history->total()) }} {{ $history->total() === 1 ? 'ENTRY' : 'ENTRIES' }}
                </span>
            </div>

            <div class="mt-[10px] flex flex-col">
                @forelse ($historyRows as $row)
                    {{-- The three fixed columns tighten on a phone so the
                         description keeps the width it needs to say what
                         actually happened. --}}
                    <div
                        wire:key="history-{{ $row['id'] }}"
                        class="grid grid-cols-[62px_minmax(0,1fr)_58px_42px] items-center gap-2 border-t border-fq-divider py-[10px] sm:grid-cols-[86px_minmax(0,1fr)_86px_74px] sm:gap-3"
                    >
                        <span
                            class="font-mono-fq text-[9px] tracking-[0.1em]"
                            style="color: var(--fq-tag-{{ $row['tone'] }}-fg)"
                        >{{ $row['tag'] }}</span>
                        <span class="min-w-0 truncate text-[13px] text-fq-text-2">{{ $row['text'] }}</span>
                        <span
                            class="text-right font-mono-fq text-[11px]"
                            style="color: {{ $row['amountColor'] }}"
                        >{{ $row['amount'] }}</span>
                        <span class="text-right font-mono-fq text-[10px] text-fq-text-5">{{ $row['when'] }}</span>
                    </div>
                @empty
                    <p class="py-2 text-sm text-fq-text-5">Your history starts with the first chore you clear.</p>
                @endforelse
            </div>

            @if ($history->hasPages())
                <p class="mt-[14px] font-mono-fq text-[10px] text-fq-text-5">
                    {{ number_format($history->firstItem()) }}&ndash;{{ number_format($history->lastItem()) }}
                    OF {{ number_format($history->total()) }}
                </p>
            @endif

            <x-pager :paginator="$history" page-name="history" />
        </div>
    </div>
</x-kid.shell>
