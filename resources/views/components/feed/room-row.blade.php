{{-- One room in the list.

     The same markup at both sizes — bigger on the phone's own screen, smaller
     in the laptop's rail — because it is the same row, and two copies of it
     would drift the first time either changed.

     A room is identifiable without reading any of it: a glyph or a monogram, a
     colour, and a fixed position in the list. That is not decoration. Colton is
     six. --}}
{{-- `click` and `rowKey` exist for a person in "Just you two" who has no
     conversation yet: there is no room to open, so the row starts one instead,
     and it is keyed by the person rather than by a room that does not exist. --}}
@props(['entry', 'selected' => false, 'tz' => 'UTC', 'click' => null, 'rowKey' => null])

@php
    $room = $entry['room'];
    $at = $entry['at'];
@endphp

<button
    type="button"
    wire:click="{{ $click ?? 'open('.$room->id.')' }}"
    wire:key="{{ $rowKey ?? 'room-'.$room->id }}"
    @class([
        'flex w-full items-center gap-[10px] rounded-[13px] border p-[9px_10px] text-left transition lg:gap-[11px]',
        'max-lg:gap-[11px] max-lg:rounded-[15px] max-lg:p-[11px]',
        'border-fq-ticket-line' => $selected,
        'border-fq-line bg-fq-panel hover:bg-fq-sunk' => ! $selected,
    ])
    @style(['background: var(--fq-ticket-bg)' => $selected])
    aria-current="{{ $selected ? 'page' : 'false' }}"
>
    @if ($entry['glyph'])
        <span
            class="grid size-8 shrink-0 place-items-center rounded-[10px] text-[15px] max-lg:size-10 max-lg:rounded-[13px] max-lg:text-[18px]"
            style="background: {{ $selected ? 'var(--fq-ink)' : 'var(--fq-panel-alt)' }}"
        >{{ $entry['glyph'] }}</span>
    @else
        <span
            class="grid size-8 shrink-0 place-items-center rounded-[10px] border border-fq-line-3 font-baloo text-[13px] font-extrabold max-lg:size-10 max-lg:rounded-[13px] max-lg:text-[15px]"
            style="background: var(--fq-line); color: {{ $entry['accent'] }}"
        >{{ $entry['monogram'] }}</span>
    @endif

    <span class="min-w-0 flex-1">
        <span
            class="block font-baloo text-[15px] font-extrabold max-lg:text-[16.5px]"
            @style(['color: var(--fq-lime)' => $selected])
        >{{ $entry['name'] }}</span>

        {{-- Truncated because it is a preview. Nothing in a message *body*
             is ever truncated anywhere on this page. --}}
        <span class="block truncate text-[12px] text-fq-text-4 max-lg:text-[13px]">
            {{ $entry['preview'] ?? 'Nothing said here yet' }}
        </span>
    </span>

    @if ($entry['unread'] > 0)
        <span
            class="shrink-0 rounded-full px-[7px] py-[3px] font-mono-fq text-[10.5px]"
            style="background: var(--fq-streak); color: var(--fq-streak-ink)"
        >{{ $entry['unread'] }}</span>
    @elseif ($at)
        <span class="shrink-0 font-mono-fq text-[10px] text-fq-text-5">{{ $at->copy()->setTimezone($tz)->format('g:i') }}</span>
    @endif
</button>
