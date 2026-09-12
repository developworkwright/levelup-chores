{{-- Grateful today.

     The other half of the quiet column, and the one that is *meant* to be read
     by everybody: a grateful line is almost always about somebody else in this
     house, and the point of writing it is that they hear it. Shared by default
     with a per-entry opt-out on the quest card — see the `shared` column's
     migration for why that is the opposite default to a feeling's.

     Drawn at every size, directly under Today in the house in the left column.
     It used to be laptop-only, with a one-line summary on the phone that opened
     the lists in a pop-up; once both cards shared a column that summary was a
     second copy of the card sitting right above it, so it went. --}}
@props(['gratitude' => ['lists' => [], 'withheld' => [], 'total' => 0], 'roster' => 0])

<div
    class="flex flex-col gap-[9px] rounded-[18px] p-[13px]"
    style="border: 1px solid color-mix(in srgb, var(--fq-magenta) 38%, transparent); background: var(--fq-wash-violet)"
>
    <div class="flex items-center justify-between gap-2">
        <span class="font-mono-fq text-[9.5px] tracking-[0.2em] uppercase" style="color: var(--fq-magenta)">Grateful today</span>
        <span class="font-mono-fq text-[9.5px] text-fq-text-5">{{ $gratitude['total'] }} OF {{ $roster }}</span>
    </div>

    <div class="flex flex-col gap-[7px] text-[13.5px] leading-[1.4]">
        @forelse ($gratitude['lists'] as $list)
            <span>
                <strong class="font-baloo font-bold">{{ $list['profile']->name }}</strong>
                &mdash; {{ implode(', ', $list['items']) }}
            </span>
        @empty
            <span class="text-fq-text-5">Nobody has written one yet today.</span>
        @endforelse

        {{-- Drawn rather than left out. Somebody who opted out did a thing, and
             omitting them silently makes it look like they wrote nothing. --}}
        @foreach ($gratitude['withheld'] as $person)
            <span class="text-fq-text-5">{{ $person->name }} kept theirs private</span>
        @endforeach
    </div>

    {{-- The handoff put a "Read them all" button here. It is gone: this card
         already *is* all of them, so the button opened a panel showing exactly
         what was underneath it. The phone keeps its version of the opener,
         because there the card really is collapsed to one line. --}}
</div>
