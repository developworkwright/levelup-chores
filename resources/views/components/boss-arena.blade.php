{{-- The family goal, fought.

     `steps` always holds at least one entry — the boss as it stands now. When
     it holds more, the kid missed damage while they were away and the arena
     plays those stages back before settling on the truth. Every stage is
     rendered server-side and stacked; Alpine only picks which one is showing,
     so six monsters' worth of artwork never has to exist in JavaScript.

     Numbers displayed during a replay come from the step, not from `state` —
     otherwise the bar would sit at the final figure while the monster acted out
     getting there. --}}
@props([
    'state',
    'steps',
    'hits' => null,
])

@php
    $skin = $state['skin'];
    $final = $steps[count($steps) - 1] ?? null;
    $replaying = count($steps) > 1;

    // Only the scalars cross into Alpine — the stage enums stay on this side,
    // where the artwork that needs them has already been rendered.
    $timeline = collect($steps)
        ->map(fn (array $step) => collect($step)->except('stage')->all())
        ->values()
        ->all();
@endphp

<div
    x-data="fqBossReplay(@js($timeline))"
    @click="finish()"
    class="overflow-hidden rounded-[24px] border border-fq-line-2"
    style="background: linear-gradient(160deg, #1d0b2f, var(--fq-panel) 60%)"
>
    <div class="flex flex-wrap items-start gap-5 p-5 sm:p-6">
        {{-- Square, and holding its own height: every stage is absolutely
             positioned so they stack, which means none of them can be the one
             propping the box open. The viewBox is square, so the ratio is the
             artwork's own. --}}
        <div class="relative mx-auto aspect-square w-[190px] shrink-0 sm:mx-0 sm:w-[210px]">
            {{-- The monster, one layer per stage. Stage 0 renders uncloaked so
                 the monster is on screen before Alpine boots — during a replay
                 that is the oldest stage, which is exactly where it should
                 start from. --}}
            @foreach ($steps as $index => $step)
                <div
                    wire:key="boss-step-{{ $index }}"
                    x-show="index === {{ $index }}"
                    @if ($index > 0) x-cloak @endif
                    :class="hit && index === {{ $index }} ? 'fq-boss-hit' : ''"
                    class="absolute inset-0"
                >
                    <x-dynamic-component :component="$skin->component()" :skin="$skin" :stage="$step['stage']" />
                </div>
            @endforeach

            {{-- The damage chip for the blow that just landed. --}}
            <span
                x-show="hit && current.landed > 0"
                x-text="hitLabel"
                x-cloak
                class="pointer-events-none absolute top-2 right-1 font-baloo text-[26px] leading-none font-extrabold text-fq-coral"
                style="text-shadow: 0 2px 10px rgba(0,0,0,0.6)"
            ></span>
        </div>

        <div class="min-w-[220px] flex-1">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h3 class="font-baloo text-[28px] leading-none font-extrabold">{{ $state['name'] }}</h3>
                <span
                    x-text="current.label"
                    class="rounded-[6px] bg-fq-line px-[7px] py-[3px] font-mono-fq text-[10px] tracking-[0.14em] text-fq-coral uppercase"
                ></span>
            </div>

            <p class="mt-[6px] text-[13px] text-fq-text-4">{{ $state['tagline'] }}</p>

            {{-- Health, not progress. Same number as the family goal's bar,
                 read from the other end. --}}
            <div class="mt-4">
                <div class="h-[22px] overflow-hidden rounded-full border border-fq-line bg-fq-track">
                    {{-- Object form, not a string. Alpine's string `:style`
                         replaces the whole style attribute, which wipes the
                         gradient below and leaves a bar that is filled but
                         invisible. The object form only writes the one
                         property it names. --}}
                    <div
                        class="h-full rounded-full transition-[width] duration-700 ease-out"
                        :style="{ width: current.healthPercent + '%' }"
                        style="background: linear-gradient(90deg, #7a1030, var(--fq-streak) 60%, #ff8ac7)"
                    ></div>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 font-mono-fq text-[11px]">
                    <span class="text-fq-text-2">
                        <span x-text="current.health.toLocaleString()"></span> / {{ number_format($state['maxHealth']) }} HP
                    </span>
                    <span class="text-fq-coral">
                        <span x-text="current.damagePercent"></span>% BEATEN
                    </span>
                </div>
            </div>

            <p
                x-text="`&ldquo;${current.taunt}&rdquo;`"
                class="mt-3 font-baloo text-[15px] text-fq-text-2 italic"
            ></p>

            @if ($replaying)
                {{-- Only shown while there is something left to play, so it
                     never lingers as a dead line of text. --}}
                <p x-show="replaying" x-cloak class="mt-2 font-mono-fq text-[10px] tracking-[0.14em] text-fq-gold uppercase">
                    Catching you up &middot; tap to skip
                </p>
            @endif

            @if ($final && $final['stage']->isDefeated())
                <p x-show="! replaying" class="mt-3 font-baloo text-[17px] font-extrabold text-fq-lime">
                    Beaten! Ask a parent to set up the next one.
                </p>
            @endif
        </div>
    </div>

    @if ($hits && $hits->isNotEmpty())
        <div class="border-t border-fq-divider px-5 pt-[14px] pb-5 sm:px-6">
            <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Latest hits</p>

            <div class="mt-[10px] flex flex-col gap-[6px]">
                @foreach ($hits as $hit)
                    <div
                        wire:key="boss-hit-{{ $hit->id }}"
                        class="flex items-center gap-[10px] rounded-[12px] border border-fq-line-2 bg-fq-sunk px-3 py-2"
                    >
                        @if ($hit->profile)
                            <span
                                class="grid h-[22px] w-[22px] shrink-0 place-items-center rounded-[6px] font-baloo text-[11px] font-extrabold text-fq-bg"
                                style="background: {{ $hit->profile->color->cssVar() }}"
                            >{{ mb_substr($hit->profile->name, 0, 1) }}</span>
                        @endif

                        <span class="min-w-0 flex-1 truncate text-[13px] text-fq-text-2">{{ $hit->description }}</span>

                        <span class="font-baloo text-[14px] font-extrabold whitespace-nowrap text-fq-coral">
                            &minus;{{ number_format($hit->amount) }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
