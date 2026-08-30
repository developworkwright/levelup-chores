{{-- One song in the header's music picker.

     Rendered inside an Alpine `x-for` over `track`, from two places — the loose
     songs at the top of the library and the songs inside an open album — so it
     lives here rather than being written out twice and drifting.

     `indent` is for the album case, where the row sits under a heading it
     belongs to. --}}
@props(['indent' => false])

<button
    type="button"
    {{-- Picking a song while the music is off selects it without starting
         anything: the picker is also how you choose what plays next time. --}}
    @click="music.select(track.id)"
    :title="track.title"
    class="flex w-full items-center gap-2 rounded-[10px] py-[9px] text-left text-[13px] transition {{ $indent ? 'pr-2 pl-[26px]' : 'px-2' }}"
    :class="track.id === music.trackId
        ? 'bg-fq-sunk font-semibold text-fq-text'
        : 'text-fq-text-3 hover:text-fq-text'"
>
    <span
        class="w-[12px] shrink-0 text-[11px]"
        :class="track.id === music.trackId ? 'text-fq-gold' : 'text-transparent'"
    >&#9835;</span>

    {{-- Truncated rather than wrapped. A soundtrack's filenames carry the
         artist and the album in front of the actual name, so a wrapping row
         turns a hundred-song list into a wall. The full name is on hover and
         in the title attribute for anyone who needs it. --}}
    <span class="truncate" x-text="track.title"></span>
</button>
