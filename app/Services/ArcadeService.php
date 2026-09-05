<?php

namespace App\Services;

use App\Enums\ArcadeGame;
use App\Enums\ProfileRole;
use App\Enums\TicketKind;
use App\Models\ArcadeScore;
use App\Models\ArcadeWeekPrize;
use App\Models\Household;
use App\Models\Profile;
use App\Notifications\ArcadeGameAdded;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Throwable;

/**
 * The arcade: posting a run, the weekly board, and the prize that closes it.
 *
 * This class used to be shaped end to end by one constraint — the game
 * stood on `/`, which has no auth, so a stranger with the URL could read the
 * board and post to it. That is why scores carried a rolled codename instead
 * of a name, and why nothing here knew who anybody was.
 *
 * The game moved behind the PIN, and the constraint went with it. A run now
 * names the person who played, and the board belongs to one household. Two
 * things survive from the public era and should not be mistaken for leftovers:
 * the score is still treated as a *claim* rather than a fact, because it still
 * arrives from a browser; and the old codename rows are still readable, which
 * is why `ArcadeScore::displayName()` falls back to that column.
 *
 * There is more than one game now, and that is why every method below takes an
 * `ArcadeGame`. Nothing here may be asked a question that spans them: a tower
 * is floors, a walk is lanes and a pit is points, so a mixed board ranks
 * numbers that are not the same kind of number. `settle()` is the one
 * exception, and it fans out over the games rather than merging them.
 *
 * Not every game is one of these questions' subject. A *toy* keeps no score —
 * see `ArcadeGame::isRanked()` — so it never reaches a board, never settles a
 * week and is refused by `post()`. `weeklyLeaders()` and `settle()` already
 * only ever see ranked games; `post()` is the one that had to be told.
 */
class ArcadeService
{
    /**
     * What topping a finished week is worth.
     *
     * Three, which is a Bonus Shop perk and change — enough that the board is
     * worth trying to win, not so much that the fastest thumbs in the house
     * out-earn a week of actual chores. Paid to kids only: a grown-up who tops
     * the week wins the week and nothing else, which is the joke and also the
     * rule that keeps the prize pointing at the people it is for.
     *
     * Paid *per game*. One prize across all the games would make each new
     * game pointless for everybody who is not already best at the first, which
     * is the opposite of the reason it was added.
     */
    public const PRIZE_TICKETS = 3;

    /**
     * How far back a lazy settlement will look.
     *
     * There is no scheduler here — weeks are settled by whoever opens the
     * arcade next — so a house that ignores the game for a month comes back
     * to several unpaid weeks at once. Six is enough to cover a holiday and
     * bounded enough that the catch-up is a handful of queries rather than a
     * walk through the whole history.
     */
    private const SETTLE_WEEKS_BACK = 6;

