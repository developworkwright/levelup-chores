{{-- The header's background music control: one 52px square that starts and
     stops the song, and a narrow tab beside it that opens the picker.

     Two targets rather than one, because these are two different questions. A
     kid who wants quiet should not have to read a menu to get it, and a kid
     browsing for a song should not have to stop the music to see the list.

     Everything it knows lives in the `music` Alpine store — see
     resources/js/music.js for why the audio is deliberately not an element on
     the page. --}}
@props(['profile' => null])

@php
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

    // This kid's own lists, ids only — the catalogue above already carries
    // every title and url, so sending them twice would be the same payload
    // again on every page. Never anybody else's: a playlist belongs to whoever
    // made it, and the header only ever draws for the profile signed in.
    $playlists = $profile === null
        ? []
        : app(App\Services\PlaylistService::class)->payloadFor($profile);
@endphp

@if ($tracks !== [])
    <div
        x-data="fqMusic(@js($tracks), {{ $latestAt }}, @js($playlists))"
        {{-- On the wrapper so a tap on either button counts as inside;
             hung off the panel it would race its own opening. --}}
        @click.outside="open = false"
        class="relative shrink-0"
    >
        <div class="flex h-[52px] items-stretch overflow-hidden rounded-[15px] border border-fq-line-2 bg-fq-sunk">
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
                class="flex w-[52px] items-center justify-center text-[18px] transition hover:text-fq-text"
            >&#9835;</button>

            {{-- `relative` so the marker can sit on its corner. --}}
            <button
                type="button"
                @click="togglePanel()"
                :aria-expanded="open"
                :title="music.hasNew ? 'New music — choose a song' : 'Choose a song'"
                :aria-label="music.hasNew ? 'New music — choose a song' : 'Choose a song'"
                class="relative flex w-[30px] items-center justify-center border-l border-fq-line-2 text-[11px] text-fq-text-4 transition hover:text-fq-text"
                :class="open ? 'text-fq-text' : ''"
            >
                &#9662;

                {{-- The whole of how a kid finds out. Deliberately a dot and
                     not a count: the marker's job is "look in here", and a
                     number invites counting rather than listening. It goes the
                     moment the picker opens. --}}
                <span
                    x-show="music.hasNew"
                    x-cloak
                    class="pointer-events-none absolute top-[9px] right-[5px] h-[7px] w-[7px] rounded-full"
                    style="background: var(--fq-lime); box-shadow: 0 0 0 2px var(--fq-sunk)"
                ></span>
            </button>
        </div>

        {{-- A sheet on a phone, a dropdown from `sm` up.

             It used to be a dropdown at every size, anchored to the right edge
             of the button it hangs from — which is fine until the header wraps
             and puts that button near the left of the row. Then 288px of panel
             extends leftwards from it and most of the menu is off the screen,
             which is exactly what happened. A capped max-width does not save
             it: the width was never the problem, the anchor was.

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
                 same-specificity utilities Tailwind happens to emit last. --}}
            class="fixed inset-x-0 bottom-0 z-40 flex max-h-[72vh] flex-col rounded-t-[18px] border-t border-fq-line-2 bg-fq-panel p-3 pb-[max(12px,env(safe-area-inset-bottom))] shadow-lg sm:absolute sm:top-[58px] sm:right-0 sm:bottom-auto sm:left-auto sm:w-[288px] sm:rounded-[14px] sm:border sm:pb-3"
        >
            <p class="mb-2 shrink-0 font-mono-fq text-[10px] tracking-wide text-fq-text-4">MUSIC</p>

            {{-- The list scrolls, the volume slider below it does not. A
                 soundtrack is a hundred songs and the panel is anchored to a
                 header — without a cap it would run off the bottom of a phone
                 and take the volume control with it. --}}
            <div class="-mr-1 flex max-h-[46vh] flex-col gap-[3px] overflow-y-auto pr-1">
                {{-- The kid's own lists, above the library they were built out
                     of. A playlist is a shortcut past the scrolling, so it has
                     to be the first thing in the panel or it is not one.

                     Inside the same scroller as the songs rather than pinned
                     above it: twelve playlists and a hundred songs in two
                     boxes that scroll separately is two places to be lost. --}}
                <template x-if="music.playlists.length">
                    <div class="flex flex-col gap-[3px] border-b border-fq-line pb-2">
                        <template x-for="list in music.playlists" :key="list.id">
                            <button
                                type="button"
                                {{-- Tapping the one already playing leaves it,
                                     the same "tap it again" as the albums. --}}
                                @click="music.playPlaylist(list.id)"
                                :title="list.name"
                                :aria-pressed="music.playlistId === list.id"
                                class="flex w-full items-center gap-2 rounded-[10px] px-2 py-[9px] text-left text-[13px] transition"
                                :class="music.playlistId === list.id
                                    ? 'bg-fq-sunk font-semibold text-fq-text'
                                    : 'text-fq-text-3 hover:text-fq-text'"
                            >
                                <span
                                    class="w-[12px] shrink-0 text-[10px]"
                                    :class="music.playlistId === list.id ? 'text-fq-lime' : 'text-fq-text-5'"
                                >&#9654;</span>

                                <span class="truncate" x-text="list.name"></span>

                                <span
                                    class="ml-auto shrink-0 font-mono-fq text-[10px] text-fq-text-5"
                                    x-text="countIn(list)"
                                ></span>
                            </button>
                        </template>
                    </div>
                </template>

                <template x-for="track in loose" :key="track.id">
                    <x-music-song />
                </template>

                <template x-for="album in albums" :key="album">
                    <div>
                        {{-- Click only, deliberately. An album that opens on
                             hover opens itself as the pointer crosses it on
                             the way to somewhere else, and on a phone it does
                             not open at all — so the two behave differently on
                             the two devices this runs on, for no gain. --}}
                        <button
                            type="button"
                            @click="toggleAlbum(album)"
                            :aria-expanded="openAlbum === album"
                            class="flex w-full items-center gap-2 rounded-[10px] px-2 py-[9px] text-left text-[13px] transition hover:text-fq-text"
                            :class="openAlbum === album ? 'text-fq-text' : 'text-fq-text-3'"
                        >
                            <span
                                class="w-[12px] shrink-0 text-[9px] transition-transform"
                                :class="openAlbum === album ? 'rotate-90' : ''"
                            >&#9654;</span>

                            <span class="truncate font-semibold" x-text="album"></span>

                            <span
                                class="ml-auto shrink-0 font-mono-fq text-[10px] text-fq-text-5"
                                x-text="songsIn(album).length"
                            ></span>
                        </button>

                        <div x-show="openAlbum === album" class="flex flex-col gap-[3px]">
                            <template x-for="track in songsIn(album)" :key="track.id">
                                <x-music-song indent />
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Only while a playlist is on, because neither control means
                 anything to a single song on repeat: there is nothing to
                 shuffle and nothing to skip to. --}}
            <div
                x-show="music.inPlaylist"
                x-cloak
                class="mt-3 flex shrink-0 items-center gap-2 border-t border-fq-line pt-3"
            >
                <button
                    type="button"
                    @click="music.toggleShuffle()"
                    :aria-pressed="music.shuffle"
                    class="rounded-[10px] border border-fq-line-2 px-[10px] py-[6px] text-[12px] transition"
                    :class="music.shuffle ? 'text-fq-lime' : 'text-fq-text-4 hover:text-fq-text'"
                >&#8646; Shuffle</button>

                <button
                    type="button"
                    @click="music.advance()"
                    class="rounded-[10px] border border-fq-line-2 px-[10px] py-[6px] text-[12px] text-fq-text-4 transition hover:text-fq-text"
                >Next &#9654;&#9654;</button>
            </div>

            {{-- Music plays under everything else the app makes noise about, so
                 the mix is a real setting rather than a nicety. --}}
            <label class="mt-3 flex shrink-0 items-center gap-2 border-t border-fq-line pt-3">
                <span class="font-mono-fq text-[10px] text-fq-text-4">VOL</span>
                <input
                    type="range"
                    min="0"
                    max="1"
                    step="0.05"
                    :value="music.volume"
                    @input="music.setVolume($event.target.value)"
                    aria-label="Music volume"
                    class="h-[4px] w-full accent-fq-lime"
                >
            </label>

            @if ($profile?->isKid())
                {{-- The way in to making one. The panel is a picker and stays a
                     picker: building a list in a box this size, on a phone,
                     would be a worse version of the page this points at. --}}
                <a
                    href="{{ route('kid.music') }}"
                    wire:navigate
                    @click="open = false"
                    class="mt-2 shrink-0 rounded-[10px] px-2 py-[7px] text-center text-[12px] text-fq-text-4 transition hover:text-fq-text"
                >Make a playlist &rarr;</a>
            @endif
        </div>
    </div>
@endif
