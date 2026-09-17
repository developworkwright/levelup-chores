<?php

namespace App\Enums;

/**
 * The seven things a kid wears at once, in locker order.
 *
 * Mirrors `FQCosmetics.SLOTS` in resources/js/cosmetics.js, which draws the
 * art; `CosmeticCatalogTest` holds the two halves together.
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
            default => ['width' => 512, 'height' => 512, 'max_kb' => 1024, 'alpha' => true],
        };
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
        return in_array($this, [self::Frame, self::Avatar], true);
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