    /**
     * How far a run got, in words a kid can picture. One ladder per game.
     *
     * This is the shared spine of the whole feature: each game's artwork is
     * keyed to its own ladder, so the scenery changes exactly where the banner
     * says it does. Stack the Mess keys its parallax to these entries *by
     * index* in `resources/js/arcade.js`; Windy Walkies carries its own copy of
     * the list inside `resources/js/fart-dash.js` and draws its banner from it.
     * Changing either half on its own fails `ArcadeMilestoneTest`.
     *
     * Only the ranked games are here. A toy keeps no score, so there is nothing
     * for a rung to label — see `milestonesFor()`.
     *
     * @var array<string, list<array{0: int, 1: string}>>
     */
    public const MILESTONES = [
        ArcadeGame::StackTheMess->value => [
            [0, 'On the rug'],
            [3, 'Sofa height'],
            [6, 'Light switch'],
            [9, 'Picture rail'],
            [12, 'Window height'],
            [15, 'Top shelf'],
            [18, 'Ceiling'],
            [22, 'In the attic'],
            [26, 'Through the roof'],
            [31, 'Treetops'],
            [36, 'In the clouds'],
            [42, 'Bird height'],
            [50, 'Stratosphere'],
            [60, 'Moonlit'],
            [75, 'Outer space'],
        ],

        /*
         * The walk's ladder. Its thresholds are keyed to the biome cycle on
         * purpose — the scenery changes every 14 lanes, so "In the farmyard"
         * lands when the farm does and "Through the doors" when the shop
         * starts. A rung that drifts off a biome boundary announces a landmark
         * the player cannot see.
         */
        ArcadeGame::WindyWalkies->value => [
            [0, 'Off the kerb'],
            [4, 'Across the road'],
            [8, 'Over the water'],
            [14, 'Down the lane'],
            [20, 'In the farmyard'],
            [27, 'Past the tractors'],
            [34, 'Through the doors'],
            [42, 'Frozen aisle'],
            [50, 'Out the back'],
            [60, 'Open country'],
            [72, 'Long gone'],
            [90, 'Legendary guff'],
        ],

        /*
         * The flight's ladder, and the one place where a rung is a *place*
         * rather than a height: every promotion is a city further east, so the
         * board reads as a trip somebody is on rather than a number going up.
         * `resources/js/grand-tour.js` carries its own copy and draws the HUD
         * from it — `ArcadeFlightTest` holds the two halves together.
         */
        ArcadeGame::GrandTour->value => [
            [0, 'On the runway'],
            [40, 'Over the Channel'],
            [110, 'Paris'],
            [220, 'The Alps'],
            [360, 'Venice'],
            [540, 'Athens'],
            [780, 'All the way round'],
        ],

        /*
         * The slide's ladder, in metres. Its thresholds are the ones
         * `resources/js/penguin-launch.js` carries, not the ones the design
         * document beside it prints — the two disagreed on arrival, and the
         * game file is the half that actually draws a rung on the canvas the
         * moment a kid passes it. A ladder that disagreed with the canvas would
         * have the board congratulating somebody for reaching a place the game
         * never told them they had reached. `ArcadePenguinTest` holds the two
         * halves together from here on.
         */
        ArcadeGame::PenguinLaunch->value => [
            [0, 'Belly on the ice'],
            [60, 'Off the shelf'],
            [140, 'Open ice'],
            [260, 'Iceberg alley'],
            [420, 'Past the whales'],
            [640, 'Halfway to the pole'],
            [900, 'Nobody can beat this'],
        ],
    ];

    /**
     * One game's ladder.
     *
     * A toy has none — there is no score to label — and asking for one is the
     * same bug as asking it for a unit, so it raises the same error rather than
     * handing back an empty array that `altitude()` would then index into.
     *
     * @return list<array{0: int, 1: string}>
     */
    public static function milestonesFor(ArcadeGame $game): array
    {
        return self::MILESTONES[$game->value] ?? throw new LogicException(
            $game->label().' is a toy and keeps no score, so it has no milestone ladder.'
        );
    }

    /** ISO year-week — the bucket a run is posted into, e.g. "2026-W35". */
    public function currentWeek(?Carbon $at = null): string
    {
        return ($at ?? now())->format('o-\WW');
    }

    /** Human label for the current board's week, e.g. "Mon 24 Aug". */
    public function weekStartedOn(): Carbon
    {
        return now()->startOfWeek();
    }

    /**
     * How far a given score got, in words. Falls back to the first milestone,
     * which is where every run starts.
     */
    public function altitude(ArcadeGame $game, int $score): string
    {
        $ladder = self::milestonesFor($game);
        $label = $ladder[0][1];

        foreach ($ladder as [$at, $name]) {
            if ($score >= $at) {
                $label = $name;
            }
        }

        return $label;
    }

