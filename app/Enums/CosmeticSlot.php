<?php

namespace App\Enums;

/**
 * The things a kid wears at once, in locker order.
 *
 * The first seven mirror `FQCosmetics.SLOTS` in resources/js/cosmetics.js,
 * which draws their art; `CosmeticCatalogTest` holds the two halves together.
 *
 * Pet is the app's own eighth, and the one slot with no drawn-in-code art at
 * all: a pet is a twelve-pose sprite sheet a grown-up uploads, and it runs
 * around the kid's pages rather than sitting still on a tile.
 */
enum CosmeticSlot: string
{
    case Frame = 'frame';
    case Avatar = 'avatar';
    case Plate = 'plate';
    case Theme = 'theme';
    case Pattern = 'pattern';
    case Cabinet = 'cabinet';
    case Spark = 'spark';
    case Pet = 'pet';

    public function label(): string
    {
        return match ($this) {
            self::Frame => 'Frame',
            self::Avatar => 'Avatar',
            self::Plate => 'Name plate',
            self::Theme => 'Theme',
            self::Pattern => 'Home pattern',
            self::Cabinet => 'Cabinet skin',
            self::Spark => 'Tap effect',
            self::Pet => 'Pet',
        };
    }

    public function blurb(): string
    {
        return match ($this) {
            self::Frame => 'The ring around your face',
            self::Avatar => 'Who you are in the house',
            self::Plate => 'What your name sits on',
            self::Theme => 'The whole app, recolored',
            self::Pattern => 'Behind your own pages',
            self::Cabinet => 'Your arcade machine',
            self::Spark => 'What flies out when you win',
            self::Pet => 'Runs around your pages',
        };
    }

    /**
     * Who sees it once it's worn, in a kid's words. Only the face, the frame
     * and the plate travel with a kid; the other four are theirs alone, and
     * telling a kid "everyone can see it" about a theme is a small lie they
     * will catch the first time a sibling looks at their screen.
     */
    public function seenBy(): string
    {
        return match ($this) {
            self::Frame, self::Avatar, self::Plate => 'On you now — everyone in the house can see it',
            self::Theme => 'Your pages are wearing it — only you see it',
            self::Pattern => 'Behind your own pages — only you see it',
            self::Cabinet => 'Your arcade machine is wearing it',
            self::Spark => 'Wait until you win something',
            self::Pet => 'Out now — it follows you page to page, and visits the house',
        };
    }

    /** The label under a slot on the rail, where seven have to share 390px. */
    public function short(): string
    {
        return match ($this) {
            self::Frame => 'FRAME',
            self::Avatar => 'FACE',
            self::Plate => 'PLATE',
            self::Theme => 'THEME',
            self::Pattern => 'BACK',
            self::Cabinet => 'CAB',
            self::Spark => 'POP',
            self::Pet => 'PET',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Frame => 'fa-circle-notch',
            self::Avatar => 'fa-face-grin-stars',
            self::Plate => 'fa-id-badge',
            self::Theme => 'fa-palette',
            self::Pattern => 'fa-border-all',
            self::Cabinet => 'fa-gamepad',
            self::Spark => 'fa-wand-magic-sparkles',
            self::Pet => 'fa-paw',
        };
    }

    /** The column on `profiles` holding the id of the item worn in this slot. */
    public function wornColumn(): string
    {
        return 'worn_'.$this->value.'_id';
    }

    /**
     * Whether a grown-up can upload a picture for this slot.
     *
     * A theme is seven colours rather than a picture, so it is the one slot
     * that only ever comes from the catalog.
     */
    public function takesUpload(): bool
    {
        return $this !== self::Theme;
    }

    /**
     * What an uploaded picture for this slot has to be — the same numbers the
     * image-generation prompt asks for, so a picture made from the prompt
     * passes without anybody resizing it.
     *
     * @return array{width: int, height: int, max_kb: int, alpha: bool}
     */
    public function uploadSpec(): array
    {
        return match ($this) {
            // The one file-size cap for a slot: the upload rule, the check and the
            // hint above the picker all read `max_kb`, so they can't disagree.
            // Sized for what an image generator actually hands back — a 512px
            // PNG straight out of one is routinely several hundred kilobytes, and
            // the design's 180 KB turned most real art away.
            self::Plate => ['width' => 768, 'height' => 192, 'max_kb' => 1024, 'alpha' => true],
            // Under 2 MB on purpose: that is upload_max_filesize here, and a file
            // past it is dropped by PHP before this app can say why — see
            // FeedPhotos::uploadCeilingKb().
            self::Cabinet => ['width' => 800, 'height' => 1000, 'max_kb' => 1536, 'alpha' => true],
            // A pattern sits behind text and is tiled, so it is the one picture
            // that must be opaque.
            self::Pattern => ['width' => 512, 'height' => 512, 'max_kb' => 1024, 'alpha' => false],
            // A sprite sheet rather than one picture — see poseGrid(). Bigger
            // than the rest and still under PHP's 2 MB ceiling.
            self::Pet => ['width' => 1024, 'height' => 768, 'max_kb' => 1536, 'alpha' => true],
            default => ['width' => 512, 'height' => 512, 'max_kb' => 1024, 'alpha' => true],
        };
    }

