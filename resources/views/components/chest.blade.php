@props([
    'wireKey',
    'revealed',
    'openAction' => null,
    'accent' => 'var(--fq-gold)',
    'closedTitle',
    'closedText',
    'openingText' => 'The chest is rattling...',
    'prizeLabel',
    'prizeSub',
    'celebrateMessage' => null,
])

<div
    wire:key="{{ $wireKey }}"
    x-data="{
        phase: @js($revealed ? 'revealed' : 'closed'),
        open() {
            if (this.phase !== 'closed') return;
            this.phase = 'opening';
            @if ($openAction) $wire.{{ $openAction }}(); @endif
            setTimeout(() => {
                this.phase = 'revealed';
                window.dispatchEvent(new CustomEvent('celebrate', { detail: { message: @js($celebrateMessage ?? $prizeLabel), style: 'confetti' } }));
            }, 4600);
        },
    }"
>
    <div
        x-show="phase === 'closed'"
        x-transition
        class="flex flex-col items-center rounded-[24px] border p-8 text-center"
        style="animation: fq-pop .3s ease both; background:linear-gradient(135deg, #23306b, #171c38); border-color: {{ $accent }}"
    >
        <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: {{ $accent }}">{{ $closedTitle }}</p>

        <button type="button" @click="open()" class="mt-5 cursor-pointer" aria-label="Open the chest">
            <x-chest-icon class="h-24 w-24" :accent="$accent" style="animation: fq-float 3s ease-in-out infinite" />
        </button>

        <p class="mt-5 max-w-[320px] text-sm text-fq-text-2">{{ $closedText }}</p>
    </div>

    <div
        x-show="phase === 'opening'"
        x-transition
        class="flex flex-col items-center rounded-[24px] border p-8 text-center"
        style="background:linear-gradient(135deg, #23306b, #171c38); border-color: {{ $accent }}"
    >
        <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: {{ $accent }}; animation: fq-pulse 1s ease-in-out infinite">Opening&hellip;</p>

        <div class="relative mt-5 flex h-24 w-24 items-center justify-center">
            <div
                class="absolute"
                style="top:50%; left:50%; width:150px; height:150px; border-radius:50%; background: radial-gradient(circle, {{ $accent }} 0%, transparent 70%); opacity:.4; animation: fq-glow-pulse 1s ease-in-out infinite"
            ></div>
            <x-chest-icon class="relative h-24 w-24" :accent="$accent" style="animation: fq-chest-jiggle 4.5s ease-in-out both" />
        </div>

        <p class="mt-5 text-sm text-fq-text-2">{{ $openingText }}</p>
    </div>

    <div x-show="phase === 'revealed'">
        {{ $slot }}
    </div>

    <template x-if="phase === 'revealed'">
        <div
            x-data="{ show: true }"
            x-init="setTimeout(() => show = false, 2800)"
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
                <div
                    class="absolute"
                    style="top:50%; left:50%; width:420px; height:420px; border-radius:50%; transform:translate(-50%,-50%); background: radial-gradient(circle, {{ $accent }} 0%, transparent 70%); opacity:.45; filter:blur(4px); animation: fq-glow-pulse 1.6s ease-in-out infinite"
                ></div>

                <div
                    class="relative rounded-[22px] border px-10 py-8 text-center"
                    style="animation: fq-pop .4s ease both; background:#1d2440; border-color: {{ $accent }}; box-shadow: 0 26px 60px -20px #000"
                >
                    <p class="font-mono-fq text-[11px] tracking-[0.2em] uppercase" style="color: {{ $accent }}">{{ $prizeSub }}</p>
                    <p class="mt-2 max-w-[70vw] font-baloo text-[28px] leading-tight font-extrabold">{{ $prizeLabel }}</p>
                </div>
            </div>
        </div>
    </template>
</div>