    /**
     * Write a run to a game's board. Returns null when the claim is not
     * worth keeping — a scoreless run, or a number no player could reach.
     *
     * The name is taken off the profile rather than sent by the browser, which
     * is the same rule the codenames enforced by another means: nothing a
     * player types can reach this column. It is stored as well as linked so a
     * deleted profile leaves a readable row behind instead of a blank one.
     *
     * The *game* does not come from the browser either. It is whichever game
     * the page is showing, held server-side, so a run cannot be posted to a
     * board it was not played on.
     *
     * A toy is refused outright rather than being allowed to write a row that
     * nothing would ever read. Slime Time never sends a score — it has none —
     * but the page's `post()` is reachable from the browser whichever game is
     * showing, and a board that quietly accepted runs from a game with no
     * scoring would be a board nobody could explain.
     */
    public function post(Profile $profile, ArcadeGame $game, int $score): ?ArcadeScore
    {
        if (! $game->isRanked()) {
            return null;
        }

        // The ceiling belongs to the game rather than to this class: a flight
        // is scored in points earned a dozen a second and a tower in floors
        // climbed one at a time, so one number for all of them would either
        // wave through a tampered tower or throw away an honest flight. See
        // ArcadeGame::maxScore().
        if ($score < 1 || $score > $game->maxScore()) {
            return null;
        }

        return ArcadeScore::create([
            'household_id' => $profile->household_id,
            'profile_id' => $profile->id,
            'game' => $game,
            'codename' => $profile->name,
            'score' => $score,
            'week' => $this->currentWeek(),
        ]);
    }

    /**
     * This week's board for one game. Ties break oldest-first: getting there
     * first is the tiebreak everywhere else in this app, and it stops a new run
     * from bumping an equal one that has been sitting on the board all week.
     *
     * @return Collection<int, ArcadeScore>
     */
    public function weeklyTop(Household $household, ArcadeGame $game, int $limit = 10): Collection
    {
        return $this->boardFor($household, $game, $this->currentWeek(), $limit);
    }

    /**
     * This week's standings on one game: one row per player, their best run.
     *
     * Distinct from `boardFor()`, which lists *runs*. A board of runs is the
     * right thing for settling a week — the top run wins it, and whose it is
     * follows — but the wrong thing to show three of: one kid having a good
     * evening fills all three rows with their own name, and the column that is
     * meant to say who is winning says nothing at all.
     *
     * Ties keep the incumbent, the same rule as everywhere else here: the runs
     * arrive sorted highest-first and oldest-first, and `unique()` keeps the
     * first it sees of each player.
     *
     * Legacy rows from the public era have no `profile_id`, so they collapse on
     * their stored codename instead — which is what a codename was.
     *
     * @return Collection<int, ArcadeScore>
     */
    public function weeklyStandings(Household $household, ArcadeGame $game, int $limit = 10): Collection
    {
        return $this->boardFor($household, $game, $this->currentWeek(), $limit * 4)
            ->unique(fn (ArcadeScore $score) => $score->profile_id ?? 'codename:'.$score->codename)
            ->take($limit)
            ->values();
    }

    /**
     * The run at the top of this week on every ranked game at once, keyed by
     * game.
     *
     * One query rather than one per game, because the rail asks this question
     * about every game on the page at the same time — it is the "best 38 this
     * wk" line under each name, which is what turns the switcher into a glance
     * at who is winning before anything has been opened.
     *
     * A game nobody has played this week maps to null, which the rail says out
     * loud rather than printing a zero: "nobody yet" is an invitation and
     * "best 0" is a scoreboard.
     *
     * @return array<string, ArcadeScore|null>
     */
    public function weeklyLeaders(Household $household): array
    {
        $runs = ArcadeScore::query()
            ->with('profile')
            ->where('household_id', $household->id)
            ->where('week', $this->currentWeek())
            ->orderByDesc('score')
            ->orderBy('id')
            ->get();

        $leaders = [];

        foreach (ArcadeGame::ranked() as $game) {
            $leaders[$game->value] = $runs->firstWhere('game', $game);
        }

        return $leaders;
    }

    /**
     * The number on the "beat NN for 3 tickets" strip.
     *
     * One more than the leader, because a tie keeps the incumbent — that is the
     * tiebreak `boardFor()` applies, so a target of "equal it" would be a lie.
     * On a game nobody has played this week it is 1: the first run that scores
     * anything at all takes the week.
     */
    public function beatTarget(?ArcadeScore $leader): int
    {
        return ($leader?->score ?? 0) + 1;
    }

