<?php

use App\Enums\CompletionStatus;
use App\Enums\MonsterTier;
use App\Enums\ProfileRole;
use App\Models\ChoreCompletion;
use App\Models\Profile;
use App\Services\ChoreService;
use App\Services\HouseholdClock;
use App\Services\MonsterService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    /** The window the "this week" columns cover, today included. */
    private const WEEK_DAYS = 7;

    /** How far out a finish date is worth quoting before "one day" is the honest answer. */
    private const MAX_FORECAST_DAYS = 365;

    public Profile $profile;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isParent(), 403);
    }

    /**
     * One leaderboard: every kid ranked on a single measure, biggest first.
     *
     * Kids on zero stay on the board rather than dropping off it — the point
     * of showing a parent this is who's ahead and who could use a nudge, and
     * the second half of that only shows up if everyone is listed.
     *
     * @param  Collection<int, Profile>  $kids
     * @param  callable(Profile): int  $value
     * @param  callable(Profile): string  $display
     * @return array{label: string, color: string, best: int, rows: Collection<int, array{profile: Profile, value: int, display: string, isLeader: bool}>}
     */
    private function board(string $label, string $color, Collection $kids, callable $value, callable $display): array
    {
        $rows = $kids->map(fn (Profile $kid) => [
            'profile' => $kid,
            'value' => (int) $value($kid),
            'display' => (string) $display($kid),
        ])->sortByDesc('value')->values();

        $best = (int) $rows->max('value');

        return [
            'label' => $label,
            'color' => $color,
            // Bars are drawn against the leader, so a two-kid household on a
            // board they're both nailing doesn't render two half-width stubs.
            'best' => max(1, $best),
            'rows' => $rows->map(fn (array $row) => [
                ...$row,
                // Ties share the crown rather than letting the sort order pick
                // a winner out of two identical numbers.
                'isLeader' => $best > 0 && $row['value'] === $best,
            ]),
        ];
    }

    /**
     * Days to cover $remaining at $perDay, or null when the answer is so far
     * out that quoting a date would be a fiction rather than a plan.
     */
    private function daysAt(int $remaining, float $perDay): ?int
    {
        if ($remaining <= 0) {
            return 0;
        }

        if ($perDay < 1) {
            return null;
        }

        $days = (int) ceil($remaining / $perDay);

        return $days > self::MAX_FORECAST_DAYS ? null : $days;
    }

    /**
     * What one kid is working toward and how it's going — the same figures
     * their own Goal Planner shows them, minus the "what if" ladder, which is
     * a decision for the kid rather than something to read over their shoulder.
     *
     * @return array{
     *     profile: Profile,
     *     saving: ?\App\Models\StoreItem,
     *     remaining: int,
     *     percent: int,
     *     dailyGoal: ?int,
     *     earnedToday: int,
     *     todayPercent: int,
     *     pace: float,
     *     daysAtPace: ?int,
     *     daysAtGoal: ?int,
     * }
     */
    private function goalFor(Profile $kid, ChoreService $chores): array
    {
        $saving = $kid->savingFor;
        $remaining = $saving ? max(0, $saving->cost - $kid->points) : 0;

        $dailyGoal = $kid->daily_points_goal;
        $earnedToday = $chores->pointsEarnedToday($kid);
        $pace = $chores->dailyPace($kid);

        return [
            'profile' => $kid,
            'saving' => $saving,
            'remaining' => $remaining,
            'percent' => $saving && $saving->cost > 0
                ? min(100, (int) round($kid->points / $saving->cost * 100))
                : 0,
            'dailyGoal' => $dailyGoal,
            'earnedToday' => $earnedToday,
            'todayPercent' => $dailyGoal > 0 ? min(100, (int) round($earnedToday / $dailyGoal * 100)) : 0,
            'pace' => $pace,
            // Both are worth showing: one is the plan, the other is the truth,
            // and a target nobody is hitting is the thing to talk about.
            'daysAtPace' => $saving ? $this->daysAt($remaining, $pace) : null,
            'daysAtGoal' => $saving && $dailyGoal ? $this->daysAt($remaining, $dailyGoal) : null,
        ];
    }

    public function with(): array
    {
        $household = $this->profile->household;
        $chores = app(ChoreService::class);
        $clock = HouseholdClock::for($household);

        $kids = Profile::where('household_id', $household->id)
            ->where('role', ProfileRole::Kid)
            ->with('savingFor')
            ->withCount('badges')
            ->orderBy('name')
            ->get();

        // One grouped query rather than a pair per kid — this page exists to be
        // glanced at, and every kid is on it at once.
        $since = $clock->startOf($clock->today()->copy()->subDays(self::WEEK_DAYS - 1));
        $week = ChoreCompletion::whereIn('profile_id', $kids->modelKeys())
            ->where('status', CompletionStatus::Approved)
            ->where('submitted_at', '>=', $since)
            ->selectRaw('profile_id, count(*) as chores_done, sum(points_awarded) as points_earned')
            ->groupBy('profile_id')
            ->get()
            ->keyBy('profile_id');

        $choresThisWeek = fn (Profile $kid) => (int) ($week->get($kid->id)?->chores_done ?? 0);
        $pointsThisWeek = fn (Profile $kid) => (int) ($week->get($kid->id)?->points_earned ?? 0);

        // The long game is what the standings are about — a table planning
        // against three goals at once would be planning against a number
        // nobody is working toward.
        $arena = app(MonsterService::class);
        $longGame = $arena->at($household, MonsterTier::Three);
        $longGameState = $longGame ? $arena->stateFor($longGame) : null;
        $contributors = $longGame ? $arena->contributionsFor($longGame) : collect();
        $intoTheGoal = $contributors->pluck('points', 'profile_id');

        return [
            'household' => $household,
            'kids' => $kids,
            'longGame' => $longGameState,
            'goalPercent' => $longGameState['damagePercent'] ?? 0,
            'goalRemaining' => $longGameState['health'] ?? 0,
            'familyPace' => $chores->householdDailyPace($household),
            'contributors' => $contributors,
            'boards' => [
                $this->board(
                    'Into the long game', 'var(--fq-lime)', $kids,
                    fn (Profile $kid) => (int) ($intoTheGoal[$kid->id] ?? 0),
                    fn (Profile $kid) => number_format($intoTheGoal[$kid->id] ?? 0).' PTS',
                ),
                $this->board(
                    'Level', 'var(--fq-violet)', $kids,
                    fn (Profile $kid) => $kid->xp,
                    fn (Profile $kid) => 'LVL '.$kid->level().' · '.number_format($kid->xp).' XP',
                ),
                $this->board(
                    'Streak', 'var(--fq-gold)', $kids,
                    fn (Profile $kid) => $kid->streak,
                    fn (Profile $kid) => $kid->streak.'D',
                ),
                $this->board(
                    'Chores this week', 'var(--fq-cyan)', $kids,
                    $choresThisWeek,
                    fn (Profile $kid) => $choresThisWeek($kid).' · '.number_format($pointsThisWeek($kid)).' PTS',
                ),
                $this->board(
                    'Badges', 'var(--fq-magenta)', $kids,
                    fn (Profile $kid) => $kid->badges_count,
                    fn (Profile $kid) => $kid->badges_count.' EARNED',
                ),
            ],
            'goals' => $kids->map(fn (Profile $kid) => $this->goalFor($kid, $chores)),
        ];
    }
}; ?>

