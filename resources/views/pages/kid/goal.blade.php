<?php

use App\Models\Chore;
use App\Models\Profile;
use App\Services\ChoreService;
use App\Services\HouseholdClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * The daily targets offered, as multiples of what a chore round here
     * typically pays. Multiples rather than fixed point values so the ladder
     * fits a household of 10-point chores and one of 500-point chores alike —
     * "one chore a day" has to mean the same thing in both.
     */
    private const LADDER = [1, 2, 3, 4, 5, 6];

    /** Bounds on a hand-tuned daily target. */
    private const MIN_DAILY = 5;

    private const MAX_DAILY = 5000;

    /** How far a forecast is worth showing before "one day" is the honest answer. */
    private const MAX_FORECAST_DAYS = 365;

    /** Rungs to keep even once they've stopped changing the answer. */
    private const MIN_ROWS = 3;

    public Profile $profile;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isKid(), 403);
    }

    public function setDailyGoal(int $points): void
    {
        $this->profile->daily_points_goal = max(self::MIN_DAILY, min(self::MAX_DAILY, $points));
        $this->profile->save();
    }

    /** The +/- stepper, which has to have something to step from before one is picked. */
    public function adjustDailyGoal(int $delta): void
    {
        $this->setDailyGoal(($this->profile->daily_points_goal ?? $this->step()) + $delta);
    }

    public function clearDailyGoal(): void
    {
        $this->profile->daily_points_goal = null;
        $this->profile->save();
    }

    /**
     * One rung of the ladder: what a typical chore in this household pays,
     * rounded to something a kid can hold in their head. Floored so a family
     * of five-point chores doesn't get a ladder that tops out at 30.
     */
    private function step(): int
    {
        $average = (int) round(
            Chore::where('household_id', $this->profile->household_id)->avg('points') ?? 0
        );

        return max(25, (int) round($average / 5) * 5);
    }

    /**
     * Days to cover $remaining at $perDay, or null when the answer is so far
     * out that a date would be a fiction rather than a plan.
     */
    private function daysAt(int $remaining, int $perDay): ?int
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
     * A row of the "what if" table: how long a given daily rate takes, and the
     * date it lands on.
     *
     * @return array{perDay: int, days: ?int, date: ?Carbon, chores: int}
     */
    private function forecast(int $remaining, int $perDay, Carbon $today, int $averageChore): array
    {
        $days = $this->daysAt($remaining, $perDay);

        return [
            'perDay' => $perDay,
            'days' => $days,
            'date' => $days ? $today->copy()->addDays($days) : null,
            // The only unit of effort that means anything before you can read a
            // calendar: how many chores a day this actually is.
            'chores' => (int) max(1, round($perDay / max(1, $averageChore))),
        ];
    }

    /**
     * Drops the rungs past the point where earning more stops moving the date.
     *
     * A household of 400-point chores against a 650-point goal otherwise gets
     * six rows all reading "1 day", which is a table that has stopped saying
     * anything. Three rows are kept regardless so there's always a choice.
     *
     * @param  Collection<int, array{days: ?int}>  $plans
     * @return Collection<int, array{days: ?int}>
     */
    private function informativeRows(Collection $plans): Collection
    {
        $stopAt = $plans->search(fn (array $plan) => $plan['days'] !== null && $plan['days'] <= 1);

        return $stopAt === false
            ? $plans
            : $plans->take(max(self::MIN_ROWS, $stopAt + 1));
    }


    public function with(): array
    {
        $chores = app(ChoreService::class);
        $household = $this->profile->household;
        $today = HouseholdClock::for($household)->today();

        $step = $this->step();
        $ladder = collect(self::LADDER)->map(fn (int $multiple) => $multiple * $step);

        $saving = $this->profile->savingFor;
        $remaining = $saving ? max(0, $saving->cost - $this->profile->points) : 0;

        $dailyGoal = $this->profile->daily_points_goal;
        $earnedToday = $chores->pointsEarnedToday($this->profile);
        $pace = $chores->dailyPace($this->profile);

        $plans = $ladder->map(fn (int $perDay) => $this->forecast($remaining, $perDay, $today, $step));

        // The plan a kid is actually on, spelled out at the top of the page
        // rather than left to be read off a row of the table below.
        $chosenPlan = $dailyGoal ? $this->forecast($remaining, $dailyGoal, $today, $step) : null;

        return [
            'saving' => $saving,
            'savingRemaining' => $remaining,
            'savingPercent' => $saving && $saving->cost > 0
                ? min(100, (int) round($this->profile->points / $saving->cost * 100))
                : 0,
            'dailyGoal' => $dailyGoal,
            'suggestedGoal' => $step,
            'earnedToday' => $earnedToday,
            'todayPercent' => $dailyGoal > 0 ? min(100, (int) round($earnedToday / $dailyGoal * 100)) : 0,
            'pace' => $pace,
            'chosenPlan' => $chosenPlan,
            /*
             * The whole page in one number. Only shown once all of its parts
             * exist — something to save for, a daily target, and a date near
             * enough to be worth naming — because a banner reading "— days
             * until —" is worse than no banner at all.
             */
            'countdown' => $saving && $remaining > 0 && $chosenPlan && $chosenPlan['days'] !== null
                ? $chosenPlan
                : null,
            'pacePlan' => $pace >= 1
                ? $this->forecast($remaining, (int) floor($pace), $today, $step)
                : null,
            // Trimmed only once there's a goal to measure against: with nothing
            // picked yet the rows carry no date, so the full ladder is just the
            // set of targets to choose from.
            'plans' => $saving
                ? $this->informativeRows($plans)
                : $plans,
            'household' => $household,
        ];
    }
}; ?>

