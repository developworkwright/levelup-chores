{{-- One song on the parent music screen: retitle it, hear it, delete it.

     Its own component because the playlist draws it from two places — the loose
     songs and the open album's songs — and a rename control that drifted
     between the two would be a quiet way to lose a file. --}}
@props(['track', 'maxTitle'])

<div
    wire:key="track-{{ $track['id'] }}"
    class="flex flex-wrap items-center gap-2 rounded-[14px] border border-fq-line-2 bg-fq-panel px-3 py-[10px]"
>
    <input
        type="text"
        value="{{ $track['title'] }}"
        maxlength="{{ $maxTitle }}"
        wire:change="renameSong(@js($track['path']), $event.target.value)"
        aria-label="Song title"
        class="min-w-[160px] flex-1 rounded-[10px] border border-transparent bg-transparent px-[6px] py-[4px] font-baloo text-[15px] font-bold text-fq-text hover:border-fq-line-2 focus:border-fq-line-4 focus:bg-fq-sunk focus:outline-none"
    />

    {{-- A parent should be able to hear what they just named without signing in
         as a kid and turning the header music on. preload="none" matters more
         than it looks here: an album's worth of these would otherwise start
         fetching megabytes the moment the folder is opened. --}}
    <audio controls preload="none" src="{{ $track['url'] }}" class="h-[34px] max-w-[260px]"></audio>

    <span class="font-mono-fq text-[10px] whitespace-nowrap text-fq-text-5">
        {{ number_format($track['bytes'] / 1048576, 1) }} MB
    </span>

    <button
        type="button"
        wire:click="removeSong(@js($track['path']))"
        wire:confirm="Delete {{ $track['title'] }}?"
        title="Delete {{ $track['title'] }}"
        aria-label="Delete {{ $track['title'] }}"
        class="shrink-0 rounded-[10px] border border-fq-line-2 px-[10px] py-[6px] text-[12px] text-fq-text-4 transition hover:text-fq-text"
    >Delete</button>
</div>
