<?php

use App\Models\Profile;
use App\Services\MusicService;
use App\Services\PlaylistService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * The music library — where the songs the kid header plays are added and named.
 *
 * A screen rather than a folder a grown-up SSHes into, because in production
 * the songs live in the attached bucket and adding one should not be a deploy.
 * The filename is the title a kid reads, so naming is the substance of this
 * page: everything else is upload, rename, delete.
 *
 * Songs can sit in a folder, which the picker shows as an album. That exists
 * because a bought soundtrack is a hundred tracks, and a hundred tracks in one
 * flat list is not a menu anybody can use.
 *
 * @see MusicService for why the library is a folder rather than a table, and
 *      why stored filenames carry underscores instead of spaces.
 */
new class extends Component
{
    use WithFileUploads;

    public Profile $profile;

    /**
     * The song being added. One at a time, and deliberately untyped.
     *
     * Not `multiple`, however much a hundred-track album wants it to be:
     * Livewire's S3 temporary-upload driver refuses a multiple input outright
     * — see S3DoesntSupportMultipleFileUploads, thrown from _startUpload the
     * instant a file is chosen, before any of this component's own code runs.
     * It throws on a single file too, because it goes off the attribute rather
     * than the number of files. Locally it never fires, because a local
     * temporary disk is not S3; in production it always does.
     *
     * Untyped because Livewire assigns a bare TemporaryUploadedFile here, and
     * a typed array property would refuse it. Bulk imports go through storage
     * directly and then Rescan — see rescan().
     */
    public $upload = null;

    /** Which folder to put them in. Blank leaves them loose at the top. */
    public string $newAlbum = '';

    /** What to call it. Blank falls back to the uploaded file's own name. */
    public string $newTitle = '';

    /** Which album's songs are drawn. One at a time, so the page stays short. */
    public ?string $openAlbum = null;

    public ?string $flashMessage = null;

    public ?string $errorMessage = null;

    /** The storage layer's own words, under the friendly line. */
    public ?string $errorDetail = null;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();

        abort_unless($this->profile->isParent(), 403);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // mimetypes as well as the extension: the extension is what the
            // library keys off, and a renamed .wav would be silently unplayable
            // on exactly the browsers nobody in the house is testing on.
            'upload' => ['required', 'file', 'mimetypes:audio/mpeg', 'extensions:mp3', 'max:'.MusicService::MAX_UPLOAD_KB],
            'newTitle' => ['nullable', 'string', 'max:'.MusicService::MAX_TITLE],
            'newAlbum' => ['nullable', 'string', 'max:'.MusicService::MAX_TITLE],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'upload.required' => 'Pick an mp3 first.',
            'upload.mimetypes' => 'That has to be an mp3.',
            'upload.extensions' => 'That has to be an mp3.',
            'upload.max' => 'That song is over '.round(MusicService::MAX_UPLOAD_KB / 1024).'MB.',
        ];
    }

    public function addSong(): void
    {
        $this->validate();

        $service = app(MusicService::class);

        try {
            $stored = $service->store($this->upload, $this->newTitle, $this->newAlbum);
        } catch (\Throwable $e) {
            // Whether the disk throws or quietly returns false, store() ends up
            // here — a bucket that rejected the write must not leave this page
            // claiming the song was added.
            report($e);

            $this->errorMessage = 'That did not save — the music storage turned it down.';
            $this->errorDetail = $e->getMessage();
            $this->flashMessage = null;

            return;
        }

        $album = $service->folder($this->newAlbum);

        $this->reset('upload', 'newTitle', 'errorMessage', 'errorDetail');

        // Nothing is sent to anybody. Adding a song used to fire a push, which
        // is unusable when an album is a hundred separate uploads — the kids
        // find out from the marker on the header music button instead, which
        // costs them nothing and can never arrive a hundred times.
        $this->flashMessage = $service->title($stored).' is on the list.';

        // Left where it was put, so the row it just made is on screen.
        $this->openAlbum = $album !== '' ? str_replace('_', ' ', $album) : null;
    }

    /**
     * Named so it does not collide with the $openAlbum property above.
     *
     * A method and a property sharing a name works perfectly from PHP — and
     * breaks silently in the browser, which is the only place it matters.
     * `wire:click="openAlbum('Undertale')"` becomes `$wire.openAlbum(...)`, and
     * $wire resolves a name to the *property* when one exists: the click called
     * null and the album simply never opened. Nothing in the server-side test
     * suite can see it, because ->call() never goes through $wire.
     */
    public function toggleAlbum(?string $album): void
    {
        $this->openAlbum = $this->openAlbum === $album ? null : $album;
    }

    /**
     * Retitle a song, and take the kids' playlists with it.
     *
     * A song's id is a slug of its path, so a rename is a move that changes it
     * — and every playlist entry naming the old one would be left pointing at a
     * file that is no longer there. The library has no table for a foreign key
     * to hang off (see MusicService), so nothing does this on its own: the one
     * screen that renames songs is the one that has to say so.
     */
    public function renameSong(string $path, string $title): void
    {
        $service = app(MusicService::class);
        $title = trim($title);

        // Worked out before the move, because afterwards the old path is gone
        // and there is nothing left to derive the new one from.
        $target = $service->targetPath($path, $title);

        if ($service->rename($path, $title)) {
            app(PlaylistService::class)->retrack(
                $service->idFor($path),
                $service->idFor($target),
                $service->title($target),
            );
        }

        $this->flashMessage = null;
    }

    /**
     * The one destructive control, and it asks first in the markup rather than
     * here: a kid loses nothing but a remembered choice, which falls back to
     * the first song on the list, but the parent loses the upload.
     */
    public function removeSong(string $path): void
    {
        $service = app(MusicService::class);

        $this->flashMessage = $service->title($path).' is gone.';
        $service->delete($path);

        // And out of every playlist that named it. A kid's list is allowed to
        // outlive a song — see PlaylistService — but not when the app knows
        // perfectly well that the song was deleted on purpose.
        app(PlaylistService::class)->untrack($service->idFor($path));
    }

    /**
     * Drop the cached listing.
     *
     * A bucket listing is held for an hour, so songs put there any other way —
     * dropped in from the platform's own dashboard, or copied up in bulk with
     * an S3 client, which is how anybody sane adds a hundred-track album —
     * would otherwise not show up until it expired.
     */
    public function rescan(): void
    {
        app(MusicService::class)->forget();

        $this->flashMessage = 'Had another look at the music storage.';
    }

    public function with(): array
    {
        // One instance, because the failure below is recorded on it by the
        // tracks() call — a second app() resolve would come back clean.
        $service = app(MusicService::class);
        $tracks = collect($service->tracks());

        $albums = $tracks->whereNotNull('album')
            ->groupBy('album')
            ->map(fn ($songs) => ['count' => $songs->count(), 'bytes' => $songs->sum('bytes')]);

        return [
            'loose' => $tracks->whereNull('album')->all(),
            'albums' => $albums,
            // Only the open one is drawn. A hundred rows, each with its own
            // audio element, is a page that takes a visible moment to appear.
            'openTracks' => $this->openAlbum === null
                ? []
                : $tracks->where('album', $this->openAlbum)->all(),
            'total' => $tracks->count(),
            'totalBytes' => $tracks->sum('bytes'),
            'knownAlbums' => $service->albums(),
            'maxTitle' => MusicService::MAX_TITLE,
            'maxMb' => (int) round(MusicService::MAX_UPLOAD_KB / 1024),
            // Named on the page so a bucket that is not wired up is visible
            // here, rather than as silence on a kid's phone.
            'diskName' => config('filesystems.music_disk'),
            // Storage that cannot be read no longer takes pages down, which
            // means an empty playlist is now ambiguous: no songs, or no bucket.
            // This is the difference, and it is on the one screen whose job is
            // to answer it.
            'storageFailure' => $service->failure(),
            // Names only, never the credentials behind them. The platform
            // registers its own disks at boot from its own environment, so the
            // one MUSIC_DISK is supposed to name cannot be known when this file
            // is written — but it *is* in this list at runtime, and reading it
            // off the screen beats hunting through a dashboard.
            'availableDisks' => array_keys(config('filesystems.disks')),
        ];
    }
}; ?>

