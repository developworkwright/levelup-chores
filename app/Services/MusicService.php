<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use RuntimeException;
use Throwable;

/**
 * The background music library — the mp3s the kid header plays.
 *
 * The library is a folder, not a table. The filename is the title a kid reads,
 * so "Mossy Save Point.mp3" is a song called Mossy Save Point, and adding one
 * is putting a file next to the others. Which folder is a deployment question
 * answered by `filesystems.music_disk`: a directory under public/ locally, the
 * attached bucket in production.
 *
 * It is deliberately not in the repository. Git keeps every version of a binary
 * forever, and a music library is the kind of thing that gets re-encoded,
 * renamed and swapped out — each pass leaving another few megabytes behind that
 * no later delete gets back.
 */
class MusicService
{
    /** Long enough for a real title, short enough to stay a filename. */
    public const MAX_TITLE = 80;

    /** Roughly ten minutes of decent-bitrate mp3. */
    public const MAX_UPLOAD_KB = 20480;

    private const CACHE_KEY = 'music.tracks';

    /** Set by tracks() when the library could not be read at all. */
    private ?string $failure = null;

    /**
     * How long a bucket listing is trusted.
     *
     * Every mutation on this class clears it, so the window only matters for a
     * file put in the bucket by hand — from Laravel Cloud's own dashboard, say.
     * An hour is the compromise between that and a LIST on every page render.
     */
    private const CACHE_MINUTES = 60;

