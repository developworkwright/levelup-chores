<?php

namespace App\Services;

use App\Enums\CosmeticSlot;
use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * An uploaded cosmetic picture: the machine checks, and the copy that is kept.
 *
 * The checks answer what a machine can — the size, the transparency, whether
 * the middle of a frame is clear enough for a face to sit in. They cannot
 * answer the only question that really matters about a piece of art, which is
 * whether it still reads at 40px in a header. The parent console shows the
 * picture at its three real sizes for that, and a warning here is a nudge to
 * look rather than a refusal.
 *
 * Stored the way FeedPhotos stores a photograph and for the same reason: the
 * upload is decoded and redrawn, so what reaches the disk is pixels this class
 * made, never the file somebody handed over.
 */
class CosmeticArt
{
    /** The folder every picture is filed under, on whichever disk is in use. */
    public const FOLDER = 'cosmetics';

    /** Past this many pixels nothing is decoded — the decompression-bomb ceiling. */
    private const MAX_PIXELS = 4000000;

    /** Shares the drawings disk: private, served only through CosmeticArtController. */
    public function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.drawings_disk'));
    }

    /**
     * Runs every check a slot has on a picture.
     *
     * @return array<int, array{label: string, status: string}>
     */
    public function inspect(string $binary, CosmeticSlot $slot): array
    {
        $spec = $slot->uploadSpec();
        $info = @getimagesizefromstring($binary);

        if (! is_array($info) || ($info[2] ?? null) !== IMAGETYPE_PNG) {
            return [['label' => 'Not a PNG this can read', 'status' => 'fail']];
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];
        $kb = (int) ceil(strlen($binary) / 1024);
        $checks = [];

        $checks[] = $width === $spec['width'] && $height === $spec['height']
            ? ['label' => "{$width}×{$height}, PNG", 'status' => 'pass']
            : ['label' => "{$width}×{$height} — needs to be {$spec['width']}×{$spec['height']}", 'status' => 'fail'];

        $checks[] = $kb <= $spec['max_kb']
            ? ['label' => "{$kb} KB, under the cap", 'status' => 'pass']
            : ['label' => "{$kb} KB — the cap is {$slot->uploadLimitLabel()}", 'status' => 'fail'];

        if ($width * $height > self::MAX_PIXELS) {
            return $checks;
        }

        $image = @imagecreatefromstring($binary);

        if (! $image instanceof GdImage) {
            return [...$checks, ['label' => 'The picture would not open', 'status' => 'fail']];
        }

        imagepalettetotruecolor($image);

        $corners = $this->opacity($image, fn (int $x, int $y) => ($x < $width * 0.06 || $x > $width * 0.94) && ($y < $height * 0.06 || $y > $height * 0.94));

        if ($spec['alpha']) {
            $checks[] = $corners < 0.1
                ? ['label' => 'Transparent background', 'status' => 'pass']
                : ['label' => 'The background isn\'t transparent', 'status' => 'fail'];
        } else {
            $checks[] = $this->opacity($image, fn () => true) > 0.98
                ? ['label' => 'No see-through patches', 'status' => 'pass']
                : ['label' => 'Has transparent areas — a pattern must be solid', 'status' => 'fail'];
        }

        $checks = [...$checks, ...$this->slotChecks($image, $slot, $width, $height)];

        imagedestroy($image);

        return $checks;
    }

    /**
     * The one check per slot that is about the shape of the art rather than
     * the file — each is the line in that slot's prompt image models get wrong.
     *
     * @return array<int, array{label: string, status: string}>
     */
    private function slotChecks(GdImage $image, CosmeticSlot $slot, int $width, int $height): array
    {
        $cx = $width / 2;
        $cy = $height / 2;
        $inCircle = fn (float $fraction) => fn (int $x, int $y) => (($x - $cx) ** 2 + ($y - $cy) ** 2) <= ($width * $fraction) ** 2;

        return match ($slot) {
            CosmeticSlot::Frame => [
                $this->opacity($image, $inCircle(0.31)) < 0.02
                    ? ['label' => 'Middle 62% is clear', 'status' => 'pass']
                    : ['label' => 'Art inside the middle 62% — the face has nowhere to go', 'status' => 'fail'],
                ['label' => 'Check the 40px preview yourself', 'status' => 'warn'],
            ],
            CosmeticSlot::Spark => [
                $this->opacity($image, $inCircle(0.1)) < 0.05
                    ? ['label' => 'Centre is empty', 'status' => 'pass']
                    : ['label' => 'Something sits in the centre — it should burst outward', 'status' => 'fail'],
            ],
            CosmeticSlot::Pattern => [
                $this->seam($image, $width, $height) <= 28
                    ? ['label' => 'Tiles without a seam', 'status' => 'pass']
                    : ['label' => 'Seam visible when tiled — reupload', 'status' => 'fail'],
            ],
            CosmeticSlot::Plate => [
                $this->busyness($image, $width, $height) <= 40
                    ? ['label' => 'Middle is flat enough for a name', 'status' => 'pass']
                    : ['label' => 'Busy middle — check the name still reads', 'status' => 'warn'],
            ],
            default => [['label' => 'Check the small preview yourself', 'status' => 'warn']],
        };
    }

    /**
     * How much of a region is visible, 0 to 1, sampled every fourth pixel.
     *
     * @param  callable(int, int): bool  $inRegion
     */
    private function opacity(GdImage $image, callable $inRegion): float
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $seen = 0;
        $visible = 0;

        for ($y = 0; $y < $height; $y += 4) {
            for ($x = 0; $x < $width; $x += 4) {
                if (! $inRegion($x, $y)) {
                    continue;
                }

                $seen++;

                // GD's alpha runs 0 (opaque) to 127 (clear).
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) < 110) {
                    $visible++;
                }
            }
        }

        return $seen === 0 ? 0.0 : $visible / $seen;
    }

    /** The mean colour difference between opposite edges, 0 to 255. */
    private function seam(GdImage $image, int $width, int $height): float
    {
        $total = 0;
        $count = 0;

        for ($y = 0; $y < $height; $y += 2) {
            $total += $this->distance(imagecolorat($image, 0, $y), imagecolorat($image, $width - 1, $y));
            $count++;
        }

        for ($x = 0; $x < $width; $x += 2) {
            $total += $this->distance(imagecolorat($image, $x, 0), imagecolorat($image, $x, $height - 1));
            $count++;
        }

        return $total / max(1, $count);
    }

    /** The spread of brightness across a plate's middle band, as a standard deviation. */
    private function busyness(GdImage $image, int $width, int $height): float
    {
        $values = [];

        for ($y = (int) ($height * 0.3); $y < $height * 0.7; $y += 3) {
            for ($x = (int) ($width * 0.15); $x < $width * 0.85; $x += 3) {
                $rgb = imagecolorat($image, $x, $y);
                $values[] = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
            }
        }

        if ($values === []) {
            return 0.0;
        }

        $mean = array_sum($values) / count($values);

        return sqrt(array_sum(array_map(fn (float $v) => ($v - $mean) ** 2, $values)) / count($values));
    }

    private function distance(int $a, int $b): float
    {
        return (abs((($a >> 16) & 0xFF) - (($b >> 16) & 0xFF))
            + abs((($a >> 8) & 0xFF) - (($b >> 8) & 0xFF))
            + abs(($a & 0xFF) - ($b & 0xFF))) / 3;
    }

    /**
     * Redraws the picture and writes it, returning the path.
     *
     * @throws RuntimeException when the picture can't be read or saved
     */
    public function store(int $householdId, string $binary): string
    {
        $info = @getimagesizefromstring($binary);

        if (! is_array($info) || ($info[2] ?? null) !== IMAGETYPE_PNG || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new RuntimeException('That picture could not be read.');
        }

        $image = @imagecreatefromstring($binary);

        if (! $image instanceof GdImage) {
            throw new RuntimeException('That picture could not be read.');
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        imagepng($image, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        $path = self::FOLDER."/{$householdId}/".Str::random(32).'.png';

        if ($this->disk()->put($path, $png) === false) {
            throw new RuntimeException('That picture did not save. Try again?');
        }

        return $path;
    }
}
