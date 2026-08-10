<?php

namespace App\Enums;

/**
 * Which of the three monsters a hit is aimed at.
 *
 * The tier is the whole choice the kids make: a small, quick reward against a
 * long one they have to agree on together. It carries no numbers of its own —
 * health and reward belong to the monster standing at the tier, because what a
 * "level 1" is worth is a decision only a parent can make, and it changes every
 * time one is beaten.
 */
enum MonsterTier: int
{
    case One = 1;
    case Two = 2;
    case Three = 3;

    public function label(): string
    {
        return match ($this) {
            self::One => 'Level 1',
            self::Two => 'Level 2',
            self::Three => 'Level 3',
        };
    }

    /** How the tier reads on a card, before the reward has been named. */
    public function blurb(): string
    {
        return match ($this) {
            self::One => 'Small and quick.',
            self::Two => 'Worth a few weeks.',
            self::Three => 'The long game.',
        };
    }

    /**
     * Where a killing blow's excess goes: overkill on level 1 rolls onto level
     * 2, level 2 onto level 3, and level 3 has nowhere left to send it.
     */
    public function above(): ?self
    {
        return self::tryFrom($this->value + 1);
    }

    /**
     * How dread-soaked the artwork is, 0-1 — the floor glow, the mote drift and
     * how heavily the thing breathes.
     *
     * `monsters.js` already takes this; all that was missing was a reason for
     * the three to differ. A level 1 monster in the same weather as a level 3
     * reads as exactly as dangerous, which is the whole problem.
     */
    public function dread(): float
    {
        return match ($this) {
            self::One => 0.45,
            self::Two => 0.65,
            self::Three => 0.85,
        };
    }

    /**
     * How far the artwork overflows its own frame.
     *
     * Cropping is the cheapest way to make something read as too big for the
     * shot: a level 3 monster loses its feet and the tips of its horns to the
     * edges, while a level 1 sits comfortably inside with room to spare. Same
     * drawing either way — the frame does the work.
     */
    public function artZoom(): float
    {
        return match ($this) {
            self::One => 1.0,
            self::Two => 1.12,
            self::Three => 1.3,
        };
    }

    /**
     * How many pieces the health bar is cut into.
     *
     * Straight out of fighting games, and it works for the same reason: a bar
     * in four segments reads as more fight than one long trough, before anybody
     * has read the number beside it.
     */
    public function healthSegments(): int
    {
        return match ($this) {
            self::One => 1,
            self::Two => 2,
            self::Three => 4,
        };
    }

    /**
     * What the card is worth in the arena row — bigger tier, bigger footprint.
     *
     * A flex basis rather than a width: the three still wrap onto a phone one
     * per line, and the ratio only shows up once there is room for it to.
     *
     * Whole numbers rather than 1 : 1.15 : 1.4, for readability only — decimals
     * compile perfectly well.
     */
    public function cardBasis(): string
    {
        return match ($this) {
            self::One => 'flex-[5_1_220px]',
            self::Two => 'flex-[6_1_240px]',
            self::Three => 'flex-[8_1_280px]',
        };
    }

    /** The border a card of this tier wears. Plainer at the bottom, ornate at the top. */
    public function frameClass(): string
    {
        return match ($this) {
            self::One => 'border',
            self::Two => 'border-2',
            self::Three => 'border-2 fq-frame-ornate',
        };
    }

    /** The tier, short enough to sit in a chip beside a name. */
    public function badge(): string
    {
        return 'LVL '.$this->value;
    }

    /**
     * The colour the tier answers to. Cool and unremarkable at the bottom,
     * hot at the top — so the ladder is legible before any of it is read.
     */
    public function accent(): string
    {
        return match ($this) {
            self::One => 'var(--fq-text-4)',
            self::Two => 'var(--fq-gold)',
            self::Three => 'var(--fq-coral)',
        };
    }

    /**
     * How big the thumbnail is in the quest board's strip.
     *
     * The strip is where the choice actually gets made — it is on the page kids
     * live on — so the ladder has to be visible there and not only in the
     * arena, where all three were already drawn at the same size.
     */
    public function stripArtWidth(): string
    {
        return match ($this) {
            self::One => 'w-[40px]',
            self::Two => 'w-[52px]',
            self::Three => 'w-[66px]',
        };
    }

    /** How heavy the strip's health bar is. Same ladder, read by weight. */
    public function stripBarHeight(): string
    {
        return match ($this) {
            self::One => 'h-[8px]',
            self::Two => 'h-[10px]',
            self::Three => 'h-[14px]',
        };
    }

    /**
     * A title, for the one monster that has earned one. The long game is the
     * thing a family talks about for weeks; the ice cream monster is Tuesday.
     */
    public function epithet(): ?string
    {
        return match ($this) {
            self::One, self::Two => null,
            self::Three => 'The one worth waiting for',
        };
    }
}
