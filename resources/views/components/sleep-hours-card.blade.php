{{-- The hours card: how long did you sleep, asked once each morning.

     The card a kid graduates onto from the own-bed one, and the same deal
     underneath — three bands, none of them a failure, and nothing that ever
     goes backwards. Sleep is the thing on this card a kid has least control
     over, so a card that scolded them for a bad night would be scolding them
     for lying awake.

     No sky here. The constellations belong to the card that drew them, and a
     kid who has moved up keeps theirs finished rather than starting a new one.

     `card` is what SleepService::cardFor() returns for a kid on this type. --}}
@props(['card', 'answerAction' => 'answerSleepHours'])

@php
    use App\Enums\SleepBand;
    use App\Services\SleepService;

    $answered = $card['answered'];
    $bands = $card['bands'];
    $rate = max(1, $card['pointsPerDollar']);

    $money = fn (int $points) => '$'.number_format($points / $rate, 2);

    // Same chest logic as the own-bed card: a chest is only ever drawn when
    // there is genuinely one waiting, and every other leg gets the strip that
    // promises the next one in words.
    $next = $card['nextMilestone'];
    $previous = $card['previousMilestone'];
    $waiting = $card['pendingChest'];
    $everOpened = $card['runPaidThrough'] > 0;

    $showRail = $waiting !== null;
    $showStrip = $waiting === null && $next !== null;

    $legLength = $next ? $next - $previous : 0;
    $legDone = $next ? max(0, $card['run'] - $previous) : 0;
    $legPercent = $legLength > 0 ? min(100, round($legDone / $legLength * 100, 1)) : 0;
    $showPips = $legLength > 0 && $legLength <= 8;

    $answeredBand = $answered?->band();
@endphp

