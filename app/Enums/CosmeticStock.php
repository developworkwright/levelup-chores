<?php

namespace App\Enums;

/**
 * How an item is sold.
 *
 * The string values are the words `cosmetics.js` uses, so the catalog seeds
 * straight across. "Rotating" is what a grown-up reads; "weekly" is what the
 * artwork file already called it.
 */
enum CosmeticStock: string
{
    /** Always buyable. */
    case Shelf = 'shelf';

    /** Buyable in the weeks the rotation picks it, and back another week. */
    case Rotating = 'weekly';

    /** On sale for one week, ever, and never restocked. */
    case Limited = 'limited';

    public function label(): string
    {
        return match ($this) {
            self::Shelf => 'Shelf',
            self::Rotating => 'Rotating',
            self::Limited => 'Limited',
        };
    }

    /** The small print under the label on the parent form. */
    public function note(): string
    {
        return match ($this) {
            self::Shelf => 'Always there',
            self::Rotating => 'Comes back',
            self::Limited => 'One week, ever',
        };
    }

    /** What a kid's card says about it. */
    public function kidLabel(): string
    {
        return match ($this) {
            self::Shelf => 'ON THE SHELF',
            self::Rotating => 'BACK LATER',
            self::Limited => 'NEVER AGAIN',
        };
    }
}