<x-parent.shell :profile="$profile" active="music">
    <div class="flex flex-col gap-3">
        <div class="flex flex-col gap-3 rounded-[28px] border border-fq-line bg-fq-bg p-[16px_14px]">
            <div>
                <h2 class="font-baloo text-xl font-extrabold">Add a song</h2>
                <p class="mt-[3px] text-xs text-fq-text-3">
                    mp3 only, up to {{ $maxMb }}MB. Give it an album and the kids get it as a
                    folder in the header menu instead of loose in one long list.
                </p>
                {{-- One at a time is a hard limit, not a choice: Livewire's S3
                     temporary-upload driver rejects a `multiple` input, and in
                     production the temporary disk is the bucket. A whole album
                     goes into storage directly, which is faster anyway — none
                     of it passes through the app. --}}
                <p class="mt-[6px] text-xs text-fq-text-5">
                    Adding a whole album? Copy the folder straight into music storage, then
                    press <span class="text-fq-text-3">Rescan storage</span> below.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <input
                    type="file"
                    wire:model="upload"
                    accept="audio/mpeg,.mp3"
                    aria-label="Song file"
                    class="min-w-[200px] flex-1 rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[11px] py-[9px] text-[13px] text-fq-text-2-b file:mr-3 file:rounded-[8px] file:border-0 file:bg-fq-panel-alt file:px-3 file:py-[5px] file:text-[12px] file:text-fq-text-2-b focus:border-fq-line-4 focus:outline-none"
                />

                {{-- A list rather than a free-text box alone: adding the second
                     song to an album is far more common than starting a new
                     one, and retyping is how you end up with an "Undertale"
                     and an "undertale" side by side. --}}
                <input
                    wire:model="newAlbum"
                    type="text"
                    list="known-albums"
                    maxlength="{{ $maxTitle }}"
                    placeholder="Album (optional)"
                    class="w-[170px] rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[11px] py-[9px] text-[13px] text-fq-text placeholder:text-fq-text-5 focus:border-fq-line-4 focus:outline-none"
                />
                <datalist id="known-albums">
                    @foreach ($knownAlbums as $album)
                        <option value="{{ $album }}"></option>
                    @endforeach
                </datalist>

                <input
                    wire:model="newTitle"
                    type="text"
                    maxlength="{{ $maxTitle }}"
                    placeholder="Call it something"
                    class="w-[170px] rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[11px] py-[9px] text-[13px] text-fq-text placeholder:text-fq-text-5 focus:border-fq-line-4 focus:outline-none"
                />

                <button
                    type="button"
                    wire:click="addSong"
                    wire:loading.attr="disabled"
                    wire:target="upload,addSong"
                    class="ml-auto shrink-0 rounded-[11px] px-4 py-[9px] font-baloo text-[13px] font-extrabold transition hover:brightness-110 disabled:opacity-60"
                    style="background: var(--fq-fill-gold-soft); color: var(--fq-ink)"
                >
                    <span wire:loading.remove wire:target="upload,addSong">Add song</span>
                    <span wire:loading wire:target="upload,addSong">Uploading&hellip;</span>
                </button>
            </div>

            @error('upload')
                <p class="text-[13px]" style="color: var(--fq-danger)">{{ $message }}</p>
            @enderror

            @if ($errorMessage)
                <p class="text-[13px]" style="color: var(--fq-danger)">{{ $errorMessage }}</p>

                @if ($errorDetail)
                    <p class="font-mono-fq text-[11px] leading-relaxed break-words text-fq-text-5">
                        {{ $errorDetail }}
                    </p>
                @endif
            @endif

            @if ($flashMessage)
                <p class="text-[13px]" style="color: var(--fq-lime)">{{ $flashMessage }}</p>
            @endif
        </div>

        <div class="flex flex-col gap-3 rounded-[28px] border border-fq-line bg-fq-bg p-[16px_14px]">
            <div class="flex flex-wrap items-baseline gap-2">
                <h2 class="font-baloo text-xl font-extrabold">The playlist</h2>
                <span class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                    {{ $total }} {{ Str::plural('SONG', $total) }}
                    @if ($total)
                        &middot; {{ number_format($totalBytes / 1048576, 1) }} MB
                    @endif
                    &middot; {{ $diskName }}
                </span>

                {{-- For songs put in the bucket some other way, which for a
                     hundred-track album is the sane way. --}}
                <button
                    type="button"
                    wire:click="rescan"
                    class="ml-auto shrink-0 rounded-[10px] border border-fq-line-2 px-[10px] py-[6px] text-[12px] text-fq-text-4 transition hover:text-fq-text"
                >Rescan storage</button>
            </div>

            @if ($storageFailure)
                <div
                    class="rounded-[14px] border px-3 py-[10px]"
                    style="border-color: var(--fq-danger); background: var(--fq-sunk)"
                >
                    <p class="font-baloo text-[15px] font-bold" style="color: var(--fq-danger)">
                        The music storage cannot be read.
                    </p>
                    <p class="mt-[3px] text-[13px] text-fq-text-3">
                        The kids see no music button at all until this is fixed. Everything else
                        in the app is unaffected.
                    </p>
                    <p class="mt-2 font-mono-fq text-[11px] leading-relaxed break-words text-fq-text-5">
                        {{ $storageFailure }}
                    </p>

                    <p class="mt-3 text-[12px] text-fq-text-4">
                        MUSIC_DISK is set to <span class="font-mono-fq text-fq-text-2-b">{{ $diskName }}</span>.
                        The disks this app actually has are:
                    </p>
                    <p class="mt-1 font-mono-fq text-[11px] break-words text-fq-text-2-b">
                        {{ implode(' · ', $availableDisks) }}
                    </p>
                </div>
            @elseif (! $total)
                <p class="text-[13px] text-fq-text-5">
                    Nothing here yet, so the music button stays off the kid header entirely.
                </p>
            @else
                <div class="flex flex-col gap-2">
                    @foreach ($loose as $track)
                        <x-music-row :track="$track" :max-title="$maxTitle" />
                    @endforeach

                    @foreach ($albums as $name => $album)
                        <div wire:key="album-{{ Str::slug($name) }}" class="flex flex-col gap-2">
                            <button
                                type="button"
                                wire:click="toggleAlbum(@js($name))"
                                class="flex items-center gap-2 rounded-[14px] border border-fq-line-2 bg-fq-panel px-3 py-[11px] text-left transition hover:border-fq-line-focus"
                            >
                                {{-- The characters themselves, not entities:
                                     these go through {{ }}, which escapes, so
                                     an entity here reaches the page as its own
                                     source text. --}}
                                <span class="w-[12px] shrink-0 text-[10px] text-fq-text-4">
                                    {{ $openAlbum === $name ? '▼' : '▶' }}
                                </span>
                                <span class="font-baloo text-[15px] font-bold">{{ $name }}</span>
                                <span class="ml-auto shrink-0 font-mono-fq text-[10px] text-fq-text-5">
                                    {{ $album['count'] }} {{ Str::plural('SONG', $album['count']) }}
                                    &middot; {{ number_format($album['bytes'] / 1048576, 1) }} MB
                                </span>
                            </button>

                            @if ($openAlbum === $name)
                                <div class="flex flex-col gap-2 pl-4">
                                    @foreach ($openTracks as $track)
                                        <x-music-row :track="$track" :max-title="$maxTitle" />
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-parent.shell>
