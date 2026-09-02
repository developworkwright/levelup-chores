{{-- Tonight's streak clock, in the header bank next to points and tickets.

     The strip in the streak section says the whole thing; this says the one
     number, from every page a kid is standing on. That is the half that was
     missing — the kids were never on Home when the question came up, they were
     on Quests picking something to do or in the shop spending what they'd
     earned. Tapping it lands on the streak section, where the strip explains
     itself.

     Three states, matching <x-streak-timer>:

       running    time left until bedtime, in the streak colour
       overtime   bedtime has gone. A moon and no number, deliberately: the day
                  is still winnable and the strip says so in words, but a tile
                  reading "6h" at half nine is the exact false comfort this was
                  built to remove.
       secured    a tick, because the day is done and there is nothing to read

     Colour rides on :class rather than :style for the reason the tiles beside
     it do: a morph rewriting the style attribute would clobber a binding, and
     this element re-renders on every Livewire round trip in the app.

     No wire:navigate on any branch, deliberately. The fragment is the whole
     point of the link — it lands on the streak section, not the top of Home —
     and a plain load is the one way to be sure the browser honours it. --}}
@props([
    'href',
    'closesAt' => null,
    'secured' => false,
    'overtime' => false,
    'compact' => false,
])

@php
    /*
     * `compact` is the kid header's size — and it is a *phone* size, not a
     * small size. The identity row has seven things in it and 390px to put
     * them in, which is the only reason any of this shrinks; on anything wider
     * the tile goes back to being readable across a room, which is what a
     * countdown pinned above every page is for.
     *
     * So compact carries `md:` twins of everything it shrinks, and the two
     * branches meet at the full size rather than diverging. Getting this wrong
     * the other way is what made the first landing of the one-row header look
     * like a phone screenshot pasted into a desktop page.
     *
     * A prop rather than two components: the three states below are the whole
     * substance of this thing, and a copy of them at another size is two
     * places for "past bedtime" to be got wrong.
     */
    $tile = $compact
        ? 'relative flex shrink-0 flex-col items-end rounded-[10px] border bg-fq-sunk px-[8px] py-[5px] transition md:rounded-[15px] md:px-3 md:py-[8px]'
        : 'relative flex h-[52px] w-[92px] shrink-0 flex-col items-end justify-center rounded-[15px] border bg-fq-sunk px-3 transition';

    $value = $compact ? 'font-baloo text-[14px] leading-none font-extrabold md:text-[19px]' : 'font-baloo text-[17px] leading-none font-extrabold';
    $glyph = $compact ? 'font-baloo text-[15px] leading-none font-extrabold md:text-[22px]' : 'font-baloo text-[24px] leading-none font-extrabold';
    $label = $compact ? 'mt-[2px] font-mono-fq text-[7px] whitespace-nowrap md:mt-[3px] md:text-[9px]' : 'font-mono-fq text-[9px]';
@endphp

@if ($secured)
    <a href="{{ $href }}" title="Today's streak day is safe" class="{{ $tile }} border-fq-lime">
        {{-- Nothing left to count, so the tick takes the space the number would
             have had. It is the whole message. --}}
        <span class="{{ $glyph }} text-fq-lime">&#10003;</span>
        <span class="{{ $label }} text-fq-lime">{{ $compact ? "SAFE" : "STREAK SAFE" }}</span>
    </a>
@elseif ($overtime || ! $closesAt)
    <a href="{{ $href }}" title="Past bedtime — the day resets soon" class="{{ $tile }} border-fq-danger">
        <span class="{{ $glyph }} text-fq-danger">&#9790;</span>
        <span class="{{ $label }} text-fq-danger">PAST BED</span>
    </a>
@else
    <a
        href="{{ $href }}"
        title="Time left before bedtime to keep your streak"
        x-data="{
            closesAt: {{ $closesAt->getTimestampMs() }},
            remaining: '',
            timer: null,
            tick() {
                const left = this.closesAt - Date.now();

                // Bedtime landing is a real change of state — the tile stops
                // counting and the strip changes what it says — so this is the
                // one round trip it costs, rather than a poll all evening.
                if (left <= 0) {
                    clearInterval(this.timer);
                    this.remaining = '0:00';
                    $wire.$refresh();

                    return;
                }

                const total = Math.floor(left / 1000);
                const [hours, minutes, seconds] = [Math.floor(total / 3600), Math.floor(total / 60) % 60, total % 60];

                this.remaining = hours > 0
                    ? `${hours}h ${minutes}m`
                    : `${minutes}:${String(seconds).padStart(2, '0')}`;
            },
        }"
        x-init="tick(); timer = setInterval(() => tick(), 1000)"
        x-on:destroy="clearInterval(timer)"
        class="{{ $tile }} border-fq-streak"
    >
        <span class="{{ $value }} text-fq-streak" x-text="remaining"></span>
        <span class="{{ $label }} text-fq-text-4">TILL BED</span>
    </a>
@endif
