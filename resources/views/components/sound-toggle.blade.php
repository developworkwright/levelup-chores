{{-- One of the three 52px icon squares both headers end with. Muting only
     dims the glyph rather than swapping it, so the row never reflows. --}}
<div x-data="{ muted: localStorage.getItem('fq-muted') === '1' }">
    <button
        type="button"
        x-on:click="muted = !muted; localStorage.setItem('fq-muted', muted ? '1' : '0')"
        :title="muted ? 'Sound off — tap to turn on' : 'Sound on — tap to mute'"
        :aria-label="muted ? 'Sound off' : 'Sound on'"
        :style="muted ? 'opacity:0.35' : ''"
        class="flex h-[52px] w-[52px] items-center justify-center rounded-[15px] border border-fq-line-2 bg-fq-sunk text-[16px] text-fq-text-4 transition hover:text-fq-text"
    >&#9834;</button>
</div>
