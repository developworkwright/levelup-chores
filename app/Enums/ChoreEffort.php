<?php

namespace App\Enums;

/**
 * How much work a chore actually is, when the picture can't say.
 *
 * The one axis that cannot be guessed from a chore's name or its face.
 * Scrubbing a bathroom is hard work behind an indoor icon, and weed whacking
 * is the case that proves it — so unlike {@see ChoreIcon}, there is no keyword
 * pass here. A wrong guess sends a six-year-old at a job he can't finish,
 * which is the same reasoning `ChoreIcon::forName()` uses when it returns null
 * rather than a wrong picture.
 *
 * Stored nullable, and **null means "not said"** rather than "light". A parent
 * who has never touched the control has told us nothing, and the kid board's
 * Muscle chip only ever collects the chores somebody deliberately flagged.
 */
enum ChoreEffort: string
{
    case Light = 'light';
    case Heavy = 'heavy';

    /** What a parent sees on the chore row's cycle button. */
    public function label(): string
    {
        return match ($this) {
            self::Light => 'Easy going',
            self::Heavy => 'Hard work',
        };
    }

    /** How the effort reads on a kid's board row, alongside the cadence. */
    public function kidLabel(): string
    {
        return match ($this) {
            self::Light => 'Easy',
            self::Heavy => 'Muscle',
        };
    }

    /**
     * The next state for the parent's cycle button, wrapping back to "not
     * said".
     *
     * Static and null-tolerant because null is a real state here — the same
     * three-way shape as the min-age stepper, where "Any age" is a value a
     * parent can get back to rather than a starting point they lose.
     */
    public static function next(?self $current): ?self
    {
        return match ($current) {
            null => self::Light,
            self::Light => self::Heavy,
            self::Heavy => null,
        };
    }
}