<x-kid.shell :profile="$profile" active="goal">
    <div class="flex flex-col gap-[14px]">
        <div class="rounded-[24px] border border-fq-line bg-fq-panel p-6">
            <p class="font-mono-fq text-[10px] tracking-[0.24em] text-fq-gold uppercase">Make a plan</p>
            <h2 class="mt-1 font-baloo text-2xl font-extrabold">Goal Planner</h2>
            <p class="mt-2 max-w-[560px] text-sm text-fq-text-2">
                Pick how many points you want to earn each day and see exactly when you'll
                get what you're saving for. Bigger days, sooner payday.
            </p>
        </div>

        @if ($countdown)
            <div
                class="flex flex-wrap items-center gap-[18px] rounded-[22px] border border-fq-lime px-[22px] py-[18px]"
                style="background: linear-gradient(120deg, #2a2405, #221046 55%, var(--fq-panel))"
            >
                <span class="font-baloo text-[50px] leading-[0.9] font-extrabold text-fq-lime">
                    {{ $countdown['days'] }}
                </span>
                <div class="min-w-[220px] flex-1">
                    <p class="font-baloo text-[22px] leading-[1.2] font-extrabold">
                        {{ Str::plural('day', $countdown['days']) }} until {{ $saving->name }} is yours
                    </p>
                    <p class="mt-1 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-3 uppercase">
                        If you earn {{ number_format($dailyGoal) }} points a day ·
                        {{ $countdown['date']->toFormattedDateString() }}
                    </p>
                </div>
            </div>
        @endif

        {{-- One column now. The monsters and the family plan that used to sit
             beside this moved to the Arena, where the rest of what the house
             shares already lives — this page is the kid's own goal and nothing
             else. --}}
        <div class="flex flex-wrap items-start gap-[14px]">
            <div class="flex min-w-0 flex-[1_1_100%] flex-col gap-[14px]">
            @if ($saving)
                <div
                    class="overflow-hidden rounded-[24px] border border-fq-line-3 p-5"
                    style="background: var(--fq-wash-goal)"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-mono-fq text-[10px] tracking-[0.22em] text-fq-text-4 uppercase">Saving up for</p>
                            <h3 class="mt-[6px] font-baloo text-[27px] leading-[1.1] font-extrabold">{{ $saving->name }}</h3>
                        </div>
                        <a
                            href="{{ route('kid.loot') }}"
                            wire:navigate
                            class="rounded-[12px] border border-dashed border-fq-line-4 bg-fq-sunk px-[14px] py-[9px] text-[13px] text-fq-text-2-b transition hover:border-solid hover:text-fq-text"
                        >Change goal</a>
                    </div>

                    <div class="mt-4 h-[18px] overflow-hidden rounded-full border border-fq-line bg-fq-track">
                        <div
                            class="h-full rounded-full transition-[width] duration-500"
                            style="width:{{ $savingPercent }}%; background: linear-gradient(90deg, var(--fq-cyan), var(--fq-lime), var(--fq-gold))"
                        ></div>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-4">
                        <span class="font-mono-fq text-[11px] text-fq-text-2">
                            {{ min($profile->points, $saving->cost) }} / {{ $saving->cost }} PTS
                        </span>
                        <span class="font-mono-fq text-[11px] text-fq-text-4">{{ $savingPercent }}%</span>
                        <span class="font-mono-fq text-[11px] text-fq-gold">
                            {{ number_format($savingRemaining) }} TO GO
                        </span>
                    </div>
                </div>
            @else
                <div class="rounded-[24px] border border-dashed border-fq-line-4 bg-fq-panel p-6 text-center">
                    <h3 class="font-baloo text-xl font-bold">Nothing picked yet</h3>
                    <p class="mx-auto mt-2 max-w-[320px] text-sm text-fq-text-2">
                        Choose something in the Loot Shop to save for and this page will work
                        out how long it takes. The family plan below works either way.
                    </p>
                    <a
                        href="{{ route('kid.loot') }}"
                        wire:navigate
                        class="mt-4 inline-block rounded-[14px] px-5 py-[11px] font-baloo text-base font-extrabold text-fq-bg transition hover:brightness-110"
                        style="background: var(--fq-gold)"
                    >Go pick one</a>
                </div>
            @endif

            <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="font-baloo text-xl font-bold">Your daily target</h3>
                    @if ($dailyGoal)
                        <button
                            type="button"
                            wire:click="clearDailyGoal"
                            class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase transition hover:text-fq-text"
                        >Clear</button>
                    @endif
                </div>

                @if ($dailyGoal)
                    <div class="mt-3 flex flex-wrap items-center gap-4">
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                wire:click="adjustDailyGoal(-{{ $suggestedGoal }})"
                                class="h-10 w-10 rounded-[12px] border border-fq-line-3 bg-fq-sunk text-lg"
                                aria-label="Lower the target"
                            >&minus;</button>
                            <span class="w-[92px] text-center font-baloo text-[34px] leading-none font-extrabold text-fq-lime">
                                {{ number_format($dailyGoal) }}
                            </span>
                            <button
                                type="button"
                                wire:click="adjustDailyGoal({{ $suggestedGoal }})"
                                class="h-10 w-10 rounded-[12px] border border-fq-line-3 bg-fq-sunk text-lg"
                                aria-label="Raise the target"
                            >+</button>
                        </div>
                        <span class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Points a day</span>
                    </div>

                    <div class="mt-4 h-4 overflow-hidden rounded-full border border-fq-line bg-fq-track">
                        <div
                            class="h-full rounded-full transition-[width] duration-500"
                            style="width:{{ $todayPercent }}%; background: linear-gradient(90deg, var(--fq-cyan), var(--fq-lime))"
                        ></div>
                    </div>
                    <p class="mt-2 font-mono-fq text-[11px] text-fq-text-4">
                        TODAY {{ number_format($earnedToday) }} / {{ number_format($dailyGoal) }} PTS
                        @if ($earnedToday >= $dailyGoal)
                            · <span class="text-fq-lime">DONE FOR TODAY</span>
                        @endif
                    </p>

                    @if ($chosenPlan && $savingRemaining > 0)
                        <p class="mt-3 text-sm text-fq-text-2">
                            @if ($chosenPlan['days'] === null)
                                That's a slow road — try a bigger daily number below and watch the date jump.
                            @else
                                Keep that up and <span class="font-semibold text-fq-text">{{ $saving->name }}</span>
                                is yours in
                                <span class="font-baloo text-base font-extrabold text-fq-gold">{{ $chosenPlan['days'] }}</span>
                                {{ Str::plural('day', $chosenPlan['days']) }} — by
                                <span class="font-semibold text-fq-text">{{ $chosenPlan['date']->toFormattedDateString() }}</span>.
                            @endif
                        </p>
                    @elseif ($saving && $savingRemaining <= 0)
                        <p class="mt-3 text-sm font-semibold text-fq-lime">
                            You've already got enough for {{ $saving->name }} — go cash it out!
                        </p>
                    @endif

                    {{-- The plan against the truth. A target nobody is hitting is
                         worth knowing about while there's still time to lower it. --}}
                    <p class="mt-2 text-[13px] text-fq-text-5">
                        Last {{ \App\Services\ChoreService::PACE_DAYS }} days you've averaged
                        <span class="font-semibold text-fq-text-2">{{ number_format($pace, 0) }} pts</span> a day.
                        @if ($pacePlan && $pacePlan['days'] !== null && $savingRemaining > 0)
                            At that rate: {{ $pacePlan['days'] }} {{ Str::plural('day', $pacePlan['days']) }}.
                        @endif
                    </p>
                @else
                    <p class="mt-2 text-sm text-fq-text-2">
                        You haven't set one yet. Pick a number below — you can change it any time.
                    </p>
                @endif

                <div class="mt-4 border-t border-fq-divider pt-[14px]">
                    <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                        What if I earned&hellip;
                    </p>

                    @if (! $saving)
                        <p class="mt-2 text-[13px] text-fq-text-5">
                            Pick something in the Loot Shop and these turn into finish dates.
                        </p>
                    @endif

                    <div class="mt-3 flex flex-col gap-2">
                        @foreach ($plans as $plan)
                            @php $chosen = $plan['perDay'] === $dailyGoal; @endphp

                            <div
                                wire:key="plan-{{ $plan['perDay'] }}"
                                class="flex flex-wrap items-center gap-4 rounded-[16px] border px-4 py-3 {{ $chosen ? 'border-fq-lime' : 'border-fq-line-2' }}"
                                style="background: {{ $chosen ? 'var(--fq-wash-goal)' : 'var(--fq-sunk)' }}"
                            >
                                {{-- shrink-0 and nowrap together: a flex item with a
                                     min-width set can otherwise be squeezed narrower
                                     than its own label, which runs the rate straight
                                     into the date beside it. --}}
                                <div class="shrink-0">
                                    <p class="font-baloo text-[19px] leading-none font-extrabold" style="color: {{ $chosen ? 'var(--fq-lime)' : 'var(--fq-text)' }}">
                                        {{ number_format($plan['perDay']) }}
                                    </p>
                                    <p class="mt-[2px] font-mono-fq text-[10px] whitespace-nowrap text-fq-text-4">
                                        PTS/DAY &middot; ~{{ $plan['chores'] }} {{ Str::plural('chore', $plan['chores']) }}
                                    </p>
                                </div>

                                <div class="min-w-[140px] flex-1">
                                    @if ($saving && $savingRemaining <= 0)
                                        <p class="text-[13px] text-fq-lime">Already there!</p>
                                    @elseif ($saving && $plan['days'] === null)
                                        <p class="text-[13px] text-fq-text-5">More than a year away.</p>
                                    @elseif ($saving)
                                        <p class="text-sm font-semibold">
                                            {{ $plan['days'] }} {{ Str::plural('day', $plan['days']) }}
                                        </p>
                                        <p class="font-mono-fq text-[10px] whitespace-nowrap text-fq-text-4 uppercase">
                                            BY {{ $plan['date']->toFormattedDateString() }}
                                        </p>
                                    @endif
                                </div>

                                <button
                                    type="button"
                                    wire:click="setDailyGoal({{ $plan['perDay'] }})"
                                    @disabled($chosen)
                                    class="ml-auto rounded-[12px] px-4 py-[9px] text-[13px] font-semibold {{ $chosen ? 'cursor-default text-fq-bg' : 'border border-fq-line-3 bg-fq-panel text-fq-text-2-b transition hover:border-fq-lime hover:text-fq-text' }}"
                                    style="{{ $chosen ? 'background: var(--fq-lime)' : '' }}"
                                >{{ $chosen ? 'My target' : 'Use this' }}</button>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            </div>
        </div>
    </div>
</x-kid.shell>
