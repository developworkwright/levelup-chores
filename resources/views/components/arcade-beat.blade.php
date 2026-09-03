{{-- The number to aim at, read immediately before a run rather than after one.

     It appears twice on the page and deliberately so: at the top of the board
     on a desktop, and directly above the canvas on a phone, where the board has
     scrolled off the bottom by the time anybody presses start. A component
     rather than two copies so the four states below can only be got wrong once.

     Four states, because a target only means something if it is true: somebody
     else leads, you lead, nobody has played, or the reader is a grown-up and
     the tickets were never theirs to win. --}}
@props([
    'leader' => null,
    'beat' => 1,
    'youLead' => false,
    'canWinTickets' => true,
    'prize' => 3,
])

<div
    class="flex items-baseline gap-[6px] rounded-[11px] border-2 px-[9px] py-[8px] {{ $youLead ? 'border-fq-lime' : 'border-fq-coral' }}"
    style="background: linear-gradient(180deg, {{ $youLead ? 'rgba(255, 225, 77, 0.16)' : 'rgba(255, 138, 199, 0.16)' }}, var(--fq-sunk))"
>
    @if ($youLead)
        <span class="shrink-0 font-mono-fq text-[8px] tracking-[0.12em] text-fq-lime uppercase">Leading</span>
        <span class="font-baloo text-[18px] leading-none font-extrabold text-fq-text">{{ $leader->score }}</span>
        <span class="min-w-0 text-[10.5px] leading-tight text-pretty text-fq-text-3">
            @if ($canWinTickets)
                {{ $prize }} {{ Str::plural('ticket', $prize) }} if it holds
            @else
                hold it to take the week
            @endif
        </span>
    @elseif ($leader)
        <span class="shrink-0 font-mono-fq text-[8px] tracking-[0.12em] text-fq-coral uppercase">Beat</span>
        <span class="font-baloo text-[18px] leading-none font-extrabold text-fq-text">{{ $beat }}</span>
        <span class="min-w-0 text-[10.5px] leading-tight text-pretty text-fq-text-3">
            @if ($canWinTickets)
                for {{ $prize }} {{ Str::plural('ticket', $prize) }}
            @else
                to take the week
            @endif
        </span>
    @else
        <span class="shrink-0 font-mono-fq text-[8px] tracking-[0.12em] text-fq-coral uppercase">Open</span>
        <span class="min-w-0 text-[10.5px] leading-tight text-pretty text-fq-text-3">
            @if ($canWinTickets)
                Nobody yet &mdash; the first run takes {{ $prize }} {{ Str::plural('ticket', $prize) }}.
            @else
                Nobody yet &mdash; the first run takes the week.
            @endif
        </span>
    @endif
</div>
