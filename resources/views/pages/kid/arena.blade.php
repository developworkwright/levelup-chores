<?php

use App\Enums\ProfileRole;
use App\Exceptions\InsufficientTicketsException;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ArenaService;
use App\Services\HouseholdClock;
use App\Services\MonsterService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * The Arena — the kid landing page, and the one kid page that is not about the
 * kid looking at it.
 *
 * Everything on it is household-wide: whose run is on the line tonight, where
 * everyone sits on the milestone ladder, and what the house is fighting. The
 * kid's own board is a tap away from here rather than the other way round,
 * because "Nova's nine nights die at 4am" is news and "your board" is not.
 *
 * The one rule the whole page hangs off: **nothing expires at bedtime.** The
 * household day rolls at `day_boundary_hour` (4am by default) and a quest open
 * at 9pm is not late. `evening_watch_hour` is a *display* threshold — past it
 * an open quest reads as at risk and the urgency ramps — and no copy anywhere
 * on this page may count down to a lights-out that doesn't exist.
 */
new class extends Component
{
    public Profile $profile;

    /** @var array<string, mixed>|false|null */
    private array|false|null $arenaState = null;


    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isKid(), 403);
    }

    /**
     * The monster, with whatever this kid missed queued up in front of it.
     *
     * Memoised, because this both reads the "last looked" marker and moves it:
     * a second call inside one request would find the gap it had already
     * closed. `false` rather than null for "asked, nothing standing" — null is
     * the not-yet-asked state, and an empty arena must not re-run markSeen().
     *
     * @return array<string, mixed>|null
     */
    private function arena(Household $household): ?array
    {
        if ($this->arenaState !== null) {
            return $this->arenaState ?: null;
        }

        $monsters = app(MonsterService::class);
        $monster = $monsters->rotateWeakness($household);

        $state = $monster === null ? false : [
            ...$monsters->stateFor($monster),
            'steps' => $monsters->replayFor($monster, $this->profile),
            'startDelay' => 0,
        ];

        $monsters->markSeen($household, $this->profile);

        $this->arenaState = $state;

        return $state ?: null;
    }

    /** Why the last nudge or rescue didn't land. A silent no-op reads as broken. */
    public ?string $arenaMessage = null;

    public function nudge(int $profileId): void
    {
        $this->arenaMessage = null;
        $target = $this->sibling($profileId);

        if (! $target || ! app(ArenaService::class)->nudge($this->profile, $target)) {
            $this->arenaMessage = 'That nudge didn\'t land — one each per night.';

            return;
        }

        $this->dispatch('celebrate', message: "Nudged {$target->name}!", style: 'star', motion: 'burst', origin: 'tap');
    }

    public function rescue(int $profileId): void
    {
        $this->arenaMessage = null;
        $target = $this->sibling($profileId);

        if (! $target) {
            return;
        }

        try {
            if (! app(ArenaService::class)->rescue($this->profile, $target)) {
                $this->arenaMessage = app(ArenaService::class)->rescueBlockedReason($this->profile, $target)
                    ?? 'There is nothing to rescue there.';

                return;
            }
        } catch (InsufficientTicketsException $e) {
            $this->arenaMessage = $e->getMessage();

            return;
        }

        // Deliberately says what a rescue is and isn't. The night is saved; it
        // was not earned, and nothing here may suggest otherwise.
        $this->dispatch(
            'celebrate',
            message: "{$target->name}'s run is safe tonight — it still pays them nothing.",
            style: 'heart',
            motion: 'burst',
            origin: 'tap',
        );
    }

    /**
     * A kid in this household who isn't the viewer.
     *
     * The role filter is load-bearing, not decoration. nudge() and rescue()
     * are public Livewire methods and take whatever id they are handed, and a
     * parent's id reaching questFor() would create a daily quest for a parent
     * profile — or throw, if no chore is age-appropriate for them. Matches
     * ownedKid() on the parent console, which scopes the same way.
     */
    private function sibling(int $profileId): ?Profile
    {
        return Profile::where('household_id', $this->profile->household_id)
            ->where('role', ProfileRole::Kid)
            ->where('id', $profileId)
            ->whereKeyNot($this->profile->id)
            ->first();
    }

    public function with(): array
    {
        $household = $this->profile->household;
        $arena = app(ArenaService::class);

        $race = $arena->raceFor($household);
        $lanes = $race['lanes'];

        return [
            'household' => $household,
            'lanes' => $lanes,
            'flags' => $race['flags'],
            // One card per at-risk kid, and only those — the panel is about
            // tonight, not about everyone. The peer-action state is resolved
            // here rather than in the card, so the template never has to ask
            // the service anything.
            'atRisk' => $lanes
                ->where('state', ArenaService::STATE_AT_RISK)
                ->map(fn (array $entry) => [
                    ...$entry,
                    'nudged' => $arena->hasNudged($this->profile, $entry['profile']),
                    'nudgeStamp' => $arena->lastNudgeFor($entry['profile']),
                    'rescueBlocked' => $arena->rescueBlockedReason($this->profile, $entry['profile']),
                ])
                ->values(),
            'monsterState' => $this->arena($household),
            'choresToday' => $arena->choresToday($household),
            'superlatives' => $arena->superlatives($household),
            'crown' => $arena->crown($household),
            'houseWeek' => $arena->houseWeek($household),
            'prizeStanding' => $arena->prizeStanding($household),
            // Whole days between today and the Sunday rollover the week turns
            // on, so the prize card can say how long is left to chase it.
            'prizeDaysLeft' => (int) HouseholdClock::for($household)->today()
                ->diffInDays(HouseholdClock::for($household)->today()->copy()->startOfWeek(Carbon::SUNDAY)->addWeek()),
            'ticker' => $arena->ticker($household),
            'watchLabel' => $household->evening_watch_hour > 12
                ? ($household->evening_watch_hour - 12).':00pm'
                : $household->evening_watch_hour.':00am',
            'rollLabel' => $household->day_boundary_hour > 12
                ? ($household->day_boundary_hour - 12).':00pm'
                : $household->day_boundary_hour.':00am',
        ];
    }
}; ?>

