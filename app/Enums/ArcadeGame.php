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

    case GrandTour = 'grand_tour';

    /** Ships as "Penguin Launch" in the design — see label(). */
    case PenguinLaunch = 'penguin_launch';

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
            self::StackTheMess, self::WindyWalkies, self::GrandTour, self::PenguinLaunch => true,
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
            /*
             * The smallest budget of the four, because Grand Tour stacks the
             * least: a canvas, a 10px gap and one 44px button. Its score, its
             * distance and the city it has reached are all drawn inside the
             * canvas rather than above it, and one button never wraps to a
             * second row the way the toy's four do.
             */
            self::GrandTour => 140,
            /*
             * The same stack as Grand Tour — a canvas, a 10px gap and one 44px
             * hold-to-charge button — so the same budget. Its distance, its
             * milestone and its best are all drawn inside the canvas.
             */
            self::PenguinLaunch => 140,
        };
    }

    /**
     * The height of the fixed board this game draws before it is scaled, in px.
     *
     * Every game is 320 wide and stretches its canvas to whatever box the page
     * gives it, so width is the only dial — but the full-screen stage sizes
     * that box from a *height* budget, and it needs the aspect ratio to turn
     * one into the other. Four games draw 320x460 and Westin's Whacky Game
     * draws 320x470; hard-coding 460 would size the taller board about 2% past
     * the budget and push the bottom of the ice off a short screen, which is
     * the exact failure `stageChrome()` exists to prevent.
     */
    public function boardHeight(): int
    {
        return match ($this) {
            self::StackTheMess, self::WindyWalkies, self::SlimeTime, self::GrandTour => 460,
            self::PenguinLaunch => 470,
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
            self::GrandTour => '2026-09-04',
            /*
             * The day after Grand Tour, and a day of its own rather than a
             * second game sharing that one — the rule above wants distinct
             * dates, and `newest()` has no other tie-breaker to fall back on.
             */
            self::PenguinLaunch => '2026-09-05',
        });
    }

    public function label(): string
    {
        return match ($this) {
            self::StackTheMess => 'Stack the Mess',
            self::WindyWalkies => 'Windy Walkies',
            self::SlimeTime => 'Slime Time',
            self::GrandTour => 'Grand Tour',
            /*
             * The house's name for it, and the only name anybody sees. The
             * design calls it "Penguin Launch" and so does everything the
             * browser touches — the element, both events, the file — because
             * none of those is user-facing and renaming them would fork the
             * file from the bundle for nothing.
             */
            self::PenguinLaunch => "Westin's Whacky Game",
        };
    }

    /** What the score counts, for every line that prints one. */
    public function unit(): string
    {
        return match ($this) {
            self::StackTheMess => 'floors',
            self::WindyWalkies => 'lanes',
            /*
             * Points rather than kilometres, and the distinction is the whole
             * reason this method exists. A flight scores its distance *plus* a
             * bonus for every gap threaded, so the number on the board is
             * always larger than the kilometres the plane actually flew —
             * calling it km on a board the whole house reads would make the
             * game look like it was lying about how far anybody got.
             */
            self::GrandTour => 'points',
            /*
             * Metres, and there is nothing else in the number. Rings, mines
             * and power-ups are worth having because they carry the penguin
             * further, never because they add points — which is what makes
             * this the one board that cannot be farmed.
             */
            self::PenguinLaunch => 'metres',
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
            self::GrandTour => 'points flown',
            self::PenguinLaunch => 'metres slid',
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
            self::GrandTour => 'fly a paper plane the whole way across Europe.',
            self::PenguinLaunch => 'sling a penguin as far across the ice as it will go.',
        };
    }

    /** The tips-strip icon, which is the one thing about a game that is not words. */
    public function icon(): string
    {
        return match ($this) {
            self::StackTheMess => 'fa-layer-group',
            self::WindyWalkies => 'fa-wind',
            self::SlimeTime => 'fa-splotch',
            self::GrandTour => 'fa-paper-plane',
            // No penguin in the free icon set, so the ice it lands on.
            self::PenguinLaunch => 'fa-icicles',
        };
    }

    /** What a winning week is called in the kid's ticket history. */
    public function prizeReason(int $score): string
    {
        return match ($this) {
            self::StackTheMess => 'Tallest tower of the week — '.$score.' floors',
            self::WindyWalkies => 'Longest walk of the week — '.$score.' lanes',
            self::GrandTour => 'Longest flight of the week — '.$score.' points',
            self::PenguinLaunch => 'Longest slide of the week — '.$score.' metres',
            self::SlimeTime => throw $this->notARankedGame('prize wording'),
        };
    }

    /** What an empty board says, in the words of the game that has no runs on it. */
    public function emptyBoard(): string
    {
        return match ($this) {
            self::StackTheMess => 'Nobody has stacked anything yet this week.',
            self::WindyWalkies => 'Nobody has taken the dog out yet this week.',
            self::GrandTour => 'Nobody has taken off yet this week.',
            self::PenguinLaunch => 'Nobody has launched a penguin yet this week.',
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
            // A flight can end on the first gap, so the walk's number rather
            // than the tower's.
            self::GrandTour => 120,
            // A bad pull is over in about three seconds, so the walk's number
            // rather than the tower's.
            self::PenguinLaunch => 120,
            self::SlimeTime => throw $this->notARankedGame('post rate'),
        };
    }

    /**
     * The biggest run this game's board will believe.
     *
     * A ceiling on what a tampered request can write, not a cap on real play —
     * so it sits far enough above the last rung of the ladder that no honest
     * run ever meets it. A score arrives from a browser and is a claim until
     * this has looked at it.
     *
     * It is per game because the games do not count the same *sort* of number.
     * Floors and lanes are distances a body travels one at a time, and 999 of
     * either is several times further than anybody has ever got; points are
     * earned a dozen a second, so squeezing a flight under the same figure
     * would throw away good runs in silence and leave a board that looks like a
     * game nobody is any good at. That is worse than no ceiling at all: the
     * kid is never told, and the run they were proudest of is the one that
     * vanishes.
     */
    public function maxScore(): int
    {
        return match ($this) {
            self::StackTheMess, self::WindyWalkies => 999,
            /*
             * Roughly five times the last city on the ladder, which is itself
             * about a minute of flawless flying. The score climbs at ten to
             * twenty points a second once the curve has run out, so a very
             * good long run reaches four figures and has to be believed.
             */
            self::GrandTour => 4000,
            /*
             * Metres, and about four times the last rung of its ladder. A
             * distance game needs a generous ceiling for the same reason the
             * flight does: a run that chains a ring arc onto a glare-ice slide
             * keeps building long after the last milestone, and a tighter
             * number would throw away exactly the runs a kid was proudest of,
             * in silence.
             */
            self::PenguinLaunch => 4000,
            self::SlimeTime => throw $this->notARankedGame('score ceiling'),
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
