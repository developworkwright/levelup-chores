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

    /**
     * The paper the pad is painted on, and the colour the eraser draws in.
     *
     * It lives here rather than in draw.js because the tray's markup and the
     * canvas both need it, and a second copy of a hex is how the eraser ends up
     * leaving faint rectangles a shade off the background.
     *
     * The paper is opaque for a reason — see paint() in resources/js/draw.js.
     */
    public const PAPER = '#150c26';

    /**
     * The six colours on the pad, by the name a screen reader reads out.
     *
     * Named rather than a bare list of hexes because every swatch used to carry
     * the identical label "Draw in this colour", which told a kid using
     * VoiceOver nothing at all about which of the six they had landed on.
     *
     * Six, and a picker beside them for anything else. A grid of forty colours
     * is a thing to browse; this is a thing to reach for mid-drawing.
     *
     * @var array<string, string>
     */
    public const PALETTE = [
        'Yellow' => '#ffe14d',
        'Pink' => '#ff8ac7',
        'Purple' => '#d8b4ff',
        'Mint' => '#7fe6c0',
        'Red' => '#ff6b6b',
        'White' => '#f7f0ff',
    ];

    /**
     * The three nib widths, in canvas pixels, thin to fat.
     *
     * Three rather than a slider: a slider is a drag a six-year-old has to aim,
     * and the useful range here is "a line", "a thick line" and "wipe a big
     * area". The fattest doubles as the eraser's footprint, which is why it is
     * wide enough to clear a corner in one stroke.
     *
     * @var array<int, int>
     */
    public const BRUSHES = [4, 10, 22];

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

        /*
         * And the bytes have to actually *be* a PNG.
         *
         * For a while this checked only that the string started with the PNG
         * data-URL prefix, which a hand-edited payload writes for free — so
         * anything at all could be stored under a .png name and served back
         * with a PNG content type. Nothing could execute it (the type is a
         * constant and the response carries nosniff, and only the household can
         * fetch it), but "arbitrary bytes on our disk, unexecutable for now" is
         * not a property worth keeping.
         *
         * Read as a header rather than decoded: this is the same ordering
         * FeedPhotos::store() uses and for the same reason — the size check
         * above has already run, so a declared-huge image never reaches a
         * decoder. A bound rather than an equality, so the one-pixel PNG the
         * tests post still passes and a retina canvas stays possible later.
         */
        $info = @getimagesizefromstring($binary);

        if (! is_array($info)
            || ($info[2] ?? null) !== IMAGETYPE_PNG
            || (int) $info[0] < 1
            || (int) $info[1] < 1
            || (int) $info[0] > self::WIDTH
            || (int) $info[1] > self::HEIGHT
        ) {
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
     * FeedMediaController, which checks the viewer can read the room it was
     * posted in. See FeedMessage::mediaUrl().
     */
}
