{{-- Home's pointer at the Lucky Block.

     One strip, not a section. Home already runs seven sections and two
     openable chests; an eighth would push the day's actual work further down
     the page to advertise something that lives somewhere else. So this is a
     single line above the run, and the whole of it is one tap target.

     Three states from one integer, and the third is the important one:

       3+   the gold "you've got enough" strip
       2    a dimmed "one more ticket" version, which is the only place the
            block's price is ever taught
       0–1  nothing at all — no placeholder, no progress bar, no nagging

     Nothing renders either when the pool is empty, whatever the ticket count:
     pointing a kid at a block that isn't there is worse than saying nothing.
     --}}
@props([
    'tickets',
    'open' => true,
    'cost' => \App\Services\LuckyBlockService::TICKET_COST,
])

@php $ready = $tickets >= $cost; @endphp

@if ($open && $tickets >= $cost - 1)
    <a
        href="{{ route('kid.loot') }}"
        wire:navigate
        {{-- Merged rather than fixed: the caller owns the gap between this and
             whatever it sits above, and the strip vanishing must take that gap
             with it. --}}
        {{ $attributes->merge(['class' => 'flex min-h-[64px] items-center gap-3 rounded-[16px] border px-[14px] py-[13px] transition hover:brightness-110']) }}
        style="border-color: {{ $ready ? 'var(--fq-gold)' : 'var(--fq-line-2)' }};
               background: {{ $ready ? 'var(--fq-lucky-strip)' : 'var(--fq-sunk)' }}"
    >
        {{-- The same block as the shop card at half size. The bevel is scaled
             with it — 3px rather than 4px — or it reads as a flat gold tile. --}}
        <span
            class="grid h-[38px] w-[38px] shrink-0 place-items-center rounded-[9px]"
            style="background: {{ $ready ? 'var(--fq-lucky-face)' : 'var(--fq-lucky-strip-dim)' }};
                   box-shadow: inset 0 3px 0 rgba(255,255,255,.5), inset 0 -3px 0 rgba(120,66,4,.55)"
        >
            <span
                class="font-baloo text-[23px] leading-none font-extrabold"
                style="color: var(--fq-lucky-glyph); text-shadow: 0 2px 0 var(--fq-lucky-glyph-shadow)"
            >?</span>
        </span>

        <span class="min-w-0 flex-1">
            @if ($ready)
                <span class="block font-baloo text-base leading-tight font-extrabold" style="color: var(--fq-lime)">
                    You've got enough for a Lucky Block
                </span>
                <span class="mt-[2px] block text-xs text-fq-ticket-label">It's in the shop.</span>
            @else
                <span class="block text-xs text-fq-text-3">One more ticket for a Lucky Block</span>
                <span class="mt-[3px] block font-mono-fq text-[9px] text-fq-text-4">{{ $tickets }}/{{ $cost }}</span>
            @endif
        </span>

        <i
            aria-hidden="true"
            class="fa-fw fa-solid fa-arrow-right text-[13px]"
            style="color: {{ $ready ? 'var(--fq-gold)' : 'var(--fq-text-4)' }}"
        ></i>
    </a>
@endif