    /**
     * The grid an uploaded picture for this slot is cut into, or null when it is
     * one picture. Only a pet has one.
     *
     * @return array{cols: int, rows: int}|null
     */
    public function poseGrid(): ?array
    {
        return $this === self::Pet ? ['cols' => 4, 'rows' => 3] : null;
    }

    /**
     * A pet's twelve cells, in the order the sheet draws them — left to right,
     * top row first. The engine asks for them by name, and the upload checks
     * name the empty one, so this list is the contract with the prompt below.
     *
     * @var array<int, string>
     */
    public const PET_POSES = [
        'idle', 'blink', 'crouch', 'jump',
        'walk', 'happy', 'held', 'landed',
        'play', 'toss', 'sleep', 'toy',
    ];

    /**
     * The image-generation prompt for a pet.
     *
     * The other six live in cosmetics.js, because the design bundle shipped
     * them. This one is the app's own, and it is written to the same shape: a
     * subject line to edit, then geometry and negative blocks to leave alone.
     *
     * One sheet rather than twelve pictures, and that is the whole trick.
     * Generating "the pet sleeping" and "the pet jumping" separately gives you a
     * slightly different animal each time — different colours, different ears.
     * Asking for every pose in one image gets one character.
     */
    public const PET_PROMPT = 'A sprite sheet of one small pet character, [SUBJECT: a stubby three-eyed swamp gremlin with a long tail], rendered as [STYLE: bold flat cartoon with thick dark outlines], the same character drawn eleven times, plus its toy drawn once.

The pet\'s toy is [TOY: a squeaky rubber bone], sized to fit in the pet\'s paws.

GEOMETRY — follow exactly:
· 1024x768 image, fully transparent background.
· A grid of 4 columns × 3 rows of equal 256×256 cells. Nothing crosses a cell
  edge.
· In every cell the character is the SAME size, centered left to right, with its
  feet on the same line 24px above the bottom of the cell.
· The standing character fills about 70% of the cell height.
· Identical colours, markings, proportions and outline weight in every cell.

THE TWELVE CELLS, left to right, top row first:
1. Idle — standing, facing the viewer, relaxed.
2. Blink — exactly cell 1 with its eyes closed.
3. Crouch — squashed down, about to jump.
4. Jump — in the air, stretched tall, feet tucked up (may leave the foot line).
5. Walk — facing right, one foot forward.
6. Happy — eyes squeezed shut, grinning, as if it has just been petted.
7. Held — dangling from the scruff of its neck, legs hanging, surprised but not
   upset. Centered in the cell; it may leave the foot line.
8. Landed — flattened on the foot line as if it has just dropped, dizzy, not
   hurt.
9. Play — on its back or pouncing, holding the toy.
10. Toss — throwing the toy up, the toy just above its paws.
11. Sleep — curled up lying down, eyes closed.
12. The toy on its own, no pet, resting on the foot line.

MUST NOT INCLUDE: a background, ground, cast shadow or scenery; grid lines,
borders, cell outlines or a ruled foot line; text, numerals, "zzz", hearts or
sound effects; more than one character in a cell; any prop other than the toy;
motion blur or speed lines; anything touching the left or right edge of its
cell.';

    /**
     * The block the parent console adds to the end of every prompt.
     *
     * The six prompts in cosmetics.js say what to draw and never say what file
     * to hand back, because the bundle was written before anything uploaded
     * them. Asked for "a transparent background", generators routinely return a
     * JPEG, or a PNG with the checkerboard painted in — both of which arrive
     * here as art on a grey chequered rug.
     *
     * Appended rather than pasted into cosmetics.js: that file ships verbatim
     * from the design bundle, and this way one block fixes all seven prompts
     * and any prompt added later.
     */
    public function promptOutput(): string
    {
        $spec = $this->uploadSpec();
        $size = "{$spec['width']}x{$spec['height']}";

        $background = $spec['alpha']
            ? '· The background must be REAL transparency — an alpha channel, not a white'."\n".
              '  rectangle and not a painted grey-and-white checkerboard. Nothing behind the'."\n".
              '  subject at all.'
            : '· No transparency: every pixel is part of the picture.';

        return "\n\nOUTPUT — follow exactly:\n".
            "· Hand back a PNG file. Not a JPEG, not a WebP.\n".
            $background."\n".
            "· Exactly {$size} pixels, or a larger size in the same shape ".
            'and nothing else — a different shape gets turned away.';
    }

    /** The size cap in words — "1 MB" — for the hint, the refusal and the check alike. */
    public function uploadLimitLabel(): string
    {
        $kb = $this->uploadSpec()['max_kb'];

        return $kb >= 1024 ? rtrim(rtrim(number_format($kb / 1024, 1), '0'), '.').' MB' : $kb.' KB';
    }

    /**
     * Whether this slot is empty until something is bought for it.
     *
     * A frame and an avatar are: a kid with neither keeps the letter tile they
     * have always had. Every other slot has a free house item that is simply
     * the app as it already looks.
     */
    public function startsEmpty(): bool
    {
        return in_array($this, [self::Frame, self::Avatar, self::Pet], true);
    }

    /**
     * Uploadable slots, in locker order.
     *
     * @return array<int, self>
     */
    public static function uploadable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $slot) => $slot->takesUpload()));
    }
}
