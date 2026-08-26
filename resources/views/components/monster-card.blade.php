{{-- The monster, drawn as a card.

     Takes its click handling through `$attributes` rather than owning any, so
     the arena can hand it a handler without the card growing an opinion about
     what tapping it means.

     `state['steps']`, when it holds more than one entry, is the damage this kid
     missed while they were away — the card plays those stages back before
     settling on the truth. Every stage is rendered server-side and stacked;
     Alpine only picks which one is showing, so a monster's whole range of
     artwork never has to exist in JavaScript. Numbers shown during a replay come
     from the step rather than the state, or the bar would sit at the final
     figure while the monster acted out getting there.

     Framed as health *remaining* — the bar empties as the family earns. --}}
@props(['state'])

@php
    $skin = $state['skin'];

    // A card with nothing to catch up on still runs the component — it is what
    // owns the numbers — it just has a single stage to show.
    $steps = $state['steps'] ?? [[
        'stage' => $state['stage'],
        'damage' => $state['damage'],
        'health' => $state['health'],
        'damagePercent' => $state['damagePercent'],
        'healthPercent' => $state['healthPercent'],
        'landed' => 0,
        'label' => $state['stage']->label(),
        'taunt' => $state['taunt'],
    ]];

    // Only the scalars cross into Alpine — the stage enums stay on this side,
    // where the artwork that needs them has already been rendered.
    $timeline = collect($steps)
        ->map(fn (array $step) => collect($step)->except('stage')->all())
        ->values()
        ->all();
@endphp

<div
    x-data="fqMonsterReplay(@js($timeline), @js($skin->value), @js($state['startDelay'] ?? 0))"
    @click="finish()"
    {{-- The ornate frame used to be the level 3 card's alone, earned by being
         the biggest of three. There is only one now, so it is the big fight by
         definition and simply wears it. --}}
    {{ $attributes->merge([
        'class' => 'fq-frame-ornate flex flex-col overflow-hidden rounded-[22px] border-2 border-fq-line-2 transition',
    ]) }}
    style="background: linear-gradient(160deg, #1d0b2f, var(--fq-panel) 60%)"
>
    <div class="flex flex-col items-center gap-3 p-4">
        {{-- Square, and holding its own height: every stage is absolutely
             positioned so they stack, which means none of them can be the one
             propping the box open. --}}
        <div class="relative aspect-square w-full max-w-[220px] shrink-0 overflow-hidden rounded-[18px]">
            {{-- Drawn by monsters.js rather than Blade, so `wire:ignore` keeps
                 Livewire's morph from wiping markup the server never rendered.
                 The key carries the stage, so a genuine change still replaces
                 the node and Alpine redraws it. --}}
            @foreach ($steps as $index => $step)
                <div
                    wire:key="monster-art-{{ $skin->value }}-{{ $step['stage']->value }}-{{ $index }}"
                    wire:ignore
                    x-show="index === {{ $index }}"
                    @if ($index > 0) x-cloak @endif
                    :class="hit && index === {{ $index }} ? 'fq-boss-hit' : ''"
                    x-html="monster(@js($step['stage']->value))"
                    :style="{ background: cardBg }"
                    class="fq-boss absolute inset-0"
                ></div>
            @endforeach

            {{-- The damage chip for the blow that just landed. --}}
            <span
                x-show="hit && current.landed > 0"
                x-text="hitLabel"
                x-cloak
                class="pointer-events-none absolute top-1 right-1 font-baloo text-[22px] leading-none font-extrabold text-fq-coral"
                style="text-shadow: 0 2px 10px rgba(0,0,0,0.6)"
            ></span>
        </div>

        <div class="w-full min-w-0 flex-1 text-center">
            <h3 class="font-baloo text-[22px] leading-tight font-extrabold">
                {{ $state['name'] }}
            </h3>

            {{-- The reward is the reason anybody is hitting it, so it outranks
                 the monster's own flavour text. --}}
            <p class="mt-[3px] text-[13px] font-semibold text-fq-lime">{{ $state['reward'] }}</p>

            <p class="mt-[6px] text-[12px] text-fq-text-4">{{ $state['tagline'] }}</p>
        </div>
    </div>

    <div class="mt-auto px-4 pb-4">
        <div class="relative h-[16px] overflow-hidden rounded-full border border-fq-line bg-fq-track">
            {{-- Object form, not a string. Alpine's string `:style` replaces the
                 whole style attribute, which wipes the gradient below and leaves
                 a bar that is filled but invisible. --}}
            <div
                class="h-full rounded-full transition-[width] duration-700 ease-out"
                :style="{ width: current.healthPercent + '%' }"
                style="background: linear-gradient(90deg, #7a1030, var(--fq-streak) 60%, #ff8ac7)"
            ></div>
        </div>

        <div class="mt-2 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 font-mono-fq text-[10px]">
            <span class="text-fq-text-2">
                <span x-text="current.health.toLocaleString()"></span> / {{ number_format($state['maxHealth']) }} HP LEFT
            </span>
            <span class="text-fq-coral"><span x-text="current.healthPercent"></span>%</span>
        </div>

        @if (count($steps) > 1)
            <p x-show="replaying" x-cloak class="mt-2 font-mono-fq text-[10px] tracking-[0.14em] text-fq-gold uppercase">
                Catching you up &middot; tap to skip
            </p>
        @endif

        @if ($state['defeated'])
            <p class="mt-[10px] font-baloo text-[15px] font-extrabold text-fq-lime">
                Beaten! Ask a parent what's next.
            </p>
        @elseif ($state['weakChore'])
            {{-- The one line on the card that changes what a kid does today. --}}
            <p class="mt-[10px] rounded-[10px] border border-dashed border-fq-line-4 bg-fq-sunk px-[10px] py-2 text-[12px] text-fq-text-2">
                <span class="font-mono-fq text-[9px] tracking-[0.14em] text-fq-gold uppercase">Flinches at</span><br>
                <span class="font-semibold text-fq-text">{{ $state['weakChore'] }}</span>
                &mdash; double damage
            </p>
        @endif

        @unless ($state['defeated'])
            <p x-text="`&ldquo;${current.taunt}&rdquo;`" class="mt-[10px] font-baloo text-[13px] text-fq-text-2 italic"></p>
        @endunless
    </div>
</div>
