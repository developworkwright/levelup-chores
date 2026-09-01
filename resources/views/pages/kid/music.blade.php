<?php

use App\Models\Playlist;
use App\Models\Profile;
use App\Services\MusicService;
use App\Services\PlaylistService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * Music — where a kid builds the lists the header plays.
 *
 * The split is deliberate and is the whole design of this page. Choosing and
 * playing music happens in the header, on whatever page a kid is already on,
 * because music is something you put on while doing something else. Building a
 * playlist is the opposite: it is the thing you are doing, it wants the library
 * laid out in front of you, and it does not fit in a panel the size of a
 * dropdown on a phone.
 *
 * Nothing here is worth points, tickets or XP, and that is on purpose — see the
 * shell for why this page sits under Me. It is the one screen in the app that
 * is only for the kid's own enjoyment.
 *
 * Every mutation announces the new lists to the browser, because the header is
 * on this same screen: Livewire morphs the page around the header's Alpine
 * component without re-evaluating its `x-data`, so a playlist made here would
 * otherwise not turn up in the picker until a full page load.
 *
 * @see PlaylistService for what happens to a list when a song it names is
 *      renamed or deleted out from under it.
 */
new class extends Component
{
    public Profile $profile;

    /** Which playlist is open. One at a time, so the page stays readable. */
    public ?int $openPlaylistId = null;

    /** Which album's songs are drawn in the library below. Same reason. */
    public ?string $openAlbum = null;

    public string $newName = '';

    public ?string $flashMessage = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();

        abort_unless($this->profile->isKid(), 403);

        // Straight into the only one they have. A kid with a single playlist
        // should never have to open it before they can add anything to it.
        $this->openPlaylistId = app(PlaylistService::class)
            ->forProfile($this->profile)
            ->first()?->id;
    }

    /**
     * Named for what it does, not after `$openPlaylistId`.
     *
     * A public method sharing a name with a public property is legal PHP that
     * breaks only in the browser: `wire:click="openPlaylist(3)"` becomes
     * `$wire.openPlaylist(3)`, and $wire resolves the name to the property, so
     * the click quietly calls null. The parent music screen learned this the
     * hard way — there is a test that keeps both pages honest.
     */
    public function showPlaylist(?int $id): void
    {
        $this->openPlaylistId = $this->openPlaylistId === $id ? null : $id;
        $this->flashMessage = null;
        $this->errorMessage = null;
    }

    public function toggleAlbum(?string $album): void
    {
        $this->openAlbum = $this->openAlbum === $album ? null : $album;
    }

    public function createPlaylist(): void
    {
        $service = app(PlaylistService::class);

        $this->errorMessage = $service->rejection($this->profile, $this->newName);

        if ($this->errorMessage !== null) {
            return;
        }

        $playlist = $service->create($this->profile, $this->newName);

        $this->newName = '';
        $this->flashMessage = $playlist->name.' is ready — add some songs.';

        // Opened, because making one is how you say you are about to fill it.
        $this->openPlaylistId = $playlist->id;

        $this->announce();
    }

    public function renamePlaylist(int $id, string $name): void
    {
        $playlist = $this->playlist($id);

        if ($playlist === null) {
            return;
        }

        $service = app(PlaylistService::class);

        // Said out loud rather than silently reverted: the input keeps showing
        // what the kid typed, so a rename that just didn't happen looks like it
        // did until the next render.
        $this->errorMessage = $service->rejection($this->profile, $name, $playlist);

        if ($this->errorMessage !== null) {
            return;
        }

        $service->rename($playlist, $name);

        $this->flashMessage = null;

        $this->announce();
    }

    public function deletePlaylist(int $id): void
    {
        $playlist = $this->playlist($id);

        if ($playlist === null) {
            return;
        }

        app(PlaylistService::class)->delete($playlist);

        $this->flashMessage = $playlist->name.' is gone.';
        $this->errorMessage = null;

        if ($this->openPlaylistId === $id) {
            $this->openPlaylistId = null;
        }

        $this->announce();
    }

    public function addSong(string $trackId): void
    {
        $playlist = $this->playlist($this->openPlaylistId);

        if ($playlist === null) {
            return;
        }

        $service = app(PlaylistService::class);

        if (! $service->add($playlist, $trackId)) {
            // The only reason worth a message. A song already in the list has
            // a filled-in button saying so, and an id naming nothing cannot
            // come from a button on this page at all.
            if ($playlist->tracks()->count() >= PlaylistService::MAX_TRACKS) {
                $this->errorMessage = $playlist->name.' is full at '.PlaylistService::MAX_TRACKS.' songs.';
            }

            return;
        }

        $this->flashMessage = null;
        $this->errorMessage = null;

        $this->announce();
    }

    public function removeSong(int $id, string $trackId): void
    {
        $playlist = $this->playlist($id);

        if ($playlist === null) {
            return;
        }

        app(PlaylistService::class)->remove($playlist, $trackId);

        $this->announce();
    }

    public function moveSong(int $id, string $trackId, int $direction): void
    {
        $playlist = $this->playlist($id);

        if ($playlist === null) {
            return;
        }

        app(PlaylistService::class)->move($playlist, $trackId, $direction);

        $this->announce();
    }

    /**
     * One of this kid's playlists, by id, or null.
     *
     * Every method above goes through here. The id arrives from the browser and
     * a playlist is the first thing in the app one kid could edit another's
     * with — the lists are per profile precisely so siblings can't.
     */
    private function playlist(?int $id): ?Playlist
    {
        if ($id === null) {
            return null;
        }

        return Playlist::where('profile_id', $this->profile->id)->find($id);
    }

    /**
     * Hand the header the new lists.
     *
     * See the class docblock: the picker's Alpine component survives the
     * morph that redraws this page, so it has to be told rather than re-read.
     */
    private function announce(): void
    {
        $this->dispatch(
            'playlists-updated',
            playlists: app(PlaylistService::class)->payloadFor($this->profile),
        );
    }

    public function with(): array
    {
        $service = app(PlaylistService::class);
        $music = app(MusicService::class);

        $playlists = $service->forProfile($this->profile);
        $open = $playlists->firstWhere('id', $this->openPlaylistId);

        $tracks = collect($music->tracks());

        return [
            'playlists' => $playlists,
            'open' => $open,
            'songs' => $open === null ? [] : $service->songsIn($open),
            // What the add buttons check against, so a song already in the open
            // list reads as in it rather than offering to go in twice.
            'chosen' => $open === null ? [] : $open->tracks->pluck('track_id')->all(),
            'loose' => $tracks->whereNull('album')->all(),
            'albums' => $tracks->whereNotNull('album')->groupBy('album'),
            'openTracks' => $this->openAlbum === null
                ? []
                : $tracks->where('album', $this->openAlbum)->all(),
            'librarySize' => $tracks->count(),
            'maxName' => PlaylistService::MAX_NAME,
            'maxPlaylists' => PlaylistService::MAX_PER_PROFILE,
        ];
    }
}; ?>

