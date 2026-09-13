<?php

namespace App\Services;

use App\Models\Profile;
use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Where a photograph goes, and what is left of it when it gets there.
 *
 * This is the first untrusted binary this app has ever accepted. Everything
 * else on a disk here is either an mp3 a grown-up chose or a PNG this app's own
 * canvas produced; a camera roll is neither. So nothing that arrives is stored:
 * the bytes are decoded, checked, redrawn and written back out as a JPEG this
 * class made itself. What reaches the disk is pixels, and only pixels.
 *
 * That one decision does most of the work here:
 *
 * - **EXIF, and the GPS in it, does not survive.** A phone stamps the
 *   coordinates of the house into every photo a kid takes in the garden. A
 *   decode and re-encode drops every tag, so those never land on a disk at all.
 * - **A polyglot stops being one.** A file that is a valid JPEG *and* a valid
 *   PHP script, or one with a payload hidden in a comment segment, loses
 *   everything but its pixels the moment it is redrawn.
 * - **One output format means one Content-Type.** FeedMediaController serves
 *   {@see self::MIME}, a constant, rather than anything read back out of a
 *   database row.
 *
 * The order of the checks matters more than the checks. See store().
 *
 * @see FeedDrawings for the finger pad, which shares this disk.
 */
class FeedPhotos
{
    /** The folder every photo is filed under, on whichever disk is in use. */
    public const FOLDER = 'photos';

    /**
     * The biggest upload accepted, in kilobytes.
     *
     * Twelve megabytes is a generous modern phone photo. It is a floor under
     * the work, not a judgement about the picture: everything past this point
     * has to be decoded to be checked, and decoding is the expensive step.
     */
    public const MAX_UPLOAD_KB = 12288;

    /**
     * The most pixels this will decode, whatever the file weighs.
     *
     * This is the decompression-bomb ceiling and it is the reason the header is
     * read before the image is. A 200-byte PNG can honestly declare itself
     * 50000x50000; imagecreatefromstring() would take that at its word and ask
     * for about ten gigabytes, and the worker dies before any later check runs.
     */
    public const MAX_PIXELS = 50000000;

    /** The longest side kept. A phone camera is far past this and nobody needs it. */
    public const MAX_EDGE = 1600;

    public const QUALITY = 82;

    /** The one type ever written, and the one type ever served. */
    public const MIME = 'image/jpeg';

    /**
     * What a browser is asked to offer, and what is accepted.
     *
     * No HEIC, which is what an iPhone shoots by default — deliberately. GD
     * cannot read it at all, and iOS transcodes to JPEG on upload when `accept`
     * asks for JPEG, so the common case is handled by the phone and the rare
     * one is refused with a sentence rather than a decode error.
     *
     * No SVG, ever, and not merely because GD would not draw it: an SVG is an
     * XML document that can carry a <script>, and one served inline from this
     * app's own origin is stored cross-site scripting against the whole family.
     * It is excluded here, in the component's validation rules, and by
     * Laravel's `image` rule, which all have to agree.
     *
     * @var array<int, string>
     */
    public const ACCEPT = ['image/jpeg', 'image/png', 'image/webp'];

