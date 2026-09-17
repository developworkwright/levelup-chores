<?php

namespace App\Enums;

/**
 * A treatment laid over a piece of uploaded art, rather than drawn into it.
 *
 * This is what makes a twenty-ticket pet worth twenty: the same sprite sheet
 * with its colours crawling, or with the app's own fire burning behind it. The
 * art doesn't know about it, so one drawing can be sold plain or special.
 *
 * Deliberately not a motion: {@see CosmeticMotion} moves the whole picture,
 * while an effect adds something to it. A pet can wear both.
 */
enum CosmeticEffect: string
{
    /** The colours crawl through a hue sweep — the badge board's rainbow tier, on art. */
    case Rainbow = 'rainbow';

    /** The streak's own flame, burning behind the art. */
    case Flame = 'flame';

    public function label(): string
    {
        return match ($this) {
            self::Rainbow => 'Rainbow — the colours crawl',
            self::Flame => 'Flame — it burns',
        };
    }

    /** What a kid's card says it is. */
    public function badge(): string
    {
        return match ($this) {
            self::Rainbow => 'RAINBOW',
            self::Flame => 'ON FIRE',
        };
    }

    /** The class the art wears. Both are defined in app.css. */
    public function cssClass(): string
    {
        return 'fq-aura-'.$this->value;
    }
}
