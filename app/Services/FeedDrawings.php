<?php

namespace App\Services;

use App\Models\Profile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Where a finger drawing goes, and where it comes back from.
 *
 * Split out of FeedService because it is the one part of the feature that talks
 * to a disk, and the disk moves: a local folder in development, a bucket on
 * whatever host this is deployed to. Everything else in the feed is rows.
 *
 * The canvas hands over a flattened PNG as a data URL rather than a file
 * upload, because the drawing never existed as a file — it is pixels in a
 * <canvas> the moment the kid lifts their finger, and routing that through a
 * temporary upload would be a round trip to turn a string into a file and back.
 */
class FeedDrawings
{
    /** The canvas size the tool draws at. Fixed, so nothing ever reflows. */
    public const WIDTH = 640;

    public const HEIGHT = 380;

    /** The folder every drawing is filed under, on whichever disk is in use. */
    public const FOLDER = 'drawings';

    /**
     * A generous ceiling for a 640×380 PNG of finger strokes — a fully scribbled
     * one lands well under this. It is here to stop a hand-edited payload, not
     * to stop a drawing, so it does not need to be tight.
     */
    public const MAX_BYTES = 2 * 1024 * 1024;

    public function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.drawings_disk'));
    }

    /**
     * Writes a data-URL PNG to the disk and returns its path.
     *
     * @throws RuntimeException when the payload isn't a PNG data URL, or is too
     *                          big to be one drawing
     */
    public function store(Profile $author, string $dataUrl): string
    {
        $prefix = 'data:image/png;base64,';

        if (! str_starts_with($dataUrl, $prefix)) {
            // Only PNG, and only from the canvas. Anything else arriving here
            // is a hand-edited payload rather than a kid drawing a dog.
            throw new RuntimeException('That drawing could not be read.');
        }

        $binary = base64_decode(substr($dataUrl, strlen($prefix)), true);

        if ($binary === false || $binary === '' || strlen($binary) > self::MAX_BYTES) {
            throw new RuntimeException('That drawing could not be read.');
        }

        // Under `drawings/` in the path itself rather than in a disk's `root`,
        // so the folder is the same on every disk — including the one Laravel
        // Cloud builds for the bucket, which has no root prefix and shares the
        // top of the bucket with the music library. Then by household, so a
        // bucket shared with another install can never hand one family's
        // drawing to another, and named randomly so the path says nothing
        // about who drew it or when.
        $path = self::FOLDER."/{$author->household_id}/".Str::random(32).'.png';

        $this->disk()->put($path, $binary);

        return $path;
    }

    /*
     * There is deliberately no url() here. A drawing has no public address: the
     * bucket is private, and every drawing is fetched through
     * FeedDrawingController, which checks the viewer can read the room it was
     * posted in. See FeedMessage::drawingUrl().
     */
}