<x-parent.shell :profile="$profile" active="standings">
    <div class="flex flex-col gap-[14px]">
        <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
            <p class="font-mono-fq text-[10px] tracking-[0.22em] text-fq-cyan uppercase">Who's winning what</p>
            <h2 class="mt-1 font-baloo text-2xl font-extrabold">Standings</h2>
            <p class="mt-2 max-w-[620px] text-sm text-fq-text-2">
                The same numbers the kids are playing for, all in one place — handy for
                pointing out who's on a tear and who's a couple of chores off the crown.
            </p>
        </div>

        <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
            @if (! $longGame)
                <h3 class="font-baloo text-xl font-bold">No long game standing</h3>
                <p class="mt-2 text-sm text-fq-text-2">
                    Line up a Level 3 monster on the
                    <a href="{{ route('parent.monsters') }}" wire:navigate class="text-fq-lime underline">Monster Deck</a>
                    and this fills in.
                </p>
            @else
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="font-baloo text-xl font-bold">{{ $longGame['reward'] }}</h3>
                    <span class="font-mono-fq text-[10px] text-fq-lime">{{ $goalPercent }}%</span>
                </div>

                <div class="mt-3 h-4 overflow-hidden rounded-full border border-fq-line bg-fq-track">
                    <div
                        class="h-full rounded-full transition-[width] duration-500"
                        style="width:{{ $goalPercent }}%;background:linear-gradient(90deg, var(--fq-cyan), var(--fq-lime), var(--fq-gold))"
                    ></div>
                </div>

                <p class="mt-2 font-mono-fq text-[11px] text-fq-text-4">
                    {{ number_format($longGame['damage']) }} / {{ number_format($longGame['maxHealth']) }} PTS ·
                    {{ number_format($goalRemaining) }} TO GO ·
                    {{ number_format($familyPace, 0) }} PTS/DAY BETWEEN THEM
                </p>

                <div class="mt-4 border-t border-fq-divider pt-[14px]">
                    <x-goal-mvp :contributors="$contributors" />
                </div>
            @endif
        </div>

        @if ($kids->isEmpty())
            <div class="rounded-[18px] border border-dashed border-fq-line-3 bg-fq-panel p-6 text-center text-sm text-fq-text-5">
                No kids on the roster yet — add one and the boards fill themselves in.
            </div>
        @else
            <div class="grid grid-cols-[repeat(auto-fit,minmax(280px,1fr))] gap-[14px]">
                @foreach ($boards as $board)
                    <div wire:key="board-{{ $board['label'] }}" class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
                        <p class="font-mono-fq text-[10px] tracking-[0.16em] uppercase" style="color: {{ $board['color'] }}">
                            {{ $board['label'] }}
                        </p>

                        <div class="mt-[10px] flex flex-col gap-[10px]">
                            @foreach ($board['rows'] as $row)
                                @php $kid = $row['profile']; @endphp

                                <div wire:key="board-{{ $board['label'] }}-{{ $kid->id }}">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="grid h-[22px] w-[22px] shrink-0 place-items-center rounded-[6px] font-baloo text-[11px] font-extrabold text-fq-bg"
                                            style="background: {{ $kid->color->cssVar() }}"
                                        >{{ mb_substr($kid->name, 0, 1) }}</span>

                                        <span class="min-w-0 flex-1 truncate text-[13px] font-semibold">
                                            {{ $kid->name }}
                                            @if ($row['isLeader'])
                                                <span title="Leading this board">&#128081;</span>
                                            @endif
                                        </span>

                                        <span class="font-mono-fq text-[11px] whitespace-nowrap text-fq-text-4">
                                            {{ $row['display'] }}
                                        </span>
                                    </div>

                                    <div class="mt-[6px] h-[10px] overflow-hidden rounded-full bg-fq-track">
                                        <div
                                            class="h-full rounded-full transition-[width] duration-500"
                                            style="width: {{ round($row['value'] / $board['best'] * 100) }}%; background: {{ $row['isLeader'] ? $board['color'] : $kid->color->cssVar() }}"
                                        ></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        @if ($board['rows']->sum('value') === 0)
                            <p class="mt-[10px] text-[13px] text-fq-text-5">Nobody's on this board yet.</p>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="grid grid-cols-[repeat(auto-fit,minmax(320px,1fr))] gap-[14px]">
                @foreach ($goals as $goal)
                    @php $kid = $goal['profile']; @endphp

                    <div wire:key="goal-{{ $kid->id }}" class="flex flex-col gap-3 rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
                        <div class="flex items-center gap-3">
                            <div
                                class="flex h-[38px] w-[38px] items-center justify-center rounded-[12px] font-baloo text-base font-extrabold text-fq-bg"
                                style="background:{{ $kid->color->cssVar() }}"
                            >{{ mb_substr($kid->name, 0, 1) }}</div>
                            <div class="min-w-0">
                                <p class="font-baloo text-[17px] font-bold">{{ $kid->name }}'s goal</p>
                                <p class="font-mono-fq text-[10px] text-fq-text-4 uppercase">
                                    LVL {{ $kid->level() }} · {{ $kid->streak }}D STREAK · {{ number_format($kid->points) }} PTS BANKED
                                </p>
                            </div>
                        </div>

                        @if ($goal['saving'])
                            <div class="rounded-[16px] border border-fq-line-2 bg-fq-sunk p-[14px]">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <span class="text-sm font-semibold">{{ $goal['saving']->name }}</span>
                                    <span class="font-mono-fq text-[10px] text-fq-gold">{{ $goal['percent'] }}%</span>
                                </div>

                                <div class="mt-[10px] h-[12px] overflow-hidden rounded-full border border-fq-line bg-fq-track">
                                    <div
                                        class="h-full rounded-full transition-[width] duration-500"
                                        style="width:{{ $goal['percent'] }}%; background: linear-gradient(90deg, var(--fq-cyan), var(--fq-lime), var(--fq-gold))"
                                    ></div>
                                </div>

                                <p class="mt-2 font-mono-fq text-[10px] text-fq-text-4 uppercase">
                                    {{ number_format(min($kid->points, $goal['saving']->cost)) }} /
                                    {{ number_format($goal['saving']->cost) }} PTS ·
                                    {{ number_format($goal['remaining']) }} TO GO
                                </p>

                                <p class="mt-2 text-[13px] text-fq-text-5">
                                    @if ($goal['remaining'] <= 0)
                                        <span class="text-fq-lime">Already got enough — they can cash it in.</span>
                                    @elseif ($goal['daysAtPace'] === null)
                                        At their current pace this one is more than a year out.
                                    @else
                                        About {{ $goal['daysAtPace'] }} {{ Str::plural('day', $goal['daysAtPace']) }} away
                                        at their recent pace.
                                    @endif
                                </p>
                            </div>
                        @else
                            <div class="rounded-[16px] border border-dashed border-fq-line-3 bg-fq-sunk p-[14px] text-[13px] text-fq-text-5">
                                Hasn't picked anything to save for yet. Anything in the Loot Shop can be
                                pinned as a goal from their side.
                            </div>
                        @endif

                        <div class="rounded-[16px] border border-fq-line-2 bg-fq-sunk p-[14px]">
                            <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Daily target</p>

                            @if ($goal['dailyGoal'])
                                <div class="mt-2 h-[12px] overflow-hidden rounded-full border border-fq-line bg-fq-track">
                                    <div
                                        class="h-full rounded-full transition-[width] duration-500"
                                        style="width:{{ $goal['todayPercent'] }}%; background: linear-gradient(90deg, var(--fq-cyan), var(--fq-lime))"
                                    ></div>
                                </div>
                                <p class="mt-2 font-mono-fq text-[10px] text-fq-text-4 uppercase">
                                    TODAY {{ number_format($goal['earnedToday']) }} /
                                    {{ number_format($goal['dailyGoal']) }} PTS
                                    @if ($goal['earnedToday'] >= $goal['dailyGoal'])
                                        · <span class="text-fq-lime">HIT IT</span>
                                    @endif
                                </p>

                                @if ($goal['daysAtGoal'] !== null && $goal['remaining'] > 0)
                                    <p class="mt-2 text-[13px] text-fq-text-5">
                                        Sticking to it lands them there in {{ $goal['daysAtGoal'] }}
                                        {{ Str::plural('day', $goal['daysAtGoal']) }}.
                                    </p>
                                @endif
                            @else
                                <p class="mt-1 text-[13px] text-fq-text-5">
                                    No target set. {{ number_format($goal['earnedToday']) }} pts banked today.
                                </p>
                            @endif

                            <p class="mt-2 text-[13px] text-fq-text-5">
                                Averaging <span class="font-semibold text-fq-text-2">{{ number_format($goal['pace'], 0) }} pts</span>
                                a day over the last {{ \App\Services\ChoreService::PACE_DAYS }} days.
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-parent.shell>
