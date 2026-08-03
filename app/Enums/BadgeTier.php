<?php

namespace App\Enums;

/**
 * The metal a badge star is struck from, read straight off its XP reward so a
 * harder achievement always looks more valuable without anyone hand-assigning
 * a tier. Rainbow isn't a metal at all — it's the tier that moves, kept rare
 * on purpose so it still means something when one turns up.
 */
enum BadgeTier: string
{
    case Silver = 'silver';
    case Gold = 'gold';
    case Rainbow = 'rainbow';

    /** Lowest XP reward that strikes a badge in gold rather than silver. */
    public const GOLD_XP = 150;

    /** Lowest XP reward that earns the animated top tier. */
    public const RAINBOW_XP = 300;

    public static function fromXp(int $xpReward): self
    {
        return match (true) {
            $xpReward >= self::RAINBOW_XP => self::Rainbow,
            $xpReward >= self::GOLD_XP => self::Gold,
            default => self::Silver,
        };
    }

    /** The gradient a star is filled with (defined in resources/css/tokens.css). */
    public function fillVar(): string
    {
        return "var(--fq-tier-{$this->value})";
    }

    /** The flat colour a card border and tier label take from this tier. */
    public function ringVar(): string
    {
        return "var(--fq-tier-{$this->value}-ring)";
    }

    public function glowVar(): string
    {
        return "var(--fq-tier-{$this->value}-glow)";
    }

    /**
     * Whether this tier's colours move. Only the top one does — a board where
     * every star drifted would read as noise rather than as a reward.
     */
    public function isAnimated(): bool
    {
        return $this === self::Rainbow;
    }

    public function label(): string
    {
        return strtoupper($this->value);
    }
}
