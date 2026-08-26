{{-- The arena: the monster the house is fighting.

     Three stood here once, one per tier, and every finished chore stopped to
     ask the kid which of them it hit. The choice was real but it cost a tap on
     every claim, and what it bought — a small reward and a long one, running at
     the same time — was never worth the friction. One bar, one reward, and the
     work lands where it obviously should. --}}
@props(['state'])

<div {{ $attributes->merge(['class' => 'rounded-[24px] border border-fq-line-2 bg-fq-panel p-5 sm:p-6']) }}>
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <div>
            <p class="font-mono-fq text-[10px] tracking-[0.24em] text-fq-coral uppercase">The Arena</p>
            <h2 class="mt-1 font-baloo text-2xl font-extrabold">
                {{ $state ? 'What the house is fighting' : 'The arena is empty' }}
            </h2>
        </div>

        @if ($state)
            <p class="max-w-[420px] text-[13px] text-fq-text-4">
                Every chore you finish hits it. Beat it and the family gets what it
                was guarding.
            </p>
        @endif
    </div>

    @if (! $state)
        <div class="mt-4 rounded-[18px] border border-dashed border-fq-line-4 bg-fq-sunk p-5 text-center">
            <h3 class="font-baloo text-xl font-bold">Nothing standing yet</h3>
            <p class="mx-auto mt-2 max-w-[360px] text-sm text-fq-text-2">
                A parent picks what the next monster is guarding &mdash; and how much
                work it takes to bring it down.
            </p>
        </div>
    @else
        {{-- Capped rather than full-bleed: on a desktop the card is the only
             thing in the row, and a health bar stretched across 1200px reads as
             a loading indicator rather than a monster. --}}
        <div class="mt-4 flex justify-center">
            <x-monster-card :state="$state" class="w-full max-w-[420px]" wire:key="arena-monster" />
        </div>
    @endif
</div>