<div
    wire:key="sleep-hours-card"
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
        <div class="min-w-[240px] flex-1">
            @if ($answered)
                <h3 class="font-baloo text-xl font-bold">{{ $answeredBand->response() }}</h3>

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <span
                        class="rounded-full border border-fq-line-2 px-[11px] py-[5px] font-mono-fq text-[10px] tracking-[0.1em] uppercase"
                        style="color: {{ $answeredBand->cssVar() }}"
                    >{{ $answeredBand->glyph() }} {{ SleepBand::say($answered->minutes) }}</span>

                    @php $paid = $bands[$answeredBand->value] ?? 0; @endphp

                    @if ($paid > 0)
                        <span class="font-baloo text-[17px] font-extrabold text-fq-lime">
                            +{{ $money($paid) }}
                        </span>
                    @endif

                    @if ($answered->wasSaved())
                        <span class="font-mono-fq text-[10px] text-fq-cyan">&#9790; NIGHT SAVER USED</span>
                    @endif
                </div>
            @else
                <h3 class="font-baloo text-xl font-bold">How long did you sleep?</h3>

                @if (($bands[SleepBand::Full->value] ?? 0) > 0)
                    <p class="mt-1 text-sm text-fq-text-2">
                        A full night is worth
                        <span class="font-baloo text-[15px] font-extrabold text-fq-lime">{{ $money($bands[SleepBand::Full->value]) }}</span>.
                        Give your best guess — nothing here goes backwards.
                    </p>
                @else
                    <p class="mt-1 text-sm text-fq-text-2">
                        Give your best guess — nothing here goes backwards, whatever you put.
                    </p>
                @endif

                {{-- The stepper. A number rather than three buttons, because the
                     number is the thing worth keeping: the bands are what it
                     pays, but "he averaged 6h20 this week" is what a parent
                     needs and three buttons can never say it.

                     Alpine holds the value and names the band client-side, so
                     the payout moves under the thumb rather than after a round
                     trip. The server derives the band again from the minutes it
                     is sent — see SleepService::recordHours(), which is what
                     stops a kid posting themselves into the paying band. --}}
                <div
                    class="mt-4"
                    x-data="{
                        minutes: {{ $card['startMinutes'] }},
                        step: {{ SleepBand::STEP_MINUTES }},
                        max: {{ SleepBand::MAX_MINUTES }},
                        rate: {{ $rate }},
                        pays: {{ Js::from($bands) }},
                        bump(by) {
                            this.minutes = Math.max(0, Math.min(this.max, this.minutes + by * this.step));
                        },
                        get band() {
                            if (this.minutes >= {{ SleepBand::FULL_MINUTES }}) return 'full';
                            if (this.minutes >= {{ SleepBand::SHORT_MINUTES }}) return 'short';
                            return 'poor';
                        },
                        get label() {
                            const h = Math.floor(this.minutes / 60);
                            const m = this.minutes % 60;
                            return m === 0 ? h + 'h' : h + 'h ' + m + 'm';
                        },
                        get money() {
                            return '$' + ((this.pays[this.band] ?? 0) / this.rate).toFixed(2);
                        },
                        get tone() {
                            return { full: 'var(--fq-lime)', short: 'var(--fq-cyan)', poor: 'var(--fq-text-4)' }[this.band];
                        },
                        get bandLabel() {
                            return { full: 'A full night', short: 'A short night', poor: 'A rough night' }[this.band];
                        },
                    }"
                >
                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            @click="bump(-1)"
                            aria-label="Half an hour less"
                            class="h-12 w-12 shrink-0 rounded-[14px] border border-fq-line-2 bg-fq-sunk font-baloo text-xl font-extrabold transition hover:border-fq-cyan"
                        >&minus;</button>

                        <div class="flex-1 text-center">
                            <p class="font-baloo text-[34px] leading-none font-extrabold" x-text="label" :style="'color: ' + tone"></p>
                            <p class="mt-1 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase" x-text="bandLabel"></p>
                        </div>

                        <button
                            type="button"
                            @click="bump(1)"
                            aria-label="Half an hour more"
                            class="h-12 w-12 shrink-0 rounded-[14px] border border-fq-line-2 bg-fq-sunk font-baloo text-xl font-extrabold transition hover:border-fq-cyan"
                        >+</button>
                    </div>

                    {{-- The three bands as a track, so a kid can see where the
                         next line is without having to be told the rules. The
                         lit one follows the stepper. --}}
                    <div class="mt-3 flex gap-1">
                        @foreach (SleepBand::cases() as $case)
                            @php $pays = $bands[$case->value] ?? 0; @endphp

                            <div class="flex-1">
                                <div
                                    class="h-[5px] rounded-full transition"
                                    :style="band === '{{ $case->value }}' ? 'background: ' + tone : 'background: var(--fq-track)'"
                                ></div>
                                <p class="mt-[5px] text-center font-mono-fq text-[9px] tracking-[0.06em] text-fq-text-5 uppercase">
                                    {{ $case->range() }}
                                </p>
                                <p class="text-center font-mono-fq text-[10px]" style="color: {{ $pays > 0 ? $case->cssVar() : 'var(--fq-text-5)' }}">
                                    {{ $pays > 0 ? $money($pays) : '—' }}
                                </p>
                            </div>
                        @endforeach
                    </div>

                    <button
                        type="button"
                        @click="$wire.{{ $answerAction }}(minutes)"
                        class="mt-4 flex w-full items-center justify-center gap-2 rounded-[16px] border p-[13px_16px] font-baloo text-[16px] font-extrabold transition hover:brightness-110"
                        style="border-color: var(--fq-lime); background: var(--fq-fill-gold); color: var(--fq-ink)"
                    >
                        <span x-text="'That\'s my answer · ' + label"></span>
                        <span class="font-mono-fq text-[11px] opacity-70" x-text="money"></span>
                    </button>
                </div>
            @endif

            <div class="mt-4 flex flex-col gap-1 border-t border-fq-divider pt-3 font-mono-fq text-[11px]">
                <div class="flex flex-wrap items-center gap-x-5 gap-y-1">
                    <span class="text-fq-text-2">
                        <span class="text-fq-lime">{{ $card['nights'] }}</span>
                        FULL {{ Str::plural('NIGHT', $card['nights']) }}
                    </span>
                    <span class="text-fq-text-2"><span class="text-fq-cyan">{{ $card['run'] }}</span> IN A ROW</span>
                </div>

                @if ($next && ! $showStrip)
                    <span class="text-fq-text-4">
                        {{ $next - $card['run'] }} more in a row &rarr;
                        <span class="text-fq-cyan">{{ SleepService::RUN_MILESTONES[$next] }} tickets</span>
                    </span>
                @endif
            </div>
        </div>

        {{-- The same chest as everywhere else in the app. --}}
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

                    <x-chest-icon
                        class="fq-chest-waiting relative block h-[100px] w-[120px]"
                        accent="var(--fq-lime)"
                    />

                    <span class="absolute rounded-full" style="left:0; top:2px; width:8px; height:8px; background:#fff6b0; animation: fq-sparkle 1.8s ease-in-out infinite"></span>
                    <span class="absolute rounded-full" style="right:2px; top:14px; width:6px; height:6px; background: var(--fq-lime); animation: fq-sparkle 2.3s ease-in-out .5s infinite"></span>
                    <span class="absolute rounded-full" style="left:22px; top:-6px; width:5px; height:5px; background: var(--fq-lime); animation: fq-sparkle 2.9s ease-in-out .9s infinite"></span>
                </div>

                <p class="font-mono-fq text-[10px] tracking-[0.12em] uppercase" style="color: var(--fq-ticket-label)">
                    {{ $waiting }} full nights in a row
                </p>

                <button
                    type="button"
                    wire:click="openSleepChest"
                    class="w-full rounded-[14px] border p-[11px_14px] font-baloo text-[16px] font-extrabold transition hover:brightness-110"
                    style="border-color: var(--fq-lime); background: var(--fq-fill-gold); color: var(--fq-ink)"
                >Open it &middot; {{ SleepService::RUN_MILESTONES[$waiting] ?? 0 }} tickets</button>
            </div>
        @endif
    </div>

    @if ($showStrip)
        <div
            class="mt-4 flex flex-col gap-2 rounded-[16px] border p-[11px_14px] min-[420px]:flex-row min-[420px]:items-center min-[420px]:gap-3"
            style="border-color: var(--fq-ticket-line); background: var(--fq-ticket-bg)"
        >
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
</div>
