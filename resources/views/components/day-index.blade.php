{{-- The accordion's handles: a board of tiles on a phone, a list of rows at desk
     size. Shared by the kid's "Your day" and the parent's Home.

     Drawn as siblings rather than wrapped, because the caller's column is a flex
     column that orders the one open panel into place: straight after its own
     row on a desk (rows sit at order 2, 4, 6…, so a panel at 3 + index × 2 lands
     under the right one), and after the whole board on a phone, where the rows
     are hidden. Nesting the panel inside each row instead would mean a copy of
     every panel in the markup — and two copies of a chest is two Alpine
     instances of one chest.

     Every row is an array of: key, glyph, label, tileLabel, accent, sub, status,
     statusColor, done and quiet, and optionally attention. `done` or `quiet`
     greys a shut row; `attention` marks a row with something in it waiting on
     somebody — a parent's queue with items in it. It reads as a notification
     (the feed's unread red, a dot, an edge) and never as a fill: a gold fill is
     what "open" looks like, and the two were being confused.
     The toggle calls the page's own toggleRow(). --}}
@props([
    'rows',
    'openRow' => null,
    // The index of the first row of a second group, which gets a breath above
    // it on a desk. Null for one group.
    'breakBefore' => null,
])

{{-- The board is the accordion's *handle* — the panel opens below it, never in
     place of it, so the index never scrolls away while the panel is in use. --}}
<div class="grid grid-cols-3 gap-[7px] lg:hidden" style="order: 1">
    @foreach ($rows as $row)
        @php
            $open = $openRow === $row['key'];
            $attention = ! $open && ($row['attention'] ?? false);
        @endphp

        <button
            type="button"
            wire:key="tile-{{ $row['key'] }}"
            wire:click="toggleRow('{{ $row['key'] }}')"
            aria-expanded="{{ $open ? 'true' : 'false' }}"
            aria-controls="day-panel"
            @class([
                'relative flex min-h-[72px] flex-col justify-center gap-[3px] rounded-[14px] border px-2 py-[9px] text-center transition',
                // A tile left alone on the last line of three takes the line,
                // rather than sitting in the corner looking like it fell off.
                'col-span-3' => $loop->last && count($rows) % 3 === 1,
                'opacity-[.72]' => ! $open && ($row['done'] || $row['quiet']),
            ])
            @if ($attention) data-attention @endif
            style="{{ match (true) {
                $open => 'border-color: var(--fq-gold); background: var(--fq-gold-fill); box-shadow: 0 0 0 1px var(--fq-ticket-line)',
                default => 'border-color: var(--fq-line); background: var(--fq-panel)',
            } }}"
        >
            @if ($attention)
                <span
                    class="absolute top-[6px] right-[6px] size-[9px] rounded-full"
                    style="background: var(--fq-streak); box-shadow: 0 0 0 2px var(--fq-panel)"
                    aria-hidden="true"
                ></span>
            @endif

            <span class="text-[17px]" aria-hidden="true">{{ $row['glyph'] }}</span>
            <span
                class="font-baloo text-[13.5px] font-extrabold"
                style="color: {{ $open ? 'var(--fq-gold)' : $row['accent'] }}"
            >{{ $row['tileLabel'] }}</span>
            {{-- The status never carries the meaning on its own: the colour says
                 it twice, and this says it in words. --}}
            @if ($attention)
                <span
                    class="mx-auto rounded-full px-[6px] py-[1px] font-mono-fq text-[8px] tracking-[0.1em] uppercase"
                    style="background: var(--fq-streak); color: var(--fq-streak-ink)"
                >{{ $row['status'] }}</span>
            @else
                <span
                    class="font-mono-fq text-[8px] tracking-[0.1em] uppercase"
                    style="color: {{ $open ? 'var(--fq-gold)' : 'var(--fq-text-4)' }}"
                >{{ $open ? 'Open ▲' : $row['status'] }}</span>
            @endif
        </button>
    @endforeach
</div>

{{-- The same rows at desk size, where a 340px column has the room to say what
     each one is rather than abbreviate it. --}}
@foreach ($rows as $index => $row)
    @php
        $open = $openRow === $row['key'];
        $attention = ! $open && ($row['attention'] ?? false);
    @endphp

    <button
        type="button"
        wire:key="row-{{ $row['key'] }}"
        wire:click="toggleRow('{{ $row['key'] }}')"
        aria-expanded="{{ $open ? 'true' : 'false' }}"
        aria-controls="day-panel"
        @class([
            'hidden items-center gap-[11px] rounded-[16px] border p-3 text-left transition lg:flex',
            'lg:mt-[6px]' => $breakBefore !== null && $index === $breakBefore,
            'opacity-[.72] hover:opacity-100' => ! $open && ($row['done'] || $row['quiet']),
        ])
        @if ($attention) data-attention @endif
        style="order: {{ 2 + $index * 2 }}; {{ match (true) {
            $open => 'border-color: var(--fq-ticket-line); background: linear-gradient(160deg, var(--fq-gold-fill), var(--fq-panel) 72%)',
            $attention => 'border-color: var(--fq-line-2); background: var(--fq-panel); box-shadow: inset 4px 0 0 var(--fq-streak)',
            default => 'border-color: var(--fq-line); background: var(--fq-panel)',
        } }}"
    >
        <span
            class="grid h-[34px] w-[34px] flex-none place-items-center rounded-[11px] text-[16px]"
            style="background: var(--fq-sunk)"
            aria-hidden="true"
        >{{ $row['glyph'] }}</span>

        <span class="min-w-0 flex-1">
            <span
                class="block font-baloo text-[16px] font-extrabold"
                style="color: {{ $open ? 'var(--fq-gold)' : $row['accent'] }}"
            >{{ $row['label'] }}</span>
            <span class="block truncate text-[12.5px] text-fq-text-4">{{ $row['sub'] }}</span>
        </span>

        @if ($attention)
            <span
                class="flex-none rounded-full px-[8px] py-[3px] font-mono-fq text-[10px] whitespace-nowrap"
                style="background: var(--fq-streak); color: var(--fq-streak-ink)"
            >{{ $row['status'] }}</span>
        @else
            <span
                class="flex-none font-mono-fq text-[10px] whitespace-nowrap"
                style="color: {{ $open ? 'var(--fq-gold)' : $row['statusColor'] }}"
            >{{ $row['status'] }}</span>
        @endif

        <span class="flex-none text-[13px]" style="color: {{ $open ? 'var(--fq-gold)' : 'var(--fq-text-5)' }}" aria-hidden="true">
            {{ $open ? '▲' : '▼' }}
        </span>
    </button>
@endforeach
