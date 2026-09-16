{{-- Today in the house.

     The quiet half of this page, and the reason it is at the top of it rather
     than in the rail: the feelings card is the thing this app most wants read,
     and squeezed into a 290px column it gets skimmed. On a phone it is
     full-width, which is the right weight for it.

     Every privacy decision here is made upstream, in
     FeelingEntry::becauseVisibleTo() — `$row['because']` arrives already
     resolved, so this template has nothing it could leak even by accident. The
     feeling *word* is household-public and is never gated; only the reason has
     a door on it. --}}
@props([
    'house' => null,
    'gratitude' => ['lists' => [], 'withheld' => [], 'total' => 0],
    'dinner' => ['tonight' => null, 'tomorrow' => null],
    'viewer' => null,
])

@php $shared = count($gratitude['lists']); @endphp

<div class="flex flex-col gap-[9px] rounded-[18px] border border-fq-line bg-fq-panel p-3">
    <span class="font-mono-fq text-[9.5px] tracking-[0.2em] text-fq-text-4 uppercase">Today in the house</span>

    {{-- Dinner, and deliberately *above* the feelings gate below.

         That gate — "say how your day went first" — exists to protect the
         feelings: reading everybody else's answer is what answering buys you.
         Dinner is not one of those things. A kid must not have to file a
         feeling to find out what's for tea, and putting it behind the same
         door would turn a privacy rule into a toll booth.

         Drawn only when there is something to say. A standing "no dinner
         planned" line would be the app nagging a grown-up on the one screen
         the grown-ups are not the audience for. --}}
    @if ($dinner['tonight'] || $dinner['tomorrow'])
        @php $isParent = $viewer?->isParent() ?? false; @endphp

        <div class="flex flex-col gap-[3px] rounded-[13px] bg-fq-sunk p-[9px_11px]">
            @if ($dinner['tonight'])
                <div class="flex items-baseline gap-[7px]">
                    <span class="font-mono-fq text-[9px] tracking-[0.16em] uppercase" style="color: var(--fq-gold)">Tonight</span>
                    <span class="min-w-0 flex-1 font-baloo text-[15px] font-bold">{{ $dinner['tonight']->name }}</span>
                </div>

                @if ($dinner['tonight']->note)
                    <div class="text-[12.5px] text-fq-text-4">{{ $dinner['tonight']->note }}</div>
                @endif
            @endif

            {{-- Quieter than tonight's, because it is the answer to a question
                 nobody asked yet. --}}
            @if ($dinner['tomorrow'])
                <div class="flex items-baseline gap-[7px] {{ $dinner['tonight'] ? 'mt-[3px]' : '' }}">
                    <span class="font-mono-fq text-[9px] tracking-[0.16em] text-fq-text-5 uppercase">Tomorrow</span>
                    <span class="min-w-0 flex-1 text-[13.5px] text-fq-text-3">{{ $dinner['tomorrow']->name }}</span>
                </div>
            @endif

            {{-- Only a grown-up has anywhere to go from here. --}}
            @if ($isParent)
                <a href="{{ route('parent.meals') }}" wire:navigate class="mt-[2px] font-mono-fq text-[9px] tracking-[0.16em] uppercase underline" style="color: var(--fq-text-4)">Change the menu</a>
            @endif
        </div>
    @elseif ($viewer?->isParent())
        {{-- The one case where the empty state earns its place: a grown-up is
             the person who can fix it, and this is a screen they are on daily. --}}
        <a href="{{ route('parent.meals') }}" wire:navigate class="rounded-[13px] bg-fq-sunk p-[9px_11px] text-[13px] text-fq-text-4">
            No dinner set yet. <span class="font-semibold underline" style="color: var(--fq-gold)">Set the menu</span>
        </a>
    @endif

    @if ($house === null)
        {{-- The feelings card's own rule, kept rather than routed around:
             reading everybody else's answer is what answering buys you, and a
             second door into the house's feelings would quietly undo that. --}}
        <p class="text-[13.5px] text-fq-text-4">
            Say how your day went first and you'll see everyone else's.
            <a href="{{ route('kid.home') }}" wire:navigate class="font-semibold underline" style="color: var(--fq-cyan)">Today's card</a>
        </p>
    @else
        <div class="flex flex-col gap-2">
            @foreach ($house as $row)
                @php
                    $entry = $row['entry'];
                    $person = $row['profile'];
                @endphp

                @if ($entry)
                    <div class="flex items-center gap-[9px]">
                        <span
                            class="grid size-8 shrink-0 place-items-center rounded-full text-[15px]"
                            style="border: 1.5px solid {{ $entry->color() }}"
                        >{{ $entry->glyph() }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="font-baloo text-[14.5px] font-bold">
                                {{ $person->name }} &middot;
                                <span class="font-semibold" style="color: {{ $entry->color() }}">{{ $entry->label() }}</span>
                            </div>
                            @if ($row['because'])
                                <div class="truncate text-[12.5px] text-fq-text-4">{{ $row['because'] }}</div>
                            @elseif ($entry->hasBecause())
                                {{-- There is a reason and it isn't ours to read. The
                                     padlock is drawn rather than the line being left
                                     blank: an entry that hid the fact it had been kept
                                     back would make the lock look like it lost the text. --}}
                                <div class="text-[12.5px] text-fq-text-5">&#128274; kept the why to themselves</div>
                            @endif
                        </div>
                    </div>
                @else
                    {{-- Never omitted. An absent person is visibly absent, and an
                         absence is not a failure — it is somebody who hasn't got
                         to it yet, drawn as a dashed ring rather than a mark. --}}
                    <div class="flex items-center gap-[9px] opacity-60">
                        <span class="grid size-8 shrink-0 place-items-center rounded-full border-[1.5px] border-dashed border-fq-line-3 text-[14px] text-fq-text-5">?</span>
                        <div class="text-[13.5px] text-fq-text-4">{{ $person->name }} hasn't said yet</div>
                    </div>
                @endif
            @endforeach
        </div>
    @endif

</div>