    /**
     * One week of one game, highest first.
     *
     * @return Collection<int, ArcadeScore>
     */
    public function boardFor(Household $household, ArcadeGame $game, string $week, int $limit = 10): Collection
    {
        return ArcadeScore::query()
            ->with('profile')
            ->where('household_id', $household->id)
            ->where('game', $game)
            ->where('week', $week)
            ->orderByDesc('score')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** The best run this house has ever posted on a game, or null if nobody has played it. */
    public function allTimeBest(Household $household, ArcadeGame $game): ?ArcadeScore
    {
        return ArcadeScore::query()
            ->with('profile')
            ->where('household_id', $household->id)
            ->where('game', $game)
            ->orderByDesc('score')
            ->orderBy('id')
            ->first();
    }

    /**
     * One player's own best on a game, ever — the number under each card in
     * the game switcher. Zero rather than null where they have never played it,
     * because "Best 0" is something to go and beat and a blank is not.
     */
    public function personalBest(Profile $profile, ArcadeGame $game): int
    {
        return (int) ArcadeScore::query()
            ->where('household_id', $profile->household_id)
            ->where('profile_id', $profile->id)
            ->where('game', $game)
            ->max('score');
    }

    /**
     * The games this kid has not been shown yet.
     *
     * A game is new to somebody until they next open the arcade, measured off
     * `profiles.arcade_seen_at` against each game's release date. Null means a
     * profile that has never been, so everything is new to them — the same
     * reading `StoreService::newCountFor()` gives an empty `loot_seen_at`.
     *
     * Newest first, because the caller that cares about order is the one
     * choosing which game to open on: two unseen games should land a kid on
     * the one that just arrived, not the one that arrived first.
     *
     * @return Collection<int, ArcadeGame>
     */
    public function newGamesFor(Profile $profile): Collection
    {
        return collect(ArcadeGame::cases())
            ->filter(fn (ArcadeGame $game) => $profile->arcade_seen_at === null
                || $game->releasedOn()->greaterThan($profile->arcade_seen_at))
            ->sortByDesc(fn (ArcadeGame $game) => $game->releasedOn())
            ->values();
    }

    /** The number the Arcade nav row wears, and the reason a kid opens it at all. */
    public function newCountFor(Profile $profile): int
    {
        return $this->newGamesFor($profile)->count();
    }

    /**
     * Marks the arcade as looked at.
     *
     * Called on mount, *after* the page has taken its snapshot of what was new
     * — the same ordering `StoreService::markShopSeen()` needs, and for the same
     * reason: marking first would erase the very thing the flash exists to show,
     * on the one visit it was meant for.
     */
    public function markGamesSeen(Profile $profile): void
    {
        $profile->arcade_seen_at = now();
        $profile->save();
    }

    /**
     * Tells the kids a game has landed in the arcade.
     *
     * The push half of the same job the "new" flash does in the app: the flash
     * catches a kid who opens it, this catches the one who doesn't — which is
     * most of them, because nothing about the arcade ever comes looking for
     * anybody. The same split `StoreService::announceNewItem()` makes.
     *
     * Fired by hand from `arcade:announce` rather than from anything automatic,
     * because a game arrives in a deploy: there is no row being written and
     * no form being submitted that could trigger it, and a game can go in
     * quietly while it is being tried out.
     */
    public function announceNewGame(Household $household, ArcadeGame $game): int
    {
        $kids = Profile::where('household_id', $household->id)
            ->where('role', ProfileRole::Kid)
            ->get();

        if ($kids->isEmpty()) {
            return 0;
        }

        try {
            Notification::send($kids, new ArcadeGameAdded(
                'New game in the arcade!',
                $game->label().' — '.$game->pitch(),
            ));
        } catch (Throwable $e) {
            Log::error('Arcade game notification failed.', [
                'game' => $game->value,
                'household_id' => $household->id,
                'exception' => $e,
            ]);

            return 0;
        }

        return $kids->count();
    }

    /**
     * Pay out every finished week this household has not settled yet, on every
     * game.
     *
     * Lazy on purpose: a scheduled command is one more thing that has to be
     * running for a kid to get what they won, and the arcade is opened often
     * enough that "settle on the way in" pays within a day of the week turning
     * over. Called from the page rather than from the shell — a prize nobody
     * has gone to look at can wait, and the shell renders on every request in
     * the console.
     *
     * The one method here that spans the games, and it fans out rather than
     * merging: opening any game settles all of them, because a kid who only
     * plays one should not be the reason the others never pay. Toys settle
     * nothing and need no excluding — weeks are read off the scores, and a
     * game that keeps none has no weeks to find.
     *
     * @return Collection<int, ArcadeWeekPrize> what this call settled
     */
    public function settle(Household $household): Collection
    {
        $settled = collect();

        foreach ($this->unsettled($household) as $pending) {
            $prize = $this->settleWeek($household, $pending['game'], $pending['week']);

            if ($prize !== null) {
                $settled->push($prize);
            }
        }

        return $settled;
    }

    /**
     * The most recent settled week of one game that actually had a winner.
     *
     * What the board prints under "last champion". A week nobody played is
     * settled with no profile and is skipped here — "nobody won last week" is
     * not news worth a line on the page.
     */
    public function lastChampion(Household $household, ArcadeGame $game): ?ArcadeWeekPrize
    {
        return ArcadeWeekPrize::query()
            ->with('profile')
            ->where('household_id', $household->id)
            ->where('game', $game)
            ->whereNotNull('profile_id')
            ->orderByDesc('week')
            ->first();
    }

    /**
     * Settle one finished week of one game, or return null if somebody else
     * got there first.
     *
     * The row is written *before* the tickets are minted, and the unique key on
     * (household, week, game) is what makes this exactly-once: two kids opening
     * the arcade at the same moment on a Monday both find the week unpaid, and
     * the second one's insert fails rather than paying it twice. The cost of
     * that order is that a crash between the two lines loses a payout — three
     * tickets, once, in a case that needs the process to die inside a
     * millisecond — which is the better way round to be wrong.
     */
    private function settleWeek(Household $household, ArcadeGame $game, string $week): ?ArcadeWeekPrize
    {
        $winner = $this->boardFor($household, $game, $week, 1)->first();
        $profile = $winner?->profile;

        // Kids only. A parent still wins the week — the row records it, and the
        // board still says so — they just do not get paid for it.
        $tickets = $profile?->isKid() ? self::PRIZE_TICKETS : 0;

        try {
            $prize = ArcadeWeekPrize::create([
                'household_id' => $household->id,
                'week' => $week,
                'game' => $game,
                'profile_id' => $profile?->id,
                'score' => $winner?->score,
                'tickets' => $tickets,
            ]);
        } catch (QueryException $e) {
            // The unique key doing its job: another request settled this week
            // between the check above and this insert.
            return null;
        }

        if ($tickets > 0 && $profile !== null) {
            app(TicketService::class)->record(
                $profile,
                TicketKind::Arcade,
                $tickets,
                $game->prizeReason($winner->score),
                $winner,
            );
        }

        return $prize;
    }

    /**
     * Finished weeks with runs on them that have never been settled, as
     * week-and-game pairs.
     *
     * Weeks are read off the scores rather than counted back from today, so a
     * week nobody played is never settled and never has to be: there is nothing
     * to pay and nothing to say about it. Reading the game off the same rows is
     * what keeps that true per game — a week in which only the tower was
     * played settles the tower and leaves the walk alone.
     *
     * @return Collection<int, array{week: string, game: ArcadeGame}>
     */
    private function unsettled(Household $household): Collection
    {
        $current = $this->currentWeek();
        $earliest = $this->currentWeek(now()->subWeeks(self::SETTLE_WEEKS_BACK));

        $played = ArcadeScore::query()
            ->where('household_id', $household->id)
            ->where('week', '<', $current)
            ->where('week', '>=', $earliest)
            ->distinct()
            ->orderBy('week')
            ->get(['week', 'game']);

        $paid = ArcadeWeekPrize::query()
            ->where('household_id', $household->id)
            ->whereIn('week', $played->pluck('week'))
            ->get(['week', 'game'])
            ->map(fn (ArcadeWeekPrize $prize) => $prize->week.'|'.$prize->game->value)
            ->all();

        return $played
            ->reject(fn (ArcadeScore $score) => in_array($score->week.'|'.$score->game->value, $paid, true))
            ->map(fn (ArcadeScore $score) => ['week' => $score->week, 'game' => $score->game])
            ->values();
    }
}
