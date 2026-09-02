<?php

namespace App\Enums;

/**
 * Which cabinet a run was played on.
 *
 * The arcade was one game for as long as there was one game, and the whole
 * feature was written that way: a score was a score, the board was the board,
 * and the week paid one winner. A second cabinet makes every one of those
 * questions ambiguous, so this enum is what re-asks them — every score, every
 * board query and every weekly prize is scoped to a game now.
 *
 * The two are deliberately not comparable. A tower is floors and a run is
 * lanes; they are different numbers measuring different things, which is why
 * there are two boards and two prizes rather than one of each. Merging them
 * would make the second cabinet pointless for whoever is already best at the
 * first.
 *
 * Windy Walkies shipped as "Bean Dash" in `handoff/design_handoff_bean_dash`,
 * and its game file still calls itself that inside — see
 * `resources/js/fart-dash.js`, which is shipped verbatim from that bundle.
 */
enum ArcadeGame: string
{
    case StackTheMess = 'stack_the_mess';

    case WindyWalkies = 'windy_walkies';

    /** The cabinet the switcher opens on. The new one, while it is new. */
    public static function default(): self
    {
        return self::WindyWalkies;
    }

    public function label(): string
    {
        return match ($this) {
            self::StackTheMess => 'Stack the Mess',
            self::WindyWalkies => 'Windy Walkies',
        };
    }

    /** What the score counts, for every line that prints one. */
    public function unit(): string
    {
        return match ($this) {
            self::StackTheMess => 'floors',
            self::WindyWalkies => 'lanes',
        };
    }

    /**
     * The name each game draws on its own start screen, in caps.
     *
     * Only Windy Walkies' matters: it is drawn inside the canvas by a file that
     * shipped under a different working title, so this is the string
     * `ArcadeMilestoneTest` holds that file to.
     */
    public function titleScreen(): string
    {
        return mb_strtoupper($this->label());
    }

    /** The tips-strip icon, which is the one thing about a cabinet that is not words. */
    public function icon(): string
    {
        return match ($this) {
            self::StackTheMess => 'fa-layer-group',
            self::WindyWalkies => 'fa-wind',
        };
    }

    /** What a winning week is called in the kid's ticket history. */
    public function prizeReason(int $score): string
    {
        return match ($this) {
            self::StackTheMess => 'Tallest tower of the week — '.$score.' floors',
            self::WindyWalkies => 'Longest walk of the week — '.$score.' lanes',
        };
    }

    /** What an empty board says, in the words of the game that has no runs on it. */
    public function emptyBoard(): string
    {
        return match ($this) {
            self::StackTheMess => 'Nobody has stacked anything yet this week.',
            self::WindyWalkies => 'Nobody has taken the dog out yet this week.',
        };
    }

    /**
     * Runs one player may post per hour on this cabinet before the board stops
     * listening.
     *
     * Per game because a run is a different length in each. A tower takes a
     * minute or two to fall over, so forty is a bound on tampering that honest
     * play never comes near; a walk can end three seconds after it starts, and
     * forty is an evening a determined six-year-old would silently spend off
     * the board.
     */
    public function postsPerHour(): int
    {
        return match ($this) {
            self::StackTheMess => 40,
            self::WindyWalkies => 120,
        };
    }
}
