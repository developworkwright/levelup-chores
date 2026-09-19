<?php

namespace App\Enums;

/**
 * The things a kid wears at once, in locker order.
 *
 * The first seven mirror `FQCosmetics.SLOTS` in resources/js/cosmetics.js,
 * which draws their art; `CosmeticCatalogTest` holds the two halves together.
 *
 * Pet is the app's own eighth, and the one slot with no drawn-in-code art at
 * all: a pet is an eighteen-pose sprite sheet a grown-up uploads, and it runs
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
            self::Pet => ['width' => 1536, 'height' => 768, 'max_kb' => 1536, 'alpha' => true],
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
        return $this === self::Pet ? ['cols' => 6, 'rows' => 3] : null;
    }

    /**
     * A pet's eighteen cells, in the order the sheet draws them — left to
     * right, top row first. The engine asks for them by name, and the upload
     * checks name the empty one, so this list is the contract with the prompt
     * below.
     *
     * Play, toss and back have empty paws, and the app draws the toy into
     * them (see CosmeticArt::anchors()). So the pet's own toy, a toy bought at
     * the prize counter, and any toy added later all play the same way.
     *
     * @var array<int, string>
     */
    public const PET_POSES = [
        'idle', 'blink', 'sit', 'crouch', 'jump', 'landed',
        'walk', 'walk2', 'happy', 'surprised', 'held', 'sniff',
        'swipe', 'play', 'toss', 'back', 'sleep', 'toy',
    ];

    /**
     * The twelve poses of a sheet cut before the re-grid, four by three, with
     * the sheet's own toy drawn into play and toss. Such pets keep working
     * (Cosmetic::rig() is null for them) until a grown-up gives them new art.
     *
     * @var array<int, string>
     */
    public const LEGACY_PET_POSES = [
        'idle', 'blink', 'crouch', 'jump',
        'walk', 'happy', 'held', 'landed',
        'play', 'toss', 'sleep', 'toy',
    ];

    /**
     * The poses the app draws the toy into, as opposed to the one cell that
     * is the toy.
     *
     * @var array<int, string>
     */
    public const EMPTY_PAW_POSES = ['play', 'toss', 'back'];

    /**
     * The grid of a sheet with all three ages on it: nine by six, two rows
     * per age, baby at the top. Nine wide, because eighteen poses in two rows
     * of nine keep each pose in the same column for every age, which is the
     * one layout hint generators follow. That makes it 3:2, the generators'
     * own landscape size, with cells the size the old six-by-six square had.
     * App\Services\CosmeticArt::splitFamily() cuts it into the three sheets.
     *
     * @var array{cols: int, rows: int}
     */
    public const FAMILY_GRID = ['cols' => 9, 'rows' => 6];

    /**
     * The prompt for a pet: baby, young and adult in one picture. The only
     * way a pet is made — every pet has all three ages.
     *
     * One picture rather than three prompts, because a generator keeps a
     * character consistent inside one image far better than across separate
     * ones — asked separately it drifts, and handed its own adult sheet as a
     * reference it tends to hand the same drawing back as the "young" one.
     *
     * The ages are drawn at their true sizes here, which is also what stops
     * that copying: a young pet that has to be visibly shorter than the adult
     * cannot be the adult again. The app draws each age's own art full size.
     */
    public const PET_FAMILY_PROMPT = 'A sprite sheet of ONE pet character at three ages — baby, young and adult — [SUBJECT: a stubby three-eyed swamp gremlin with a long tail], rendered as [STYLE: bold flat cartoon with thick dark outlines]. It is the same individual animal growing up: identical colours, identical markings in the same places, the same number of eyes, limbs, ears and tails. Only its proportions and size change.

The pet\'s toy is [TOY: a squeaky rubber bone]. It is the same toy at every age, and it ages with the pet: brand new and shiny with the BABY, chewed and scuffed with the YOUNG pet, ragged, torn and patched with the ADULT — visibly the same toy, visibly well loved.

WHERE THE TOY IS — the toy is drawn in EXACTLY ONE cell of each age: cell 18,
on its own, with no pet. Every other cell has NO toy in it at all — not in
its paws, not in its mouth, not at its feet, not in the air. That includes
Play, Toss and Back: their paws are EMPTY, because the app puts the toy in
them itself. A toy drawn in any other cell ruins the sheet.

GEOMETRY — follow exactly:
· A LANDSCAPE image, 3:2 (one and a half times as wide as it is tall), fully
  transparent background.
· A grid of 9 columns × 6 rows of equal square cells — 54 cells. Nothing
  crosses a cell edge.
