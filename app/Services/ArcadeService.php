<?php

namespace App\Services;

use App\Enums\ArcadeGame;
use App\Enums\ProfileRole;
use App\Enums\TicketKind;
use App\Models\ArcadeScore;
use App\Models\ArcadeWeekPrize;
use App\Models\Household;
use App\Models\Profile;
use App\Notifications\ArcadeCabinetAdded;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The arcade: posting a run, the weekly board, and the prize that closes it.
 *
 * This class used to be shaped end to end by one constraint — the cabinet
 * stood on `/`, which has no auth, so a stranger with the URL could read the
 * board and post to it. That is why scores carried a rolled codename instead
 * of a name, and why nothing here knew who anybody was.
 *
 * The cabinet moved behind the PIN, and the constraint went with it. A run now
 * names the person who played, and the board belongs to one household. Two
 * things survive from the public era and should not be mistaken for leftovers:
 * the score is still treated as a *claim* rather than a fact, because it still
 * arrives from a browser; and the old codename rows are still readable, which
 * is why `ArcadeScore::displayName()` falls back to that column.
 *
 * There are two cabinets now, and that is why every method below takes an
 * `ArcadeGame`. Nothing here may be asked a question that spans both: a tower
 * is floors and a walk is lanes, so a mixed board ranks numbers that are not
 * the same kind of number. `settle()` is the one exception, and it fans out
 * over the games rather than merging them.
 */
class ArcadeService
{
    /**
     * The biggest run the server will believe, whichever cabinet it came off.
     *
     * A tower's floors get roughly a pixel narrower each imperfect drop from a
     * 180px slab, and a walk's traffic speeds up with distance, so a player who
     * never misses runs out of game long before this on either. It exists to
     * put a ceiling on what a tampered request can write, not to cap real play.
     */
    public const MAX_SCORE = 999;

    /**
     * What topping a finished week is worth.
     *
     * Three, which is a Bonus Shop perk and change — enough that the board is
     * worth trying to win, not so much that the fastest thumbs in the house
     * out-earn a week of actual chores. Paid to kids only: a grown-up who tops
     * the week wins the week and nothing else, which is the joke and also the
     * rule that keeps the prize pointing at the people it is for.
     *
     * Paid *per game*. One prize across both cabinets would make the second
     * game pointless for everybody who is not already best at the first, which
     * is the opposite of the reason it was added.
     */
    public const PRIZE_TICKETS = 3;

    /**
     * How far back a lazy settlement will look.
     *
     * There is no scheduler here — weeks are settled by whoever opens the
     * arcade next — so a house that ignores the cabinet for a month comes back
     * to several unpaid weeks at once. Six is enough to cover a holiday and
     * bounded enough that the catch-up is a handful of queries rather than a
     * walk through the whole history.
     */
    private const SETTLE_WEEKS_BACK = 6;

    /**
     * How far a run got, in words a kid can picture. One ladder per cabinet.
     *
     * This is the shared spine of the whole feature: each game's artwork is
     * keyed to its own ladder, so the scenery changes exactly where the banner
     * says it does. Stack the Mess keys its parallax to these entries *by
     * index* in `resources/js/arcade.js`; Windy Walkies carries its own copy of
     * the list inside `resources/js/fart-dash.js` and draws its banner from it.
     * Changing either half on its own fails `ArcadeMilestoneTest`.
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
    ];

    /**
     * One cabinet's ladder.
     *
     * @return list<array{0: int, 1: string}>
     */
    public static function milestonesFor(ArcadeGame $game): array
    {
        return self::MILESTONES[$game->value];
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
     * Write a run to a cabinet's board. Returns null when the claim is not
     * worth keeping — a scoreless run, or a number no player could reach.
     *
     * The name is taken off the profile rather than sent by the browser, which
     * is the same rule the codenames enforced by another means: nothing a
     * player types can reach this column. It is stored as well as linked so a
     * deleted profile leaves a readable row behind instead of a blank one.
     *
     * The *game* does not come from the browser either. It is whichever cabinet
     * the page is showing, held server-side, so a run cannot be posted to a
     * board it was not played on.
     */
    public function post(Profile $profile, ArcadeGame $game, int $score): ?ArcadeScore
    {
        if ($score < 1 || $score > self::MAX_SCORE) {
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
     * This week's board for one cabinet. Ties break oldest-first: getting there
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
     * One week of one cabinet, highest first.
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

    /** The best run this house has ever posted on a cabinet, or null if nobody has played it. */
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
     * One player's own best on a cabinet, ever — the number under each card in
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
     * The cabinets this kid has not been shown yet.
     *
     * A game is new to somebody until they next open the arcade, measured off
     * `profiles.arcade_seen_at` against each game's release date. Null means a
     * profile that has never been, so everything is new to them — the same
     * reading `StoreService::newCountFor()` gives an empty `loot_seen_at`.
     *
     * Newest first, because the caller that cares about order is the one
     * choosing which cabinet to open on: two unseen games should land a kid on
     * the one that just arrived, not the one that arrived first.
     *
     * @return Collection<int, ArcadeGame>
     */
    public function newCabinetsFor(Profile $profile): Collection
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
        return $this->newCabinetsFor($profile)->count();
    }

    /**
     * Marks the arcade as looked at.
     *
     * Called on mount, *after* the page has taken its snapshot of what was new
     * — the same ordering `StoreService::markShopSeen()` needs, and for the same
     * reason: marking first would erase the very thing the flash exists to show,
     * on the one visit it was meant for.
     */
    public function markCabinetsSeen(Profile $profile): void
    {
        $profile->arcade_seen_at = now();
        $profile->save();
    }

    /**
     * Tells the kids a cabinet has landed in the arcade.
     *
     * The push half of the same job the "new" flash does in the app: the flash
     * catches a kid who opens it, this catches the one who doesn't — which is
     * most of them, because nothing about the arcade ever comes looking for
     * anybody. The same split `StoreService::announceNewItem()` makes.
     *
     * Fired by hand from `arcade:announce` rather than from anything automatic,
     * because a cabinet arrives in a deploy: there is no row being written and
     * no form being submitted that could trigger it, and a game can go in
     * quietly while it is being tried out.
     */
    public function announceNewCabinet(Household $household, ArcadeGame $game): int
    {
        $kids = Profile::where('household_id', $household->id)
            ->where('role', ProfileRole::Kid)
            ->get();

        if ($kids->isEmpty()) {
            return 0;
        }

        try {
            Notification::send($kids, new ArcadeCabinetAdded(
                'New game in the arcade!',
                $game->label().' — '.$game->pitch(),
            ));
        } catch (Throwable $e) {
            Log::error('Arcade cabinet notification failed.', [
                'game' => $game->value,
                'household_id' => $household->id,
                'exception' => $e,
            ]);

            return 0;
        }

        return $kids->count();
    }

    /**
     * Pay out every finished week this household has not settled yet, on both
     * cabinets.
     *
     * Lazy on purpose: a scheduled command is one more thing that has to be
     * running for a kid to get what they won, and the arcade is opened often
     * enough that "settle on the way in" pays within a day of the week turning
     * over. Called from the page rather than from the shell — a prize nobody
     * has gone to look at can wait, and the shell renders on every request in
     * the console.
     *
     * The one method here that spans the games, and it fans out rather than
     * merging: opening either cabinet settles both, because a kid who only
     * plays one should not be the reason the other never pays.
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
     * The most recent settled week of one cabinet that actually had a winner.
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
     * Settle one finished week of one cabinet, or return null if somebody else
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
     * what keeps that true per cabinet — a week in which only the tower was
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
