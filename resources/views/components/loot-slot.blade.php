{{-- One of the three slots in the Loot Tray: bonus chest, streak chest, bonus
     wheel.

     The tray exists to make the day's *extras* read as one group rather than as
     three cards competing with the main quest, so a slot is deliberately small
     and says only two things — what it is, and whether it's ready.

     A chest slot opens in place. That's the rule the handoff is firm about:
     these never take over the page the way the quest chest does, so the jiggle
     and the burst happen inside the slot and only the status line changes
     afterwards. The suspense runs *before* the server call for the same reason
     it does in `<x-chest>` — opening banks the reward and re-renders the header,
     so calling first let the balance climb while the chest was still rattling.

     The tap target is a full-cover overlay rather than a wrapping <button> or
     <a>, so the same block of content serves all three cases (openable, link,
     inert) instead of being written out once per case. --}}
@props([
    'wireKey',
    'name',
    'status',
    'statusColor' => 'var(--fq-text-4)',
    'border' => 'var(--fq-line)',
    'background' => 'var(--fq-panel)',
    'icon' => 'chest',
    'chestFill' => 'linear-gradient(180deg, #8fe0ff, #2f6f9e)',
    'dim' => false,
    'pulse' => false,
    'href' => null,
    'scrollTo' => null,
    'openAction' => null,
    'openingStatus' => 'Opening…',
    'openedStatus' => null,
    'accent' => 'var(--fq-cyan)',
    'prizeSub' => '',
    'prizeLabel' => '',
    'prizeProperty' => null,
    'celebrateMessage' => null,
    'actionLabel' => null,
])

<div
    wire:key="{{ $wireKey }}"
    @if ($openAction)
        x-data="{
            phase: 'closed',
            label: @js($prizeLabel),
            justOpened: false,
            {{-- 100ms past the 2.5s .fq-chest-opening jiggle in app.css — see
                 <x-chest>, which keeps the same pair in step. --}}
            async open() {
                if (this.phase !== 'closed') return;
                this.phase = 'opening';
                await new Promise(resolve => setTimeout(resolve, 2600));
                await $wire.{{ $openAction }}();
                @if ($prizeProperty) this.label = $wire.{{ $prizeProperty }} ?? this.label; @endif
                this.phase = 'revealed';
                this.justOpened = true;
                window.dispatchEvent(new CustomEvent('celebrate', { detail: { message: @js($celebrateMessage) ?? this.label, style: 'confetti', motion: 'burst', origin: 'tap', hold: 2600 } }));
            },
        }"
    @endif
    {{-- overflow-hidden for the same reason as <x-chest>: the opening halo is
         wider than the slot and must stay inside its border. --}}
    class="relative flex flex-col items-center gap-2 overflow-hidden rounded-[18px] border p-[16px_12px] text-center {{ $dim ? 'opacity-60' : '' }}"
    style="border-color: {{ $border }}; background: {{ $background }}"
>
    @if ($openAction)
        <button
            type="button"
            x-show="phase === 'closed'"
            @click="open()"
            aria-label="{{ $actionLabel ?? 'Open the '.$name }}"
            class="absolute inset-0 z-10 cursor-pointer rounded-[18px]"
        ></button>
    @elseif ($href)
        <a
            href="{{ $href }}"
            wire:navigate
            aria-label="{{ $actionLabel ?? $name }}"
            class="absolute inset-0 z-10 rounded-[18px]"
        ></a>
    @elseif ($scrollTo)
        {{-- A slot with nothing to open is still worth tapping: it takes the
             kid to the card that explains it. Scrolled rather than jumped, so
             they see where on the page the answer lives. --}}
        <button
            type="button"
            x-data
            @click="
                const card = document.getElementById(@js($scrollTo));
                if (card) window.scrollTo({ top: card.getBoundingClientRect().top + window.scrollY - 16, behavior: 'smooth' });
            "
            aria-label="{{ $actionLabel ?? $name }}"
            class="absolute inset-0 z-10 cursor-pointer rounded-[18px]"
        ></button>
    @endif

    @if ($icon === 'wheel')
        {{-- Six alternating wedges and a hub — the carnival wheel at thumbnail
             size. It pulses only while the spin is still there to be taken. --}}
        <div
            class="relative h-[38px] w-[38px] rounded-full sm:h-[46px] sm:w-[46px]"
            style="border: 2px solid var(--fq-magenta); background: conic-gradient(var(--fq-magenta) 0deg 60deg, #2a1150 60deg 120deg, var(--fq-magenta) 120deg 180deg, #2a1150 180deg 240deg, var(--fq-magenta) 240deg 300deg, #2a1150 300deg 360deg){{ $pulse ? '; animation: fq-pulse 2.2s ease-in-out infinite' : '' }}"
        >
            <div
                class="absolute top-1/2 left-1/2 h-[14px] w-[14px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-fq-panel"
                style="border: 2px solid var(--fq-magenta)"
            ></div>
        </div>
    @elseif ($openAction)
        <div class="relative flex items-center justify-center">
            {{-- Sized through --fq-glow-size, not a width utility — see
                 <x-chest> and .fq-glow in app.css for why. --}}
            <div
                x-show="phase === 'opening'"
                x-cloak
                class="fq-glow"
                style="--fq-glow-size: 90px; background: radial-gradient(circle, {{ $accent }} 0%, transparent 70%)"
            ></div>

            {{-- :class, never :style — the chest carries its body colour in
                 the style attribute, and an Alpine style binding owns that
                 attribute outright. See .fq-chest-opening in app.css. --}}
            <x-chest-block
                :fill="$chestFill"
                class="relative h-[34px] w-[44px] sm:h-[42px] sm:w-[54px]"
                x-bind:class="phase === 'opening' ? 'fq-chest-opening' : ''"
            />
        </div>
    @else
        <x-chest-block :fill="$chestFill" class="h-[34px] w-[44px] sm:h-[42px] sm:w-[54px]" />
    @endif

    <p class="font-baloo text-[13px] font-bold sm:text-[15px]">{{ $name }}</p>

    @if ($openAction)
        <p
            x-show="phase === 'closed'"
            class="font-mono-fq text-[8.5px] leading-[1.3] tracking-[0.1em] uppercase sm:text-[9.5px]"
            style="color: {{ $statusColor }}"
        >{{ $status }}</p>

        <p
            x-show="phase === 'opening'"
            x-cloak
            class="font-mono-fq text-[8.5px] leading-[1.3] tracking-[0.1em] uppercase sm:text-[9.5px]"
            style="color: {{ $accent }}; animation: fq-pulse 1s ease-in-out infinite"
        >{{ $openingStatus }}</p>

        <p
            x-show="phase === 'revealed'"
            x-cloak
            class="font-mono-fq text-[8.5px] leading-[1.3] tracking-[0.1em] uppercase sm:text-[9.5px]"
            style="color: {{ $accent }}"
        >{{ $openedStatus ?? 'Opened' }}</p>

        <x-prize-overlay :accent="$accent" :sub="$prizeSub" />
    @else
        <p
            class="font-mono-fq text-[8.5px] leading-[1.3] tracking-[0.1em] uppercase sm:text-[9.5px]"
            style="color: {{ $statusColor }}"
        >{{ $status }}</p>
    @endif
</div>