· EVERY DRAWING IS ITS OWN ISLAND. Each one stays inside the middle 70% of its
  cell — a margin of at least 15% of the cell left EMPTY on all four sides, so
  between any two neighbouring drawings there is a clear gutter of transparent
  space at least a third of a cell wide. No drawing touches, overlaps or even
  nearly meets any other: no tail curling into the next cell, no ear or tuft
  reaching up into the row above, no paw or tail resting on the drawing
  below. The tall poses (Jump, Held, Toss) and the wide ones (Walk, Sniff,
  Play, Back, Sleep) obey the margin too. If a pose would cross into its
  margin, make that whole row smaller — the drawings are cut apart by the
  empty space between them, and a sheet whose drawings touch is thrown away.
· EXACTLY NINE DRAWINGS IN EVERY ROW — count them. Never a tenth: no extra
  walking step, no spare pose squeezed in at the end. A row of ten is
  thrown away.
· Rows 1–2: the BABY. Rows 3–4: the YOUNG pet. Rows 5–6: the ADULT.
· Each age takes its two rows the same way: the eighteen cells below, left to
  right, nine in its first row and nine in its second. So every column holds
  the same pose at all three ages.
· Feet on a line about 15% above the bottom of every cell.
· Sizes grow with age: the ADULT stands about 62% of the cell height, the
  YOUNG pet about 56%, the BABY about 50%.
· ONE SCALE PER AGE, in every one of its eighteen cells. The animal in the
  second row of an age is exactly as big as in the first — same head size,
  same paw size, same body length. Poses change its shape, never its size:
  sniffing, playing, lying on its back and sleeping are NOT drawn smaller.

WHAT EACH AGE LOOKS LIKE:
· BABY: oversized head, huge eyes, stubby legs, a round soft body. Every
  marking already there, just softer.
· YOUNG: lanky legs, big paws and ears it has not grown into, markings nearly
  full.
· ADULT: the full build, every marking crisp.
Each age is drawn fresh. Never repeat one age\'s drawing for another.

THE EIGHTEEN CELLS OF EACH AGE, in order:
1. Idle — standing, facing the viewer, relaxed.
2. Blink — exactly cell 1 with its eyes closed.
3. Sit — sitting upright on its haunches, facing the viewer, content, tail
   curled round its feet.
4. Crouch — squashed down low, about to jump.
5. Jump — in the air, stretched tall, feet tucked up (may leave the foot line).
6. Landed — flattened on the foot line as if it has just dropped, dizzy, not
   hurt.
7. Walk — side view facing right, mid-stride: the near front leg and the far
   back leg forward.
8. Walk, other step — exactly cell 7, the same size and facing the same way,
   with the OTHER pair of legs forward: the far front leg and the near back
   leg. Cells 7 and 8 played one after the other make it walk.
9. Happy — eyes squeezed shut, grinning, as if it has just been petted.
10. Surprised — standing, facing the viewer, startled: eyes wide, ears and
    tail straight up, leaning back a little. Amazed, not scared.
11. Held — hanging in mid-air as if lifted by the scruff of its neck: the
    scruff at the top of the cell, the body hanging straight down below it,
    every leg dangling limp, feet off the foot line. Surprised, not upset.
    NOT sitting, NOT standing. Draw NO hand, arm or person holding it —
    whatever lifts it is off the picture; only the pet is drawn.
12. Sniff — side view facing right, head down and nose to the foot line,
    sniffing the ground, tail up.
13. Swipe — side view facing right, standing on three legs with one front paw
    raised and swiping out in front of it, as if batting at something. The
    paw is EMPTY.
14. Play — side view facing right, pouncing: front end down low, both front
    paws stretched out in front of it flat on the foot line, rear end up, tail
    wagging. The paws are EMPTY, with clear space right in front of them.
15. Toss — standing up on its hind legs, facing the viewer, looking up, both
    front paws raised together above its head, open and EMPTY, as if it has
    just thrown something up into the air.
16. Back — lying on its back, belly up, happy, all four paws in the air: the
    two front paws held together, open and EMPTY, above its chest, with clear
    space above them. Full size: as long as it is when walking.
17. Sleep — curled up lying down, eyes closed. Full size: curled up, not
    shrunk — as wide as the walking pose is long.
18. The toy on its own, no pet, resting on the foot line.

MUST NOT INCLUDE: a background, ground, cast shadow or scenery; grid lines,
borders, cell outlines or a ruled foot line; text, labels, numerals, "zzz",
hearts, question marks or sound effects; more than one character in a cell; a
hand, arm or person anywhere; the toy in any cell but 18; anything held in the
paws or mouth; any prop other than the toy; motion blur or speed lines; any
drawing touching another drawing or the edge of its cell.';

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
        $size = $this->promptSize();

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

    /**
     * The picture size a prompt asks for. The stored size, except for a pet:
     * what is uploaded is the 3:2 sheet with all three ages on it, which is
     * cut into three sheets of uploadSpec()'s size.
     */
    public function promptSize(): string
    {
        $spec = $this->uploadSpec();

        return $this === self::Pet ? '1536x1024' : "{$spec['width']}x{$spec['height']}";
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
