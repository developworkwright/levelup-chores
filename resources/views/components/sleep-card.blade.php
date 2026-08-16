{{-- The own-bed card: one question, asked once each morning.

     Three answers and no wrong one. The card never scolds — a kid who came in
     at 3am gets a warm sentence and their totals untouched, because the only
     thing that moves on a bad night is the run.

     The prize is said out loud, deliberately. The first version of this card
     mentioned none of it: a kid saw a dot appear and the money landed silently
     a week later, which is a reward nobody knows they are working towards.

     `card` is what SleepService::cardFor() returns, or null when this kid isn't
     being asked at all. --}}
@props(['card', 'answerAction' => 'answerSleep'])

@php
    use App\Services\SleepService;

    $drawing = $card['drawing'];
    $stars = $drawing->stars();
    $lit = $card['starsLit'];
    $answered = $card['answered'];
    $prizes = $card['prizes'];
    $rate = max(1, $card['pointsPerDollar']);

    $money = fn (int $points) => '$'.number_format($points / $rate, 2);

    /*
     * A chest on this card always means "open me".
     *
     * The handoff also specced a dim in-between chest for a kid who had opened
     * one before, on the grounds that they would recognise the object. In use
     * it read as a chest that was there but wouldn't open — so the rail is now
     * drawn only when there is genuinely one waiting, and every other leg gets
     * the strip that promises the next one in words.
     */
    $next = $card['nextMilestone'];
    $previous = $card['previousMilestone'];
    $waiting = $card['pendingChest'];
    $everOpened = $card['runPaidThrough'] > 0;

    $showRail = $waiting !== null;
    $showStrip = $waiting === null && $next !== null;

    // The leg runs previous → next, not zero → next: on night eight you are
    // one night into the seven that lead to fourteen.
    $legLength = $next ? $next - $previous : 0;
    $legDone = $next ? max(0, $card['run'] - $previous) : 0;
    $legPercent = $legLength > 0 ? min(100, round($legDone / $legLength * 100, 1)) : 0;
    // Above eight the pips stop counting as a glance and become a texture.
    $showPips = $legLength > 0 && $legLength <= 8;
@endphp

<div
    wire:key="sleep-card"
    class="rounded-[24px] border p-5"
    style="background: linear-gradient(160deg, #0d1030, var(--fq-panel) 65%); border-color: var(--fq-line-cool)"