<x-kid.shell :profile="$profile" active="arena">
    {{-- 1. Tonight. The top of the page and the reason it exists. --}}
    @php
        $count = $atRisk->count();
        // Anything that isn't cleared. A broken run still has tonight's quest
        // to do, so it counts here as much as an ordinary open one.
        $stillOpen = $lanes->where('state', '!==', ArenaService::STATE_SAFE)->count();
        // Only the scale steps as more kids are at risk — never the content.
        // Three at risk must not degrade into chips: a kid whose run is on the
        // line gets a real candle and a real headline whatever else is
        // happening in the house.
        [$candleW, $candleH, $flameBox, $waxW, $waxH, $streakSize, $headSize] = match (true) {
            $count <= 1 => [128, 196, 70, 80, 92, 34, 30],
            $count === 2 => [104, 172, 62, 70, 78, 28, 23],
            default => [88, 156, 56, 62, 68, 24, 19],
        };
    @endphp

    <div
        class="rounded-[26px] border p-[20px_22px]"
        style="border-color: {{ $count ? 'var(--fq-streak-fill)' : 'var(--fq-line-cool)' }};
               background: {{ $count ? 'linear-gradient(150deg,#3b0c1d,var(--fq-sunk) 70%)' : 'linear-gradient(160deg,var(--fq-sunk),var(--fq-bg) 70%)' }}"
    >
        <div class="flex flex-wrap items-baseline justify-between gap-4">
            <span class="font-mono-fq text-[11px] tracking-[0.26em] uppercase" style="color: {{ $count ? 'var(--fq-streak)' : 'var(--fq-lime)' }}">
                {{ $count ? 'At risk right now' : 'Tonight' }}
            </span>
            {{-- The rule, stated where it can't be missed. Every countdown on
                 this page is measured *from* the watch hour, never *to* a
                 bedtime, because nothing happens at bedtime. --}}
            <span class="font-mono-fq text-[11px] tracking-[0.1em] text-fq-text-4">
                NOTHING EXPIRES AT BEDTIME &middot; THE DAY ROLLS AT {{ strtoupper($rollLabel) }}@if ($count) &middot; WATCHING SINCE {{ strtoupper($watchLabel) }}@endif
            </span>
        </div>

        @if ($count === 0)
            <div class="mt-4 flex flex-wrap items-center gap-4 pb-[2px]">
                {{-- The flame is the safe state's mark and belongs only to the
                     day everyone actually finished. An open board gets the same
                     neutral ring the lanes use. --}}
                <span class="text-[34px]" @if ($stillOpen) style="color: var(--fq-text-3)" @endif>{{ $stillOpen === 0 ? '🔥' : '○' }}</span>
                @if ($stillOpen === 0)
                    <span class="font-baloo text-[28px] leading-[1.1] font-extrabold">Every quest cleared.</span>
                    <span class="font-mono-fq text-[11px] text-fq-text-4">
                        Nobody's run is on the line — the next one starts at {{ $rollLabel }}.
                    </span>
                @else
                    {{-- "Nobody is at risk" is not "everybody is done". Before
                         the watch hour nobody can *be* at risk, so a morning on
                         which nothing has happened was reading as a clean
                         sweep — the one claim on this panel that has to be
                         earned. This says what is actually true. --}}
                    <span class="font-baloo text-[28px] leading-[1.1] font-extrabold">
                        {{ $stillOpen }} {{ Str::plural('quest', $stillOpen) }} still open.
                    </span>
                    <span class="font-mono-fq text-[11px] text-fq-text-4">
                        Nothing's on the line until {{ $watchLabel }} — plenty of day left.
                    </span>
                @endif
            </div>
        @else
            {{-- auto-fit rather than count-specific breakpoints: one card takes
                 the width, two sit side by side, three fit at 1080, and they go
                 one per row on a phone without a media query anywhere. --}}
            <div class="mt-4 grid gap-[14px]" style="grid-template-columns: repeat(auto-fit, minmax(310px, 1fr))">
                @foreach ($atRisk as $kid)
                    @php
                        $risk = $kid['risk'];
                        // Flame geometry across the window: it shrinks and dims
                        // as the ramp climbs, then holds. The size is what
                        // carries the state, which is why reduced motion can
                        // drop the animation and lose nothing.
                        $flameH = round(62 - 32 * $risk);
                        $flameW = round(32 - 12 * $risk);
                        $glowAlpha = round(0.34 - 0.16 * $risk, 3);
                        $blur = round(26 - 12 * $risk);
                        $flickSpeed = round(1.5 - 0.6 * $risk, 2);
                    @endphp

                    <div
                        wire:key="at-risk-{{ $kid['profile']->id }}"
                        class="flex flex-wrap items-center gap-4 rounded-[20px] border p-4"
                        style="border-color: var(--fq-streak-fill); background: linear-gradient(150deg,#3b0c1d,var(--fq-sunk) 70%)"
                    >
                        <div class="relative flex flex-none flex-col items-center justify-end" style="width: {{ $candleW }}px; height: {{ $candleH }}px">
                            <div
                                class="absolute bottom-[10px] h-[150px] w-[150px] rounded-full"
                                style="filter: blur(20px); background: rgba(224,54,91,{{ $glowAlpha }})"
                            ></div>

                            <div class="relative flex flex-col items-center">
                                <div class="relative grid w-[50px] place-items-end justify-center" style="height: {{ $flameBox }}px">
                                    <div
                                        class="fq-candle-flame"
                                        style="width: {{ $flameW }}px; height: {{ $flameH }}px;
                                               --fq-flick-speed: {{ $flickSpeed }}s;
                                               background: linear-gradient(180deg,#fff6b0,#ffc93d 46%,#e0365b);
                                               box-shadow: 0 0 {{ $blur }}px rgba(255,201,61,.55)"
                                    ></div>
                                </div>
                                <div class="h-[9px] w-[5px]" style="background: var(--fq-line-2)"></div>
                                <div
                                    class="grid place-items-center rounded-[9px_9px_4px_4px] border"
                                    style="width: {{ $waxW }}px; height: {{ $waxH }}px; border-color: var(--fq-line-3); background: linear-gradient(180deg,var(--fq-line-2),var(--fq-panel-alt))"
                                >
                                    <span class="font-baloo leading-none font-extrabold" style="font-size: {{ $streakSize }}px; color: {{ $kid['profile']->color->cssVar() }}">
                                        {{ $kid['streak'] }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="flex min-w-[190px] flex-1 flex-col gap-[9px]" style="flex-basis: 200px">
                            {{-- A zero streak has nothing on the line — saying
                                 it has is the page claiming a loss that cannot
                                 happen, and the candle beside it already reads
                                 0. Those kids get the true version: there is a
                                 run to *start* tonight. --}}
                            <div class="font-baloo leading-[1.08] font-extrabold" style="font-size: {{ $headSize }}px; text-wrap: pretty">
                                @if ($kid['streak'] > 0)
                                    {{ $kid['profile']->name }}'s {{ $kid['streak'] }} {{ Str::plural('night', $kid['streak']) }} are on the line.
                                @else
                                    {{ $kid['profile']->name }} could start a run tonight.
                                @endif
                            </div>

                            <div class="flex items-center gap-[9px] rounded-[10px] border px-[11px] py-2" style="border-color: #6b2033; background: #26101d">
                                <span class="text-sm">⚑</span>
                                {{-- States what is true — the quest, and how far
                                     past the watch hour it is. Never a countdown
                                     to a deadline that doesn't exist. --}}
                                <span class="font-mono-fq text-[11px] leading-[1.5]" style="color: #f7c8d4; text-wrap: pretty">
                                    Tonight's quest — {{ $kid['quest'] }} — still open,
                                    {{ $kid['watchAt']->diffForHumans(null, true, true) }} past {{ $watchLabel }}
                                </span>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                @if ($kid['profile']->is($profile))
                                    {{-- Their own card: the peer actions make no
                                         sense pointed at yourself, so both are
                                         hidden rather than disabled. --}}
                                    <a
                                        href="{{ route('kid.quests') }}"
                                        wire:navigate
                                        class="inline-flex rounded-[11px] px-[18px] py-3 font-baloo text-[15px] font-extrabold transition hover:brightness-110"
                                        style="background: linear-gradient(150deg,#fff6b0,var(--fq-gold)); color: #231702"
                                    >Go claim it &rarr;</a>
                                @else
                                    <button
                                        type="button"
                                        wire:click="nudge({{ $kid['profile']->id }})"
                                        @disabled($kid['nudged'])
                                        class="rounded-[11px] px-[18px] py-3 font-baloo text-[15px] font-extrabold transition hover:brightness-110 disabled:opacity-45"
                                        style="background: linear-gradient(150deg,#fff6b0,var(--fq-gold)); color: #231702"
                                    >{{ $kid['nudged'] ? 'Nudged' : 'Nudge '.$kid['profile']->name }}</button>

                                    <button
                                        type="button"
                                        wire:click="rescue({{ $kid['profile']->id }})"
                                        @disabled($kid['rescueBlocked'] !== null)
                                        title="{{ $kid['rescueBlocked'] ?? 'Keeps their run alive through the rollover' }}"
                                        class="rounded-[11px] border px-[16px] py-3 font-mono-fq text-[11px] tracking-[0.1em] uppercase transition hover:brightness-125 disabled:opacity-45"
                                        style="border-color: var(--fq-line-4); color: var(--fq-cyan); background: var(--fq-panel-alt)"
                                    >Rescue &middot; {{ \App\Services\ArenaService::RESCUE_COST }} tickets</button>
                                @endif
                            </div>

                            {{-- The rule, stated where the button is. A rescue
                                 must never read as a night that was earned. --}}
                            <span class="font-mono-fq text-[10px] leading-[1.5]" style="color: #a98c9c; text-wrap: pretty">
                                @unless ($kid['profile']->is($profile))
                                    A rescue keeps the run alive — the night still pays nothing.
                                @endunless
                                @if ($kid['nudgeStamp'])
                                    Nudged by {{ $kid['nudgeStamp']->from->name }}
                                    {{ $kid['nudgeStamp']->created_at->timezone($household->timezone)->format('g:ia') }}.
                                @endif
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($arenaMessage)
            <p class="mt-3 font-mono-fq text-[11px]" style="color: var(--fq-streak)">{{ $arenaMessage }}</p>
        @endif
    </div>

    {{-- 2. The race. --}}
    <div
        class="mt-[18px] rounded-[26px] border p-[24px_26px_20px]"
        style="border-color: var(--fq-line-cool); background: linear-gradient(160deg,var(--fq-sunk),var(--fq-bg) 70%)"
    >
        <div class="flex flex-wrap items-end justify-between gap-[18px]">
            <div class="flex flex-col gap-[2px]">
                <span class="font-mono-fq text-[11px] tracking-[0.26em] uppercase" style="color: var(--fq-violet)">The race</span>
                <span class="font-baloo text-[30px] leading-[1.05] font-extrabold">Nights in a row</span>
            </div>
            <span class="font-mono-fq text-[11px] tracking-[0.14em] text-fq-text-4">
                EVERY CLEARED QUEST IS A NIGHT &middot; MISS ONE AND THE RUN RESETS
            </span>
        </div>

        {{-- The flag header is a flex row with spacers matching the lane's own
             columns rather than padding, so the labels land exactly on the
             in-lane ticks at any width. --}}
        <div class="mt-[18px] hidden items-end sm:flex" style="height: 22px">
            <div class="flex-none" style="width: 186px"></div>
            <div class="flex-none" style="width: 28px"></div>
            <div class="relative h-[22px] flex-1">
                @foreach ($flags as $flag)
                    <div class="absolute bottom-0 flex -translate-x-1/2 flex-col items-center gap-[2px]" style="left: {{ $flag['left'] }}%">
                        <span class="font-mono-fq text-[9px] tracking-[0.14em] text-fq-text-4">${{ $flag['reward'] }}</span>
                        <span class="font-mono-fq text-[11px] font-semibold" style="color: var(--fq-gold)">{{ $flag['nights'] }}</span>
                    </div>
                @endforeach
            </div>
            <div class="flex-none" style="width: 28px"></div>
            <div class="flex-none" style="width: 150px"></div>
        </div>

        <div class="mt-[11px] flex flex-col gap-[11px]">
            @foreach ($lanes as $lane)
                @php
                    $kid = $lane['profile'];
                    $accent = $kid->color->cssVar();

                    [$glyph, $stateLabel, $pillInk, $pillBg] = match (true) {
                        $lane['state'] === ArenaService::STATE_SAFE => ['🔥', 'Cleared', 'var(--fq-lime)', 'color-mix(in srgb, var(--fq-lime) 16%, transparent)'],
                        $lane['state'] === ArenaService::STATE_AT_RISK => ['🕯️', 'At risk', 'var(--fq-streak)', 'color-mix(in srgb, var(--fq-streak) 20%, transparent)'],
                        $lane['state'] === ArenaService::STATE_BROKEN => ['💀', 'Back to zero', 'var(--fq-text-4)', 'var(--fq-panel-alt)'],
                        default => ['○', 'Still open', 'var(--fq-text-3)', 'var(--fq-panel-alt)'],
                    };

                    $subLabel = match (true) {
                        $lane['state'] === ArenaService::STATE_SAFE => $lane['clearedAt']
                            ? 'Quest cleared '.$lane['clearedAt']->timezone($household->timezone)->format('g:ia')
                            : 'Quest cleared',
                        $lane['state'] === ArenaService::STATE_BROKEN => 'Run of '.$lane['brokenFrom'].' ended at '.$rollLabel,
                        default => $lane['quest'],
                    };
                @endphp

                <div
                    wire:key="lane-{{ $kid->id }}"
                    class="flex flex-col gap-3 rounded-[20px] border p-[12px_14px] sm:flex-row sm:items-center sm:gap-[14px]"
                    style="border-color: {{ $lane['state'] === ArenaService::STATE_AT_RISK ? 'var(--fq-streak-fill)' : 'var(--fq-line)' }};
                           background: {{ $lane['state'] === ArenaService::STATE_AT_RISK ? 'linear-gradient(120deg,#2a0a16,var(--fq-panel) 60%)' : 'var(--fq-panel)' }};
                           {{ $lane['state'] === ArenaService::STATE_BROKEN ? 'filter: saturate(.5)' : '' }}"
                >
                    {{-- Below ~560px the lane stops being a row: the name block
                         and state column alone are 336px, so it stacks into a
                         card with the track full width beneath. --}}
                    <div class="flex flex-none items-center justify-between gap-[11px] sm:justify-start" style="--fq-lane-name: 186px" x-data>
                        <div class="flex items-center gap-[11px] sm:w-[186px]">
                            <div
                                class="grid h-[46px] w-[46px] flex-none place-items-center rounded-[14px] font-baloo text-[19px] font-extrabold"
                                style="background: {{ $accent }}; color: var(--fq-bg)"
                            >{{ mb_substr($kid->name, 0, 1) }}</div>
                            <div class="flex flex-col gap-[1px]">
                                <span class="font-baloo text-[19px] leading-none font-extrabold">{{ $kid->name }}</span>
                                <span class="font-mono-fq text-[10px] tracking-[0.12em] text-fq-text-4">
                                    {{ $lane['streak'] }} {{ Str::plural('NIGHT', $lane['streak']) }}
                                </span>
                            </div>
                        </div>

                        <span
                            class="rounded-full px-[11px] py-1 font-mono-fq text-[10px] font-semibold tracking-[0.14em] whitespace-nowrap uppercase sm:hidden"
                            style="background: {{ $pillBg }}; color: {{ $pillInk }}"
                        >{{ $glyph }} {{ $stateLabel }}</span>
                    </div>

                    <div class="hidden flex-none sm:block" style="width: 28px"></div>

                    <div class="relative h-[46px] flex-1">
                        <div class="absolute top-[21px] right-0 left-0 h-[6px] rounded-full border" style="background: var(--fq-panel-alt); border-color: var(--fq-line)"></div>
                        <div
                            class="absolute top-[21px] left-0 h-[6px] rounded-full"
                            style="width: {{ $lane['position'] }}%; background: linear-gradient(90deg, {{ $accent }}, var(--fq-gold))"
                        ></div>

                        @foreach ($flags as $flag)
                            <div class="absolute top-[12px] h-[24px] w-[2px] -translate-x-1/2" style="left: {{ $flag['left'] }}%; background: var(--fq-line-2)"></div>
                        @endforeach

                        <div class="absolute top-0 grid h-[46px] w-[46px] -translate-x-1/2 place-items-center" style="left: {{ $lane['position'] }}%">
                            <div
                                class="absolute h-[44px] w-[44px] rounded-full {{ $lane['state'] === ArenaService::STATE_BROKEN ? '' : 'fq-lane-halo' }}"
                                style="background: radial-gradient(circle, {{ $accent }} 0%, transparent 70%); opacity: .5"
                            ></div>
                            <div
                                class="relative grid h-[34px] w-[34px] place-items-center rounded-[12px] border-2 text-[17px] {{ $lane['state'] === ArenaService::STATE_AT_RISK ? 'fq-lane-bob' : '' }}"
                                style="border-color: var(--fq-bg); background: var(--fq-panel-alt)"
                            >{{ $glyph }}</div>
                        </div>
                    </div>

                    <div class="hidden flex-none sm:block" style="width: 28px"></div>

                    <div class="hidden flex-none flex-col items-end gap-[3px] sm:flex" style="width: 150px">
                        <span
                            class="rounded-full px-[11px] py-1 font-mono-fq text-[10px] font-semibold tracking-[0.14em] whitespace-nowrap uppercase"
                            style="background: {{ $pillBg }}; color: {{ $pillInk }}"
                        >{{ $stateLabel }}</span>
                        <span class="text-right font-mono-fq text-[10px] text-fq-text-4">{{ $subLabel }}</span>
                    </div>

                    <span class="font-mono-fq text-[10px] text-fq-text-4 sm:hidden">{{ $subLabel }}</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- 3. The monster, moved off the Goals page. --}}
    <div class="mt-[18px] flex flex-col gap-[14px]">
        <div class="flex flex-wrap items-end justify-between gap-[18px]">
            <div class="flex flex-col gap-[2px]">
                <span class="font-mono-fq text-[11px] tracking-[0.26em] uppercase" style="color: var(--fq-coral)">The fight</span>
                <span class="font-baloo text-[30px] leading-[1.05] font-extrabold">What the house is fighting</span>
            </div>
            <span class="font-mono-fq text-[11px] tracking-[0.14em] text-fq-text-4">
                EVERY APPROVED CHORE IS DAMAGE
            </span>
        </div>

        <x-monster-arena :state="$monsterState" />
    </div>

    {{-- 4. Today: what everyone got done, and who is wearing the crown. --}}
    <div class="mt-[18px] flex flex-wrap items-stretch gap-[18px]">
        <div
            class="min-w-0 flex-[1_1_600px] rounded-[26px] border p-[22px_24px]"
            style="border-color: var(--fq-line-cool); background: var(--fq-panel)"
        >
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h3 class="font-baloo text-[24px] font-extrabold">Chores today</h3>
                {{-- Not "since 4am". The board is a thing that grows while
                     they watch it, and saying so is the invitation. --}}
                <span class="font-mono-fq text-[10px] tracking-[0.16em] text-fq-text-5">UPDATES AS THEY LAND</span>
            </div>

            @php
                $mostToday = max(1, (int) $choresToday->max('chores'));
                $doneToday = (int) $choresToday->sum('chores');
                $shownLines = collect([['first', 'First done'], ['biggest', 'Biggest job'], ['last', 'Last standing']])
                    ->filter(fn (array $line) => $superlatives[$line[0]] !== null);
            @endphp

            @if ($doneToday === 0)
                {{-- Three empty tracks read as broken rather than as "nobody
                     has started". A day with nothing in it says so. --}}
                <p class="mt-3 text-sm text-fq-text-2">
                    Nothing done yet today — first one on the board sets the pace.
                </p>
            @else
                <div class="mt-4 flex flex-col gap-3">
                    @foreach ($choresToday as $row)
                        <div wire:key="today-{{ $row['profile']->id }}" class="flex items-center gap-3">
                            <span class="w-[74px] shrink-0 truncate font-baloo text-[16px] font-bold">{{ $row['profile']->name }}</span>

                            {{-- 26px and squared off, not a hairline pill: the
                                 bar is the thing being read across the row, and
                                 a gradient gives it somewhere to travel. --}}
                            <div class="h-[26px] flex-1 overflow-hidden rounded-[8px]" style="background: var(--fq-sunk)">
                                <div
                                    class="h-full rounded-[8px] transition-[width] duration-700"
                                    style="width: {{ round($row['chores'] / $mostToday * 100) }}%;
                                           background: {{ $row['leader']
                                               ? 'linear-gradient(90deg,#e0b312,var(--fq-lime))'
                                               : 'linear-gradient(90deg,var(--fq-line-3),'.$row['profile']->color->cssVar().')' }}"
                                ></div>
                            </div>

                            <span class="w-[112px] shrink-0 text-right font-mono-fq text-[11px] text-fq-text-4">
                                <span
                                    class="font-baloo text-[19px] font-extrabold"
                                    style="color: {{ $row['leader'] ? 'var(--fq-lime)' : 'var(--fq-text)' }}"
                                >{{ $row['chores'] }}</span>
                                &middot; {{ number_format($row['points']) }} PTS
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- The divider is part of the block, not a separator that hangs
                 around after it: with nothing signed off there are no lines to
                 rule off, and an empty rule under an empty panel is the gap
                 that made this card look broken. --}}
            @if ($shownLines->isNotEmpty())
                <div class="mt-4 flex flex-col gap-2 border-t pt-[14px]" style="border-color: #251b38">
                    @foreach ($shownLines as [$key, $label])
                        <div class="flex flex-wrap items-baseline gap-[10px] font-mono-fq text-[11px]">
                            <span class="w-[112px] shrink-0 tracking-[0.14em] text-fq-text-5 uppercase">{{ $label }}</span>
                            <span class="font-semibold text-fq-text">{{ $superlatives[$key]['profile']->name }}</span>
                            <span class="text-fq-text-4">{{ $superlatives[$key]['note'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Today's crown. The title rotates daily so the same kid can't own
             one thing forever, and tomorrow's is named so everybody knows what
             to go after next. --}}
        <div
            class="flex min-w-[280px] flex-[1_1_300px] flex-col items-center gap-[14px] rounded-[26px] border p-[22px_24px] text-center"
            style="border-color: var(--fq-line-4); background: linear-gradient(170deg,var(--fq-panel-alt),var(--fq-panel))"
        >
            <span class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: var(--fq-magenta)">Today's crown</span>

            @if ($crown['winner'])
                <span class="text-[44px] leading-none">♛</span>
                <span class="font-baloo text-[28px] leading-none font-extrabold">{{ $crown['winner']->name }}</span>
                <span
                    class="rounded-full border px-[13px] py-[5px] font-mono-fq text-[10px] tracking-[0.14em] uppercase"
                    style="border-color: var(--fq-line-4); color: var(--fq-cyan)"
                >{{ $crown['label'] }}</span>
                <span class="font-mono-fq text-[11px] leading-[1.7] text-fq-text-4" style="text-wrap: pretty">{{ $crown['note'] }}</span>
            @else
                {{-- Dimmed rather than absent: the title is still today's, and
                     an empty slot with a name on it is the thing to go and
                     take. --}}
                <span class="text-[44px] leading-none opacity-30">♛</span>
                <span class="font-baloo text-[22px] leading-tight font-extrabold text-fq-text-3">{{ $crown['label'] }}</span>
                <span class="font-mono-fq text-[11px] leading-[1.7] text-fq-text-4">Nobody's claimed it yet today.</span>
            @endif

            <span class="mt-auto w-full border-t pt-3 font-mono-fq text-[10px] tracking-[0.12em] text-fq-text-5 uppercase" style="border-color: var(--fq-line)">
                Tomorrow's crown &middot; {{ $crown['tomorrow'] }}
            </span>
        </div>
    </div>

    {{-- 5. The week: one shared target and whatever is up for hitting it. --}}
    @if ($houseWeek || $household->weekly_prize)
        <div class="mt-[18px] flex flex-wrap items-stretch gap-[18px]">
            @if ($houseWeek)
                <div
                    class="flex min-w-[460px] flex-[1_1_520px] flex-col gap-[14px] rounded-[26px] border p-[22px_24px]"
                    style="border-color: var(--fq-line-cool); background: var(--fq-panel)"
                >
                    <div class="flex flex-wrap items-baseline justify-between gap-3">
                        <h3 class="font-baloo text-[24px] font-extrabold">The house, this week</h3>
                        <span class="font-mono-fq text-[11px] text-fq-text-4">
                            {{ number_format($houseWeek['done']) }} of {{ number_format($houseWeek['target']) }} chores &middot; Sun&ndash;Sat
                        </span>
                    </div>

                    {{-- Segmented per kid, each carrying its own count. The
                         target is shared, and one undivided bar would hide
                         that one of them did most of it — the number inside
                         the segment is what stops the colours needing a
                         legend underneath. --}}
                    <div class="flex h-[34px] overflow-hidden rounded-[12px]" style="background: var(--fq-sunk)">
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

                    <div class="flex justify-between font-mono-fq text-[10px] tracking-[0.12em] text-fq-text-5">
                        <span>{{ number_format($houseWeek['done']) }} DONE</span>
                        <span style="color: var(--fq-gold)">
                            {{ number_format($houseWeek['target']) }} = {{ $household->weekly_prize ? 'THE PRIZE' : 'HOUSE BONUS' }}
                        </span>
                    </div>

                    {{-- Names the prize rather than referring to "the bonus".
                         The bar and the card beside it are one goal, and a bar
                         promising an unnamed reward next to a card naming an
                         unexplained one read as two competitions. --}}
                    <p class="font-mono-fq text-[11px] leading-[1.7] text-fq-text-4" style="text-wrap: pretty">
                        Everyone's chores count toward the same bar. Hit {{ number_format($houseWeek['target']) }}
                        by Saturday night and the whole house gets
                        @if ($household->weekly_prize)
                            <span class="text-fq-text">{{ $household->weekly_prize }}</span>
                        @else
                            the bonus
                        @endif
                        &mdash; nobody has to win it.
                    </p>
                </div>
            @endif

            @if ($household->weekly_prize)
                <div
                    class="flex min-w-[320px] flex-[1_1_380px] flex-col gap-3 rounded-[26px] border p-[22px_24px]"
                    style="border-color: var(--fq-gold); background: linear-gradient(160deg,#2a1c05,var(--fq-panel) 70%)"
                >
                    <div class="flex items-center justify-between gap-[10px]">
                        <span class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: var(--fq-gold)">This week's prize</span>
                        {{-- Nothing records *which* grown-up set it, so this
                             says what is actually known rather than naming
                             one of them and being wrong half the time. --}}
                        <span class="font-mono-fq text-[9px] tracking-[0.14em] text-fq-text-4">SET BY A GROWN-UP</span>
                    </div>

                    <h3 class="font-baloo text-[26px] leading-[1.15] font-extrabold" style="text-wrap: pretty">{{ $household->weekly_prize }}</h3>

                    {{-- What it takes, stated on the prize itself. Without it
                         this card names a reward and leaves the goal on the
                         other card, which is how the two stopped reading as
                         one thing. --}}
                    @if ($houseWeek)
                        <p class="font-mono-fq text-[11px] leading-[1.7] text-fq-text-2">
                            The whole house gets it at
                            <span class="text-fq-text">{{ number_format($houseWeek['target']) }} chores</span>
                            &mdash; {{ number_format(max(0, $houseWeek['target'] - $houseWeek['done'])) }} to go.
                        </p>
                    @endif

                    @if ($household->weekly_prize_note)
                        <p class="font-mono-fq text-[11px] leading-[1.7] text-fq-text-2">{{ $household->weekly_prize_note }}</p>
                    @endif

                    <div class="flex flex-col gap-[7px] border-t pt-3" style="border-color: #3a2b0a">
                        {{-- A contribution list, not a leaderboard: same metric
                             as the bar next door, and nobody is knocked out of
                             it by coming third. --}}
                        <span class="font-mono-fq text-[9px] tracking-[0.14em] text-fq-text-5 uppercase">Who's put in what</span>

                        @foreach ($prizeStanding as $place)
                            <div wire:key="prize-{{ $place['profile']->id }}" class="flex items-center gap-[10px] font-mono-fq text-[11px]">
                                <span class="w-[24px] text-fq-text-5">#{{ $place['rank'] }}</span>
                                <span class="flex-1 font-baloo text-[15px] font-bold" style="color: {{ $place['profile']->color->cssVar() }}">
                                    {{ $place['profile']->name }}
                                </span>
                                <span class="text-fq-text-4">{{ $place['chores'] }} {{ Str::plural('chore', $place['chores']) }}</span>
                            </div>
                        @endforeach
                    </div>

                    {{-- The reset, with how long is left on it. "2 days left"
                         is the half that makes a standing worth looking at —
                         the deadline is what turns #2 into something to do
                         something about. --}}
                    <p class="font-mono-fq text-[10px] tracking-[0.12em] text-fq-text-5">
                        RESETS SUNDAY {{ strtoupper($rollLabel) }} &middot; {{ $prizeDaysLeft }}
                        {{ Str::plural('DAY', $prizeDaysLeft) }} LEFT
                    </p>
                </div>
            @endif
        </div>
    @endif

    {{-- 6. What everyone's up to. --}}
    @if ($ticker->isNotEmpty())
        <div
            class="mt-[18px] flex flex-col gap-3 rounded-[26px] border p-[20px_24px]"
            style="border-color: var(--fq-line); background: var(--fq-panel)"
        >
            <div class="flex items-baseline justify-between gap-3">
                <h3 class="font-baloo text-[22px] font-extrabold">What everyone's up to</h3>
                <span class="font-mono-fq text-[10px] tracking-[0.16em] text-fq-text-5">LAST {{ $ticker->count() }} IN THE HOUSE</span>
            </div>

            @foreach ($ticker as $event)
                {{-- Glyph, name, what they did, what it was worth, when. The
                     name is the kid's own colour, so a row is identifiable
                     before it is read — which is the difference between a feed
                     and a wall of sentences. --}}
                <div class="flex items-center gap-3 border-t py-[9px]" style="border-color: var(--fq-panel-alt)">
                    <span class="w-[22px] flex-none text-center text-[16px]">{{ $event['glyph'] }}</span>
                    <span
                        class="w-[70px] flex-none truncate font-baloo text-[15px] font-bold"
                        style="color: {{ $event['profile']->color->cssVar() }}"
                    >{{ $event['profile']->name }}</span>
                    <span class="min-w-0 flex-1 font-mono-fq text-[12px] text-fq-text-2" style="text-wrap: pretty">{{ $event['what'] }}</span>
                    <span
                        class="w-[96px] flex-none text-right font-mono-fq text-[11px]"
                        style="color: {{ $event['valueInk'] }}"
                    >{{ $event['value'] }}</span>
                    <span class="w-[60px] flex-none text-right font-mono-fq text-[10px] text-fq-text-5">
                        {{ $event['at']->timezone($household->timezone)->format('g:ia') }}
                    </span>
                </div>
            @endforeach
        </div>
    @endif
</x-kid.shell>
