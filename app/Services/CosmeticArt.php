<?php

namespace App\Services;

use App\Enums\CosmeticSlot;
use App\Enums\PetStage;
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

    /**
     * Bumped whenever the way a pet picture is cut or checked changes, so a
     * cut cached under the old rules (see the parent console) is never used.
     */
    public const CUT_VERSION = 8;

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

        /*
         * On a sprite sheet the corners are the corners of pose cells, and a
         * pet rolling on its back in the play cell fills one. So a sheet is
         * judged on the whole picture instead: a painted-in background covers
         * nearly all of it, and a real sheet is mostly empty space.
         */
        if ($spec['alpha'] && $slot->poseGrid() !== null) {
            $checks[] = $this->opacity($image, fn () => true) < 0.75
                ? ['label' => 'Transparent background', 'status' => 'pass']
                : ['label' => 'The background isn\'t transparent', 'status' => 'fail'];
        } elseif ($spec['alpha']) {
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
            CosmeticSlot::Pet => $this->sheetChecks($image, $slot),
            default => [['label' => 'Check the small preview yourself', 'status' => 'warn']],
        };
    }

    /**
     * A sprite sheet's own three questions: is every pose there, does any of
     * them bleed into its neighbour, and is it the same animal throughout.
     *
     * The last one is a warning rather than a refusal. What it really measures
     * is whether the poses are all about the same height, which is the symptom
     * of a generator quietly drawing a different creature in each cell — but a
     * pet that genuinely crouches low and jumps tall would trip it too, so it
     * asks a grown-up to look rather than turning the art away.
     *
     * @return array<int, array{label: string, status: string}>
     */
    private function sheetChecks(GdImage $image, CosmeticSlot $slot): array
    {
        $grid = $slot->poseGrid();
        $cellWidth = imagesx($image) / $grid['cols'];
        $cellHeight = imagesy($image) / $grid['rows'];
        $poses = $slot::PET_POSES;

        $empty = [];
        $bleeding = [];
        $heights = [];

        foreach ($poses as $index => $pose) {
            $left = (int) (($index % $grid['cols']) * $cellWidth);
            $top = (int) (intdiv($index, $grid['cols']) * $cellHeight);
            $box = $this->boundingBox($image, $left, $top, (int) $cellWidth, (int) $cellHeight);

            if ($box === null) {
                $empty[] = $pose;

                continue;
            }

            $heights[$pose] = $box['bottom'] - $box['top'];

            // Only the left and right edges are worth mentioning. Those are
            // where the next pose sits, so art touching one may show a sliver of
            // its neighbour. Touching the top or the bottom costs nothing but a
            // clipped pixel, and a generator that drew the feet on the line does
            // that every time.
            if ($box['left'] <= $left + 1 || $box['right'] >= $left + $cellWidth - 2) {
                $bleeding[] = $pose;
            }
        }

        $checks = [];

        $checks[] = $empty === []
            ? ['label' => 'All '.count($poses).' poses are there', 'status' => 'pass']
            : ['label' => 'Nothing in the '.implode(', ', $empty).' '.(count($empty) === 1 ? 'cell' : 'cells'), 'status' => 'fail'];

        /*
         * A warning, not a refusal.
         *
         * It was a refusal, and that was the wrong call: a perfectly good sheet
         * where one pose's tail reaches within a pixel of its cell edge could
         * not be published at all, while the actual harm is a couple of stray
         * pixels along the side of a 78px sprite. A refusal is for what breaks
         * the pet — a missing pose, the wrong size, no transparency.
         */
        $checks[] = $bleeding === []
            ? ['label' => 'Every pose stays inside its cell', 'status' => 'pass']
            : ['label' => implode(', ', $bleeding).' sits right on the cell edge — it may show a sliver of the pose beside it', 'status' => 'warn'];

        // The standing poses only — a crouch is meant to be short and a jump
        // tall, so including them would fire this on a perfectly good sheet.
        $standing = array_intersect_key($heights, array_flip(['idle', 'blink', 'walk', 'walk2', 'happy']));

        if (count($standing) > 1 && min($standing) > 0 && max($standing) / min($standing) > 1.35) {
            $checks[] = ['label' => 'The standing poses are different sizes — check it is the same animal', 'status' => 'warn'];
        } else {
            $checks[] = ['label' => 'One animal, one size', 'status' => 'pass'];
        }

        return $checks;
    }

    /**
     * The box the visible pixels of one cell sit in, or null for an empty cell.
     *
     * @return array{left: int, top: int, right: int, bottom: int}|null
     */
    private function boundingBox(GdImage $image, int $left, int $top, int $width, int $height): ?array
    {
        $box = null;

        for ($y = $top; $y < $top + $height; $y += 2) {
            for ($x = $left; $x < $left + $width; $x += 2) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) >= 110) {
                    continue;
                }

                $box ??= ['left' => $x, 'top' => $y, 'right' => $x, 'bottom' => $y];
                $box['left'] = min($box['left'], $x);
                $box['top'] = min($box['top'], $y);
                $box['right'] = max($box['right'], $x);
                $box['bottom'] = max($box['bottom'], $y);
            }
        }

        return $box;
    }

    /**
     * Rubs out the grid an image generator drew on a sprite sheet.
     *
     * Every generator tried so far ignores "no grid lines, no borders" and rules
     * the cells anyway, often with a foot line across a row as well. Those
     * pixels would end up in the game as stray lines through the pet.
     *
     * A line is told apart from art by how far it runs: a ruling spans the whole
     * sheet, and no drawing of a 256px pet ever spans 1024px. So any row or
     * column that is almost entirely opaque is erased, and nothing else is
     * touched.
     *
     * It refuses to run away with itself: if more rows and columns look like
     * rulings than any sheet could really have, they are not rulings — the art
     * is edge to edge, or a background is still painted in — and nothing is
     * erased at all. That is the difference between tidying a sheet and rubbing
     * the whole picture out.
     *
     * @return int how many rows and columns were rubbed out
     */
    private function stripSheetLines(GdImage $image): int
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $clear = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $erased = 0;

        // The all-ages sheet is nine by six: thirteen rulings, plus a foot line
        // under each row, at up to four pixels thick. A hundred is generous
        // and still nowhere near a whole sheet.
        $ceiling = 100;

        $visibleAt = function (int $x, int $y) use ($image): bool {
            return ((imagecolorat($image, $x, $y) >> 24) & 0x7F) < 110;
        };

        /*
         * A ruling is a band, not a line. Resizing a two-pixel rule to another
         * size smears it across three, and the smeared edge is well short of
         * "every pixel opaque" — so the run is found at a high bar and then
         * followed outwards at a lower one. Left as single rows, the leftover
         * half-line reads as art touching the edge of its cell.
         */
        $run = function (callable $fractionAt, int $length): array {
            $found = [];

            for ($i = 0; $i < $length; $i++) {
                if ($fractionAt($i) <= 0.92) {
                    continue;
                }

                $found[$i] = true;

                foreach ([-1, 1] as $step) {
                    for ($j = $i + $step; $j >= 0 && $j < $length; $j += $step) {
                        if (isset($found[$j]) || $fractionAt($j) <= 0.55) {
                            break;
                        }

                        $found[$j] = true;
                    }
                }
            }

            // And a pixel either side of each band. A rule resized from one size
            // to another always leaves a faint smear at its edges, well under
            // any threshold worth setting, and that smear reads to the checks
            // as art touching the side of its cell.
            foreach (array_keys($found) as $i) {
                foreach ([$i - 1, $i + 1] as $j) {
                    if ($j >= 0 && $j < $length && $fractionAt($j) > 0.15) {
                        $found[$j] = true;
                    }
                }
            }

            return array_keys($found);
        };

        $rows = $run(function (int $y) use ($width, $visibleAt): float {
            $visible = 0;

            for ($x = 0; $x < $width; $x += 2) {
                $visible += $visibleAt($x, $y) ? 1 : 0;
            }

            return $visible / ceil($width / 2);
        }, $height);

        $columns = $run(function (int $x) use ($height, $visibleAt): float {
            $visible = 0;

            for ($y = 0; $y < $height; $y += 2) {
                $visible += $visibleAt($x, $y) ? 1 : 0;
            }

            return $visible / ceil($height / 2);
        }, $width);

        if (count($rows) + count($columns) > $ceiling) {
            return 0;
        }

        imagealphablending($image, false);

        foreach ($rows as $y) {
            imageline($image, 0, $y, $width - 1, $y, $clear);
            $erased++;
        }

        foreach ($columns as $x) {
            imageline($image, $x, 0, $x, $height - 1, $clear);
            $erased++;
        }

        imagealphablending($image, true);

        return $erased;
    }

    /**
     * Cuts out a background a generator painted instead of leaving empty.
     *
     * Asked for transparency, an image model will often hand back a fully opaque
     * picture with the grey-and-white checkerboard *drawn in* — the thing that
     * usually means "nothing here" rendered as actual pixels. It looks right in
     * a preview and is wrong everywhere else: the pet would run around the page
     * inside a grey tile.
     *
     * Cleared by flooding in from the edges rather than by matching colour
     * everywhere, so a white patch enclosed by the drawing — the cat's eye, a
     * highlight — is kept. Only what a border pixel can walk to goes.
     *
     * On a sprite sheet the flood runs **per cell**, and that is not an
     * optimisation. The same generator that paints the background also rules
     * the grid, and those rulings wall each cell's background in: flooding from
     * the outside of the sheet reaches the four corner cells and leaves the
     * middle ones sitting in a grey box.
     *
     * Runs on a checkerboard or a plain flat backdrop alike, and does nothing
     * at all when the picture already has real transparency.
     *
     * The one thing it cannot do is keep art that is the same colour as the
     * background *and* joined to it — a white ear against a white backdrop is
     * indistinguishable from the backdrop, whatever the rule. Drawings with an
     * outline, which is every style worth using here, never hit it.
     *
     * @param  array{cols: int, rows: int}|null  $grid
     * @return array{cut: bool, palette: array<int, int>} what it found, and the
     *                                                    colours it took as background
     */
    private function cutBackground(GdImage $image, ?array $grid = null): array
    {
        $nothing = ['cut' => false, 'palette' => []];

        $width = imagesx($image);
        $height = imagesy($image);

        // Already transparent at the corners: nothing painted to cut.
        if ($this->opacity($image, fn (int $x, int $y) => ($x < $width * 0.03 || $x > $width * 0.97) && ($y < $height * 0.03 || $y > $height * 0.97)) < 0.5) {
            return $nothing;
        }

        // The background's colours, read off the top-left corner — one for a
        // flat backdrop, two for a checkerboard's light and dark squares.
        $seen = [];

        for ($y = 0; $y < min(64, $height); $y++) {
            for ($x = 0; $x < min(64, $width); $x++) {
                $rgb = imagecolorat($image, $x, $y) & 0xFFFFFF;
                $seen[$rgb] = ($seen[$rgb] ?? 0) + 1;
            }
        }

        arsort($seen);
        $palette = array_slice(array_keys($seen), 0, 2);

        // A checkerboard is two pale, near-grey squares. Anything else in that
        // corner is a drawing, and a drawing must not be flooded away.
        foreach ($palette as $rgb) {
            [$r, $g, $b] = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];

            if (max($r, $g, $b) < 150 || max($r, $g, $b) - min($r, $g, $b) > 24) {
                return $nothing;
            }
        }

        $clear = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $matches = function (int $x, int $y) use ($image, $palette): bool {
            $rgb = imagecolorat($image, $x, $y);

            if ((($rgb >> 24) & 0x7F) >= 110) {
                return false;
            }

            foreach ($palette as $background) {
                if (abs((($rgb >> 16) & 0xFF) - (($background >> 16) & 0xFF)) <= 26
                    && abs((($rgb >> 8) & 0xFF) - (($background >> 8) & 0xFF)) <= 26
                    && abs(($rgb & 0xFF) - ($background & 0xFF)) <= 26) {
                    return true;
                }
            }

            return false;
        };

        imagealphablending($image, false);

        $cellWidth = $grid ? $width / $grid['cols'] : $width;
        $cellHeight = $grid ? $height / $grid['rows'] : $height;

        // One byte per pixel rather than an array entry per pixel: a million
        // keys in a PHP array is tens of megabytes for what a megabyte of
        // string does, and this runs on a sheet the size of a phone photo.
        $done = str_repeat("\0", $width * $height);

        foreach (range(0, ($grid['cols'] ?? 1) - 1) as $column) {
            foreach (range(0, ($grid['rows'] ?? 1) - 1) as $row) {
                $left = (int) ($column * $cellWidth);
                $top = (int) ($row * $cellHeight);
                $right = (int) (($column + 1) * $cellWidth) - 1;
                $bottom = (int) (($row + 1) * $cellHeight) - 1;

                $queue = [];

                /*
                 * Seeded a few pixels inside the cell rather than on its very
                 * edge. The edge is usually where the ruling is, and a flood
                 * that starts on the ruling never gets going — which left the
                 * middle cells of a ruled sheet sitting in a grey box while the
                 * corner ones came out clean.
                 */
                foreach ([0, 3] as $inset) {
                    for ($x = $left + $inset; $x <= $right - $inset; $x++) {
                        $queue[] = [$x, $top + $inset];
                        $queue[] = [$x, $bottom - $inset];
                    }

                    for ($y = $top + $inset; $y <= $bottom - $inset; $y++) {
                        $queue[] = [$left + $inset, $y];
                        $queue[] = [$right - $inset, $y];
                    }
                }

                while ($queue !== []) {
                    [$x, $y] = array_pop($queue);

                    if ($x < $left || $y < $top || $x > $right || $y > $bottom) {
                        continue;
                    }

                    $key = $y * $width + $x;

                    if ($done[$key] !== "\0") {
                        continue;
                    }

                    $done[$key] = "\1";

                    if (! $matches($x, $y)) {
                        continue;
                    }

                    imagesetpixel($image, $x, $y, $clear);

                    $queue[] = [$x + 1, $y];
                    $queue[] = [$x - 1, $y];
                    $queue[] = [$x, $y + 1];
                    $queue[] = [$x, $y - 1];
                }
            }
        }

        $this->cutTrappedBackground($image, $palette, $done);

        imagealphablending($image, true);

        return ['cut' => true, 'palette' => $palette];
    }

    /**
     * The background the flood could not get to.
     *
     * A drawing encloses pockets of its own — inside a curled tail, between two
     * legs — and those are left sitting there as white rings after a flood from
     * the outside. They cannot simply be matched by colour, because a white eye
     * is the same white.
     *
     * A checkerboard gives it away: the background is two alternating shades, so
     * a pocket holding *both* of them is a hole through to the background, and
     * one holding a single flat colour is a drawing. That is the whole test.
     *
     * @param  array<int, int>  $palette
     * @param  string  $done  the pixels the outside flood already walked
     */
    private function cutTrappedBackground(GdImage $image, array $palette, string $done): void
    {
        if (count($palette) < 2) {
            return;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $clear = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $near = function (int $rgb, int $background): bool {
            return abs((($rgb >> 16) & 0xFF) - (($background >> 16) & 0xFF)) <= 26
                && abs((($rgb >> 8) & 0xFF) - (($background >> 8) & 0xFF)) <= 26
                && abs(($rgb & 0xFF) - ($background & 0xFF)) <= 26;
        };

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $key = $y * $width + $x;

                if ($done[$key] !== "\0") {
                    continue;
                }

                $rgb = imagecolorat($image, $x, $y);

                if ((($rgb >> 24) & 0x7F) >= 110 || ! $near($rgb, $palette[0]) && ! $near($rgb, $palette[1])) {
                    $done[$key] = "\1";

                    continue;
                }

                // Walk this pocket, remembering which shades it holds.
                $region = [];
                $shades = [];
                $queue = [[$x, $y]];

                while ($queue !== []) {
                    [$px, $py] = array_pop($queue);

                    if ($px < 0 || $py < 0 || $px >= $width || $py >= $height) {
                        continue;
                    }

                    $pkey = $py * $width + $px;

                    if ($done[$pkey] !== "\0") {
                        continue;
                    }

                    $colour = imagecolorat($image, $px, $py);

                    if ((($colour >> 24) & 0x7F) >= 110) {
                        continue;
                    }

                    if (! $near($colour, $palette[0]) && ! $near($colour, $palette[1])) {
                        continue;
                    }

                    // Which shade, by whichever is nearer — not by whichever
                    // matched first. A checkerboard's two greys are about as far
                    // apart as the tolerance is wide, so "first match" called
                    // every pixel the same shade and no pocket ever looked like
                    // a checkerboard.
                    $distance = fn (int $background): int => abs((($colour >> 16) & 0xFF) - (($background >> 16) & 0xFF))
                        + abs((($colour >> 8) & 0xFF) - (($background >> 8) & 0xFF))
                        + abs(($colour & 0xFF) - ($background & 0xFF));

                    $shade = $distance($palette[0]) <= $distance($palette[1]) ? 0 : 1;

                    $done[$pkey] = "\1";
                    $shades[$shade] = true;
                    $region[] = $pkey;

                    $queue[] = [$px + 1, $py];
                    $queue[] = [$px - 1, $py];
                    $queue[] = [$px, $py + 1];
                    $queue[] = [$px, $py - 1];
                }

                // Both shades, and big enough to be a hole rather than a speckle
                // of noise inside the drawing.
                if (count($shades) < 2 || count($region) < 40) {
                    continue;
                }

                foreach ($region as $pkey) {
                    imagesetpixel($image, $pkey % $width, intdiv($pkey, $width), $clear);
                }
            }
        }
    }

    /**
     * Takes the halo off.
     *
     * Where the drawing met the painted background, the two were blended into a
     * pale outline — too far from the background to be cut with it, and nothing
     * to do with the art. Left alone it shows up as a white rim around the pet
     * on every dark page in the app.
     *
     * Faded rather than cut. A pixel touching transparency is made as
     * see-through as it is close to the background colour: the ones that are
     * practically background vanish, the ones that are mostly drawing keep
     * nearly all of themselves, and the edge comes out soft instead of chewed.
     * Cutting outright took bites out of pale art that happened to sit against
     * the outline.
     *
     * @param  array<int, int>  $palette
     */
    private function defringe(GdImage $image, array $palette): void
    {
        $width = imagesx($image);
        $height = imagesy($image);

        // How far from the background a pixel can be and still be counted a
        // blend of it. Past this it is the drawing, whatever it sits next to.
        $reach = 96;

        $distance = function (int $rgb) use ($palette): int {
            $best = 765;

            foreach ($palette as $background) {
                $best = min($best, abs((($rgb >> 16) & 0xFF) - (($background >> 16) & 0xFF))
                    + abs((($rgb >> 8) & 0xFF) - (($background >> 8) & 0xFF))
                    + abs(($rgb & 0xFF) - ($background & 0xFF)));
            }

            return $best;
        };

        // Two rings: the blend a resize leaves is one pixel, two after a
        // downscale from a much larger sheet.
        foreach ([0, 1] as $pass) {
            $faded = [];

            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $rgb = imagecolorat($image, $x, $y);

                    if ((($rgb >> 24) & 0x7F) >= 110) {
                        continue;
                    }

                    $gap = $distance($rgb);

                    if ($gap > $reach) {
                        continue;
                    }

                    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                        $nx = $x + $dx;
                        $ny = $y + $dy;

                        if ($nx < 0 || $ny < 0 || $nx >= $width || $ny >= $height
                            || ((imagecolorat($image, $nx, $ny) >> 24) & 0x7F) >= 110) {
                            $faded[] = [$x, $y, $rgb, (int) round(127 * (1 - $gap / $reach))];

                            break;
                        }
                    }
                }
            }

            foreach ($faded as [$x, $y, $rgb, $alpha]) {
                imagesetpixel($image, $x, $y, imagecolorallocatealpha(
                    $image,
                    ($rgb >> 16) & 0xFF,
                    ($rgb >> 8) & 0xFF,
                    $rgb & 0xFF,
                    min(127, max((($rgb >> 24) & 0x7F), $alpha)),
                ));
            }
        }
    }

    /**
     * Brings a picture to the size its slot wants, when it is the right shape
     * but bigger.
     *
     * Image generators are asked for 1024x768 and hand back 1536x1152 or
     * 2048x1536 instead, which is the same sheet at a higher resolution and
     * perfectly usable. Anything that is not the right *shape* is left alone,
     * so the size check still refuses it and says what it should have been.
     *
     * A sprite sheet also gets its ruled grid rubbed out here — see
     * stripSheetLines(). Both happen before the checks run, so the checks and
     * the file that gets stored are talking about the same pixels.
     *
     * @return array{binary: string, checks: array<int, array{label: string, status: string}>}
     */
    public function normalize(string $binary, CosmeticSlot $slot): array
    {
        $spec = $slot->uploadSpec();
        $info = @getimagesizefromstring($binary);
        $unchanged = ['binary' => $binary, 'checks' => []];

        if (! is_array($info) || ($info[2] ?? null) !== IMAGETYPE_PNG) {
            return $unchanged;
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];

        if ($width * $height > self::MAX_PIXELS) {
            return $unchanged;
        }

        $sheet = $slot->poseGrid() !== null;

        /*
         * A sheet is resized on nearly the right shape rather than exactly it.
         * Generators hand back 1200x896 when asked for 1024x768 — a percent off
         * the right shape — and the poses are still on an even grid inside it,
         * because the model divided whatever canvas it used. Squashing that by
         * a percent is invisible; turning it away over four pixels is not.
         *
         * Everything else stays exact. A frame or a name plate is one picture
         * with no grid to preserve, and a stretched ring is a visibly wonky one.
         */
        $ratio = $height === 0 ? 0 : ($width / $height) / ($spec['width'] / $spec['height']);
        $rightShape = $sheet
            ? abs($ratio - 1) <= 0.03
            : $width * $spec['height'] === $height * $spec['width'];

        $resize = $rightShape && ($width > $spec['width'] || ($sheet && $width !== $spec['width']));

        if (! $resize && ! $sheet) {
            return $unchanged;
        }

        $source = @imagecreatefromstring($binary);

        if (! $source instanceof GdImage) {
            return $unchanged;
        }

        imagepalettetotruecolor($source);

        if ($resize) {
            $target = imagecreatetruecolor($spec['width'], $spec['height']);
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagecopyresampled($target, $source, 0, 0, 0, 0, $spec['width'], $spec['height'], $width, $height);
            imagedestroy($source);
            $source = $target;
        }

        $checks = [];

        if ($resize) {
            $checks[] = ['label' => "Resized from {$width}×{$height} to {$spec['width']}×{$spec['height']}", 'status' => 'pass'];
        }

        // Before the rulings are looked for: on a picture with the background
        // painted in, every row is "full of pixels" and nothing can be told
        // from anything.
        if ($spec['alpha']) {
            $background = $this->cutBackground($source, $slot->poseGrid());

            if ($background['cut']) {
                $checks[] = ['label' => 'Cut out the painted-in background', 'status' => 'pass'];
            }

            // Always, not only after a cut. Art that arrives properly
            // transparent still carries a pale rim where it was drawn against
            // white, and that rim is what shows up as a halo on a dark page.
            imagealphablending($source, false);
            $this->defringe($source, $background['palette'] ?: [0xFFFFFF]);
            imagealphablending($source, true);
        }

        if ($sheet && ($erased = $this->stripSheetLines($source)) > 0) {
            $checks[] = ['label' => 'Rubbed out '.$erased.' ruled grid '.($erased === 1 ? 'line' : 'lines'), 'status' => 'pass'];
        }

        imagealphablending($source, false);
        imagesavealpha($source, true);

        ob_start();
        imagepng($source, null, 9);
        $png = (string) ob_get_clean();

        imagedestroy($source);

        return ['binary' => $png, 'checks' => $checks];
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
     * A WebP upload as a PNG, alpha and all; anything else untouched.
     *
     * The browser sends an over-size PNG as a WebP to get it under PHP's upload
     * limit (resources/js/png-shrink.js). Turned back here, before anything
     * looks at it, so every check, cut and stored file only ever deals in PNG.
     */
    public function asPng(string $binary): string
    {
        $info = @getimagesizefromstring($binary);

        if (! is_array($info) || ($info[2] ?? null) !== IMAGETYPE_WEBP || $info[0] * $info[1] > self::MAX_PIXELS) {
            return $binary;
        }

        $image = @imagecreatefromstring($binary);

        if (! $image instanceof GdImage) {
            return $binary;
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        imagepng($image, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    /**
     * Whether an upload for a pet is the all-ages sheet: nine cells by six,
     * so half as wide again as it is tall.
     */
    public function isFamilySheet(string $binary): bool
    {
        return $this->hasShape($binary, CosmeticSlot::FAMILY_GRID['cols'] / CosmeticSlot::FAMILY_GRID['rows']);
    }

    /**
     * Whether an upload is the six-by-six square every pet was made from
     * before the re-grid — the old prompt's picture, which has twelve poses
     * an age and cannot be cut into eighteen.
     */
    public function isOldFamilySheet(string $binary): bool
    {
        return $this->hasShape($binary, 1.0);
    }

    /** A readable PNG of about this width-to-height, big enough to cut. */
    private function hasShape(string $binary, float $ratio): bool
    {
        $info = @getimagesizefromstring($binary);

        if (! is_array($info) || ($info[2] ?? null) !== IMAGETYPE_PNG) {
            return false;
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];

        return $height > 0
            && abs(($width / $height) / $ratio - 1) <= 0.03
            && $height >= 600
            && $width * $height <= self::MAX_PIXELS;
    }

    /**
     * A pet's whole family from one sheet: the three ages cut apart, tidied
     * and checked, ready to store as three ordinary sheets, with where each
     * age's paws are.
     *
     * Every age is held to every check, because every pet has all three: a
     * younger age with a missing pose, or one that is just another age's
     * drawing handed back again, fails the upload like a broken adult does.
     *
     * @return array{sheets: array<string, string|null>, anchors: array<string, array<string, array<int, float>>>, checks: array<int, array{label: string, status: string}>}
     *                                                                                                                                                                       keyed by PetStage value
     */
    public function prepareFamily(string $binary): array
    {
        $cut = $this->splitFamily($binary);

        if ($cut === null) {
            return ['sheets' => ['baby' => null, 'young' => null, 'adult' => null], 'anchors' => [], 'checks' => [['label' => 'The picture would not open', 'status' => 'fail']]];
        }

        $sheets = [];
        $stageChecks = [];

        foreach (PetStage::cases() as $stage) {
            $tidied = $this->normalize($cut['sheets'][$stage->value], CosmeticSlot::Pet);
            $sheets[$stage->value] = $tidied['binary'];
            $stageChecks[$stage->value] = [...$tidied['checks'], ...$this->inspect($tidied['binary'], CosmeticSlot::Pet)];
        }

        $checks = [...$cut['checks'], ...array_map(
            fn (array $check) => ['label' => 'Adult: '.$check['label'], 'status' => $check['status']],
            $stageChecks['adult'],
        )];

        // Youngest last, so "young" is judged before "baby" is compared with it.
        foreach ([PetStage::Young, PetStage::Baby] as $stage) {
            $older = $stage->next();
            $failed = collect($stageChecks[$stage->value])->firstWhere('status', 'fail');
            $olderSheet = $sheets[$older->value] ?? $sheets['adult'];
            $name = $stage->label();

            $problem = match (true) {
                $failed !== null => mb_strtolower($failed['label']),
                $this->looksTheSame($sheets[$stage->value], $olderSheet) => 'it is the '.mb_strtolower($older === PetStage::Adult ? 'adult' : $older->label()).' drawing again',
                default => null,
            };

            if ($problem !== null) {
                $sheets[$stage->value] = null;
                $checks[] = ['label' => "{$name}: {$problem} — generate the picture again", 'status' => 'fail'];

                continue;
            }

            // No size check: every age is cut to the same size, and how big
            // each looks is PetStage::pixels(), not the drawing.
            $checks[] = ['label' => "{$name}: all ".count(CosmeticSlot::PET_POSES).' poses, drawn at '.$stage->pixels().'px', 'status' => 'pass'];
        }

        $anchors = array_map(fn (?string $sheet) => $sheet === null ? [] : $this->anchors($sheet), $sheets);

        return ['sheets' => $sheets, 'anchors' => $anchors, 'checks' => $checks];
    }

    /**
     * Where the toy goes on one age's cut sheet.
     *
     * The play, toss and back poses are drawn with empty paws and the app
     * puts the toy into them, so it needs to know where the paws are. Each is
     * a point as fractions of the cell, [x, y]:
     *
     * - play: the front paws, which is the far right of the lowest quarter
     *   of the drawing (it faces right), on its foot line;
     * - toss and back: the paws held up, which is the highest point of the
     *   drawing above the middle of its body.
     *
     * And the toy's own cell, as [middle x, middle y, width, height], so the
     * app can put the middle of the toy where the paws are.
     *
     * @return array<string, array<int, float>>
     */
    public function anchors(string $sheet): array
    {
        $image = @imagecreatefromstring($sheet);

        if (! $image instanceof GdImage) {
            return [];
        }

        $grid = CosmeticSlot::Pet->poseGrid();
        $cell = (int) (imagesx($image) / $grid['cols']);
        $fraction = fn (float $pixels, int $from) => round(($pixels - $from) / $cell, 3);
        $anchors = [];

        foreach ([...CosmeticSlot::EMPTY_PAW_POSES, 'toy'] as $pose) {
            $index = array_search($pose, CosmeticSlot::PET_POSES, true);
            $left = ($index % $grid['cols']) * $cell;
            $top = intdiv($index, $grid['cols']) * $cell;
            $box = $this->boundingBox($image, $left, $top, $cell, $cell);

            if ($box === null) {
                continue;
            }

            $height = $box['bottom'] - $box['top'] + 1;

            if ($pose === 'toy') {
                $anchors[$pose] = [
                    $fraction(($box['left'] + $box['right']) / 2, $left),
                    $fraction(($box['top'] + $box['bottom']) / 2, $top),
                    round(($box['right'] - $box['left'] + 1) / $cell, 3),
                    round($height / $cell, 3),
                ];

                continue;
            }

            if ($pose === 'play') {
                $from = (int) ($box['bottom'] - $height * 0.25);
                $xs = $this->visibleXs($image, $box['left'], $box['right'], $from, $box['bottom']);
                $anchors[$pose] = [$fraction($xs === [] ? $box['right'] : max($xs), $left), $fraction($box['bottom'], $top)];

                continue;
            }

            // Held-up paws are over the middle of the body. Not simply the
            // highest point of the drawing: a tail curled up behind a pet on
            // its back reaches higher than its paws do, and the toy ended up
            // balanced on the tip of the tail.
            $body = $this->visibleXs($image, $box['left'], $box['right'], $box['top'], $box['bottom']);
            $middle = $body === [] ? ($box['left'] + $box['right']) / 2 : array_sum($body) / count($body);
            $from = (int) max($box['left'], $middle - $cell * 0.12);
            $to = (int) min($box['right'], $middle + $cell * 0.12);
            $highest = $box['top'];

            while ($highest < $box['bottom'] && $this->visibleXs($image, $from, $to, $highest, $highest) === []) {
                $highest++;
            }

            $xs = $this->visibleXs($image, $from, $to, $highest, (int) ($highest + max(2, $height * 0.08)));
            $anchors[$pose] = [$fraction($xs === [] ? $middle : array_sum($xs) / count($xs), $left), $fraction($highest, $top)];
        }

        imagedestroy($image);

        return $anchors;
    }

    /**
     * The x of every visible pixel in a band of rows, one per pixel.
     *
     * @return array<int, int>
     */
    private function visibleXs(GdImage $image, int $left, int $right, int $top, int $bottom): array
    {
        $xs = [];

        for ($y = $top; $y <= $bottom; $y++) {
            for ($x = $left; $x <= $right; $x++) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) < 110) {
                    $xs[] = $x;
                }
            }
        }

        return $xs;
    }

    /**
     * Cuts the all-ages sheet into three ordinary six-by-three sheets, one
     * per age, untidied.
     *
     * The grid is not trusted. Asked for six even rows, a generator draws the
     * adults taller than the babies and lets the rows grow to fit, so slicing
     * the sheet into even strips cuts through the animals — feet at the top of
     * one cell, the head chopped off the next. So each animal is *found*
     * instead: rows are the bands of art between empty lines across the whole
     * picture, and within a row, each pose is the art between empty columns.
     * Only when the art will not come apart into nine by six does it fall back
     * to even strips.
     *
     * Every pose is then set into its 256px cell at one scale for the whole
     * picture, so the ages keep the sizes the generator gave them, and each
     * row keeps its own foot line — the jump still floats above it.
     *
     * @return array{sheets: array<string, string>, checks: array<int, array{label: string, status: string}>}|null
     */
    public function splitFamily(string $binary): ?array
    {
        if (! $this->isFamilySheet($binary)) {
            return null;
        }

        $source = @imagecreatefromstring($binary);

        if (! $source instanceof GdImage) {
            return null;
        }

        imagepalettetotruecolor($source);
        $grid = CosmeticSlot::FAMILY_GRID;
        $width = imagesx($source);
        $height = imagesy($source);
        $checks = [];

        $background = $this->cutBackground($source, $grid);

        if ($background['cut']) {
            $checks[] = ['label' => 'Cut out the painted-in background', 'status' => 'pass'];
            imagealphablending($source, false);
            $this->defringe($source, $background['palette']);
            imagealphablending($source, true);
        }

        // Before looking for the gaps between animals: a ruled grid fills
        // every gap it is drawn in.
        if (($erased = $this->stripSheetLines($source)) > 0) {
            $checks[] = ['label' => 'Rubbed out '.$erased.' ruled grid '.($erased === 1 ? 'line' : 'lines'), 'status' => 'pass'];
        }

        [$labels, $pieces] = $this->pieces($source);
        $pieces = array_filter($pieces, fn (array $piece) => $piece['count'] >= 6);

        $found = true;
        $touched = false;
        $rows = $this->bands(
            fn (int $y) => $this->visibleAcross($source, 0, $width, $y, true),
            $height,
            $grid['rows'],
            fn (array $run) => $this->divide($pieces, 'y', $run, [0, $width - 1]),
            $touched,
        );

        // Two animals drawn touching — one's feet on the other's head — are
        // one piece of art. Pull them apart before anything is handed out.
        //
        // Only along lines found in the art. An even strip is a guess, and
        // cutting along a guess slices one wide animal in two — a landed
        // gremlin lost its tail to the cell beside it that way. Along even
        // strips each piece just goes, whole, to the cell its middle is in.
        if ($rows === null) {
            $found = false;
            $rows = $this->evenBands($height, $grid['rows']);
        } else {
            $pieces = $this->separate($labels, $pieces, array_map(fn (array $row) => $row[1], array_slice($rows, 0, -1)));
        }

        // Where each pose roughly is, row by row, in reading order.
        $cells = [];

        foreach ($rows as $row) {
            $columns = $this->bands(
                fn (int $x) => $this->visibleAcross($source, $row[0], $row[1] + 1, $x, false),
                $width,
                $grid['cols'],
                fn (array $run) => $this->divide($pieces, 'x', $run, $row),
                $touched,
            );

            // And two touching side by side — a curled tail against the pose
            // beside it — the same way, and again only along found lines.
            if ($columns === null) {
                $found = false;
                $columns = $this->evenBands($width, $grid['cols']);
            } else {
                $pieces = $this->separate($labels, $pieces, array_map(fn (array $column) => $column[1], array_slice($columns, 0, -1)), 'x', $row);
            }

            foreach ($columns as $column) {
                $cells[] = ['left' => $column[0], 'top' => $row[0], 'right' => $column[1], 'bottom' => $row[1]];
            }
        }

        // And which art belongs to which pose — whole pieces, never a cut line.
        $figures = $this->claim($cells, $pieces);

        $all = $grid['cols'] * $grid['rows'];

        $checks = [
            match (true) {
                ! $found => ['label' => "{$width}×{$height} — cut into even strips; the poses would not come apart cleanly, so check them", 'status' => 'warn'],
                $touched => ['label' => "{$width}×{$height} — found all {$all} poses, but some were touching — check the edges with Try it out", 'status' => 'warn'],
                default => ['label' => "{$width}×{$height} — found all {$all} poses and cut them apart", 'status' => 'pass'],
            },
            ...$checks,
        ];

        $spec = CosmeticSlot::Pet->uploadSpec();
        $sheetGrid = CosmeticSlot::Pet->poseGrid();
        $cell = $spec['width'] / $sheetGrid['cols'];
        $foot = $cell - 24;
        $posesPerAge = count(CosmeticSlot::PET_POSES);

        /*
         * Every pose drawn at the same animal size, whatever the generator did.
         *
         * Generators do not keep one scale across a picture: the second row of
         * an age comes back smaller than the first, and the ages are sized by
         * whim. So each pose is sized on its own, by how much animal is in it —
         * the area of its biggest piece. A dog curled up asleep covers about
         * as much as the same dog standing, so area is a size that does not
         * care about the pose, where height would call every sleeping dog tiny.
         *
         * Every age is cut at the same FULL size: its own idle pose stands 70%
         * of a cell tall, as a one-age sheet always has, and every other
         * pose of that age has the idle's area. How big an age then looks on
         * screen is not the art's business at all — it is PetStage::pixels().
         * The toy on its own has no animal to measure, so it takes the scale
         * its row was corrected by, which is the scale it was drawn at.
         */
        $mass = fn (array $figure) => max(1, ...array_map(fn (int $id) => $pieces[$id]['count'], $figure['pieces'] ?? [0 => -1]) ?: [1]);
        $toyIndex = array_search('toy', CosmeticSlot::PET_POSES, true);
        $scales = [];

        foreach ([PetStage::Baby, PetStage::Young, PetStage::Adult] as $band => $stage) {
            $idle = $figures[$band * $posesPerAge];
            $unit = 0.7 * $cell / max(1, $idle['bottom'] - $idle['top'] + 1);

            foreach (array_keys(CosmeticSlot::PET_POSES) as $index) {
                $number = $band * $posesPerAge + $index;
                $figure = $figures[$number];

                if ($index === $toyIndex || ($figure['empty'] ?? false)) {
                    continue;
                }

                $scales[$number] = $unit * sqrt($mass($idle) / $mass($figure));
            }

            // The toy: the scale of the poses in its own row, which were drawn
            // at the same size it was.
            $row = intdiv($toyIndex, $grid['cols']) * $grid['cols'];
            $neighbours = array_filter(
                array_map(fn (int $index) => $scales[$band * $posesPerAge + $index] ?? null, range($row, $toyIndex - 1)),
                fn (?float $scale) => $scale !== null,
            );
            sort($neighbours);
            $scales[$band * $posesPerAge + $toyIndex] = $neighbours === [] ? $unit : $neighbours[intdiv(count($neighbours), 2)];
        }

        // Nothing grows past its cell.
        foreach ($scales as $number => $scale) {
            $figure = $figures[$number];
            $scales[$number] = min($scale, 0.94 * $cell / max(1, $figure['right'] - $figure['left'] + 1, $figure['bottom'] - $figure['top'] + 1));
        }

        $sheets = [];

        // Baby at the top, as the prompt asks.
        foreach ([PetStage::Baby, PetStage::Young, PetStage::Adult] as $band => $stage) {
            $sheet = imagecreatetruecolor($spec['width'], $spec['height']);
            imagealphablending($sheet, false);
            imagesavealpha($sheet, true);
            imagefill($sheet, 0, 0, imagecolorallocatealpha($sheet, 0, 0, 0, 127));

            foreach (array_keys(CosmeticSlot::PET_POSES) as $index) {
                $number = $band * $posesPerAge + $index;
                $figure = $figures[$number];

                if ($figure['empty'] ?? false) {
                    continue;
                }

                // This row's floor: where most of its animals stand.
                $row = intdiv($number, $grid['cols']);
                $bottoms = array_map(fn (array $one) => $one['bottom'], array_slice($figures, $row * $grid['cols'], $grid['cols']));
                sort($bottoms);
                $floor = $bottoms[intdiv(count($bottoms), 2)];

                $drawWidth = (int) round(($figure['right'] - $figure['left'] + 1) * $scales[$number]);
                $drawHeight = (int) round(($figure['bottom'] - $figure['top'] + 1) * $scales[$number]);
                $cellLeft = ($index % $sheetGrid['cols']) * $cell;
                $cellTop = intdiv($index, $sheetGrid['cols']) * $cell;
                $top = (int) round($cellTop + $foot - ($floor - $figure['bottom']) * $scales[$number] - $drawHeight);
                $top = max($cellTop + 2, min($top, $cellTop + $cell - 2 - $drawHeight));

                $lifted = $this->lift($source, $labels, $figure);

                imagecopyresampled(
                    $sheet,
                    $lifted,
                    (int) round($cellLeft + ($cell - $drawWidth) / 2),
                    $top,
                    0,
                    0,
                    $drawWidth,
                    $drawHeight,
                    imagesx($lifted),
                    imagesy($lifted),
                );

                imagedestroy($lifted);
            }

            ob_start();
            imagepng($sheet, null, 9);
            $sheets[$stage->value] = (string) ob_get_clean();
            imagedestroy($sheet);
        }

        imagedestroy($source);

        return ['sheets' => $sheets, 'checks' => $checks];
    }

    /**
     * Every separate piece of art in the picture: each animal, and each thing
     * floating free of one — a thrown toy, a stray speck.
     *
     * Labelled at half size, which is plenty to tell one animal from the next
     * and a quarter of the work, and eight-connected so a thin outline does
     * not fall apart into pieces.
     *
     * @return array{0: array{width: int, labels: array<int, int>}, 1: array<int, array{left: int, top: int, right: int, bottom: int, count: int, x: float, y: float}>}
     */
    private function pieces(GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $halfWidth = intdiv($width + 1, 2);
        $halfHeight = intdiv($height + 1, 2);
        $solid = [];

        for ($y = 0; $y < $halfHeight; $y++) {
            for ($x = 0; $x < $halfWidth; $x++) {
                foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$dx, $dy]) {
                    $px = min($width - 1, $x * 2 + $dx);
                    $py = min($height - 1, $y * 2 + $dy);

                    if (((imagecolorat($image, $px, $py) >> 24) & 0x7F) < 110) {
                        $solid[$y * $halfWidth + $x] = true;

                        break;
                    }
                }
            }
        }

        $labels = [];
        $pieces = [];
        $next = 0;

        foreach (array_keys($solid) as $start) {
            if (isset($labels[$start])) {
                continue;
            }

            $id = ++$next;
            $labels[$start] = $id;
            $stack = [$start];
            $piece = ['left' => PHP_INT_MAX, 'top' => PHP_INT_MAX, 'right' => 0, 'bottom' => 0, 'count' => 0, 'x' => 0.0, 'y' => 0.0];

            while ($stack !== []) {
                $at = array_pop($stack);
                $x = $at % $halfWidth;
                $y = intdiv($at, $halfWidth);

                $piece['left'] = min($piece['left'], $x);
                $piece['top'] = min($piece['top'], $y);
                $piece['right'] = max($piece['right'], $x);
                $piece['bottom'] = max($piece['bottom'], $y);
                $piece['count']++;
                $piece['x'] += $x;
                $piece['y'] += $y;

                for ($dy = -1; $dy <= 1; $dy++) {
                    for ($dx = -1; $dx <= 1; $dx++) {
                        $nx = $x + $dx;
                        $ny = $y + $dy;
                        $neighbour = $ny * $halfWidth + $nx;

                        if ($nx < 0 || $ny < 0 || $nx >= $halfWidth || $ny >= $halfHeight || isset($labels[$neighbour]) || ! isset($solid[$neighbour])) {
                            continue;
                        }

                        $labels[$neighbour] = $id;
                        $stack[] = $neighbour;
                    }
                }
            }

            // Back to full-size coordinates.
            $pieces[$id] = [
                'left' => $piece['left'] * 2,
                'top' => $piece['top'] * 2,
                'right' => min($width - 1, $piece['right'] * 2 + 1),
                'bottom' => min($height - 1, $piece['bottom'] * 2 + 1),
                'count' => $piece['count'],
                'x' => $piece['x'] / $piece['count'] * 2 + 1,
                'y' => $piece['y'] / $piece['count'] * 2 + 1,
            ];
        }

        return [['width' => $halfWidth, 'labels' => $labels], $pieces];
    }

    /**
     * Cuts apart any piece that is really two animals drawn touching — an
     * adult's feet resting on the head of the one below, or a sleeping cat's
     * tail curled against the one beside it.
     *
     * A piece counts as two when a good share of it lies on each side of a
     * line between rows ($axis 'y') or between cells in a row ($axis 'x',
     * only for pieces whose middle is inside $within). It is cut where it is
     * narrowest in its middle stretch, which is the spot where the two
     * drawings meet, and each half becomes a piece of its own. One animal
     * that merely pokes over a line — a tail, an ear — is never cut: most of
     * it sits on one side.
     *
     * @param  array{width: int, labels: array<int, int>}  $labels  relabelled in place
     * @param  array<int, array{left: int, top: int, right: int, bottom: int, count: int, x: float, y: float}>  $pieces
     * @param  array<int, int>  $lines  where one row (or cell) ends and the next begins, along $axis
     * @param  array{0: int, 1: int}|null  $within  for 'x': the row, top to bottom
     * @param  int  $depth  how many times round already
     * @return array<int, array{left: int, top: int, right: int, bottom: int, count: int, x: float, y: float}>
     */
    private function separate(array &$labels, array $pieces, array $lines, string $axis = 'y', ?array $within = null, int $depth = 0): array
    {
        $halfWidth = $labels['width'];
        $cutAny = false;
        $next = $pieces === [] ? 1 : max(array_keys($pieces)) + 1;
        [$from, $to] = $axis === 'y' ? ['top', 'bottom'] : ['left', 'right'];

        foreach ($pieces as $id => $piece) {
            if ($within !== null && ($piece['y'] < $within[0] || $piece['y'] > $within[1])) {
                continue;
            }

            $span = $piece[$to] - $piece[$from] + 1;
            $straddled = array_filter($lines, fn (int $line) => $line - $piece[$from] >= 0.3 * $span && 0.3 * $span <= $piece[$to] - $line);

            if ($straddled === []) {
                continue;
            }

            $top = intdiv($piece['top'], 2);
            $bottom = intdiv($piece['bottom'], 2);
            $left = intdiv($piece['left'], 2);
            $right = intdiv($piece['right'], 2);
            [$first, $last] = $axis === 'y' ? [$top, $bottom] : [$left, $right];

            // How much of the piece crosses each half-size line of its middle
            // stretch, the long way across it.
            $cut = null;
            $narrowest = PHP_INT_MAX;

            for ($at = $first + (int) (($last - $first) * 0.2); $at <= $last - (int) (($last - $first) * 0.2); $at++) {
                $across = 0;

                if ($axis === 'y') {
                    for ($x = $left; $x <= $right; $x++) {
                        $across += ($labels['labels'][$at * $halfWidth + $x] ?? null) === $id ? 1 : 0;
                    }
                } else {
                    for ($y = $top; $y <= $bottom; $y++) {
                        $across += ($labels['labels'][$y * $halfWidth + $at] ?? null) === $id ? 1 : 0;
                    }
                }

                if ($across < $narrowest) {
                    $narrowest = $across;
                    $cut = $at;
                }
            }

            if ($cut === null) {
                continue;
            }

            $second = $next++;
            $cutAny = true;
            $halves = [$id => ['left' => PHP_INT_MAX, 'top' => PHP_INT_MAX, 'right' => 0, 'bottom' => 0, 'count' => 0, 'x' => 0.0, 'y' => 0.0]];
            $halves[$second] = $halves[$id];

            for ($y = $top; $y <= $bottom; $y++) {
                for ($x = $left; $x <= $right; $x++) {
                    $at = $y * $halfWidth + $x;

                    if (($labels['labels'][$at] ?? null) !== $id) {
                        continue;
                    }

                    $half = ($axis === 'y' ? $y : $x) > $cut ? $second : $id;
                    $labels['labels'][$at] = $half;
                    $halves[$half]['left'] = min($halves[$half]['left'], $x);
                    $halves[$half]['top'] = min($halves[$half]['top'], $y);
                    $halves[$half]['right'] = max($halves[$half]['right'], $x);
                    $halves[$half]['bottom'] = max($halves[$half]['bottom'], $y);
                    $halves[$half]['count']++;
                    $halves[$half]['x'] += $x;
                    $halves[$half]['y'] += $y;
                }
            }

            foreach ($halves as $half => $stats) {
                if ($stats['count'] === 0) {
                    unset($pieces[$half]);

                    continue;
                }

                $pieces[$half] = [
                    'left' => $stats['left'] * 2,
                    'top' => $stats['top'] * 2,
                    'right' => $stats['right'] * 2 + 1,
                    'bottom' => $stats['bottom'] * 2 + 1,
                    'count' => $stats['count'],
                    'x' => $stats['x'] / $stats['count'] * 2 + 1,
                    'y' => $stats['y'] / $stats['count'] * 2 + 1,
                ];
            }
        }

        // A chain of three or more joined animals — a scruff hand reaching up
        // into every row — comes apart one cut at a time, so go round again
        // until nothing straddles a line.
        return $cutAny && $depth < 10
            ? $this->separate($labels, $pieces, $lines, $axis, $within, $depth + 1)
            : $pieces;
    }

    /**
     * Where a run holding two rows (or two columns) of animals divides: halfway
     * across the widest gap between the middles of the animals in it.
     *
     * Only the big pieces vote — a third the size of the biggest there or more
     * — so a thrown toy floating between two rows cannot pull the line to it.
     * The line only decides which cell a piece's middle falls in; no animal is
     * ever cut along it.
     *
     * @param  array<int, array{left: int, top: int, right: int, bottom: int, count: int, x: float, y: float}>  $pieces
     * @param  array{0: int, 1: int}  $run  along $axis
     * @param  array{0: int, 1: int}  $across  the other way
     */
    private function divide(array $pieces, string $axis, array $run, array $across): ?int
    {
        $other = $axis === 'y' ? 'x' : 'y';
        $inside = array_filter($pieces, fn (array $piece) => $piece[$axis] >= $run[0] && $piece[$axis] <= $run[1]
            && $piece[$other] >= $across[0] && $piece[$other] <= $across[1]);

        if (count($inside) < 2) {
            return null;
        }

        $biggest = max(array_column($inside, 'count'));
        $middles = array_column(array_filter($inside, fn (array $piece) => $piece['count'] >= $biggest / 3), $axis);
        sort($middles);

        if (count($middles) < 2) {
            return null;
        }

        $split = null;
        $widest = -1.0;

        for ($i = 1; $i < count($middles); $i++) {
            if ($widest < $middles[$i] - $middles[$i - 1]) {
                $widest = $middles[$i] - $middles[$i - 1];
                $split = (int) round(($middles[$i] + $middles[$i - 1]) / 2);
            }
        }

        return $split;
    }

    /**
     * Hands every piece of art to a pose.
     *
     * A piece belongs to the cell its middle is in — so an animal whose tail
     * dips into the row below still comes out whole, from its own cell. A
     * small piece floating free (a thrown toy) goes instead to whichever
     * animal it sits closest to, which is the one throwing it even when it has
     * sailed up over a row line. Specks are dropped.
     *
     * @param  array<int, array{left: int, top: int, right: int, bottom: int}>  $cells
     * @param  array<int, array{left: int, top: int, right: int, bottom: int, count: int, x: float, y: float}>  $pieces
     * @return array<int, array{left: int, top: int, right: int, bottom: int, pieces?: array<int, int>, empty?: bool}>
     */
    private function claim(array $cells, array $pieces): array
    {
        $pieces = array_filter($pieces, fn (array $piece) => $piece['count'] >= 6);
        $owner = [];

        foreach ($pieces as $id => $piece) {
            $best = null;
            $bestDistance = INF;

            foreach ($cells as $index => $cell) {
                $dx = max(0, $cell['left'] - $piece['x'], $piece['x'] - $cell['right']);
                $dy = max(0, $cell['top'] - $piece['y'], $piece['y'] - $cell['bottom']);
                $distance = $dx * $dx + $dy * $dy;

                if ($distance < $bestDistance) {
                    $best = $index;
                    $bestDistance = $distance;
                }
            }

            $owner[$id] = $best;
        }

        // Each cell's animal: its biggest piece.
        $anchors = [];

        foreach ($owner as $id => $index) {
            if (! isset($anchors[$index]) || $pieces[$id]['count'] > $pieces[$anchors[$index]]['count']) {
                $anchors[$index] = $id;
            }
        }

        $gap = function (array $one, array $other): float {
            $dx = max(0, $one['left'] - $other['right'], $other['left'] - $one['right']);
            $dy = max(0, $one['top'] - $other['bottom'], $other['top'] - $one['bottom']);

            return sqrt($dx * $dx + $dy * $dy);
        };

        foreach ($owner as $id => $index) {
            if ($anchors[$index] === $id || $pieces[$id]['count'] >= 0.25 * $pieces[$anchors[$index]]['count']) {
                continue;
            }

            $closest = $index;
            $closestGap = $gap($pieces[$id], $pieces[$anchors[$index]]);

            foreach ($anchors as $other => $anchor) {
                if (($distance = $gap($pieces[$id], $pieces[$anchor])) < $closestGap) {
                    $closest = $other;
                    $closestGap = $distance;
                }
            }

            $owner[$id] = $closest;
        }

        $figures = [];

        foreach ($cells as $index => $cell) {
            $mine = array_keys($owner, $index, true);

            if ($mine === []) {
                $figures[] = ['left' => $cell['left'], 'top' => $cell['top'], 'right' => $cell['left'], 'bottom' => $cell['top'], 'empty' => true];

                continue;
            }

            $figures[] = [
                'left' => min(array_map(fn (int $id) => $pieces[$id]['left'], $mine)),
                'top' => min(array_map(fn (int $id) => $pieces[$id]['top'], $mine)),
                'right' => max(array_map(fn (int $id) => $pieces[$id]['right'], $mine)),
                'bottom' => max(array_map(fn (int $id) => $pieces[$id]['bottom'], $mine)),
                'pieces' => $mine,
            ];
        }

        return $figures;
    }

    /**
     * One pose, cut out on its own: the pixels of its own pieces inside its
     * box, and nothing of the neighbour whose ear pokes into the same box.
     *
     * @param  array{width: int, labels: array<int, int>}  $labels
     * @param  array{left: int, top: int, right: int, bottom: int, pieces?: array<int, int>}  $figure
     */
    private function lift(GdImage $source, array $labels, array $figure): GdImage
    {
        $width = $figure['right'] - $figure['left'] + 1;
        $height = $figure['bottom'] - $figure['top'] + 1;
        $mine = array_flip($figure['pieces'] ?? []);

        $lifted = imagecreatetruecolor($width, $height);
        imagealphablending($lifted, false);
        imagesavealpha($lifted, true);
        imagefill($lifted, 0, 0, imagecolorallocatealpha($lifted, 0, 0, 0, 127));

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $sx = $figure['left'] + $x;
                $sy = $figure['top'] + $y;
                $label = $labels['labels'][intdiv($sy, 2) * $labels['width'] + intdiv($sx, 2)] ?? null;

                if ($label !== null && isset($mine[$label])) {
                    imagesetpixel($lifted, $x, $y, imagecolorat($source, $sx, $sy));
                }
            }
        }

        return $lifted;
    }

    /**
     * How many pixels of one line of the picture are art — a row when
     * $across, a column between $from and $to otherwise.
     */
    private function visibleAcross(GdImage $image, int $from, int $to, int $at, bool $across): int
    {
        $visible = 0;

        for ($i = $from; $i < $to; $i++) {
            $rgb = $across ? imagecolorat($image, $i, $at) : imagecolorat($image, $at, $i);
            $visible += (($rgb >> 24) & 0x7F) < 110 ? 1 : 0;
        }

        return $visible;
    }

    /**
     * The runs of art along one direction, separated by empty lines, as
     * [first, last] pairs — exactly $expected of them, or null.
     *
     * A stray speck makes a run of its own, and a thrown toy floating clear of
     * the animal under it makes another. Both are folded into their nearest
     * neighbour — whichever pair of runs has the narrowest gap between them —
     * until the count is right.
     *
     * Too few runs means two rows touch: a big adult's tail dipping into the
     * row below, so there is no empty line between them. $splitAt says where
     * the widest run divides — worked out from where the animals in it sit,
     * never from a line through them — and $touched says it happened.
     *
     * @param  callable(int): int  $visibleAt
     * @param  callable(array{0: int, 1: int}): ?int  $splitAt
     * @return array<int, array{0: int, 1: int}>|null
     */
    private function bands(callable $visibleAt, int $length, int $expected, callable $splitAt, bool &$touched = false): ?array
    {
        $runs = [];
        $start = null;

        for ($i = 0; $i <= $length; $i++) {
            $art = $i < $length && $visibleAt($i) > 1;

            if ($art && $start === null) {
                $start = $i;
            } elseif (! $art && $start !== null) {
                $runs[] = [$start, $i - 1];
                $start = null;
            }
        }

        while (count($runs) > $expected) {
            $narrowest = 0;

            for ($j = 1; $j < count($runs) - 1; $j++) {
                if ($runs[$j + 1][0] - $runs[$j][1] < $runs[$narrowest + 1][0] - $runs[$narrowest][1]) {
                    $narrowest = $j;
                }
            }

            array_splice($runs, $narrowest, 2, [[$runs[$narrowest][0], $runs[$narrowest + 1][1]]]);
        }

        // However short: rows packed so tight that every one touches the next
        // come back as a single run, and are divided one gap at a time.
        while ($runs !== [] && count($runs) < $expected) {
            $widest = 0;

            foreach ($runs as $j => $run) {
                if ($runs[$widest][1] - $runs[$widest][0] < $run[1] - $run[0]) {
                    $widest = $j;
                }
            }

            [$first, $last] = $runs[$widest];
            $split = $splitAt($runs[$widest]);

            // Two animals drawn joined are one piece, so there are no two
            // middles to split between. They join where they meet, which is
            // the thinnest line across the middle of the run — but only when
            // the run is plainly two animals wide. A run of ordinary width is
            // one animal, and the row is short because a pose is missing: that
            // must fail as a missing pose, not be made up by halving a pet.
            if ($split === null || $split <= $first || $split >= $last) {
                $widths = array_map(fn (array $run) => $run[1] - $run[0], $runs);
                sort($widths);
                $typical = $widths[intdiv(count($widths), 2)];

                $split = count($runs) > 1 && $last - $first > 1.6 * $typical
                    ? $this->thinnest($visibleAt, $first, $last)
                    : null;
            }

            if ($split === null) {
                break;
            }

            array_splice($runs, $widest, 1, [[$first, $split], [$split + 1, $last]]);
            $touched = true;
        }

        return count($runs) === $expected ? $runs : null;
    }

    /**
     * The line with the least art on it in the middle half of a run: where
     * two animals drawn touching meet. Null for a run too short to hold two.
     *
     * @param  callable(int): int  $visibleAt
     */
    private function thinnest(callable $visibleAt, int $first, int $last): ?int
    {
        $quarter = (int) (($last - $first) / 4);

        if ($quarter < 4) {
            return null;
        }

        $best = null;
        $least = PHP_INT_MAX;

        for ($i = $first + $quarter; $i <= $last - $quarter; $i++) {
            if (($count = $visibleAt($i)) < $least) {
                $least = $count;
                $best = $i;
            }
        }

        return $best;
    }

    /**
     * Even strips, for when the art will not come apart on its own.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private function evenBands(int $length, int $count): array
    {
        return array_map(
            fn (int $i) => [(int) round($i * $length / $count), (int) round(($i + 1) * $length / $count) - 1],
            range(0, $count - 1),
        );
    }

    /**
     * Whether two sheets are the same drawing — what a generator does when it
     * gives up on an age and repeats the one next to it.
     *
     * Compared pose by pose, each animal lifted out of its cell and squared up
     * to the same small size first. The ages are resized differently on the
     * way through the cut, so a copy is not the same pixels in the same place —
     * but it is the same shape, and shrunk to 48px a resample cannot hide it.
     */
    private function looksTheSame(string $one, string $other): bool
    {
        [$a, $b] = [@imagecreatefromstring($one), @imagecreatefromstring($other)];

        if (! $a instanceof GdImage || ! $b instanceof GdImage) {
            return false;
        }

        $grid = CosmeticSlot::Pet->poseGrid();
        $cell = (int) (imagesx($a) / $grid['cols']);
        $side = 48;

        $lift = function (GdImage $sheet, int $index) use ($grid, $cell, $side): ?GdImage {
            $left = ($index % $grid['cols']) * $cell;
            $top = intdiv($index, $grid['cols']) * $cell;
            $box = $this->boundingBox($sheet, $left, $top, $cell, $cell);

            if ($box === null) {
                return null;
            }

            $small = imagecreatetruecolor($side, $side);
            imagealphablending($small, false);
            imagesavealpha($small, true);
            imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
            imagecopyresampled($small, $sheet, 0, 0, $box['left'], $box['top'], $side, $side, $box['right'] - $box['left'] + 1, $box['bottom'] - $box['top'] + 1);

            return $small;
        };

        $difference = 0;
        $compared = 0;

        foreach (array_keys(CosmeticSlot::PET_POSES) as $index) {
            [$pa, $pb] = [$lift($a, $index), $lift($b, $index)];

            if ($pa && $pb) {
                for ($y = 0; $y < $side; $y++) {
                    for ($x = 0; $x < $side; $x++) {
                        $ca = imagecolorat($pa, $x, $y);
                        $cb = imagecolorat($pb, $x, $y);
                        $alphaA = ($ca >> 24) & 0x7F;
                        $alphaB = ($cb >> 24) & 0x7F;

                        // Empty in both is not evidence of anything.
                        if ($alphaA >= 110 && $alphaB >= 110) {
                            continue;
                        }

                        $difference += $this->distance($ca, $cb) + abs($alphaA - $alphaB) * 2;
                        $compared++;
                    }
                }
            }

            $pa && imagedestroy($pa);
            $pb && imagedestroy($pb);
        }

        imagedestroy($a);
        imagedestroy($b);

        return $compared > 0 && $difference / $compared < 12;
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
