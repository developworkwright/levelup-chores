{{-- One song in the library, on the kid's music page, with the button that
     puts it in the open playlist.

     Its own component for the same reason as music-row: the page draws it from
     two places — the loose songs and the open album's songs — and an add
     control that drifted between the two is a control that works in one place
     and not the other. --}}
@props(['track', 'chosen' => false])

<div
    wire:key="pick-{{ $track['id'] }}"
    class="flex items-center gap-2 rounded-[14px] border border-fq-line-2 bg-fq-panel px-3 py-[9px]"
>
    <span class="min-w-0 flex-1 truncate text-[13px] {{ $chosen ? 'text-fq-text-4' : 'text-fq-text-2-b' }}">
        {{ $track['title'] }}
    </span>

    {{-- Hear it before it goes in the list.

         The reason the row has three controls instead of two: the library is a
         hundred files named after the game they came out of, and a kid choosing
         between two of them has no way to tell which is which without playing
         one. It reaches into the same store the header plays from, so testing a
         song here *is* putting it on — there is never a second thing making
         noise, and the bar at the top of the card is where it gets scrubbed. --}}
    <button
        type="button"
        x-data
        {{-- The id read off the element rather than captured in an `x-data`
             object. Livewire morphs these rows as songs go in and out of the
             open list, and it rewrites attributes while leaving an
             already-evaluated `x-data` expression alone — so a captured id is
             one that can quietly go stale, and a dataset read cannot. --}}
        data-track="{{ $track['id'] }}"
        @click="$store.music.preview($el.dataset.track)"
        :aria-pressed="$store.music.isPlaying($el.dataset.track)"
        :aria-label="($store.music.isPlaying($el.dataset.track) ? 'Stop ' : 'Play ') + @js($track['title'])"
        class="shrink-0 rounded-[10px] border border-fq-line-2 px-[10px] py-[6px] text-[12px] transition"
        :class="$store.music.isPlaying($el.dataset.track) ? 'text-fq-lime' : 'text-fq-text-4 hover:text-fq-text'"
        x-text="$store.music.isPlaying($el.dataset.track) ? '■' : '▶'"
    ></button>

    {{-- Already in the list: still drawn, and drawn as done rather than hidden.
         A library that quietly loses rows as songs are added is one a kid keeps
         scrolling back through looking for what they think they missed. --}}
    @if ($chosen)
        <span
            class="shrink-0 rounded-[10px] px-[10px] py-[6px] font-mono-fq text-[10px] text-fq-lime"
            aria-label="{{ $track['title'] }} is already in this playlist"
        >&check; IN</span>
    @else
        <button
            type="button"
            wire:click="addSong(@js($track['id']))"
            aria-label="Add {{ $track['title'] }}"
            class="shrink-0 rounded-[10px] border border-fq-line-2 px-[10px] py-[6px] text-[12px] text-fq-text-4 transition hover:text-fq-text"
        >+ Add</button>
    @endif
</div>
