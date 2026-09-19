{{-- The way to the prize counter from the machines: a neon PRIZES sign in the
     same pink glow as the one over the counter itself, so the two read as the
     same place. Added because the plain PRIZES tab in the header was too easy
     to miss — a kid with tokens to spend should be looking at a lit sign, not
     hunting for a second tab.

     Says what is waiting to be spent, because "PRIZES" alone is a shop and
     "60 tokens to spend" is an errand. --}}
@props(['balance' => 0])

<button
    type="button"
    wire:click="showTab('prizes')"
    {{ $attributes->class('group relative flex w-full items-center gap-[12px] overflow-hidden rounded-[18px] border-2 px-[14px] py-[11px] text-left') }}
    style="border-color: #ff8ac7; background: radial-gradient(ellipse at 50% 120%, #3a1030, var(--fq-bg) 72%); box-shadow: 0 0 10px rgba(255, 138, 199, .55), 0 0 26px rgba(224, 54, 91, .35), inset 0 0 14px rgba(255, 138, 199, .18)"
>
    <span
        class="fq-flicker font-baloo text-[26px] leading-none font-extrabold tracking-[0.12em] text-fq-coral"
        style="text-shadow: 0 0 8px #ff8ac7, 0 0 26px #e0365b, 0 0 2px #fff"
    >PRIZES</span>

    <span class="flex min-w-0 flex-1 items-center justify-end gap-[7px]">
        <fq-prize kind="token" class="h-[22px] w-[22px] shrink-0"></fq-prize>
        <span class="min-w-0 text-right text-[12px] leading-tight text-fq-text-2">
            @if ($balance > 0)
                <strong class="font-baloo text-[16px] text-fq-lime">{{ $balance }}</strong> to spend
            @else
                Win tokens, spend them here
            @endif
        </span>
        <i class="fa-solid fa-chevron-right text-[12px] text-fq-coral transition-transform group-hover:translate-x-[2px]"></i>
    </span>
</button>
