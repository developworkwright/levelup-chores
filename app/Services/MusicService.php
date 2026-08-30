<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        // A local folder scan is free; a bucket listing is a network round trip
        // on a method the kid header calls on every render and every Livewire
        // round trip. Only the second one is worth a cache — and caching the
        // first would mean a song dropped into public/music during development
        // not showing up for an hour.
        if ($this->isLocal()) {
            return $this->readTracks();
        }

        return Cache::remember(self::CACHE_KEY, now()->addMinutes(self::CACHE_MINUTES), fn () => $this->readTracks());
    }

    /**
     * Store an uploaded song under a title of the parent's choosing.
     *
     * Returns the stored filename. The caller has already validated the file;
     * what happens here is the naming, which is the part a kid sees.
     */
    public function store(UploadedFile $file, string $title): string
    {
        $filename = $this->filename($title !== '' ? $title : $file->getClientOriginalName());

        $this->disk()->putFileAs('', $file, $filename);
        $this->forget();

        return $filename;
    }

    /** Retitle a song. The filename *is* the title, so this is a move. */
    public function rename(string $path, string $title): bool
    {
        $target = $this->filename($title);

        if ($target === $path || $title === '' || ! $this->disk()->exists($path)) {
            return false;
        }

        $this->disk()->move($path, $target);
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

    private function isLocal(): bool
    {
        return config('filesystems.disks.'.config('filesystems.music_disk').'.driver') === 'local';
    }

    /**
     * @return array<int, array{id: string, title: string, url: string, path: string, bytes: int}>
     */
    private function readTracks(): array
    {
        $disk = $this->disk();

        // Through Flysystem's listing rather than files() plus size(): the
        // sizes come back with the listing, where the second form is one
        // HEAD request per song against the bucket.
        $tracks = collect($disk->getDriver()->listContents('', false)->toArray())
            ->filter(fn ($item): bool => $item->isFile()
                && strtolower(pathinfo($item->path(), PATHINFO_EXTENSION)) === 'mp3')
            ->map(function ($item) use ($disk): array {
                $title = $this->title($item->path());

                return [
                    'id' => Str::slug($title),
                    'title' => $title,
                    'url' => $disk->url($item->path()),
                    'path' => $item->path(),
                    'bytes' => $item->fileSize() ?? 0,
                ];
            })
            ->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return $tracks;
    }
}
