<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use LogicException;

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

    /** The first toy. Keeps no score — see isRanked(). */
    case SlimeTime = 'slime_time';

    /**
     * The game the rail opens on when nothing is new to the reader.
     *
     * The newest one that has a board, which is not always the newest one.
     * A toy is a fine thing to be *sent* to — the "new" flash does exactly that
     * on the visit it arrives, and `mount()` prefers an unseen game over this —
     * but it is the wrong thing to land on every day afterwards: no board, no
     * target, no standings, so the competitive half of the arcade would be
     * invisible until somebody tapped something.
     */
    public static function default(): self
    {
        return collect(self::ranked())
            ->sortByDesc(fn (self $game) => $game->releasedOn())
            ->first();
    }

    /**
     * The most recently installed game.
     *
     * Every game claims its own release date, so this has one answer —
     * `ArcadeNewGameTest` holds both halves of that rule: no two games share a
     * date, and none is dated into the future.
     */
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
     * Slime Time is the first game to answer false, and it is the one the flag
     * was added for: there is no way to lose at it, and anything it counted
     * would have had to be invented. What this gates is the whole right-hand
     * column of the page, the settle-the-week fan-out, the "beat NN" line, and
     * whether a run may be posted at all — see the arcade component and
     * ArcadeService::settle().
     *
     * Every method below that describes a *score* — the unit, the empty board,
     * the prize wording, the throttle — throws rather than inventing an answer
     * for a toy. Asking a toy what its unit is is a bug in the caller, and a
     * silent empty string would put a blank where a number belongs, weeks
     * later, on somebody else's screen.
     */
    public function isRanked(): bool
    {
        return match ($this) {
            self::StackTheMess, self::WindyWalkies => true,
            self::SlimeTime => false,
        };
    }

    /**
     * The height each game stacks around its own canvas, in px — a score line
     * here, a d-pad there.
     *
     * Read by the full-screen stage, which subtracts it from the viewport
     * before sizing the 320:460 board. Too small and the bottom of the game
     * goes off the screen. It lives here rather than as a ternary in the Blade
     * because that ternary had to grow a branch per game, and this enum is
     * already the list of things adding a game means editing.
     *
     * Slime Time builds a canvas, a 10px gap and a 44px pad inside the
     * machine's own padding, and gets the tallest budget of the three because
     * its pad is four buttons and wraps to two rows on a narrow phone.
     */
    public function stageChrome(): int
    {
        return match ($this) {
            self::StackTheMess => 110,
            self::WindyWalkies => 155,
            self::SlimeTime => 145,
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
            /*
             * The day it went in, like every game before it, and never a date
             * in the future however tempting that looks. This is compared
             * against `profiles.arcade_seen_at`, and a game whose release has
             * not happened yet is newer than *any* marker — so its "new" flash
             * could never clear, and every kid would be told it was new on
             * every visit until the date came round. `ArcadeNewGameTest`
             * catches that.
             */
            self::SlimeTime => '2026-09-03',
        });
    }

    public function label(): string
    {
        return match ($this) {
            self::StackTheMess => 'Stack the Mess',
            self::WindyWalkies => 'Windy Walkies',
            self::SlimeTime => 'Slime Time',
        };
    }

    /** What the score counts, for every line that prints one. */
    public function unit(): string
    {
        return match ($this) {
            self::StackTheMess => 'floors',
            self::WindyWalkies => 'lanes',
            self::SlimeTime => throw $this->notARankedGame('unit'),
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
            self::SlimeTime => throw $this->notARankedGame('score label'),
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
            self::SlimeTime => 'throw goo at the walls. That is the whole game.',
        };
    }

    /** The tips-strip icon, which is the one thing about a game that is not words. */
    public function icon(): string
    {
        return match ($this) {
            self::StackTheMess => 'fa-layer-group',
            self::WindyWalkies => 'fa-wind',
            self::SlimeTime => 'fa-splotch',
        };
    }

    /** What a winning week is called in the kid's ticket history. */
    public function prizeReason(int $score): string
    {
        return match ($this) {
            self::StackTheMess => 'Tallest tower of the week — '.$score.' floors',
            self::WindyWalkies => 'Longest walk of the week — '.$score.' lanes',
            self::SlimeTime => throw $this->notARankedGame('prize wording'),
        };
    }

    /** What an empty board says, in the words of the game that has no runs on it. */
    public function emptyBoard(): string
    {
        return match ($this) {
            self::StackTheMess => 'Nobody has stacked anything yet this week.',
            self::WindyWalkies => 'Nobody has taken the dog out yet this week.',
            self::SlimeTime => throw $this->notARankedGame('empty board message'),
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
            self::SlimeTime => throw $this->notARankedGame('post rate'),
        };
    }

    /**
     * The error every score-shaped question raises when it is asked of a toy.
     *
     * A toy has no score, so there is no honest answer — and the dishonest ones
     * are worse than a crash: an empty unit prints a blank where a number goes,
     * and a zero throttle silently stops a game that was never posting anyway.
     * Everything that could reach one of these is already behind `isRanked()`,
     * so this is the guard on that gate rather than a case anybody handles.
     */
    private function notARankedGame(string $asked): LogicException
    {
        return new LogicException($this->label().' is a toy and keeps no score, so it has no '.$asked.'.');
    }
}