>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: var(--fq-cyan)">Last Night</p>
        @if ($card['bestRun'] > 0)
            <span class="font-mono-fq text-[10px] text-fq-text-4">
                BEST RUN {{ $card['bestRun'] }} {{ Str::plural('NIGHT', $card['bestRun']) }}
            </span>
        @endif
    </div>

    <div class="mt-3 flex flex-wrap items-start gap-5">
        <div class="shrink-0">
            {{-- The whole shape is drawn from night one — faint where it hasn't
                 been reached yet. Six unconnected dots said nothing about what
                 was being made, and a picture you can't see is a poor reason to
                 want tomorrow's star. --}}
            <svg viewBox="0 0 100 100" class="h-[132px] w-[132px]" xmlns="http://www.w3.org/2000/svg">
                @for ($i = 1; $i < count($stars); $i++)
                    @php $reached = $i < $lit; @endphp
                    <line
                        x1="{{ $stars[$i - 1][0] }}" y1="{{ $stars[$i - 1][1] }}"
                        x2="{{ $stars[$i][0] }}" y2="{{ $stars[$i][1] }}"
                        stroke="{{ $reached ? 'var(--fq-cyan)' : 'var(--fq-line-3)' }}"
                        stroke-width="{{ $reached ? 0.9 : 0.5 }}"
                        stroke-dasharray="{{ $reached ? 'none' : '2 3' }}"
                        opacity="{{ $reached ? 0.55 : 0.35 }}"
                    />
                @endfor

                @foreach ($stars as $index => [$x, $y])
                    @php
                        $isLit = $index < $lit;
                        // The one that landed most recently gets the flare, so
                        // the tap has a moment rather than simply being true on
                        // the next render.
                        $isNewest = $isLit && $index === $lit - 1;
                    @endphp

                    @if ($isNewest)
                        <circle cx="{{ $x }}" cy="{{ $y }}" r="7" fill="var(--fq-lime)" class="fq-star-halo" />
                    @endif

                    <circle
                        cx="{{ $x }}" cy="{{ $y }}"
                        r="{{ $isLit ? 3.2 : 1.6 }}"
                        fill="{{ $isLit ? 'var(--fq-lime)' : 'var(--fq-line-4)' }}"
                        opacity="{{ $isLit ? 0.95 : 0.55 }}"
                        @class(['fq-star-land' => $isNewest])
                    />
                @endforeach
            </svg>

            <p class="mt-1 text-center font-mono-fq text-[10px] tracking-[0.12em] text-fq-text-4 uppercase">
                {{ $drawing->label() }}
            </p>
            <p class="text-center font-mono-fq text-[10px] text-fq-text-5">
                {{ $lit }}/{{ App\Enums\Constellation::NIGHTS }} stars
            </p>
        </div>

        <div class="min-w-[220px] flex-1">
            @if ($answered)
                <h3 class="font-baloo text-xl font-bold">{{ $answered->outcome->response() }}</h3>

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <span
                        class="rounded-full border border-fq-line-2 px-[11px] py-[5px] font-mono-fq text-[10px] tracking-[0.1em] uppercase"
                        style="color: {{ $answered->outcome->cssVar() }}"
                    >{{ $answered->outcome->glyph() }} {{ $answered->outcome->shortLabel() }}</span>

                    @php $paid = $prizes['nights'][$answered->outcome->value] ?? 0; @endphp

                    @if ($paid > 0)
                        <span class="font-baloo text-[17px] font-extrabold text-fq-lime">
                            +{{ $money($paid) }}
                        </span>
                    @endif

                    @if ($answered->wasSaved())
                        <span class="font-mono-fq text-[10px] text-fq-cyan">☾ NIGHT SAVER USED</span>
                    @endif
                </div>
            @else
                <h3 class="font-baloo text-xl font-bold">How did last night go?</h3>

                {{-- What tonight is worth, before they answer rather than after.
                     This is the whole reason to press the button. --}}
                @if ($prizes['night'] > 0)
                    <p class="mt-1 text-sm text-fq-text-2">
                        A night in your own bed is worth
                        <span class="font-baloo text-[15px] font-extrabold text-fq-lime">{{ $money($prizes['night']) }}</span>.
                        Tell the truth either way — nothing here goes backwards.
                    </p>
                @else
                    <p class="mt-1 text-sm text-fq-text-2">
                        Tell the truth — nothing here goes backwards, whatever you pick.
                    </p>
                @endif

                {{-- Each answer is priced on its own button. The honest answer
                     has to be the easy one to press, and a kid who can see that
                     owning up to a cuddle still pays something is a kid with no
                     reason to claim a night they didn't have. --}}
                <div class="mt-3 flex flex-col gap-2">
                    @foreach (App\Enums\SleepOutcome::cases() as $outcome)
                        @php $pays = $prizes['nights'][$outcome->value] ?? 0; @endphp

                        <button
                            type="button"
                            wire:key="sleep-{{ $outcome->value }}"
                            wire:click="{{ $answerAction }}('{{ $outcome->value }}')"
                            class="flex items-center gap-3 rounded-[16px] border border-fq-line-2 bg-fq-sunk px-4 py-[12px] text-left text-[14px] font-semibold transition hover:border-fq-cyan"
                        >
                            <span class="text-[17px]" style="color: {{ $outcome->cssVar() }}">{{ $outcome->glyph() }}</span>
                            <span class="min-w-0 flex-1">{{ $outcome->label() }}</span>

                            @if ($pays > 0)
                                {{-- The top rung is the loud one; the rest are
                                     stated without being sold. --}}
                                <span
                                    class="font-baloo text-[15px] font-extrabold whitespace-nowrap"
                                    style="color: {{ $outcome->countsAsOwnBed() ? 'var(--fq-lime)' : 'var(--fq-text-4)' }}"
                                >{{ $money($pays) }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- What the next thing is and what it pays. Both were invisible
                 before, which made a week of nights feel like it led nowhere. --}}
            <div class="mt-4 flex flex-col gap-1 border-t border-fq-divider pt-3 font-mono-fq text-[11px]">
                <div class="flex flex-wrap items-center gap-x-5 gap-y-1">
                    <span class="text-fq-text-2">
                        <span class="text-fq-lime">{{ $card['nights'] }}</span>
                        {{ Str::plural('NIGHT', $card['nights']) }}
                    </span>
                    <span class="text-fq-text-2"><span class="text-fq-cyan">{{ $card['run'] }}</span> IN A ROW</span>
                    @if ($card['completed'] > 0)
                        <span class="text-fq-text-2">
                            <span class="text-fq-gold">{{ $card['completed'] }}</span>
                            {{ Str::plural('PICTURE', $card['completed']) }}
                        </span>
                    @endif
                </div>

                @if ($prizes['constellation'] > 0)
                    <span class="text-fq-text-4">
                        {{ $prizes['toGo'] }} more {{ Str::plural('night', $prizes['toGo']) }}
                        finishes {{ $drawing->label() }} &rarr;
                        <span class="text-fq-gold">{{ $money($prizes['constellation']) }}</span>
                    </span>
                @else
                    <span class="text-fq-text-4">
                        {{ $prizes['toGo'] }} more {{ Str::plural('night', $prizes['toGo']) }}
                        finishes {{ $drawing->label() }}
                    </span>
                @endif

                {{-- Shown beside a waiting chest, which is how the design has
                     it: the rail talks about the chest in hand, this line is
                     what is still ahead. Dropped whenever the strip is up,
                     since the strip says the same thing in more words. --}}
                @if ($next && ! $showStrip)
                    <span class="text-fq-text-4">
                        {{ $next - $card['run'] }} more in a row &rarr;
                        <span class="text-fq-cyan">{{ SleepService::RUN_MILESTONES[$next] }} tickets</span>
                    </span>
                @endif
            </div>
        </div>

        {{-- The chest rail, and it only ever draws a chest that is ready to
             open. Dressed in the ticket palette rather than the points one,
             because a chest pays tickets and the header's ticket tile already
             reads this way. --}}
        @if ($showRail)
            <div
                class="flex w-full shrink-0 flex-col items-center gap-[13px] rounded-[20px] border p-[16px_14px] sm:w-[236px]"
                style="border-color: var(--fq-lime); background: var(--fq-ticket-bg); box-shadow: var(--fq-shadow-ticket)"
            >
                <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: var(--fq-lime)">
                    Night chest &middot; ready
                </p>

                <div class="relative w-[120px]">
                    <div
                        class="absolute rounded-full"
                        style="left:50%; top:50%; width:150px; height:150px; margin:-75px 0 0 -75px; background: radial-gradient(circle, rgba(255,225,77,.3), rgba(255,225,77,0) 65%); animation: fq-glow-pulse 2.4s ease-in-out infinite"
                    ></div>

                    {{-- The shipped mark, not a new one: this is the same chest
                         as the quest chest, the loot tray and the app icon. --}}
                    <x-chest-icon
                        class="fq-chest-waiting relative block h-[100px] w-[120px]"
                        accent="var(--fq-lime)"
                    />

                    <span class="absolute rounded-full" style="left:0; top:2px; width:8px; height:8px; background:#fff6b0; animation: fq-sparkle 1.8s ease-in-out infinite"></span>
                    <span class="absolute rounded-full" style="right:2px; top:14px; width:6px; height:6px; background: var(--fq-lime); animation: fq-sparkle 2.3s ease-in-out .5s infinite"></span>
                    <span class="absolute rounded-full" style="left:22px; top:-6px; width:5px; height:5px; background: var(--fq-lime); animation: fq-sparkle 2.9s ease-in-out .9s infinite"></span>
                </div>

                <p class="font-mono-fq text-[10px] tracking-[0.12em] uppercase" style="color: var(--fq-ticket-label)">
                    {{ $waiting }} nights in a row
                </p>

                {{-- The only filled control on the card. --}}
                <button
                    type="button"
                    wire:click="openSleepChest"
                    class="w-full rounded-[14px] border p-[11px_14px] font-baloo text-[16px] font-extrabold transition hover:brightness-110"
                    style="border-color: var(--fq-lime); background: var(--fq-fill-gold); color: var(--fq-ink)"
                >Open it &middot; {{ SleepService::RUN_MILESTONES[$waiting] ?? 0 }} tickets</button>
            </div>
        @endif
    </div>

    {{-- Every leg with no chest waiting. Words rather than an object — a chest
         drawn here would be one the kid can't open, which is exactly what this
         replaced. --}}
    @if ($showStrip)
        {{-- Stacks below 420px rather than wrapping: left to `flex-wrap` the
             four children broke wherever they ran out of room, which stranded
             the bar on a line of its own or squeezed it to its minimum beside
             the payout. The label goes above, and the three measuring parts
             stay together on one line at every width. --}}
        <div
            class="mt-4 flex flex-col gap-2 rounded-[16px] border p-[11px_14px] min-[420px]:flex-row min-[420px]:items-center min-[420px]:gap-3"
            style="border-color: var(--fq-ticket-line); background: var(--fq-ticket-bg)"
        >
            {{-- "First" only while it genuinely is one; after that the strip
                 is counting to the next of many. --}}
            <span class="font-mono-fq text-[10px] tracking-[0.16em] whitespace-nowrap uppercase" style="color: var(--fq-ticket-label)">
                {{ $everOpened ? 'Next' : 'First' }} chest at {{ $next }} in a row
            </span>

            <span class="flex flex-1 items-center gap-3">
                @if ($showPips)
                    <span class="flex shrink-0 gap-[5px]">
                        @for ($pip = 1; $pip <= $legLength; $pip++)
                            <span
                                class="h-[15px] w-[15px]"
                                style="clip-path: var(--fq-star); background: {{ $pip <= $legDone ? 'var(--fq-lime)' : 'var(--fq-badge-empty)' }}"
                            ></span>
                        @endfor
                    </span>
                @endif

                <span class="h-[5px] min-w-[40px] flex-1 overflow-hidden rounded-full" style="background: var(--fq-track)">
                    <span class="block h-full rounded-full" style="width: {{ $legPercent }}%; background: var(--fq-fill-gold)"></span>
                </span>

                <span class="font-mono-fq text-[10px] tracking-[0.12em] whitespace-nowrap uppercase" style="color: var(--fq-ticket-label)">
                    {{ $next - $card['run'] }} more &rarr;
                    <span style="color: var(--fq-gold)">
                        {{ SleepService::RUN_MILESTONES[$next] }} {{ Str::plural('ticket', SleepService::RUN_MILESTONES[$next]) }}
                    </span>
                </span>
            </span>
        </div>
    @endif

    {{-- The shelf. Finishing a picture used to produce a toast and nothing
         else — no evidence a week later that it had ever happened. --}}
    @if ($card['earned'] !== [])
        <div class="mt-4 border-t border-fq-divider pt-3">
            <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Your sky</p>

            <div class="mt-2 flex flex-wrap gap-3">
                @foreach ($card['earned'] as $index => $done)
                    <div wire:key="earned-{{ $index }}" class="w-[54px]" title="{{ $done->label() }}">
                        <svg viewBox="0 0 100 100" class="h-[54px] w-[54px]" xmlns="http://www.w3.org/2000/svg">
                            @php $shape = $done->stars(); @endphp
                            @for ($i = 1; $i < count($shape); $i++)
                                <line
                                    x1="{{ $shape[$i - 1][0] }}" y1="{{ $shape[$i - 1][1] }}"
                                    x2="{{ $shape[$i][0] }}" y2="{{ $shape[$i][1] }}"
                                    stroke="var(--fq-cyan)" stroke-width="1.2" opacity="0.5"
                                />
                            @endfor
                            @foreach ($shape as [$x, $y])
                                <circle cx="{{ $x }}" cy="{{ $y }}" r="3.4" fill="var(--fq-gold)" opacity="0.95" />
                            @endforeach
                        </svg>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
