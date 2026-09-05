{{-- The test-listen bar on the playlist page: what is playing, a play button,
     and somewhere to drag into the middle of it.

     It exists because building a playlist is the one place in the app where a
     kid needs to *audition* music rather than put it on. The library is a
     hundred files named after the game they came out of, and choosing between
     two of them is guesswork until one is playing — a lot of them open on a
     long quiet intro, so even then the answer is a drag into the middle rather
     than a wait.

     The header's picker can already do all of this, and is the wrong shape for
     it here: it is a dropdown that opens over the top of the very list being
     chosen from.

     Nothing in here owns any state. It reaches into the same `music` store the
     header does, so a song tested here *is* the song the header is showing and
     there is only ever one thing making noise — see resources/js/music.js for
     why the audio is deliberately not an element on the page.

     Sticky, because the library underneath it is long: a bar that scrolled away
     with the first album would be a bar you had to scroll back up to before you
     could stop the song. --}}
<div
    {{-- One `x-data` for the whole bar, on the outside. `$store` is a magic
         rather than a global: it resolves inside an Alpine component and
         nowhere else, and a Livewire component root is not one. --}}
    x-data
    class="sticky top-[6px] z-10 flex items-center gap-[10px] rounded-[16px] border border-fq-line-2 bg-fq-panel px-3 py-[9px]"
>
    <button
        type="button"
        @click="$store.music.toggle()"
        :aria-pressed="$store.music.playing"
        :aria-label="$store.music.playing ? 'Stop the song' : 'Play the song'"
        class="grid h-[34px] w-[34px] shrink-0 place-items-center rounded-[11px] border border-fq-line-2 text-[12px] transition"
        :class="$store.music.playing ? 'text-fq-lime' : 'text-fq-text-4 hover:text-fq-text'"
        x-text="$store.music.playing ? '■' : '▶'"
    ></button>

    <div class="min-w-0 flex-1">
        {{-- Truncated for the same reason the picker's rows are: a soundtrack
             carries the game and the composer in front of the actual name, and
             a wrapping title would push the bar itself around as songs
             change. --}}
        <p
            class="truncate text-[12.5px] text-fq-text-2-b"
            x-text="$store.music.current() ? $store.music.current().title : 'Press play on a song to hear it'"
        ></p>

        <div class="mt-[6px] flex items-center gap-2">
            <input
                type="range"
                min="0"
                {{-- Never a max of zero: with both ends the same the thumb has
                     nowhere to sit and browsers disagree about where to park
                     it. Same reasoning as the header's copy of this. --}}
                :max="$store.music.seekable ? $store.music.duration : 1"
                step="1"
                :value="$store.music.elapsed"
                :disabled="! $store.music.seekable"
                {{-- The drag moves the clock; only letting go moves the song,
                     so crossing a four-minute track does not make the browser
                     start fetching at forty places on the way. --}}
                @input="$store.music.scrubTo($event.target.value)"
                @change="$store.music.seek($event.target.value)"
                aria-label="Seek through the song you are testing"
                :aria-valuetext="$store.music.clock($store.music.elapsed) + ' of ' + $store.music.clock($store.music.duration)"
                class="h-[4px] min-w-0 flex-1 accent-fq-lime disabled:opacity-40"
            >

            <span
                class="shrink-0 font-mono-fq text-[10px] whitespace-nowrap text-fq-text-5"
                x-text="$store.music.clock($store.music.elapsed) + ' / ' + ($store.music.seekable ? $store.music.clock($store.music.duration) : '--:--')"
            ></span>
        </div>
    </div>
</div>
