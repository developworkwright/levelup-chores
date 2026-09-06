{{-- The header's music control: a bar that plays, and a panel that is a whole
     player. The same control in both consoles — the library is the house's, and
     the playlists it offers are whoever is signed in.

     Built from handoff/design_handoff_music_page. The panel used to be a picker
     — one 288px scroller holding a dozen playlists above two hundred songs,
     where the only way into a list was to start it. It is now the design's
     player: a rail of sources down one side, the songs of whichever you are
     looking at beside it, and the transport along the bottom.

     The one thing it deliberately does not do is build a playlist. That is a
     page (`kid.music` / `parent.music`), it wants the whole library laid out,
     and it does not fit in a panel on a phone — so `+ New playlist` in the rail
     is a link to it rather than a form.

     Everything it knows lives in the `music` Alpine store — see
     resources/js/music.js for why the audio is deliberately not an element on
     the page, and for the *source* that lets an album queue exactly like a
     playlist. --}}
@props(['profile' => null, 'compact' => false])

@php
    /*
     * `compact` is the kid header's size, where this control sits in the
     * identity row beside the tiles. It is a *phone* size with an `md:` twin,
     * not a small size: 34px on a 390px screen, 46px on anything wider. The
     * parent console has room for the 52px original at every width.
     *
     * Sizes only. Everything below behaves identically at either size, which
     * is why this is six class strings rather than a second component.
     */
    $bar = $compact ? "h-[34px] rounded-[11px] border-fq-line md:h-[46px] md:rounded-[15px] md:border-fq-line-2" : "h-[52px] rounded-[15px] border-fq-line-2";
    $play = $compact ? "w-[34px] text-[13px] md:w-[46px] md:text-[17px]" : "w-[52px] text-[18px]";
    $tab = $compact ? "w-[20px] text-[9px] md:w-[26px] md:text-[11px]" : "w-[30px] text-[11px]";
    // The dropdown hangs off the bottom of the bar, so it moves with it.
    $drop = $compact ? "sm:top-[40px] md:top-[52px]" : "sm:top-[58px]";
    $dot = $compact ? "top-[5px] right-[3px] md:top-[8px] md:right-[4px]" : "top-[9px] right-[5px]";

    /*
     * Skip, and the one place this control breaks its own sizing rule.
     *
     * On the compact bar every other segment shrinks to 34px on a phone, and a
     * 34x34 skip sitting against the button that stops the music is under the
     * 44px minimum and next to the last thing you want to hit by accident. So
     * on a phone it is not drawn at all: the header there is for silence, and
     * the panel below carries a full-sized skip for steering.
     */
    $skip = $compact ? "hidden w-[46px] md:flex" : "flex w-[44px]";

    $music = app(App\Services\MusicService::class);

    // Only what the player needs. The library also carries each song's storage
    // path, size and age, which are the music admin screen's business and have
    // no reason to be in markup a kid's browser receives.
    $tracks = array_map(
        fn (array $track): array => [
            'id' => $track['id'],
            'title' => $track['title'],
            'album' => $track['album'],
            'url' => $track['url'],
        ],
        $music->tracks(),
    );

    // One number instead of a per-song date: the marker says "there is new
    // music", not which songs, so a high-water mark is the whole question.
    $latestAt = $music->latestChangeAt();

    // This profile's own lists, ids only — the catalogue above already carries
    // every title and url, so sending them twice would be the same payload
    // again on every page. Never anybody else's: a playlist belongs to whoever
    // made it, and the header only ever draws for the profile signed in.
    $playlists = $profile === null
        ? []
        : app(App\Services\PlaylistService::class)->payloadFor($profile);

    $buildHref = $profile === null
        ? null
        : ($profile->isParent() ? route('parent.music') : route('kid.music'));

    // One string for a rail row, because it is drawn for playlists, for the
    // whole library and for each album — three lists that must not drift into
    // three different-looking controls.
    $railRow = 'flex min-h-[44px] shrink-0 items-center gap-2 rounded-[10px] px-2 text-left text-[13.5px] transition hover:text-fq-text';
