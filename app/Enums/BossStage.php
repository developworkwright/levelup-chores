<?php

namespace App\Enums;

/**
 * How beaten up a monster looks. Derived entirely from the health it has left
 * — the stage is a way of drawing damage taken, never a thing that gets
 * stored, so it can't drift from the bar it sits under.
 *
 * The *visual* side of a stage — how far the grin opens, pupil size, tilt,
 * wear, breathing rate — belongs to `resources/js/monsters.js`, which draws
 * every monster from these same five numbers. That is deliberately one place:
 * fifteen monsters behaving like one family is the whole point, and a second
 * copy of the curve in PHP would be a second thing to re-tune. What stays here
 * is the server's half: which stage a health figure lands in, and the words
 * that go with it.
 *
 * Case values, labels and taunts must match `FQMonsters.STAGES`, which
 * `BossSkinCatalogTest` checks.
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

    public function isDefeated(): bool
    {
        return $this === self::Defeated;
    }
}
