<?php

use App\Enums\CompletionStatus;
use App\Enums\LedgerKind;
use App\Enums\PerkEffect;
use App\Enums\RedemptionStatus;
use App\Enums\SiblingOfferStatus;
use App\Models\Badge;
use App\Models\BonusTicketEntry;
use App\Models\ChoreCompletion;
use App\Models\DailyChest;
use App\Models\DailyQuest;
use App\Models\LedgerEntry;
use App\Models\OwnedPerk;
use App\Models\Profile;
use App\Models\Redemption;
use App\Models\SiblingOffer;
use App\Models\Spin;
use App\Models\StreakRepair;
use App\Services\HouseholdClock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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

    /** How many days the record board ranks. */
    private const BEST_DAYS = 3;

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
     * Points that actually landed in the bank on each household day, keyed by
     * date string.
     *
     * The Earn ledger only: cash turned in at the kitchen table and parent
     * top-ups move a balance without a day's work behind them, and a chart
     * called "points earned" that spiked on pocket money would be lying.
     *
     * @return Collection<string, int>
     */
    private function earnedByDay(): Collection
    {
        $clock = HouseholdClock::for($this->profile->household);

        return $this->profile->ledgerEntries()
            ->where('kind', LedgerKind::Earn)
            ->get(['id', 'amount', 'created_at'])
            ->groupBy(fn (LedgerEntry $entry) => $clock->dayFor($entry->created_at)->toDateString())
            ->map(fn (Collection $day) => (int) $day->sum('amount'));
    }

    /**
     * One entry per day for a window ending on $endDate, oldest first,
     * including the days nothing happened — a gap is as much of the picture as
     * a tall bar.
     *
     * @param  Collection<string, Collection<int, ChoreCompletion>>  $byDay
     * @param  Collection<string, int>  $earnedByDay
     * @return Collection<int, array{date: Carbon, count: int, points: int, isToday: bool}>
     */
    private function dayWindow(Collection $byDay, Collection $earnedByDay, Carbon $endDate, int $length): Collection
    {
        $today = HouseholdClock::for($this->profile->household)->today()->toDateString();

        return collect(range($length - 1, 0))
            ->map(function (int $daysAgo) use ($endDate, $byDay, $earnedByDay, $today) {
                $date = $endDate->copy()->subDays($daysAgo);
                $key = $date->toDateString();

                return [
                    'date' => $date,
                    'count' => $byDay->get($key, collect())->count(),
                    'points' => (int) $earnedByDay->get($key, 0),
                    'isToday' => $key === $today,
                ];
            });
    }

    /**
     * The days worth bragging about, best first — ranked on points earned,
     * with the chores that paid for them alongside.
     *
     * Ties break on chores done and then on the later date, so two identical
     * days come out in a fixed order rather than however the map happened to
     * be built.
     *
     * @param  Collection<string, Collection<int, ChoreCompletion>>  $byDay
     * @param  Collection<string, int>  $earnedByDay
     * @return Collection<int, array{date: Carbon, count: int, points: int}>
     */
    private function bestDays(Collection $byDay, Collection $earnedByDay): Collection
    {
        return $byDay->keys()
            ->merge($earnedByDay->keys())
            ->unique()
            ->map(fn (string $date) => [
                'date' => Carbon::parse($date),
                'count' => $byDay->get($date, collect())->count(),
                'points' => (int) $earnedByDay->get($date, 0),
            ])
            ->sortByDesc(fn (array $day) => [$day['points'], $day['count'], $day['date']->timestamp])
            ->take(self::BEST_DAYS)
            ->values();
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

    /**
     * The ticket feed with a running balance beside every row.
     *
     * Tickets arrive from four different places and never from anything a kid
     * deliberately did — a level crossed, a badge unlocked, a chest opened —
     * so a bare list of amounts still doesn't answer "why do I suddenly have
     * nine of these". Each row carries what the balance was before it and what
     * it became, so the column reads as a chain back to the number in the
     * header.
     *
     * The balance is walked backwards from the live one rather than forwards
     * from zero, which is what lets page two pick up exactly where page one
     * stopped without loading everything in between.
     *
     * @param  LengthAwarePaginator<int, BonusTicketEntry>  $page
     * @return Collection<int, array{id: int, text: string, kind: string, when: string, amount: string, color: string, before: int, after: int}>
     */
    private function ticketRows(LengthAwarePaginator $page, Carbon $today): Collection
    {
        $top = $page->first();

        $newer = $top === null ? 0 : (int) BonusTicketEntry::where('profile_id', $this->profile->id)
            ->where(fn (Builder $query) => $query
                ->where('created_at', '>', $top->created_at)
                ->orWhere(fn (Builder $tie) => $tie
                    ->where('created_at', $top->created_at)
                    ->where('id', '>', $top->id)))
            ->sum('amount');

        $after = $this->profile->bonus_tickets - $newer;

        return collect($page->items())->map(function (BonusTicketEntry $entry) use (&$after, $today) {
            $before = $after - $entry->amount;

            $row = [
                'id' => $entry->id,
                'text' => $this->withoutOwnName($entry->description),
                'kind' => strtoupper($entry->kind->label()),
                'when' => $this->whenLabel($entry->created_at, $today),
                'amount' => ($entry->amount > 0 ? '+' : '').number_format($entry->amount),
                'color' => $entry->amount < 0 ? 'var(--fq-coral)' : 'var(--fq-lime)',
                'before' => $before,
                'after' => $after,
            ];

            $after = $before;

            return $row;
        });
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
        $earnedByDay = $this->earnedByDay();
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

        // Everything a kid has sent in, not just what came back approved —
        // the pair is what makes a hit rate mean anything.
        $submissions = ChoreCompletion::where('profile_id', $this->profile->id)->count();
        $chorePoints = (int) $completions->sum('points_awarded');

        $chestsOpened = DailyChest::where('profile_id', $this->profile->id)->count();

        $perks = OwnedPerk::where('profile_id', $this->profile->id)
            ->selectRaw('count(*) as picked_up')
            ->selectRaw('sum(case when consumed_at is null then 0 else 1 end) as used')
            ->first();

        $ticketsEarned = (int) BonusTicketEntry::where('profile_id', $this->profile->id)
            ->where('amount', '>', 0)
            ->sum('amount');

        $rewards = Redemption::where('profile_id', $this->profile->id)
            ->selectRaw('count(*) as claimed')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as handed_over', [RedemptionStatus::Fulfilled->value])
            ->first();

        $today = HouseholdClock::for($this->profile->household)->today();

        /*
         * The furthest back paging can go: the day of the first chore this kid
         * ever got approved, rounded out to the end of the strip it sits in.
         * Without the clamp a kid could page into an empty century.
         */
        $firstDay = $dayCounts->keys()->merge($earnedByDay->keys())->min();
        $maxDaysBack = $firstDay === null
            ? 0
            : intdiv((int) Carbon::parse($firstDay)->diffInDays($today), self::RECENT_DAYS) * self::RECENT_DAYS;

        // Clamped here rather than in the click handlers, which would have to
        // load the whole history again to know where the wall is.
        $this->daysBack = max(0, min($this->daysBack, $maxDaysBack));

        // Anchored to today no matter where the strip is scrolled: "Today" and
        // "Last 7 days" answer what a kid did today, not what the window shows.
        $thisWeek = $this->dayWindow($byDay, $earnedByDay, $today, self::WEEK_DAYS);
        $strip = $this->dayWindow($byDay, $earnedByDay, $today->copy()->subDays($this->daysBack), self::RECENT_DAYS);

        $earnedToday = (int) $thisWeek->last()['points'];
        $earnedThisWeek = (int) $thisWeek->sum('points');

        $history = $this->profile->ledgerEntries()
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::HISTORY_PER_PAGE, pageName: 'history');

        // Its own cursor, so paging one feed doesn't drag the other with it.
        $ticketHistory = $this->profile->bonusTicketEntries()
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::HISTORY_PER_PAGE, pageName: 'tickets');

        $bestDayOn = $bestDayCount > 0
            ? Carbon::parse($dayCounts->search($bestDayCount))->toFormattedDateString()
            : 'still to come';

        return [
            /*
             * Today leads the page. A lifetime total is the number a kid is
             * proud of, but it isn't the one they open this page to check —
             * "what have I done today, and what is it worth" is, so both of
             * those get the hero and everything else lines up behind them.
             */
            'choresToday' => (int) $thisWeek->last()['count'],
            'earnedToday' => $earnedToday,
            'earnedTodayDollars' => $this->dollars($earnedToday),
            'heroTiles' => [
                ['label' => 'Chores done, all time', 'value' => number_format($completions->count()), 'color' => 'var(--fq-gold)'],
                ['label' => 'Last 7 days', 'value' => number_format((int) $thisWeek->sum('count')), 'color' => 'var(--fq-cyan)'],
                ['label' => 'Earned this week', 'value' => $this->dollars($earnedThisWeek), 'color' => 'var(--fq-lime)'],
                ['label' => 'Pts banked', 'value' => number_format($earned), 'color' => 'var(--fq-coral)'],
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
                    'suffix' => $bestDayCount > 0
                        ? Str::plural('chore', $bestDayCount).' · '.$bestDayOn
                        : $bestDayOn,
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
                [
                    'label' => 'Earned this week',
                    'value' => number_format($earnedThisWeek),
                    'suffix' => '≈ '.$this->dollars($earnedThisWeek),
                    'color' => 'var(--fq-lime)',
                ],
                [
                    'label' => 'Points per chore',
                    'value' => $completions->isNotEmpty()
                        ? number_format(round($chorePoints / $completions->count()))
                        : '0',
                    'suffix' => 'on average',
                    'color' => 'var(--fq-gold)',
                ],
                [
                    'label' => 'Chores approved',
                    'value' => $submissions > 0
                        ? round($completions->count() / $submissions * 100).'%'
                        : '—',
                    'suffix' => number_format($submissions).' sent in',
                    'color' => 'var(--fq-lime)',
                ],
                [
                    'label' => 'Chests opened',
                    'value' => number_format($chestsOpened),
                    'suffix' => 'daily bonuses',
                    'color' => 'var(--fq-gold)',
                ],
                [
                    'label' => 'Perks used',
                    'value' => number_format((int) $perks->used),
                    'suffix' => 'of '.number_format((int) $perks->picked_up).' picked up',
                    'color' => 'var(--fq-violet)',
                ],
                [
                    'label' => 'Bonus tickets',
                    'value' => number_format($this->profile->bonus_tickets),
                    'suffix' => number_format($ticketsEarned).' won all time',
                    'color' => 'var(--fq-magenta)',
                ],
                [
                    'label' => 'Rewards claimed',
                    'value' => number_format((int) $rewards->claimed),
                    'suffix' => number_format((int) $rewards->handed_over).' handed over',
                    'color' => 'var(--fq-coral)',
                ],
            ],
            'bestDays' => $this->bestDays($byDay, $earnedByDay),
            'topChores' => $this->topChores($completions),
            'strip' => $strip,
            /*
             * Two readings of the same fortnight, on one set of paging
             * controls. Chores done and points earned aren't the same shape —
             * a single big chore can outweigh a busy day of small ones — and
             * stacking them is how that shows up.
             *
             * Each is scaled against the busiest day on the strip rather than
             * an all-time best, so a quiet fortnight still has shape.
             */
            'charts' => [
                [
                    'title' => 'Chores done',
                    'key' => 'count',
                    'unit' => 'chores',
                    'max' => max(1, (int) $strip->max('count')),
                    'total' => number_format((int) $strip->sum('count')),
                    'fill' => 'linear-gradient(180deg, var(--fq-cyan), var(--fq-violet))',
                ],
                [
                    'title' => 'Points earned',
                    'key' => 'points',
                    'unit' => 'pts',
                    'max' => max(1, (int) $strip->max('points')),
                    'total' => number_format((int) $strip->sum('points')),
                    'fill' => 'linear-gradient(180deg, var(--fq-gold), var(--fq-coral))',
                ],
            ],
            'stripTotal' => (int) $strip->sum('count'),
            'stripLabel' => $this->daysBack === 0
                ? 'Last '.self::RECENT_DAYS.' days'
                : $strip->first()['date']->toFormattedDateString().' – '.$strip->last()['date']->toFormattedDateString(),
            'canGoEarlier' => $this->daysBack < $maxDaysBack,
            'canGoLater' => $this->daysBack > 0,
            'history' => $history,
            'historyRows' => collect($history->items())
                ->map(fn (LedgerEntry $entry) => $this->ledgerRow($entry, $today)),
            'ticketHistory' => $ticketHistory,
            'ticketRows' => $this->ticketRows($ticketHistory, $today),
            'ticketsHeld' => $this->profile->bonus_tickets,
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

        {{-- Today gets the hero: "how am I doing right now" is the question
             this page is opened with, and a lifetime total can't answer it.
             The all-time figures keep their place in the tiles alongside. --}}
        {{-- Both figures are label-over-value and exactly two lines tall, so
             they line up on the top and the bottom. The points behind the money
             ride the same baseline rather than adding a third line to one side
             and knocking the pair out of step. --}}
        <div class="flex flex-wrap items-end gap-x-9 gap-y-5 rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
            <div>
                <p class="font-mono-fq text-[10px] tracking-[0.16em] text-fq-text-4 uppercase">Chores today</p>
                <p class="mt-[6px] font-baloo text-[60px] leading-[0.9] font-extrabold text-fq-lime">
                    {{ number_format($choresToday) }}
                </p>
            </div>

            <div>
                <p class="font-mono-fq text-[10px] tracking-[0.16em] text-fq-text-4 uppercase">Earned today</p>
                <div class="mt-[6px] flex items-baseline gap-[7px]">
                    <span class="font-baloo text-[60px] leading-[0.9] font-extrabold text-fq-gold">
                        {{ $earnedTodayDollars }}
                    </span>
                    <span class="font-mono-fq text-[11px] whitespace-nowrap text-fq-text-5">
                        {{ number_format($earnedToday) }} PTS
                    </span>
                </div>
            </div>

            <div class="ml-auto flex flex-wrap justify-end gap-[10px]">
                @foreach ($heroTiles as $tile)
                    <div
                        wire:key="hero-{{ $tile['label'] }}"
                        class="rounded-[14px] border border-fq-line-2 bg-fq-sunk px-[15px] py-[11px]"
                    >
                        <p class="font-baloo text-[26px] leading-none font-extrabold" style="color: {{ $tile['color'] }}">
                            {{ $tile['value'] }}
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

            {{-- The record board: which days actually paid, and what it took
                 to get there. Ranked on points rather than chores, because a
                 single big job can beat an afternoon of little ones. --}}
            <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
                <div class="flex items-baseline justify-between gap-3">
                    <h3 class="font-baloo text-xl font-bold">Best days</h3>
                    <span class="font-mono-fq text-[10px] text-fq-text-4">YOUR RECORDS</span>
                </div>

                <div class="mt-3 flex flex-col">
                    @forelse ($bestDays as $day)
                        @php
                            $medal = ['var(--fq-gold)', 'var(--fq-text-2)', 'var(--fq-coral)'][$loop->index];
                        @endphp

                        <div
                            wire:key="best-{{ $day['date']->toDateString() }}"
                            class="flex items-center gap-3 border-b border-fq-divider py-[11px] last:border-b-0"
                        >
                            <span
                                class="w-[22px] shrink-0 font-baloo text-[19px] leading-none font-extrabold"
                                style="color: {{ $medal }}"
                            >{{ $loop->iteration }}</span>

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold">{{ $day['date']->toFormattedDateString() }}</p>
                                <p class="font-mono-fq text-[10px] text-fq-text-5">
                                    {{ $day['count'] }} {{ Str::plural('chore', $day['count']) }} done
                                </p>
                            </div>

                            <span class="font-baloo text-[19px] leading-none font-extrabold whitespace-nowrap text-fq-lime">
                                {{ number_format($day['points']) }}
                            </span>
                            <span class="font-mono-fq text-[10px] text-fq-text-4">PTS</span>
                        </div>
                    @empty
                        <p class="py-2 text-sm text-fq-text-5">
                            No record days yet. The first one is whichever day you earn on.
                        </p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Both readings of the same fortnight, on one set of controls, so
             paging back moves them together and the pair stays comparable. --}}
        <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
            <div class="flex items-baseline justify-between gap-3">
                <h3 class="font-baloo text-xl font-bold">{{ $stripLabel }}</h3>
                <span class="font-mono-fq text-[10px] text-fq-text-4">{{ $stripTotal }} CHORES</span>
            </div>

            <div class="mt-2 flex flex-col gap-4">
                @foreach ($charts as $chart)
                    <div wire:key="chart-{{ $chart['key'] }}">
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="font-mono-fq text-[9px] tracking-[0.14em] text-fq-text-4 uppercase">
                                {{ $chart['title'] }}
                            </p>
                            <span class="font-mono-fq text-[10px] text-fq-text-5">
                                {{ $chart['total'] }} {{ strtoupper($chart['unit']) }}
                            </span>
                        </div>

                        <div class="mt-2 flex h-[110px] items-end gap-[4px]">
                            @foreach ($strip as $day)
                                @php
                                    $value = $day[$chart['key']];
                                    // An empty day keeps a 3px stub, so the strip
                                    // still reads as a row of days rather than a hole.
                                    $height = max(3, round($value / $chart['max'] * 88));
                                    $fill = match (true) {
                                        $value === 0 => 'var(--fq-line-2)',
                                        $day['isToday'] => 'var(--fq-fill-gold)',
                                        default => $chart['fill'],
                                    };
                                @endphp

                                <div
                                    wire:key="{{ $chart['key'] }}-{{ $day['date']->toDateString() }}"
                                    class="flex h-full flex-1 flex-col justify-end gap-[6px]"
                                >
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

        {{-- Tickets get their own feed rather than a column in the one above:
             they're a separate currency, and nothing a kid does earns them
             directly — they turn up off levels, badges and chests. The running
             balance beside each row is the point of the card, because "why do
             I suddenly have nine of these" is the only question it's opened
             with. --}}
        <div class="rounded-[22px] border border-fq-ticket-line p-[18px]" style="background: var(--fq-panel)">
            <div class="flex items-baseline justify-between gap-3">
                <h3 class="font-baloo text-xl font-bold">Ticket activity</h3>
                <span class="font-mono-fq text-[10px] text-fq-text-4">
                    {{ number_format($ticketsHeld) }} IN HAND
                </span>
            </div>

            <div class="mt-[10px] flex flex-col">
                @forelse ($ticketRows as $row)
                    <div
                        wire:key="ticket-{{ $row['id'] }}"
                        class="flex items-center gap-3 border-t border-fq-divider py-[10px]"
                    >
                        <span
                            class="h-2 w-2 shrink-0 rounded-full"
                            style="background: {{ $row['color'] }}"
                        ></span>

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[13px] text-fq-text-2">{{ $row['text'] }}</p>
                            <p class="font-mono-fq text-[9px] tracking-[0.1em] text-fq-text-5">
                                {{ $row['kind'] }} · {{ $row['when'] }}
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <p
                                class="font-baloo text-[15px] leading-none font-extrabold"
                                style="color: {{ $row['color'] }}"
                            >{{ $row['amount'] }}</p>
                            <p class="mt-[3px] font-mono-fq text-[10px] whitespace-nowrap text-fq-text-5">
                                {{ number_format($row['before']) }} → {{ number_format($row['after']) }}
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="py-2 text-sm text-fq-text-5">
                        No tickets yet. They turn up when you level up, unlock a badge, or open a chest.
                    </p>
                @endforelse
            </div>

            <x-pager :paginator="$ticketHistory" page-name="tickets" />
        </div>
    </div>
</x-kid.shell>