    public function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.music_disk'));
    }

    /**
     * Every track, by title.
     *
     * `id` is a slug of the filename and is what gets written to a kid's
     * localStorage, so it has to survive a re-render unchanged — an index into
     * this list would silently repoint at a different song the moment somebody
     * added one earlier in the alphabet.
     *
     * @return array<int, array{id: string, title: string, url: string, path: string, bytes: int}>
     */
    public function tracks(): array
    {
        try {
            // A local folder scan is free; a bucket listing is a network round
            // trip on a method the kid header calls on every render and every
            // Livewire round trip. Only the second one is worth a cache — and
            // caching the first would mean a song dropped into public/music
            // during development not showing up for an hour.
            if ($this->isLocal()) {
                return $this->readTracks();
            }

            // The catch sits outside remember() on purpose: a closure that
            // throws stores nothing, so a bucket that is briefly unreachable
            // does not leave an empty library cached for the next hour.
            return Cache::remember(self::CACHE_KEY, now()->addMinutes(self::CACHE_MINUTES), fn () => $this->readTracks());
        } catch (Throwable $e) {
            /*
             * A library that cannot be read is a house with no music in it, not
             * a broken app.
             *
             * This method runs in the kid header, which renders on every page
             * of the kid console and on every Livewire round trip within it. A
             * misconfigured bucket throwing out of here took the whole console
             * down — every quest, every chore, every balance — over the one
             * feature nobody needs to get their jobs done.
             *
             * The failure is not swallowed, though: it is reported, and the
             * music admin screen asks for it by name so the reason is on a page
             * a grown-up can actually reach.
             */
            report($e);

            $this->failure = $e->getMessage();

            return [];
        }
    }

    /**
     * Why the last read of the library came back empty, if it did.
     *
     * Null covers both "it worked" and "there are genuinely no songs" — the
     * caller can tell those apart by whether it got any tracks. Read it off the
     * same instance that called tracks(), since this is not a singleton.
     */
    public function failure(): ?string
    {
        return $this->failure;
    }

    /**
     * Store an uploaded song under a title of the parent's choosing.
     *
     * Returns the stored filename. The caller has already validated the file;
     * what happens here is the naming, which is the part a kid sees.
     */
    public function store(UploadedFile $file, string $title, string $album = ''): string
    {
        $filename = $this->filename($title !== '' ? $title : $file->getClientOriginalName());
        $folder = $this->folder($album);

        $disk = config('filesystems.music_disk');

        /*
         * The file's own storeAs(), never the disk's putFileAs().
         *
         * They look interchangeable and are not. putFileAs() opens the source
         * with fopen($file->getRealPath()) — and an upload still sitting on
         * Livewire's temporary disk has no real path when *that* disk is itself
         * remote: getRealPath() hands back a bucket key, fopen() cannot open it,
         * and the upload dies on a missing file it can see the name of.
         *
         * Anywhere with more than one application container has to put those
         * temporary uploads somewhere shared, so this is the normal case in
         * production and never once the case on a laptop. storeAs() streams
         * from wherever the temporary file actually lives.
         */
        $stored = $file->storeAs($folder, $filename, ['disk' => $disk]);

        // Still checked: this comes back false rather than throwing on a disk
        // built with 'throw' => false, which is how Laravel Cloud builds its
        // own. A page that only catches exceptions would announce a song that
        // was never written.
        if ($stored === false) {
            throw new RuntimeException('Could not write '.$filename.' to the '.$disk.' disk.');
        }

        $this->forget();

        return $stored;
    }

    /**
     * An album name turned into the folder it is stored under.
     *
     * Same rules as filename() and for the same reason — it becomes a path
     * segment and then part of a URL. Blank means the top of the library,
     * which is where a song with no album belongs.
     */
    public function folder(string $album): string
    {
        $album = str_replace('_', ' ', $album);
        $album = preg_replace('/[^\p{L}\p{N} \'&-]+/u', '', $album) ?? '';
        $album = trim(preg_replace('/\s+/', ' ', $album) ?? '');

        return str_replace(' ', '_', mb_substr($album, 0, self::MAX_TITLE));
    }

    /**
     * When the library last changed, as a unix timestamp, or 0 when it is empty.
     *
     * One number is the whole of what a kid's browser needs to work out whether
     * there is anything new: it keeps the last value it saw, and anything
     * higher means songs have arrived since. Deliberately not per song — the
     * marker on the header says "there is new music", not "these four are new",
     * so a single high-water mark is the entire question.
     */
    public function latestChangeAt(): int
    {
        return collect($this->tracks())->max('modified') ?? 0;
    }

    /**
     * Every album in the library, by name.
     *
     * For the picker on the upload form: adding the second song to an album is
     * far more common than starting a new one, and retyping the name is how you
     * end up with an "Undertale" and an "undertale".
     *
     * @return array<int, string>
     */
    public function albums(): array
    {
        return collect($this->tracks())
            ->pluck('album')
            ->filter()
            ->unique()
            ->sort(fn (string $a, string $b): int => strcasecmp($a, $b))
            ->values()
            ->all();
    }

    /**
     * Where a song would end up if it were retitled.
     *
     * Public because a rename changes a song's id, and playlists are keyed by
     * that id — the music screen has to know the new path to move a kid's
     * playlist entries onto it, and working it out a second time on the calling
     * side is how the two would drift.
     */
    public function targetPath(string $path, string $title): string
    {
        // Back into the folder it came out of. The raw first segment, not
        // album(), which has already turned underscores into spaces for
        // reading — renaming a song must not quietly move it to a new album
        // named after the old one with the underscores taken out.
        $segments = explode('/', $path);
        $folder = count($segments) > 1 ? $segments[0].'/' : '';

        return $folder.$this->filename($title);
    }

    /** Retitle a song. The filename *is* the title, so this is a move. */
    public function rename(string $path, string $title): bool
    {
        $target = $this->targetPath($path, $title);

        if ($target === $path || $title === '' || ! $this->disk()->exists($path)) {
            return false;
        }

        if ($this->disk()->move($path, $target) === false) {
            return false;
        }

        $this->forget();

        return true;
    }

    public function delete(string $path): void
    {
        $this->disk()->delete($path);
        $this->forget();
    }

    /** Drops the cached listing, so the next read goes back to the disk. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * A title turned into the filename it is stored under.
     *
     * Underscores stand in for spaces, and that is the whole trick: it keeps
     * the title's own capitalisation — "Echoes of the Underground", not the
     * Title Case a slug round-trips back into — while leaving a filename that
     * needs no percent-encoding anywhere.
     *
     * That matters more than it looks. A URL is built by concatenating a
     * configured base onto the path — the local disk does it, and so does the
     * S3 disk whenever AWS_URL is set — and neither encodes what it is handed.
     * A space in a filename survived that only because browsers are forgiving.
     * A bucket matching a key is not.
     *
     * pathinfo() first, so a title carrying a path drops everything but its
     * last segment before anything else looks at it.
     */
    public function filename(string $title): string
    {
        $title = pathinfo($title, PATHINFO_FILENAME);
        $title = str_replace('_', ' ', $title);
        $title = preg_replace('/[^\p{L}\p{N} \'&-]+/u', '', $title) ?? '';
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? '');

        return str_replace(' ', '_', mb_substr($title, 0, self::MAX_TITLE)).'.mp3';
    }

    /** The title a kid reads, back out of a stored filename. */
    public function title(string $path): string
    {
        return str_replace('_', ' ', pathinfo($path, PATHINFO_FILENAME));
    }

    /**
     * The id a song is known by outside this class.
     *
     * Slugged from the whole path, not the title: two albums are each entitled
     * to a song called Ruins. This is what a kid's browser remembers their
     * choice by and what a playlist entry stores, so it must survive a
     * re-render unchanged — a song at the top still slugs to exactly what it
     * did before folders existed, so nobody's saved choice is forgotten.
     */
    public function idFor(string $path): string
    {
        return Str::slug(str_replace('/', ' ', preg_replace('/\.mp3$/i', '', $path) ?? $path));
    }

    private function isLocal(): bool
    {
        return config('filesystems.disks.'.config('filesystems.music_disk').'.driver') === 'local';
    }

    /**
     * @return array<int, array{id: string, title: string, album: string|null, url: string, path: string, bytes: int}>
     */
    private function readTracks(): array
    {
        $disk = $this->disk();

        // Deep, and through Flysystem's listing rather than files() plus
        // size(): one call brings back every song in every folder along with
        // its size, where the shallow-plus-HEAD form is a request per song.
        $tracks = collect($disk->getDriver()->listContents('', true)->toArray())
            ->filter(fn ($item): bool => $item->isFile()
                && strtolower(pathinfo($item->path(), PATHINFO_EXTENSION)) === 'mp3'
                && ! $this->isScratch($item->path()))
            ->map(function ($item) use ($disk): array {
                $path = $item->path();

                return [
                    'id' => $this->idFor($path),
                    'title' => $this->title($path),
                    'album' => $this->album($path),
                    'url' => $this->urlFor($disk, $path),
                    'path' => $path,
                    'bytes' => $item->fileSize() ?? 0,
                    // What makes "new music" answerable without storing
                    // anything: the library carries its own age.
                    'modified' => $item->lastModified() ?? 0,
                ];
            })
            // Loose songs first, then albums alphabetically, then the songs
            // within each album by title. Natural order, so a filename that
            // does carry a track number sorts 2 before 10 rather than after it.
            ->sort(function (array $a, array $b): int {
                $album = strcasecmp($a['album'] ?? '', $b['album'] ?? '');

                return $album !== 0 ? $album : strnatcasecmp($a['title'], $b['title']);
            })
            ->values()
            ->all();

        return $tracks;
    }

    /**
     * Whether a path belongs to something other than the music library.
     *
     * The bucket is not ours alone. Livewire parks every in-flight upload in
     * its own directory at the top of the *default* disk, which on Laravel
     * Cloud is the attached bucket — the same one the music sits in. A deep
     * listing walks straight into it, and half-finished uploads turned up as an
     * album called "livewire-tmp", on the parent screen and in the kids' picker
     * alike.
     *
     * Asked of Livewire rather than hardcoded, since the directory is
     * configurable and a renamed one would put the folder straight back.
     */
    private function isScratch(string $path): bool
    {
        return str_starts_with($path, FileUploadConfiguration::directory().'/');
    }

    /**
     * The folder a song sits in, or null for one loose at the top.
     *
     * Only ever the first segment. A folder nested inside a folder still
     * belongs to the album at the top of it, which keeps the picker two levels
     * deep however deep the files go — a menu a kid has to walk down four
     * times is not a menu.
     */
    private function album(string $path): ?string
    {
        if (! str_contains($path, '/')) {
            return null;
        }

        return str_replace('_', ' ', explode('/', $path)[0]);
    }

    /**
     * A song's public URL, with every path segment encoded exactly once.
     *
     * Storage::url() cannot be trusted with this on its own, because what it
     * does depends on how the disk is configured. Given a base url — which the
     * local disk has, and which is how every hosted bucket reaches a browser —
     * it concatenates the path on raw, spaces and all. Given no base url, the
     * S3 driver builds the URL through the SDK, which encodes properly. So
     * encoding unconditionally would produce %2520 in the second case, and
     * encoding never leaves broken URLs in the first.
     *
     * It matters now because a folder dropped in by hand carries whatever
     * filenames it came with, spaces included, rather than the underscored
     * ones store() writes.
     */
    private function urlFor(Filesystem $disk, string $path): string
    {
        /*
         * A plain, permanent URL, which means the bucket has to stay publicly
         * readable. A private one answers a kid's <audio> element with 403 and
         * says nothing about it — so the songs already in the browser cache go
         * on playing while every other one is silently dead, which reads as
         * random songs being broken.
         *
         * Signed URLs are the alternative and were tried: they work on a
         * private bucket, but they bypass the platform's own access domain and
         * have to be rotated, so a browser re-downloads several megabytes of
         * mp3 whenever the signature changes. Public is the better trade here.
         */
        $base = config('filesystems.disks.'.config('filesystems.music_disk').'.url');

        if (! is_string($base) || $base === '') {
            return $disk->url($path);
        }

        $encoded = implode('/', array_map(rawurlencode(...), explode('/', $path)));

        return rtrim($base, '/').'/'.$encoded;
    }
}
