{{-- The boss, as a strip under the main quest.

     It sits there rather than in a sidebar because the family goal is the
     reason the board is worth clearing, and a kid should meet it on the way
     down the page instead of having to look for it.

     No replay and no `markSeen()` here, deliberately. This is a status glance
     on the page kids live on; the catch-up walk through the stages they missed
     belongs to the arena on the Goal page, and marking them seen from here
     would spend that moment on a thumbnail.

     Everything is framed as health *remaining* — the bar empties as the family
     earns. See <x-boss-arena> for why the damage total stays away from a bar
     showing what's left. --}}
@props(['state', 'pending' => 0])

@php
    $health = number_format($state['health']).' / '.number_format($state['maxHealth']).' HP LEFT';
    $pendingNote = $pending > 0 ? ' · '.$pending.' PENDING' : '';
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center gap-[14px] rounded-[18px] border border-fq-line-2 px-4 py-3']) }} style="background: linear-gradient(90deg, #1d0b2f, var(--fq-panel))">
    {{-- Drawn by monsters.js; see <x-boss-arena> for why this is left alone by
         Livewire's morph and keyed on what it is drawing. --}}
    <div
        wire:key="boss-mini-{{ $state['skin']->value }}-{{ $state['stage']->value }}"
        wire:ignore
        x-data="fqMonster(@js($state['skin']->value), @js($state['stage']->value))"
        x-html="svg"
        :style="{ background: cardBg }"
        class="fq-boss aspect-square w-[62px] shrink-0 overflow-hidden rounded-[16px] sm:w-[72px]"
    ></div>

    <div class="min-w-0 flex-1">
        <div class="flex items-baseline justify-between gap-[10px]">
            <span class="font-mono-fq text-[10px] tracking-[0.24em] whitespace-nowrap text-fq-coral uppercase">Boss Fight</span>

            {{-- The phone gets the percentage and the desktop gets the count,
                 because the row only has room for one of them and the numbers
                 turn up again in the caption below. --}}
            <span class="font-mono-fq text-[10px] whitespace-nowrap text-fq-text-4 sm:hidden">
                {{ $state['healthPercent'] }}% HP LEFT
            </span>
            <span class="hidden font-mono-fq text-[10px] whitespace-nowrap text-fq-text-4 sm:inline">
                {{ $health }} · {{ $state['healthPercent'] }}%
            </span>
        </div>

        <p class="mt-[3px] font-baloo text-[17px] font-extrabold sm:text-[20px]">{{ $state['name'] }}</p>

        <div class="mt-2 h-[12px] overflow-hidden rounded-full border border-fq-line-3 bg-fq-sunk sm:h-[14px]">
            <div
                class="h-full rounded-full transition-[width] duration-700"
                style="width:{{ $state['healthPercent'] }}%;background:linear-gradient(90deg, #7a1030, var(--fq-streak) 60%, var(--fq-coral))"
            ></div>
        </div>

        <p class="mt-[7px] font-mono-fq text-[10px] text-fq-text-4">
            @if ($state['defeated'])
                BEATEN! ASK A PARENT TO LINE UP THE NEXT ONE
            @else
                <span class="sm:hidden">{{ $health }}{{ $pendingNote }}</span>
                <span class="hidden sm:inline">EVERY CHORE YOU CLEAR TAKES HP OFF IT{{ $pendingNote }}</span>
            @endif
        </p>
    </div>
</div>
