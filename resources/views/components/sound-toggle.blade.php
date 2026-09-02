{{-- One of the icon squares a header ends with — 52px on the parent console,
     32px where it rides in the kid's nav sheet. Muting only dims the glyph
     rather than swapping it, so the row never reflows. --}}
@props(['small' => false])

<div x-data="{ muted: localStorage.getItem('fq-muted') === '1' }" class="shrink-0">
    <button
        type="button"
        x-on:click="muted = !muted; localStorage.setItem('fq-muted', muted ? '1' : '0')"
        :title="muted ? 'Sound off — tap to turn on' : 'Sound on — tap to mute'"
        :aria-label="muted ? 'Sound off' : 'Sound on'"
        :style="muted ? 'opacity:0.35' : ''"
        class="flex items-center justify-center border bg-fq-sunk text-fq-text-4 transition hover:text-fq-text {{ $small ? 'h-[32px] w-[32px] rounded-[10px] border-fq-line text-[14px]' : 'h-[52px] w-[52px] rounded-[15px] border-fq-line-2 text-[16px]' }}"
    >&#9834;</button>
</div>
