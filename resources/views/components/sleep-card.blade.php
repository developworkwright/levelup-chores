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
    $drawing = $card['drawing'];
    $stars = $drawing->stars();
    $lit = $card['starsLit'];
    $answered = $card['answered'];
    $prizes = $card['prizes'];
    $rate = max(1, $card['pointsPerDollar']);

    $money = fn (int $points) => '$'.number_format($points / $rate, 2);
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

                    @if ($answered->outcome->countsAsOwnBed() && $prizes['night'] > 0)
                        <span class="font-baloo text-[17px] font-extrabold text-fq-lime">
                            +{{ $money($prizes['night']) }}
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

                <div class="mt-3 flex flex-col gap-2">
                    @foreach (App\Enums\SleepOutcome::cases() as $outcome)
                        <button
                            type="button"
                            wire:key="sleep-{{ $outcome->value }}"
                            wire:click="{{ $answerAction }}('{{ $outcome->value }}')"
                            class="flex items-center gap-3 rounded-[16px] border border-fq-line-2 bg-fq-sunk px-4 py-[12px] text-left text-[14px] font-semibold transition hover:border-fq-cyan"
                        >
                            <span class="text-[17px]" style="color: {{ $outcome->cssVar() }}">{{ $outcome->glyph() }}</span>
                            {{ $outcome->label() }}
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- What the next thing is and what it pays. Both were invisible
                 before, which made a week of nights feel like it led nowhere. --}}
            <div class="mt-4 flex flex-col gap-1 border-t border-fq-divider pt-3 font-mono-fq text-[11px]">
                <div class="flex flex-wrap items-center gap-x-5 gap-y-1">
                    <span class="text-fq-text-2"><span class="text-fq-lime">{{ $card['nights'] }}</span> NIGHTS</span>
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

                @if ($card['nextMilestone'])
                    <span class="text-fq-text-4">
                        {{ $card['nextMilestone'] - $card['run'] }} more in a row &rarr;
                        <span class="text-fq-cyan">{{ App\Services\SleepService::RUN_MILESTONES[$card['nextMilestone']] }} tickets</span>
                    </span>
                @endif
            </div>
        </div>
    </div>

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