@endphp

@if ($tracks !== [])
    <div
        x-data="fqMusic(@js($tracks), {{ $latestAt }}, @js($playlists))"
        {{-- On the wrapper so a tap on either button counts as inside;
             hung off the panel it would race its own opening. --}}
        @click.outside="open = false"
        class="relative shrink-0"
    >
        <div class="flex items-stretch overflow-hidden border bg-fq-sunk {{ $bar }}">
            <button
                type="button"
                @click="music.toggle()"
                :title="label"
                :aria-label="label"
                :aria-pressed="music.playing"
                {{-- Dimmed rather than recoloured when off, the same way the
                     bell beside it reads, so the row never reflows as state
                     settles after load. --}}
                :style="music.playing ? '' : 'opacity:0.35'"
                :class="music.blocked ? 'text-fq-gold' : (music.playing ? 'text-fq-lime' : 'text-fq-text-4')"
                class="flex items-center justify-center transition hover:text-fq-text {{ $play }}"
            >&#9835;</button>

            {{-- Drawn only while there is something to skip to, and not greyed
                 out the rest of the time: a single song on repeat has no next
                 song, and a lit button that does nothing is worse than one that
                 is not there. The bar falls back to the two segments it has
                 always had. --}}
            <button
                type="button"
                x-show="music.hasQueue"
                x-cloak
                @click="music.advance()"
                aria-label="Next song"
                class="items-center justify-center border-l border-fq-line-2 text-[14px] text-fq-text-4 transition hover:text-fq-text {{ $skip }}"
            >&#9654;&#9654;</button>

            {{-- `relative` so the marker can sit on its corner. --}}
            <button
                type="button"
                @click="togglePanel()"
                :aria-expanded="open"
                :title="music.hasNew ? 'New music — open the player' : 'Open the player'"
                :aria-label="music.hasNew ? 'New music — open the player' : 'Open the player'"
                class="relative flex items-center justify-center border-l border-fq-line-2 text-fq-text-4 transition hover:text-fq-text {{ $tab }}"
                :class="open ? 'text-fq-text' : ''"
            >
                &#9662;

                {{-- The whole of how a kid finds out. Deliberately a dot and
                     not a count: the marker's job is "look in here", and a
                     number invites counting rather than listening. It goes the
                     moment the panel opens. --}}
                <span
                    x-show="music.hasNew"
                    x-cloak
                    class="pointer-events-none absolute h-[7px] w-[7px] rounded-full {{ $dot }}"
                    style="background: var(--fq-lime); box-shadow: 0 0 0 2px var(--fq-sunk)"
                ></span>
            </button>
        </div>

        {{-- A sheet on a phone, a dropdown from `sm` up.

             It used to be a dropdown at every size, anchored to the right edge
             of the button it hangs from — which is fine until the header wraps
             and puts that button near the left of the row. Then the panel
             extends leftwards from it and most of it is off the screen, which
             is exactly what happened. A capped max-width does not save it: the
             width was never the problem, the anchor was.

             Pinned to the bottom of the viewport there is no anchor to get
             wrong, it is the pattern the kid nav already uses on a phone, and
             the songs end up under the thumb rather than up by the header. --}}
        <div
            x-show="open"
            x-cloak
            {{-- `sm:left-auto` with `sm:right-0`, never `sm:inset-x-auto`:
                 an absolutely positioned box with neither edge pinned falls
                 back to its *static* position, so the panel started at the
                 button and ran off the right of the screen. The two properties
                 are set separately here so nothing depends on which of two
                 same-specificity utilities Tailwind happens to emit last.

                 Wider than the 288px it was: the player is a rail and a track
                 list side by side, and at 288px one of them is a column of
                 truncated single words. Capped against the viewport so the
                 dropdown can never be wider than the screen it hangs in. --}}
            class="fixed inset-x-0 bottom-0 z-40 flex max-h-[78vh] flex-col rounded-t-[18px] border-t border-fq-line-2 bg-fq-panel p-3 pb-[max(12px,env(safe-area-inset-bottom))] shadow-lg sm:absolute {{ $drop }} sm:right-0 sm:bottom-auto sm:left-auto sm:max-h-[76vh] sm:w-[560px] sm:max-w-[calc(100vw-24px)] sm:rounded-[16px] sm:border sm:pb-3"
        >
            <div class="flex min-h-0 flex-1 flex-col gap-3 sm:flex-row">
                {{-- The sources rail.

                     A column from `sm`, and below it the snap-scrolling strip
                     the arcade rail already uses: a fifth playlist lengthens
                     the strip instead of shrinking every row towards
                     unreadable. It bleeds to the panel's edges on a phone for
                     the same reason the arcade's does — a strip that stops at
                     the gutter does not read as something that scrolls. --}}
                <div class="flex shrink-0 flex-col gap-[10px] sm:w-[168px] sm:min-h-0 sm:overflow-y-auto sm:border-r sm:border-fq-line sm:pr-3">
                    <template x-if="music.playlists.length">
                        <div class="flex flex-col gap-[6px]">
                            <span class="font-mono-fq text-[9.5px] tracking-[0.14em] text-fq-text-4 uppercase">Your playlists</span>

                            <div class="no-scrollbar -mx-3 flex snap-x snap-mandatory gap-[3px] overflow-x-auto px-3 sm:mx-0 sm:snap-none sm:flex-col sm:overflow-visible sm:px-0">
                                <template x-for="list in music.playlists" :key="list.id">
                                    <button
                                        type="button"
                                        {{-- Looking, not playing. The rail
                                             changes what is drawn and never
                                             what is heard — only Play all, a
                                             track row and the transport reach
                                             the store. --}}
                                        @click="look('playlist', list.id)"
                                        :title="list.name"
                                        :aria-current="viewKind === 'playlist' && viewRef === list.id"
                                        class="{{ $railRow }} w-[150px] snap-start sm:w-full"
                                        :class="viewKind === 'playlist' && viewRef === list.id
                                            ? 'bg-fq-sunk font-semibold text-fq-text'
                                            : 'text-fq-text-3'"
                                    >
                                        {{-- The caret is about what is
                                             *playing*, not what is open: a
                                             marker that lit on selection would
                                             be the panel claiming to have
                                             started something it has not. --}}
                                        <span
                                            class="w-[11px] shrink-0 text-[10px]"
                                            :class="music.playlistId === list.id ? 'text-fq-lime' : 'text-transparent'"
                                        >&#9654;</span>

                                        <span class="min-w-0 flex-1 truncate" x-text="list.name"></span>

                                        <span class="shrink-0 font-mono-fq text-[10px] text-fq-text-4" x-text="countIn(list)"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>

                    <div class="flex flex-col gap-[6px]">
                        <span class="font-mono-fq text-[9.5px] tracking-[0.14em] text-fq-text-4 uppercase">The house</span>

                        <div class="no-scrollbar -mx-3 flex snap-x snap-mandatory gap-[3px] overflow-x-auto px-3 sm:mx-0 sm:snap-none sm:flex-col sm:overflow-visible sm:px-0">
                            <button
                                type="button"
                                @click="look('all')"
                                :aria-current="viewKind === 'all'"
                                class="{{ $railRow }} w-[150px] snap-start sm:w-full"
                                :class="viewKind === 'all'
                                    ? 'bg-fq-sunk font-semibold text-fq-text'
                                    : 'text-fq-text-3'"
                            >
                                <span
                                    class="w-[11px] shrink-0 text-[10px]"
                                    :class="music.source && music.source.kind === 'all' ? 'text-fq-lime' : 'text-transparent'"
                                >&#9654;</span>

                                <span class="min-w-0 flex-1 truncate">All songs</span>

                                <span class="shrink-0 font-mono-fq text-[10px] text-fq-text-4" x-text="music.tracks.length"></span>
                            </button>

                            <template x-for="album in albums" :key="album">
                                <button
                                    type="button"
                                    @click="look('album', album)"
                                    :title="album"
                                    :aria-current="viewKind === 'album' && viewRef === album"
                                    class="{{ $railRow }} w-[150px] snap-start sm:w-full"
                                    :class="viewKind === 'album' && viewRef === album
                                        ? 'bg-fq-sunk font-semibold text-fq-text'
                                        : 'text-fq-text-3'"
                                >
                                    <span
                                        class="w-[11px] shrink-0 text-[10px]"
                                        :class="music.source && music.source.kind === 'album' && music.source.ref === album ? 'text-fq-lime' : 'text-transparent'"
                                    >&#9654;</span>

                                    <span class="min-w-0 flex-1 truncate" x-text="album"></span>

                                    <span class="shrink-0 font-mono-fq text-[10px] text-fq-text-4" x-text="songsIn(album).length"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    @if ($buildHref !== null)
                        {{-- A link, not a form. Building a list wants the whole
                             library laid out in front of you and a way to put
                             songs in it one tap at a time, which is a page —
                             and a worse version of that page squeezed in here
                             would be the panel pretending to be one. --}}
                        <a
                            href="{{ $buildHref }}"
                            wire:navigate
                            @click="open = false"
                            class="flex min-h-[44px] shrink-0 items-center justify-center gap-[6px] rounded-[12px] border border-fq-line-2 text-[12.5px] whitespace-nowrap text-fq-text-3 transition hover:border-fq-line-4 hover:text-fq-text sm:mt-auto"
                        >+ New playlist</a>
                    @endif
                </div>

                {{-- What is being looked at, and everything in it. --}}
                <div class="flex min-h-0 flex-1 flex-col gap-2">
                    <div class="flex shrink-0 flex-wrap items-end justify-between gap-3">
                        <div class="min-w-0">
                            <span class="block font-mono-fq text-[9.5px] tracking-[0.14em] text-fq-text-4 uppercase" x-text="viewKindLabel"></span>
                            <span class="mt-[3px] block truncate font-baloo text-[22px] leading-[1.1] font-extrabold" x-text="viewName"></span>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            {{-- Filled, and not one pill of two. It is what a
                                 six-year-old opened the panel for. --}}
                            <button
                                type="button"
                                @click="music.playFrom(viewing)"
                                :disabled="! viewTracks.length"
                                class="inline-flex min-h-[40px] items-center gap-[6px] rounded-[12px] px-[14px] font-baloo text-[13.5px] font-extrabold whitespace-nowrap transition hover:brightness-110 disabled:opacity-40"
                                style="background: var(--fq-fill-gold); color: var(--fq-ink)"
                            >&#9654; Play all</button>

                            <button
                                type="button"
                                @click="music.toggleShuffle()"
                                :aria-pressed="music.shuffle"
                                aria-label="Shuffle this list"
                                class="inline-flex min-h-[40px] items-center gap-[6px] rounded-[12px] border px-[12px] text-[12.5px] whitespace-nowrap transition"
                                :class="music.shuffle
                                    ? 'border-fq-lime font-bold text-fq-lime'
                                    : 'border-fq-line-2 text-fq-text-4 hover:text-fq-text'"
                                {{-- The lit ground is a style rather than a
                                     class: `--fq-tab-active` is a token and not
                                     one of the theme colours Tailwind builds
                                     utilities from. --}}
                                :style="music.shuffle ? 'background: var(--fq-tab-active)' : ''"
                            >&#8646; Shuffle</button>
                        </div>
                    </div>

                    {{-- The songs. This is the part that scrolls: a soundtrack
                         is a hundred of them and the transport below must stay
                         where a thumb left it. --}}
                    <div class="-mr-1 flex min-h-0 flex-1 flex-col gap-[2px] overflow-y-auto border-t border-fq-line pt-2 pr-1">
                        <template x-for="(track, index) in viewTracks" :key="track.id">
                            <div
                                class="flex min-h-[44px] shrink-0 items-center gap-[10px] rounded-[10px] px-2 text-[13.5px]"
                                :class="music.trackId === track.id
                                    ? 'bg-fq-sunk font-semibold text-fq-text'
                                    : 'text-fq-text-3'"
                            >
                                <button
                                    type="button"
                                    {{-- Inside the queue that is on this is a
                                         jump and the list carries on around it;
                                         outside it, a plain choice, which ends
                                         the list. preview() draws that line. --}}
                                    @click="music.preview(track.id)"
                                    :title="track.title"
                                    class="flex min-h-[44px] min-w-0 flex-1 items-center gap-[10px] text-left"
                                >
                                    {{-- The number, or a ♪ on the song that is
                                         on. One column doing both, so nothing
                                         shifts sideways as the music moves down
                                         the list. --}}
                                    <span
                                        class="w-[20px] shrink-0 font-mono-fq text-[10.5px]"
                                        :class="music.trackId === track.id ? 'text-fq-lime' : 'text-fq-text-5'"
                                        x-text="music.trackId === track.id ? '♫' : String(index + 1)"
                                    ></span>

                                    {{-- Truncated rather than wrapped. A
                                         soundtrack's filenames carry the artist
                                         and the album in front of the actual
                                         name, so a wrapping row turns a
                                         hundred-song list into a wall. The full
                                         name is on hover and in the title. --}}
                                    <span class="truncate" x-text="track.title"></span>
                                </button>

                                {{-- On every row, not only the one playing:
                                     "that one again" is the thing the house
                                     actually does with this panel, and a
                                     control that appears under your finger is
                                     one nobody finds. --}}
                                <button
                                    type="button"
                                    @click="music.repeatFrom(track.id)"
                                    :aria-pressed="music.repeatOne && music.trackId === track.id"
                                    :aria-label="'Repeat ' + track.title + ' over and over'"
                                    class="grid min-h-[44px] w-[32px] shrink-0 place-items-center text-[13px] transition hover:text-fq-lime"
                                    :class="music.repeatOne && music.trackId === track.id ? 'text-fq-lime' : 'text-fq-text-6'"
                                >&#8635;</button>
                            </div>
                        </template>

                        <p x-show="! viewTracks.length" x-cloak class="px-2 py-3 text-[13px] text-fq-text-5">
                            Nothing in here yet — put some songs in it on the music page.
                        </p>
                    </div>
                </div>
            </div>

            {{-- The transport. Everything above is about what is on screen;
                 this is the only part about what is playing, which is why it
                 sits under both columns and never scrolls away. --}}
            <div class="mt-3 flex shrink-0 flex-wrap items-center gap-3 border-t border-fq-line pt-3">
                <div class="flex shrink-0 items-center gap-2">
                    {{-- Cut on a phone: skip is in two places, and going
                         backwards is rare enough to be the row itself. --}}
                    <button
                        type="button"
                        @click="music.back()"
                        aria-label="Back a song"
                        class="hidden h-[44px] w-[44px] place-items-center rounded-[13px] border border-fq-line-2 text-[12px] text-fq-text-4 transition hover:border-fq-line-4 hover:text-fq-text sm:grid"
                    >&#9664;&#9664;</button>

                    <button
                        type="button"
                        @click="music.toggle()"
                        :aria-pressed="music.playing"
                        :aria-label="music.playing ? 'Pause' : 'Play'"
                        class="grid h-[48px] w-[48px] shrink-0 place-items-center rounded-[15px] text-[15px] transition hover:brightness-110"
                        style="background: var(--fq-fill-gold); color: var(--fq-ink)"
                        x-text="music.playing ? '❘❘' : '▶'"
                    ></button>

                    <button
                        type="button"
                        x-show="music.hasQueue"
                        x-cloak
                        @click="music.advance()"
                        aria-label="Next song"
                        class="grid h-[44px] w-[44px] place-items-center rounded-[13px] border border-fq-line-2 text-[12px] text-fq-text-4 transition hover:border-fq-line-4 hover:text-fq-text"
                    >&#9654;&#9654;</button>
                </div>

                <div class="min-w-0 flex-1 basis-[180px]">
                    <div class="flex min-w-0 items-baseline gap-2">
                        <span
                            class="truncate text-[13.5px] font-semibold text-fq-text"
                            x-text="music.current() ? music.current().title : 'Nothing on'"
                        ></span>

                        {{-- Where it is coming from and how far through — the
                             two questions the old picker could not answer about
                             the list it was playing. --}}
                        <span
                            class="shrink-0 font-mono-fq text-[9.5px] whitespace-nowrap text-fq-text-4"
                            x-text="[
                                music.scopeLabel,
                                music.hasQueue ? music.queueAt + '/' + music.queue().length : '',
                                music.repeatOne ? '↻1' : '',
                            ].filter(Boolean).join(' · ')"
                        ></span>
                    </div>

                    <div class="mt-[6px] flex items-center gap-2">
                        <input
                            type="range"
                            min="0"
                            {{-- Never a max of zero: with both ends the same the
                                 thumb has nowhere to sit and browsers disagree
                                 about where to park it. --}}
                            :max="music.seekable ? music.duration : 1"
                            step="1"
                            :value="music.elapsed"
                            :disabled="! music.seekable"
                            {{-- Two events, and the split matters. `input` fires
                                 all the way through the drag and only moves the
                                 clock; `change` fires on release and is what
                                 actually moves the song, so a kid crossing a
                                 four-minute track does not make the browser
                                 start fetching at forty places on the way. --}}
                            @input="music.scrubTo($event.target.value)"
                            @change="music.seek($event.target.value)"
                            aria-label="Seek through the song"
                            :aria-valuetext="music.clock(music.elapsed) + ' of ' + music.clock(music.duration)"
                            class="h-[5px] min-w-0 flex-1 accent-fq-lime disabled:opacity-40"
                        >

                        <span
                            class="shrink-0 font-mono-fq text-[10px] whitespace-nowrap text-fq-text-4"
                            x-text="music.clock(music.elapsed) + ' / ' + (music.seekable ? music.clock(music.duration) : '--:--')"
                        ></span>
                    </div>
                </div>

                <button
                    type="button"
                    @click="music.toggleRepeat()"
                    :aria-pressed="music.repeatOne"
                    :aria-label="music.repeatOne ? 'Stop repeating this song' : 'Repeat this song over and over'"
                    class="inline-flex min-h-[40px] shrink-0 items-center gap-[6px] rounded-[12px] border px-[12px] text-[12px] whitespace-nowrap transition"
                    :class="music.repeatOne
                        ? 'border-fq-lime font-bold text-fq-lime'
                        : 'border-fq-line-2 text-fq-text-4 hover:text-fq-text'"
                    :style="music.repeatOne ? 'background: var(--fq-tab-active)' : ''"
                >&#8635; This song</button>

                {{-- Music plays under everything else the app makes noise about,
                     so the mix is a real setting rather than a nicety. --}}
                <label class="flex shrink-0 items-center gap-2">
                    <span class="font-mono-fq text-[9.5px] text-fq-text-4">VOL</span>

                    <input
                        type="range"
                        min="0"
                        max="1"
                        step="0.05"
                        :value="music.volume"
                        @input="music.setVolume($event.target.value)"
                        aria-label="Music volume"
                        class="h-[5px] w-[84px] accent-fq-lime"
                    >
                </label>
            </div>
        </div>
    </div>
@endif
