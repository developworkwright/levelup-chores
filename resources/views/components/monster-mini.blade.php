{{-- The arena as a strip, for the page kids live on.

     It sits in the flow of the board rather than in a sidebar because the
     monster is the reason clearing the board is worth anything, and a kid
     should meet it on the way down the page instead of having to go looking.

     A glance, not the arena: no taunts, no flavour, no weak-point copy. What it
     has to answer is "how close is it to going down" — the rest is one tap away
     on the Goal page. --}}
@props(['state', 'pending' => 0])

<div {{ $attributes->merge(['class' => 'rounded-[18px] border border-fq-line-2 px-4 py-3']) }} style="background: linear-gradient(90deg, #1d0b2f, var(--fq-panel))">
    <div class="flex items-baseline justify-between gap-3">
        <span class="font-mono-fq text-[10px] tracking-[0.24em] whitespace-nowrap text-fq-coral uppercase">
            Boss Fight
        </span>

        @if ($pending > 0)
            <span class="font-mono-fq text-[10px] whitespace-nowrap text-fq-text-4">
                {{ $pending }} PENDING
            </span>
        @endif
    </div>

    <div class="mt-[10px] flex items-center gap-[12px]">
        {{-- See <x-monster-card> for why this is keyed on what it draws and
             left alone by Livewire's morph. --}}
        <div
            wire:key="mini-art-{{ $state['skin']->value }}-{{ $state['stage']->value }}"
            wire:ignore
            x-data="fqMonster(@js($state['skin']->value), @js($state['stage']->value))"
            x-html="svg"
            :style="{ background: cardBg }"
            class="fq-boss aspect-square w-[56px] shrink-0 overflow-hidden rounded-[12px]"
        ></div>

        <div class="min-w-0 flex-1">
            <div class="flex items-baseline justify-between gap-2">
                <span class="min-w-0 truncate font-baloo text-[15px] font-extrabold sm:text-[17px]">
                    {{ $state['name'] }}
                </span>

                {{-- The health total, not just the percentage. An untouched
                     500 HP monster and an untouched 8,000 HP one both read
                     100%, and they are not the same week's work. --}}
                <span class="font-mono-fq text-[10px] whitespace-nowrap text-fq-text-4">
                    {{ number_format($state['maxHealth']) }} HP · {{ $state['healthPercent'] }}%
                </span>
            </div>

            <div class="relative mt-[4px] h-[12px] overflow-hidden rounded-full border border-fq-line-3 bg-fq-sunk">
                <div
                    class="h-full rounded-full transition-[width] duration-700"
                    style="width:{{ $state['healthPercent'] }}%;background:linear-gradient(90deg, #7a1030, var(--fq-streak) 60%, var(--fq-coral))"
                ></div>
            </div>

            {{-- The reward, not the monster's flavour: on this page the only
                 question is what the work is buying. --}}
            <p class="mt-[4px] truncate font-mono-fq text-[10px] text-fq-text-4 uppercase">
                {{ $state['reward'] }}
            </p>
        </div>
    </div>
</div>
