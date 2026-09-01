{{-- The clock on tonight's streak day.

     The kids understood that streaks were worth something and not *when* they
     had to act to keep one — the run was a number on a card three sections
     down, with nothing anywhere saying a day was running out. So the deadline
     gets said out loud, on a live timer, at the top of the streak section.

     Four states, and all four are the same strip so the kid learns to look at
     one place:

       running    time to bedtime, and that any chore closes it
       urgent     the same, past the house's evening watch hour, in red
       overtime   bedtime has gone and the day hasn't been earned. **No timer.**
                  The day is still winnable and the copy says so, but handing a
                  kid who has run out of evening a fresh six-hour number is the
                  "loads of time" feeling the whole strip exists to remove.
       secured    done — no timer either, because a countdown over a day that is
                  already won is just a thing to worry about

     The countdown ticks client-side and fires exactly one $wire.$refresh() when
     it hits zero, the same deal <x-chore-countdown> makes: that is the single
     moment the strip's own copy changes — running rolls into overtime — so it
     costs one request rather than the steady drip of a wire:poll.

     $closesAt is bedtime, and null once bedtime has gone: see
     StreakService::streakWindowFor() for why the countdown points at the soft
     time and why it stops rather than re-pointing at the hard one. Times arrive
     in UTC; the wall times in the copy are what the house reads off a kitchen
     clock, hence the timezone. --}}
@props([
    'resetsAt',
    'timezone',
    'closesAt' => null,
    'bedtime' => null,
    'streak' => 0,
    'secured' => false,
    'urgent' => false,
    'overtime' => false,
])

@php
    $wallTime = fn ($moment) => $moment->copy()->setTimezone($timezone)->format('g:i A');

    $resetsLabel = $wallTime($resetsAt);
    $bedtimeLabel = $bedtime ? $wallTime($bedtime) : null;

    $accent = match (true) {
        $secured => 'var(--fq-lime)',
        $urgent || $overtime => 'var(--fq-danger)',
        default => 'var(--fq-streak)',
    };

    [$kicker, $headline] = match (true) {
        $secured => ['Streak safe', "Today's in the bag"],
        $overtime && $streak > 0 => ['Past bedtime', "Last chance for your {$streak}-day streak"],
        $overtime => ['Past bedtime', 'Last chance to start a streak'],
        $streak === 0 => ['Streak timer', 'Start a streak today'],
        $urgent => ['Running out', "Don't lose your {$streak}-day streak"],
        default => ['Streak timer', "Keep your {$streak}-day streak"],
    };
@endphp

<div
    {{ $attributes->merge(['class' => 'flex min-h-[64px] flex-wrap items-center gap-x-3 gap-y-2 rounded-[16px] border px-[14px] py-[13px]']) }}
    style="border-color: color-mix(in srgb, {{ $accent }} 55%, transparent);
           background: color-mix(in srgb, {{ $accent }} 10%, var(--fq-sunk))"
>
    <span
        class="grid h-[38px] w-[38px] shrink-0 place-items-center rounded-[11px]"
        style="background: color-mix(in srgb, {{ $accent }} 22%, transparent); color: {{ $accent }}"
    >
        <i aria-hidden="true" class="fa-fw fa-solid {{ $secured ? 'fa-circle-check' : ($overtime ? 'fa-moon' : 'fa-hourglass-half') }} text-[15px]"></i>
    </span>

    <span class="min-w-0 flex-1">
        <span class="block font-mono-fq text-[9.5px] tracking-[0.24em] uppercase" style="color: {{ $accent }}">
            {{ $kicker }}
        </span>
        <span class="mt-[2px] block font-baloo text-base leading-tight font-extrabold">{{ $headline }}</span>

        <span class="mt-[3px] block text-xs text-fq-text-3">
            @if ($secured)
                A chore's in for today, so tonight counts.
                {{ $bedtimeLabel ? "Do it again before {$bedtimeLabel} tomorrow to keep the run going." : 'Do it again tomorrow to keep the run going.' }}
            @elseif ($overtime)
                {{-- Said in words, with no number beside it. The day really does
                     survive to the rollover, and a kid who gets a chore signed
                     off at ten past still keeps their run — but a fresh
                     countdown here would tell them they had all night, which is
                     the feeling this strip exists to remove. --}}
                Bedtime's been and gone. A chore signed off before the day resets at {{ $resetsLabel }} still
                counts, but you're on borrowed time now.
            @elseif (! $bedtime)
                Today ends at {{ $resetsLabel }}. Get any chore signed off before then
                {{ $streak === 0 ? "and you're on a 1-day streak." : 'or the run goes back to zero.' }}
            @elseif ($streak === 0)
                Get any chore signed off before bedtime at {{ $bedtimeLabel }} and you're on a 1-day streak.
            @else
                Get any chore signed off before bedtime at {{ $bedtimeLabel }} and today counts towards your run.
            @endif
        </span>
    </span>

    {{-- Only while there is something worth counting: see the state list. --}}
    @if ($closesAt && ! $secured)
        <span
            x-data="{
                closesAt: {{ $closesAt->getTimestampMs() }},
                remaining: '',
                timer: null,
                tick() {
                    const left = this.closesAt - Date.now();

                    if (left <= 0) {
                        clearInterval(this.timer);
                        this.remaining = '0:00';
                        $wire.$refresh();

                        return;
                    }

                    const total = Math.floor(left / 1000);
                    const [hours, minutes, seconds] = [Math.floor(total / 3600), Math.floor(total / 60) % 60, total % 60];

                    // Seconds only once the hours are gone. A whole evening
                    // counted down to the second is a stopwatch, not a
                    // deadline — and '3:12:44' is read as three minutes by
                    // exactly the kids this strip is for.
                    this.remaining = hours > 0
                        ? `${hours}h ${minutes}m`
                        : `${minutes}:${String(seconds).padStart(2, '0')}`;
                },
            }"
            x-init="tick(); timer = setInterval(() => tick(), 1000)"
            x-on:destroy="clearInterval(timer)"
            class="flex shrink-0 flex-col items-end"
        >
            <span class="font-baloo text-[22px] leading-none font-extrabold" style="color: {{ $accent }}" x-text="remaining"></span>
            <span class="mt-[3px] font-mono-fq text-[9px] tracking-[0.1em] text-fq-text-4 uppercase">
                {{ $bedtime ? 'Until bedtime' : 'Before it resets' }}
            </span>
        </span>
    @endif
</div>
