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
        $standing = array_intersect_key($heights, array_flip(['idle', 'blink', 'walk', 'happy']));

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

        // A 4x3 grid is five rulings; at four pixels thick that is twenty rows
        // and columns. Fifty is generous and still nowhere near a whole sheet.
        $ceiling = 50;

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
         * four-three — and the poses are still on an even 4x3 grid inside it,
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
