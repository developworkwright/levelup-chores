{{-- Every room this reader can open: the group rooms, then everybody else in
     the house.

     Drawn twice on the same page — down the laptop's left column, and inside
     the phone's room picker — which is why it is a component rather than a
     block of the feed. The two are the same list, and the order of the names in
     it is load-bearing (a six-year-old finds his brother by where the row is,
     not by reading it), so a second copy would be the first thing to drift.

     `prefix` keeps the two copies' `wire:key`s apart. Two rows keyed
     `room-5` in one Livewire component is one row as far as morphing is
     concerned, and the loser stops responding. --}}
@props(['rooms', 'people', 'room' => null, 'tz' => 'UTC', 'prefix' => 'rail'])

<div {{ $attributes->class('flex flex-col gap-3') }}>
    <div class="flex flex-col gap-[6px] max-lg:gap-[7px]">
        <span class="pl-[2px] font-mono-fq text-[9.5px] tracking-[0.2em] text-fq-text-4 uppercase">Rooms</span>

        @foreach ($rooms as $entry)
            <x-feed.room-row
                :entry="$entry"
                :selected="$room && $room->id === $entry['room']->id"
                :row-key="$prefix.'-room-'.$entry['room']->id"
                :tz="$tz"
            />
        @endforeach
    </div>

    {{-- Everybody else in the house, already listed. There is no "Start one"
         and no picker: a family of five does not need to go looking for who it
         can talk to, and a six-year-old should find his brother's name where it
         always is. A conversation's room is made the first time somebody taps a
         name — see peopleFor(). --}}
    <div class="flex flex-col gap-[6px] max-lg:gap-[7px]">
        <span class="pl-[2px] font-mono-fq text-[9.5px] tracking-[0.2em] text-fq-text-4 uppercase">Just you two</span>

        @foreach ($people as $person)
            <x-feed.room-row
                :entry="$person['entry']"
                :selected="$room && $person['entry']['room'] && $room->id === $person['entry']['room']->id"
                :click="$person['started'] ? null : 'startWith('.$person['profile']->id.')'"
                :row-key="$prefix.'-person-'.$person['profile']->id"
                :tz="$tz"
            />
        @endforeach
    </div>
</div>
