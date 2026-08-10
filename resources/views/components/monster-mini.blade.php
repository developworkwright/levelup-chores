{{-- The arena as a strip, for the page kids live on.

     It sits in the flow of the board rather than in a sidebar because the
     monsters are the reason clearing the board is worth anything, and a kid
     should meet them on the way down the page instead of having to go looking.

     A glance, not the arena: no taunts, no flavour, no weak-point copy. What it
     has to answer is "who's still up, and how close are they to going down" —
     the rest is one tap away on the Goal page. --}}
@props(['states', 'pending' => 0])

@php
    $standing = collect($states)->sortBy(fn (array $state) => $state['tier']->value);
@endphp

<div {{ $attributes->merge(['class' => 'rounded-[18px] border border-fq-line-2 px-4 py-3']) }} style="background: linear-gradient(90deg, #1d0b2f, var(--fq-panel))">
    <div class="flex items-baseline justify-between gap-3">
        <span class="font-mono-fq text-[10px] tracking-[0.24em] whitespace-nowrap text-fq-coral uppercase">
            Boss Fight
        </span>

        <span class="font-mono-fq text-[10px] whitespace-nowrap text-fq-text-4">
            @if ($pending > 0)
                {{ $pending }} PENDING
            @else
                {{ $standing->count() }} {{ Str::plural('MONSTER', $standing->count()) }} UP
            @endif
        </span>
    </div>

    @if ($standing->isEmpty())
        <p class="mt-2 text-[13px] text-fq-text-4">
            No monsters standing. Ask a parent to line the next ones up.
        </p>
    @else
        <div class="mt-[10px] flex flex-col gap-[10px]">
            @foreach ($standing as $state)
                @php
                    $tier = $state['tier'];
                    $segments = $tier->healthSegments();
                @endphp

                <div class="flex items-center gap-[12px]" wire:key="mini-tier-{{ $tier->value }}">
                    {{-- Sized by tier, which is the whole point of the row: three
                         thumbnails at one size read as three peers, whatever the
                         labels beside them say.

                         See <x-monster-card> for why this is keyed on what it
                         draws and left alone by Livewire's morph. --}}
                    <div
                        wire:key="mini-art-{{ $tier->value }}-{{ $state['skin']->value }}-{{ $state['stage']->value }}"
                        wire:ignore
                        x-data="fqMonster(@js($state['skin']->value), @js($state['stage']->value), @js($tier->dread()))"
                        x-html="svg"
                        :style="{ background: cardBg }"
                        class="fq-boss aspect-square {{ $tier->stripArtWidth() }} shrink-0 overflow-hidden rounded-[12px]"
                    ></div>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="flex min-w-0 items-baseline gap-2">
                                <span
                                    class="shrink-0 rounded-[5px] px-[6px] py-[2px] font-mono-fq text-[9px] tracking-[0.12em]"
                                    style="color: {{ $tier->accent() }}; background: color-mix(in srgb, {{ $tier->accent() }} 14%, transparent)"
                                >{{ $tier->badge() }}</span>

                                <span class="min-w-0 truncate font-baloo text-[15px] font-extrabold sm:text-[17px]">
                                    {{ $state['name'] }}
                                </span>
                            </span>

                            {{-- The health total, not just the percentage. Two
                                 untouched monsters both read 100% and look
                                 identical; 500 HP against 8,000 HP does not. --}}
                            <span class="font-mono-fq text-[10px] whitespace-nowrap text-fq-text-4">
                                {{ number_format($state['maxHealth']) }} HP · {{ $state['healthPercent'] }}%
                            </span>
                        </div>

                        <div class="relative mt-[4px] {{ $tier->stripBarHeight() }} overflow-hidden rounded-full border border-fq-line-3 bg-fq-sunk">
                            <div
                                class="h-full rounded-full transition-[width] duration-700"
                                style="width:{{ $state['healthPercent'] }}%;background:linear-gradient(90deg, #7a1030, var(--fq-streak) 60%, var(--fq-coral))"
                            ></div>

                            @for ($notch = 1; $notch < $segments; $notch++)
                                <span
                                    class="pointer-events-none absolute inset-y-0 w-[2px] bg-fq-bg/70"
                                    style="left: {{ round($notch / $segments * 100, 4) }}%"
                                ></span>
                            @endfor
                        </div>

                        {{-- The reward, not the monster's flavour: on this page
                             the only question is which one is worth hitting. --}}
                        <p class="mt-[4px] truncate font-mono-fq text-[10px] text-fq-text-4 uppercase">
                            {{ $state['reward'] }}
                        </p>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
