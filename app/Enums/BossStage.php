<?php

namespace App\Enums;

/**
 * How beaten up the boss looks. Derived entirely from the health left on the
 * family goal — the stage is a way of drawing `goal_now`, never a thing that
 * gets stored, so it can't drift from the bar it sits under.
 */
enum BossStage: string
{
    case Fresh = 'fresh';
    case Angry = 'angry';
    case Damaged = 'damaged';
    case Desperate = 'desperate';
    case Defeated = 'defeated';

    /**
     * @param  int  $healthPercent  health remaining, 0-100
     */
    public static function fromHealth(int $healthPercent): self
    {
        return match (true) {
            $healthPercent <= 0 => self::Defeated,
            $healthPercent <= 25 => self::Desperate,
            $healthPercent <= 50 => self::Damaged,
            $healthPercent <= 75 => self::Angry,
            default => self::Fresh,
        };
    }

    /**
     * The damage, as a percentage of the boss's health, at which this stage
     * begins. The inverse of {@see self::fromHealth()}, and what lets a
     * catch-up replay work out where each missed stage started.
     */
    public function entryDamagePercent(): int
    {
        return match ($this) {
            self::Fresh => 0,
            self::Angry => 25,
            self::Damaged => 50,
            self::Desperate => 75,
            self::Defeated => 100,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Fresh => 'Lurking',
            self::Angry => 'Angry',
            self::Damaged => 'Hurting',
            self::Desperate => 'Cornered',
            self::Defeated => 'Defeated',
        };
    }

    /**
     * What the boss says at this stage. Written to escalate from smug to
     * rattled — the whole point of the stages is that the monster notices.
     */
    public function taunt(): string
    {
        return match ($this) {
            self::Fresh => 'You will never finish all those chores.',
            self::Angry => 'Hey! Who taught you to tidy up?',
            self::Damaged => 'Stop that. I mean it. Stop.',
            self::Desperate => 'No no no not the vacuum NOT THE VACUUM',
            self::Defeated => 'Beaten by a bunch of kids with a mop.',
        };
    }

    /**
     * How far the grin hangs open, 0-1. A cornered monster gapes; a lurking one
     * keeps it to a smirk.
     */
    public function openness(): float
    {
        return match ($this) {
            self::Fresh => 0.35,
            self::Angry => 0.6,
            self::Damaged => 0.8,
            self::Desperate => 1.0,
            self::Defeated => 0.15,
        };
    }

    /** Pupil size, 0-1. Wide eyes read as rattled, pinpricks as defeated. */
    public function eyeScale(): float
    {
        return match ($this) {
            self::Fresh => 1.0,
            self::Angry => 0.75,
            self::Damaged => 1.15,
            self::Desperate => 1.35,
            self::Defeated => 0.2,
        };
    }

    /** Degrees of list, so a hurt boss visibly can't hold itself straight. */
    public function tilt(): float
    {
        return match ($this) {
            self::Fresh => 0.0,
            self::Angry => -2.0,
            self::Damaged => 4.0,
            self::Desperate => -7.0,
            self::Defeated => 14.0,
        };
    }

    /** Opacity of the scuffs, tears and stitches layered over the body. */
    public function wear(): float
    {
        return match ($this) {
            self::Fresh => 0.0,
            self::Angry => 0.25,
            self::Damaged => 0.55,
            self::Desperate => 0.85,
            self::Defeated => 1.0,
        };
    }

    /** Seconds per idle breath. Panic breathes faster. */
    public function breathSeconds(): float
    {
        return match ($this) {
            self::Fresh => 4.0,
            self::Angry => 3.0,
            self::Damaged => 2.2,
            self::Desperate => 1.3,
            self::Defeated => 6.0,
        };
    }

    public function isDefeated(): bool
    {
        return $this === self::Defeated;
    }
}