<x-kid.shell :profile="$profile" active="music">
    <div class="flex flex-col gap-[14px]">
        <div class="rounded-[24px] border p-6" style="background: var(--fq-wash-blue); border-color: var(--fq-line-cool)">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: var(--fq-cyan)">Yours only</p>
                    <h2 class="mt-1 font-baloo text-2xl font-extrabold">Music</h2>
                </div>

                @if ($playlists->isNotEmpty())
                    <span class="font-mono-fq text-[11px] text-fq-text-4">
                        {{ $playlists->count() }} / {{ $maxPlaylists }} {{ Str::plural('PLAYLIST', $playlists->count()) }}
                    </span>
                @endif
            </div>

            <p class="mt-3 text-[13px] text-fq-text-3">
                Make a list, drop songs in it, and it turns up in the &#9835; menu at the top of
                every page. A playlist plays right through and starts again at the beginning.
            </p>

            @if ($librarySize === 0)
                <p class="mt-3 text-[13px] text-fq-text-5">
                    There is no music in the house yet — ask a grown-up to add some.
                </p>
            @else
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <input
                        wire:model="newName"
                        wire:keydown.enter="createPlaylist"
                        type="text"
                        maxlength="{{ $maxName }}"
                        placeholder="Name a new playlist"
                        aria-label="New playlist name"
                        class="min-w-[180px] flex-1 rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[11px] py-[9px] text-[13px] text-fq-text placeholder:text-fq-text-5 focus:border-fq-line-4 focus:outline-none"
                    />

                    <button
                        type="button"
                        wire:click="createPlaylist"
                        class="shrink-0 rounded-[11px] px-4 py-[9px] font-baloo text-[13px] font-extrabold transition hover:brightness-110"
                        style="background: var(--fq-fill-gold-soft); color: var(--fq-ink)"
                    >Make it</button>
                </div>
            @endif

            @if ($errorMessage)
                <p class="mt-2 text-[13px]" style="color: var(--fq-danger)">{{ $errorMessage }}</p>
            @endif

            @if ($flashMessage)
                <p class="mt-2 text-[13px]" style="color: var(--fq-lime)">{{ $flashMessage }}</p>
            @endif
        </div>

        @if ($playlists->isEmpty())
            @if ($librarySize > 0)
                <div class="rounded-[24px] border border-fq-line bg-fq-bg p-6 text-center">
                    <p class="font-baloo text-[17px] font-bold">No playlists yet</p>
                    <p class="mt-[6px] text-[13px] text-fq-text-3">
                        Name one up there and the whole library shows up underneath it.
                    </p>
                </div>
            @endif
        @else
            <div class="flex flex-col gap-2">
                @foreach ($playlists as $playlist)
                    <div wire:key="playlist-{{ $playlist->id }}" class="flex flex-col gap-2">
                        <div class="flex flex-wrap items-center gap-2 rounded-[18px] border border-fq-line-2 bg-fq-panel px-3 py-[11px]">
                            <button
                                type="button"
                                wire:click="showPlaylist({{ $playlist->id }})"
                                aria-expanded="{{ $openPlaylistId === $playlist->id ? 'true' : 'false' }}"
                                aria-label="Open {{ $playlist->name }}"
                                class="shrink-0 text-[10px] text-fq-text-4 transition hover:text-fq-text"
                            >{{ $openPlaylistId === $playlist->id ? '▼' : '▶' }}</button>

                            {{-- The name is the input. Renaming a playlist is
                                 the only thing anybody does to one that isn't
                                 about its songs, so it doesn't need a mode. --}}
                            <input
                                type="text"
                                value="{{ $playlist->name }}"
                                maxlength="{{ $maxName }}"
                                wire:change="renamePlaylist({{ $playlist->id }}, $event.target.value)"
                                aria-label="Playlist name"
                                class="min-w-[140px] flex-1 rounded-[10px] border border-transparent bg-transparent px-[6px] py-[4px] font-baloo text-[15px] font-bold text-fq-text hover:border-fq-line-2 focus:border-fq-line-4 focus:bg-fq-sunk focus:outline-none"
                            />

                            <span class="shrink-0 font-mono-fq text-[10px] text-fq-text-5">
                                {{ $playlist->tracks->count() }} {{ Str::plural('SONG', $playlist->tracks->count()) }}
                            </span>

                            {{-- Playing is the header's job everywhere else in
                                 the app, and it is the header's job here too:
                                 this reaches straight into the same store, so
                                 there is only ever one thing making noise. --}}
                            <button
                                type="button"
                                x-data
                                @click="$store.music.playPlaylist({{ $playlist->id }})"
                                class="shrink-0 rounded-[10px] border border-fq-line-2 px-[10px] py-[6px] text-[12px] text-fq-text-4 transition hover:text-fq-text"
                                :class="$store.music.playlistId === {{ $playlist->id }} ? 'text-fq-lime' : ''"
                            >&#9654; Play</button>

                            <button
                                type="button"
                                wire:click="deletePlaylist({{ $playlist->id }})"
                                wire:confirm="Delete {{ $playlist->name }}?"
                                aria-label="Delete {{ $playlist->name }}"
                                class="shrink-0 rounded-[10px] border border-fq-line-2 px-[10px] py-[6px] text-[12px] text-fq-text-4 transition hover:text-fq-text"
                            >Delete</button>
                        </div>

                        @if ($openPlaylistId === $playlist->id)
                            <div class="flex flex-col gap-[6px] pl-4">
                                @forelse ($songs as $index => $song)
                                    @php
                                        $entry = $song['entry'];
                                        $missing = $song['track'] === null;
                                    @endphp

                                    <div
                                        wire:key="entry-{{ $entry->id }}"
                                        class="flex items-center gap-2 rounded-[14px] border border-fq-line-2 bg-fq-sunk px-3 py-[9px]"
                                    >
                                        <span class="w-[18px] shrink-0 font-mono-fq text-[10px] text-fq-text-5">{{ $index + 1 }}</span>

                                        <span class="min-w-0 flex-1 truncate text-[13px] {{ $missing ? 'text-fq-text-5 line-through' : 'text-fq-text-2-b' }}">
                                            {{ $song['track']['title'] ?? $entry->title }}
                                        </span>

                                        {{-- A song whose file has gone. Shown
                                             rather than quietly dropped: a list
                                             that shrinks on its own looks like
                                             the app losing things. --}}
                                        @if ($missing)
                                            <span class="shrink-0 font-mono-fq text-[10px] text-fq-text-5">GONE</span>
                                        @endif

                                        <button
                                            type="button"
                                            wire:click="moveSong({{ $playlist->id }}, @js($entry->track_id), -1)"
                                            aria-label="Move {{ $entry->title }} up"
                                            @disabled($index === 0)
                                            class="shrink-0 px-[6px] text-[11px] text-fq-text-4 transition hover:text-fq-text disabled:opacity-30"
                                        >&#9650;</button>

                                        <button
                                            type="button"
                                            wire:click="moveSong({{ $playlist->id }}, @js($entry->track_id), 1)"
                                            aria-label="Move {{ $entry->title }} down"
                                            @disabled($index === count($songs) - 1)
                                            class="shrink-0 px-[6px] text-[11px] text-fq-text-4 transition hover:text-fq-text disabled:opacity-30"
                                        >&#9660;</button>

                                        <button
                                            type="button"
                                            wire:click="removeSong({{ $playlist->id }}, @js($entry->track_id))"
                                            aria-label="Take {{ $entry->title }} out"
                                            class="shrink-0 px-[6px] text-[13px] text-fq-text-4 transition hover:text-fq-text"
                                        >&times;</button>
                                    </div>
                                @empty
                                    <p class="text-[13px] text-fq-text-5">
                                        Nothing in here yet. Pick songs from the library below.
                                    </p>
                                @endforelse
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        {{-- The library, and only once a list is open to put things in. An add
             button with nowhere to add to is a button that has to explain
             itself, and this page would be mostly that. --}}
        @if ($open !== null)
            <div class="flex flex-col gap-3 rounded-[24px] border border-fq-line bg-fq-bg p-[16px_14px]">
                <div class="flex flex-wrap items-baseline gap-2">
                    <h3 class="font-baloo text-lg font-extrabold">Add to {{ $open->name }}</h3>
                    <span class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                        {{ $librarySize }} {{ Str::plural('SONG', $librarySize) }} IN THE HOUSE
                    </span>
                </div>

                <div class="flex flex-col gap-[6px]">
                    @foreach ($loose as $track)
                        <x-music-pick :track="$track" :chosen="in_array($track['id'], $chosen, true)" />
                    @endforeach

                    @foreach ($albums as $name => $album)
                        <div wire:key="lib-{{ Str::slug($name) }}" class="flex flex-col gap-[6px]">
                            <button
                                type="button"
                                wire:click="toggleAlbum(@js($name))"
                                class="flex items-center gap-2 rounded-[14px] border border-fq-line-2 bg-fq-panel px-3 py-[10px] text-left transition hover:border-fq-line-focus"
                            >
                                <span class="w-[12px] shrink-0 text-[10px] text-fq-text-4">
                                    {{ $openAlbum === $name ? '▼' : '▶' }}
                                </span>
                                <span class="truncate font-baloo text-[15px] font-bold">{{ $name }}</span>
                                <span class="ml-auto shrink-0 font-mono-fq text-[10px] text-fq-text-5">
                                    {{ $album->count() }}
                                </span>
                            </button>

                            @if ($openAlbum === $name)
                                <div class="flex flex-col gap-[6px] pl-4">
                                    @foreach ($openTracks as $track)
                                        <x-music-pick :track="$track" :chosen="in_array($track['id'], $chosen, true)" />
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-kid.shell>
