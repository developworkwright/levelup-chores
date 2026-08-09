{{-- The full-screen card a chest throws when it finishes opening.

     Rendered *inside* a chest's Alpine scope and reads three things from it —
     `phase`, `justOpened` and `label` — so the quest hero and the loot tray
     slots can share one celebration instead of keeping two in step.

     `justOpened` is the whole point of the gate: a chest that was already open
     when the page loaded starts in the 'revealed' phase too, so keying on the
     phase alone replayed the celebration on every visit to the tab. --}}
@props(['accent', 'sub'])

<template x-if="phase === 'revealed' && justOpened">
    <div
        x-data="{ show: true }"
        x-init="setTimeout(() => show = false, 2200)"
        x-show="show"
        x-transition:enter="transition duration-300 ease-out"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition duration-500 ease-in"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="pointer-events-none fixed inset-0 z-[58] flex items-center justify-center px-4"
    >
        <div class="relative flex flex-col items-center">
            {{-- .fq-card-halo owns the centring transform and the pulse, so
                 that reduced motion can drop the animation without the halo
                 losing the only thing holding it over the card. --}}
            <div
                class="fq-card-halo absolute"
                style="top:50%; left:50%; width:420px; height:420px; border-radius:50%; background: radial-gradient(circle, {{ $accent }} 0%, transparent 70%); opacity:.45; filter:blur(4px)"
            ></div>

            <div
                class="relative rounded-[22px] border px-10 py-8 text-center"
                style="animation: fq-pop .4s ease both; background: var(--fq-sunk); border-color: {{ $accent }}; box-shadow: 0 26px 60px -20px #000"
            >
                <p class="font-mono-fq text-[11px] tracking-[0.2em] uppercase" style="color: {{ $accent }}">{{ $sub }}</p>
                <p class="mt-2 max-w-[70vw] font-baloo text-[28px] leading-tight font-extrabold" x-text="label"></p>
            </div>
        </div>
    </div>
</template>