    /** The image types this will decode, by getimagesize()'s constants. */
    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /** Shares the drawings disk: private, no public URL, filed by household. */
    public function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.drawings_disk'));
    }

    /**
     * Takes an upload apart, keeps the picture, and writes that.
     *
     * The order below is the security, not the list:
     *
     * 1. Read the bytes.
     * 2. Read the *header* — size and type — without decoding anything.
     * 3. Refuse anything that isn't one of three real image types, or that
     *    claims more pixels than MAX_PIXELS.
     * 4. Only now decode, downscale, and re-encode.
     *
     * Steps two and three exist entirely to happen before step four.
     *
     * @return array{path: string, width: int, height: int}
     *
     * @throws RuntimeException when the upload isn't a photograph this can read
     */
    public function store(Profile $author, UploadedFile $file): array
    {
        $binary = $this->read($file);

        // The header only. Nothing is allocated for the image itself yet.
        $info = @getimagesizefromstring($binary);

        if (! is_array($info) || ! in_array($info[2] ?? null, self::TYPES, true)) {
            throw new RuntimeException('That photo could not be read.');
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];

        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new RuntimeException('That photo is too big to open.');
        }

        // Read before re-encoding, because re-encoding is what throws it away.
        // Without this every portrait photo off an iPhone arrives on its side.
        $orientation = $this->orientationOf($binary);

        $jpeg = $this->redraw($binary, $orientation);

        $path = self::FOLDER."/{$author->household_id}/".Str::random(32).'.jpg';

        // put() with bytes we made, rather than the upload's own storeAs():
        // there is no file to move any more, which sidesteps the remote
        // temporary-disk trap MusicService documents at length.
        if ($this->disk()->put($path, $jpeg['bytes']) === false) {
            // A disk built with 'throw' => false — which is how Laravel Cloud
            // builds its own — returns false rather than raising. A room that
            // only caught exceptions would show an empty frame and say nothing.
            throw new RuntimeException('That photo did not save. Try again?');
        }

        // The temporary upload, gone as soon as it is spent. Livewire prunes
        // these on a schedule and this app schedules nothing — the host scales
        // to zero and a cron would never fire — so without this they pile up on
        // the default disk forever.
        rescue(fn () => $file->delete(), report: false);

        return [
            'path' => $path,
            'width' => $jpeg['width'],
            'height' => $jpeg['height'],
        ];
    }

    /** The upload's bytes, or a refusal that says which way it failed. */
    private function read(UploadedFile $file): string
    {
        if (! $file->isValid()) {
            throw new RuntimeException('That photo did not finish uploading. Try again?');
        }

        if ($file->getSize() > self::MAX_UPLOAD_KB * 1024) {
            throw new RuntimeException('That photo is over '.round(self::MAX_UPLOAD_KB / 1024).'MB.');
        }

        $binary = rescue(fn () => $file->get(), null, false);

        if (! is_string($binary) || $binary === '') {
            throw new RuntimeException('That photo could not be read.');
        }

        return $binary;
    }

    /**
     * The EXIF orientation tag, or 1 when there isn't one worth trusting.
     *
     * exif_read_data() is noisy and the extension is optional, so this never
     * lets either fact reach the caller: an unreadable tag simply means the
     * photo is the way up it was drawn.
     */
    private function orientationOf(string $binary): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = rescue(
            fn () => @exif_read_data('data://image/jpeg;base64,'.base64_encode($binary)),
            false,
            false,
        );

        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /**
     * Decodes, turns the right way up, shrinks, and hands back a fresh JPEG.
     *
     * Everything the original file carried that was not a pixel is gone by the
     * time this returns, which is the entire point of doing it.
     *
     * @return array{bytes: string, width: int, height: int}
     */
    private function redraw(string $binary, int $orientation): array
    {
        $source = @imagecreatefromstring($binary);

        if (! $source instanceof GdImage) {
            throw new RuntimeException('That photo could not be read.');
        }

        try {
            $source = $this->turnUpright($source, $orientation);

            [$width, $height] = $this->fit(imagesx($source), imagesy($source));

            $canvas = imagecreatetruecolor($width, $height);

            if (! $canvas instanceof GdImage) {
                throw new RuntimeException('That photo could not be read.');
            }

            try {
                // White underneath, because the output is a JPEG and JPEG has no
                // alpha: a transparent PNG or WebP laid straight onto a fresh
                // truecolor canvas comes out on a black background.
                imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
                imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

                ob_start();
                $written = imagejpeg($canvas, null, self::QUALITY);
                $bytes = (string) ob_get_clean();

                if (! $written || $bytes === '') {
                    throw new RuntimeException('That photo could not be saved.');
                }

                return ['bytes' => $bytes, 'width' => $width, 'height' => $height];
            } finally {
                imagedestroy($canvas);
            }
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * Applies an EXIF orientation, so the picture is the way it was taken.
     *
     * The eight cases are the whole of the tag: four rotations and their
     * mirrored twins. A phone held sideways writes 6 or 8 constantly.
     */
    private function turnUpright(GdImage $image, int $orientation): GdImage
    {
        $rotate = match ($orientation) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };

        if ($rotate !== 0) {
            $turned = @imagerotate($image, $rotate, 0);

            if ($turned instanceof GdImage) {
                imagedestroy($image);
                $image = $turned;
            }
        }

        // The even tags are the mirrored ones.
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            rescue(fn () => imageflip($image, IMG_FLIP_HORIZONTAL), report: false);
        }

        return $image;
    }

    /**
     * The size to redraw at: the same shape, with its longest side capped.
     *
     * Never scaled up — a small photo stays small rather than being blown up to
     * MAX_EDGE and losing what sharpness it had.
     *
     * @return array{0: int, 1: int}
     */
    private function fit(int $width, int $height): array
    {
        $longest = max($width, $height);

        if ($longest <= self::MAX_EDGE) {
            return [$width, $height];
        }

        $scale = self::MAX_EDGE / $longest;

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }
}
