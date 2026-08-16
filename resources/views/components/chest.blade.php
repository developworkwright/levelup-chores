{{-- The quest chest, as the hero panel of the Quests page.

     It is the only chest that gets a panel of its own — everything else lives
     in the loot tray — because it gates the board: nothing else opens until
     this one is cleared, so there is exactly one place to tap and it is the
     loudest thing above the fold.

     prizeProperty names a Livewire property to read the prize from once the
     open action has run. A chest whose prize is known up front (the streak
     chest) doesn't need it — but one that's only decided on opening does,
     because the reveal card sits inside a <template x-if> and gets cloned from
     markup Blade rendered before the prize existed.

     celebrateOnOpen turns the reveal off for a chest that doesn't reveal
     anything. The quest chest deals three cards to choose between, so the
     thing worth announcing happens a beat later, when one of them is taken —
     and it's announced from the server, because a pick can miss. --}}
@props([
    'wireKey',
    'revealed',
    'openAction' => null,
    'accent' => 'var(--fq-gold)',
    'wash' => 'var(--fq-wash-gold)',
    'kicker',
    'closedTitle',
    'closedText',
    'openingText' => 'The chest is rattling...',
    'cta' => 'Open',
    'prizeLabel' => null,
    'prizeSub' => null,
    'celebrateMessage' => null,
    'prizeProperty' => null,
    'celebrateOnOpen' => true,
    'confirm' => false,
    'confirmPanel' => null,
])

<div
    wire:key="{{ $wireKey }}"
    x-data="{
        phase: @js($revealed ? 'revealed' : 'closed'),
        label: @js($prizeLabel),
        {{-- Only ever set by open(). A chest that was already open when the
             page loaded starts in the 'revealed' phase too, so gating the
             prize overlay on the phase alone re-fires the whole celebration
             every time a kid navigates back to the tab. --}}
        justOpened: false,
        {{-- The suspense runs *before* the server call, not alongside it.
             Opening banks the reward, and the response re-renders the header
             with the new balance — so calling first meant the points and
             tickets in the header quietly climbed while the chest was still
             rattling, and the prize card then announced something that had
             already happened four seconds earlier. --}}
        {{-- 100ms past the 2.5s .fq-chest-opening jiggle in app.css, so the
             chest has stopped shaking before the prize lands. Change one and
             change the other. --}}
        {{-- A chest that has something to ask first stops at 'confirming'
             instead of opening. The panel that renders there decides what
             happens next: it either calls begin() itself or does something
             else and then calls it. --}}
        async open() {
            if (this.phase !== 'closed') return;
            @if ($confirm)
                this.phase = 'confirming';

                return;
            @endif
            await this.begin();
        },
        async begin() {
            if (this.phase === 'opening' || this.phase === 'revealed') return;
            this.phase = 'opening';
            {{-- Announced so anything sitting *outside* this component that
                 only makes sense on a shut chest can take itself off screen
                 now, rather than waiting for the server round-trip at the end
                 of the animation and leaving 2.6 seconds in which a stale
                 control is still tappable. --}}
            this.$dispatch('chest-opening');
            await new Promise(resolve => setTimeout(resolve, 2600));
            @if ($openAction) await $wire.{{ $openAction }}(); @endif
            @if ($prizeProperty) this.label = $wire.{{ $prizeProperty }} ?? this.label; @endif
            this.phase = 'revealed';
            this.justOpened = true;
            @if ($celebrateOnOpen)
                {{-- Held for longer than the 2200ms reveal card below, so the
                     badge and level cards queued behind this one don't stack on
                     top of a chest still showing its prize. --}}
                {{-- Burst from the chest rather than rained from the top: the tap
                     that started this is still the last one recorded, even though
                     the rattle has been running for two and a half seconds. --}}
                window.dispatchEvent(new CustomEvent('celebrate', { detail: { message: @js($celebrateMessage) ?? this.label, style: 'confetti', motion: 'burst', origin: 'tap', hold: 2600 } }));
            @endif
        },
    }"
>
    {{-- Closed and opening are the same panel with the chest behaving
         differently, so the copy underneath doesn't jump between them. --}}
    <div
        x-show="phase !== 'revealed'"
        x-transition
        {{-- Clipped so the halo can only ever light the panel from inside it.
             The glow is round and the panel is a short wide row, so without
             this it reads as a lamp behind the card rather than as a chest
             about to open. --}}
        class="flex flex-col items-center gap-[14px] overflow-hidden rounded-[24px] border p-5 text-center sm:flex-row sm:gap-[18px] sm:p-5 sm:text-left"
        style="animation: fq-pop .3s ease both; background: {{ $wash }}; border-color: {{ $accent }}"
    >
        <div class="relative flex shrink-0 items-center justify-center">
            {{-- The size goes through --fq-glow-size rather than a width
                 utility because .fq-glow centres itself off half that number —
                 see app.css, which carries the two ways this has been got wrong
                 before. Only the accent stays inline; it's the one part Blade
                 has to interpolate. --}}
            <div
                x-show="phase === 'opening'"
                x-cloak
                class="fq-glow"
                style="--fq-glow-size: 120px; background: radial-gradient(circle, {{ $accent }} 0%, transparent 70%)"
            ></div>

            {{-- :class, never :style — the chest carries its body colour in
                 the style attribute, and an Alpine style binding owns that
                 attribute outright. See .fq-chest-opening in app.css. --}}
            <x-chest-block
                fill="linear-gradient(180deg, #ffe98a, #e0b312)"
                band="#5c4506"
                lock="#3c2c04"
                radius="12px"
                class="relative h-[68px] w-[88px] sm:h-[60px] sm:w-[76px]"
                x-bind:class="phase === 'opening' ? 'fq-chest-opening' : 'fq-chest-idle'"
            />
        </div>

        <div class="min-w-0 flex-1 sm:min-w-[260px]">
            <p
                class="font-mono-fq text-[10px] tracking-[0.24em] uppercase"
                style="color: {{ $accent }}"
                :class="phase === 'opening' ? 'fq-pulsing' : ''"
            >{{ $kicker }}</p>

            <h2 class="mt-[6px] font-baloo text-[22px] leading-[1.1] font-extrabold sm:text-[26px]">{{ $closedTitle }}</h2>

            <p class="mt-[6px] text-[13.5px] text-fq-text-2 sm:text-sm">
                {{-- The closed copy stays up through 'confirming': the question
                     being asked replaces the button, not the whole card, and
                     blanking this line would leave a hole above it. --}}
                <span x-show="phase === 'closed' || phase === 'confirming'">{{ $closedText }}</span>
                <span x-show="phase === 'opening'" x-cloak>{{ $openingText }}</span>
            </p>
        </div>

        <button
            type="button"
            x-show="phase === 'closed'"
            @click="open()"
            class="w-full shrink-0 cursor-pointer rounded-[16px] px-[26px] py-4 font-baloo text-[18px] font-extrabold transition hover:brightness-110 sm:w-auto"
            style="background: {{ $accent }}; color: var(--fq-bg)"
        >{{ $cta }}</button>

        @if ($confirm)
            <div x-show="phase === 'confirming'" x-cloak class="w-full shrink-0 sm:w-auto">
                {{ $confirmPanel }}
            </div>
        @endif
    </div>

    <div x-show="phase === 'revealed'">
        {{ $slot }}
    </div>

    @if ($celebrateOnOpen)
        <x-prize-overlay :accent="$accent" :sub="$prizeSub" />
    @endif
</div>
