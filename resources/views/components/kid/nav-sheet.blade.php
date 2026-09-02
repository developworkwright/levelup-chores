{{-- The ☰ button on the rail, and the sheet it opens.

     Both live in one component because they are one control: the button is
     only ever a way of showing the panel, and the panel is fixed to the
     viewport, so nothing else in the rail should have to know it exists.

     `display: contents` on the wrapper so the button stays a flex item of the
     rail rather than being wrapped in a box of its own — the two fixed layers
     below it are taken out of flow anyway.

     The sheet is the answer to "where is everything?". It lists every page in
     the app flat, including the four already on the rail, so a kid never has
     to work out which pages live where. The headings are editorial: they make
     eleven rows scannable and carry no routing and no state. --}}
@props(['pages', 'active', 'counts', 'groups', 'grid', 'journalTickets' => 0, 'count' => 0])

<div
    class="contents"
    x-data="{ open: false }"
    x-on:keydown.escape.window="open = false"
    {{-- The page behind must not scroll under the sheet, and the lock has to
         come off however the sheet goes away — including a navigation, which
         tears this element down without ever setting `open` back to false. --}}
    x-effect="document.body.style.overflow = open ? 'hidden' : ''"
    x-on:livewire:navigating.window="open = false; document.body.style.overflow = ''"
>
    <button
        type="button"
        x-on:click="open = true"
        :aria-expanded="open"
        aria-label="All pages"
        title="All pages"
        class="relative grid w-[42px] shrink-0 place-items-center rounded-[11px] border border-fq-line-2 bg-fq-panel-alt transition hover:border-fq-line-focus"
    >
        <i class="fa-solid fa-bars text-[14px] text-fq-cyan"></i>

        @if ($count > 0)
            <span
                class="absolute top-[4px] right-[5px] inline-flex h-[14px] min-w-[14px] items-center justify-center rounded-full px-[3px] font-mono-fq text-[8.5px] leading-none font-bold"
                style="background: var(--fq-count); color: var(--fq-count-ink)"
            >{{ $count }}</span>
        @endif
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-on:click="open = false"
        class="fixed inset-0 z-[54] bg-black/60"
        aria-hidden="true"
    ></div>

    <div
        x-show="open"
        x-cloak
        role="dialog"
        aria-modal="true"
        aria-label="Where to?"
        data-fq-sheet
        {{-- 240ms ease-out up from the rail, per the handoff. Instant back
             down: a kid who has changed their mind wants the page, not the
             animation. --}}
        x-transition:enter="transition ease-out duration-[240ms]"
        x-transition:enter-start="translate-y-full"
        x-transition:enter-end="translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="translate-y-0"
        x-transition:leave-end="translate-y-full"
        class="fixed inset-x-0 bottom-0 z-[55] mx-auto flex max-h-[88vh] w-full max-w-[520px] flex-col gap-[9px] overflow-y-auto rounded-t-[24px] border border-fq-nav-line bg-fq-bg px-[10px] pt-[11px] pb-[18px]"
    >
        <div class="flex items-center gap-[9px] px-[3px] pt-[2px] pb-[6px]">
            <span class="flex-1 font-baloo text-[19px] font-extrabold">Where to?</span>
            {{-- The two noise settings ride in the sheet's header rather than
                 as rows of their own: they are settings, not destinations, and
                 every row below is a place to go.

                 The bell joined the mute here when the console header became
                 one compact row — see push-toggle. They belong together
                 anyway: both answer "how much is this app allowed to bother
                 me", one while a kid is looking at it and one while they are
                 not. --}}
            <x-sound-toggle small />
            <livewire:push-toggle audience="kid" />
            <button
                type="button"
                x-on:click="open = false"
                aria-label="Close"
                class="grid h-[32px] w-[32px] place-items-center rounded-[10px] border border-fq-line bg-fq-sunk text-[14px] text-fq-text-4 transition hover:text-fq-text"
            ><i class="fa-solid fa-xmark"></i></button>
        </div>

        @foreach ($groups as $heading => $keys)
            <span class="px-[3px] {{ $loop->first ? '' : 'pt-[6px]' }} font-mono-fq text-[8px] tracking-[0.18em] text-fq-text-5 uppercase">{{ $heading }}</span>

            <div class="flex flex-col gap-[5px]">
                @foreach ($keys as $key)
                    <x-kid.nav-sheet-row
                        :page="$pages[$key]"
                        :current="$active === $key"
                        :count="$counts[$key] ?? 0"
                        :tickets="$key === 'journal' ? $journalTickets : 0"
                    />
                @endforeach

                @if ($loop->last)
                    {{-- The pages nobody opens twice in a day, two to a row —
                         a block that reads as a footnote rather than as four
                         more equal destinations. --}}
                    <div class="grid grid-cols-2 gap-[5px]">
                        @foreach ($grid as $key)
                            <x-kid.nav-sheet-row
                                :page="$pages[$key]"
                                :current="$active === $key"
                                :count="$counts[$key] ?? 0"
                                compact
                            />
                        @endforeach

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button
                                type="submit"
                                class="flex w-full items-center gap-[9px] rounded-[13px] border border-fq-line px-[12px] py-[14px] text-left text-[13.5px] text-fq-text-3 transition hover:border-fq-line-focus"
                            >
                                <i class="fa-solid fa-power-off text-[14px] text-fq-text-4"></i>
                                Exit
                            </button>
                        </form>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
