<div x-data="{ muted: localStorage.getItem('fq-muted') === '1' }" x-on:click="muted = !muted; localStorage.setItem('fq-muted', muted ? '1' : '0')">
    <button
        type="button"
        class="rounded-[14px] border border-fq-line-2 bg-fq-sunk px-3 py-[7px] font-mono-fq text-[10px] text-fq-text-4 hover:text-fq-text"
        x-text="muted ? 'Sound: off' : 'Sound: on'"
    ></button>
</div>
