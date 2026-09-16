{{-- The phone's room header: which room you are in, who can read it, and a way
     into any of the others.

     The phone used to open on the room list and make you choose before it would
     show you anything. Almost every time the answer was Everyone, so the app
     was charging a screen and a tap for a question it could have answered
     itself. It opens in the room now and the list folds up into this control.

     What the list was doing that a room cannot do for itself is say that
     something is waiting somewhere else, so that comes with it: the count on
     this button is every unread message outside the open room. Without it,
     folding the list away would have quietly traded the feed's whole reason for
     being on Home.

     The who-can-read line is inside the button rather than under it. It is
     drawn on every screen, always — this control is the room's header on a
     phone, and a header without that line is a room somebody could post in
     before knowing who hears it. --}}
@props(['rooms', 'people', 'room', 'roomName', 'audience', 'monogram' => null, 'accent' => 'var(--fq-text-3)', 'elsewhere' => 0, 'tz' => 'UTC', 'always' => false])

{{-- `picking`, never `open`. Livewire 4 resolves a wire:click against the
     Alpine scope around it before the component, and the rows in here call the
     feed's open(id) — so a flag named `open` got called instead, threw, and
     every room you picked left you in Everyone. --}}
<div
    @class(['relative', 'lg:hidden' => ! $always])
    x-data="{ picking: false }"
    x-on:keydown.escape.window="picking = false"
>
    <button
        type="button"
        x-on:click="picking = ! picking"
        x-on:click.outside="picking = false"
        :aria-expanded="picking"
        class="flex w-full items-center gap-[11px] rounded-[15px] border border-fq-line bg-fq-panel p-[11px] text-left"
    >
        {{-- The same glyph-or-monogram the row in the list carries, so the room
             you are in looks like the row you tapped to get here. --}}
        @if ($room->kind->glyph())
            <span
                class="grid size-10 shrink-0 place-items-center rounded-[13px] text-[18px]"
                style="background: var(--fq-panel-alt)"
            >{{ $room->kind->glyph() }}</span>
        @else
            <span
                class="grid size-10 shrink-0 place-items-center rounded-[13px] border border-fq-line-3 font-baloo text-[15px] font-extrabold"
                style="background: var(--fq-line); color: {{ $accent }}"
            >{{ $monogram }}</span>
        @endif

        <span class="min-w-0 flex-1">
            <span class="block truncate font-baloo text-[17px] font-extrabold">{{ $roomName }}</span>

            {{-- Never truncated, never a tooltip. Nobody learns their audience
                 after posting. --}}
            <span class="block font-mono-fq text-[9px] tracking-[0.1em] text-fq-text-4 uppercase">{{ $audience }}</span>
        </span>

        @if ($elsewhere > 0)
            <span
                class="shrink-0 rounded-full px-[7px] py-[3px] font-mono-fq text-[10.5px]"
                style="background: var(--fq-streak); color: var(--fq-streak-ink)"
            >{{ $elsewhere }}</span>
        @endif

        <span class="shrink-0 text-[13px] text-fq-text-4" aria-hidden="true">
            <i class="fa-solid fa-chevron-down transition" :class="picking ? 'rotate-180' : ''"></i>
        </span>

        <span class="sr-only">Switch rooms{{ $elsewhere > 0 ? ' — '.$elsewhere.' unread elsewhere' : '' }}</span>
    </button>

    {{-- `x-show`, not `<template x-if>`: the rows inside are Livewire's, and
         x-if clones them in as siblings of a template Livewire still morphs
         against — which repaints rows without their handlers. The panel is
         capped and scrolls, because a house with a few conversations going is
         taller than a phone.

         Closing on the way out rather than on the round trip coming back: the
         tap has already chosen a room, and a menu that stays open over the
         answer is a menu you have to dismiss twice. --}}
    <div
        x-show="picking"
        x-cloak
        x-on:click="picking = false"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 -translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        class="absolute inset-x-0 top-[calc(100%+6px)] z-30 max-h-[62vh] overflow-y-auto rounded-[17px] border border-fq-line-2 bg-fq-bg p-[9px] shadow-[0_18px_40px_rgba(0,0,0,0.45)]"
    >
        <x-feed.room-list
            :rooms="$rooms"
            :people="$people"
            :room="$room"
            :tz="$tz"
            prefix="picker"
        />
    </div>
</div>
