@props(['closesAt'])

{{-- A parent has put this chore on the clock. The countdown ticks client-side
     so the card stays honest without a poll, and fires exactly one
     $wire.$refresh() when it hits zero — that's the single moment the board
     actually changes, so it costs one request rather than the steady drip a
     wire:poll would put on a server that scales to zero when idle. --}}
<span
    x-data="{
        closesAt: {{ $closesAt->getTimestampMs() }},
        remaining: '',
        timer: null,
        tick() {
            const left = this.closesAt - Date.now();

            if (left <= 0) {
                clearInterval(this.timer);
                this.remaining = '0:00';
                $wire.$refresh();

                return;
            }

            const total = Math.floor(left / 1000);
            const parts = [Math.floor(total / 3600), Math.floor(total / 60) % 60, total % 60];

            // Seconds only matter once the hours are gone: '0:04:12' reads as
            // four hours at a glance, which is the opposite of urgent.
            this.remaining = (parts[0] > 0 ? parts.slice(0, 2) : parts.slice(1))
                .map((n, i) => i === 0 ? n : String(n).padStart(2, '0'))
                .join(':');
        },
    }"
    x-init="tick(); timer = setInterval(() => tick(), 1000)"
    x-on:destroy="clearInterval(timer)"
    {{ $attributes->merge(['class' => 'inline-flex items-center gap-[6px] rounded-[8px] px-[10px] py-1 font-mono-fq text-[10px] tracking-[0.1em] uppercase']) }}
    style="background: color-mix(in srgb, var(--fq-cyan) 20%, transparent); color: var(--fq-cyan)"
>
    <span aria-hidden="true">&#9201;</span>
    <span>Closes in <span x-text="remaining"></span></span>
</span>
