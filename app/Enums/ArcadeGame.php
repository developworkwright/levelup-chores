<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * Which game a run was played on.
 *
 * The arcade was one game for as long as there was one game, and the whole
 * feature was written that way: a score was a score, the board was the board,
 * and the week paid one winner. A second game makes every one of those
 * questions ambiguous, so this enum is what re-asks them — every score, every
 * board query and every weekly prize is scoped to a game now.
 *
 * The two are deliberately not comparable. A tower is floors and a run is
 * lanes; they are different numbers measuring different things, which is why
 * there are two boards and two prizes rather than one of each. Merging them
 * would make the second game pointless for whoever is already best at the
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

    /** The game the rail opens on. The newest one, so it is found. */
    public static function default(): self
    {
        return self::newest();
    }

    /** The most recently installed game. */
    public static function newest(): self
    {
        return collect(self::cases())
            ->sortByDesc(fn (self $game) => $game->releasedOn())
            ->first();
    }

    /**
     * Every game that keeps a score, in rail order.
     *
     * @return list<self>
     */
    public static function ranked(): array
    {
        return array_values(array_filter(self::cases(), fn (self $game) => $game->isRanked()));
    }

    /**
     * Every game that keeps no score — the toys, in rail order.
     *
     * @return list<self>
     */
    public static function toys(): array
    {
        return array_values(array_filter(self::cases(), fn (self $game) => ! $game->isRanked()));
    }

    /**
     * Whether this game competes.
     *
     * A ranked game has a board, an all-time record and a weekly prize; an
     * unranked one — a *toy* — has none of the three and cannot lose. The
     * distinction exists because the next games in the pipeline are the
     * physics-comedy kind, where a score would have to be invented to exist,
     * and an invented score on a board that pays tickets is worse than no
     * board at all.
     *
     * Everything currently in the arcade is ranked. What this gates is the
     * whole right-hand column of the page, the settle-the-week fan-out, and
     * the "beat NN" line — see the arcade component and ArcadeService::settle().
     */
    public function isRanked(): bool
    {
        return match ($this) {
            self::StackTheMess, self::WindyWalkies => true,
        };
    }

    /**
     * The day this game went into the arcade.
     *
     * Declared here rather than stored, because installing a game *is* a code
     * change — the thing that draws it is a file in `resources/js`. A row in a
     * table would be a second source of truth for something that cannot arrive
     * without a deploy anyway.
     *
     * What reads it is the "new" flash: a game is new to a kid until they
     * next open the arcade, which is `profiles.arcade_seen_at` measured against
     * this. Adding a game means adding a case, a ladder in `ArcadeService`, a
     * date here, and running `arcade:announce` — see that command.
     */
    public function releasedOn(): CarbonImmutable
    {
        return CarbonImmutable::parse(match ($this) {
            self::StackTheMess => '2026-08-25',
            self::WindyWalkies => '2026-09-02',
        });
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
     * The unit as the canvas header says it — what the run is measured in,
     * read as a phrase rather than a column heading.
     */
    public function scoreLabel(): string
    {
        return match ($this) {
            self::StackTheMess => 'floors stacked',
            self::WindyWalkies => 'lanes crossed',
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

    /** One line telling a kid what the game is, for the push that announces it. */
    public function pitch(): string
    {
        return match ($this) {
            self::StackTheMess => 'stack the clutter as high as it will go.',
            self::WindyWalkies => 'get the dog across the road, one fart at a time.',
        };
    }

    /** The tips-strip icon, which is the one thing about a game that is not words. */
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
     * Runs one player may post per hour on this game before the board stops
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
