<?php

namespace App\Services;

use App\Enums\TicketKind;
use App\Models\ArcadeScore;
use App\Models\ArcadeWeekPrize;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
 */
class ArcadeService
{
    /**
     * The tallest tower the server will believe. Floors get roughly a pixel
     * narrower each imperfect drop from a 180px slab, so even a player who
     * never misses badly runs out of tower long before this; it exists to put
     * a ceiling on what a tampered request can write, not to cap real play.
     */
    public const MAX_SCORE = 999;

    /** Runs a single player may post per hour before the board stops listening. */
    public const POSTS_PER_HOUR = 40;

    /**
     * What topping a finished week is worth.
     *
     * Three, which is a Bonus Shop perk and change — enough that the board is
     * worth trying to win, not so much that the fastest thumbs in the house
     * out-earn a week of actual chores. Paid to kids only: a grown-up who tops
     * the week wins the week and nothing else, which is the joke and also the
     * rule that keeps the prize pointing at the people it is for.
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
     * How high a tower got, in words a kid can picture.
     *
     * This is the shared spine of the whole feature: the game's parallax
     * scenery is keyed to these entries *by index*, so the wall, the attic and
     * the sky change exactly where the banner says they do. Adding a milestone
     * here without adding the matching scenery in `resources/js/arcade.js`
     * fails `ArcadeMilestoneTest`.
     *
     * @var list<array{0: int, 1: string}>
     */
    public const MILESTONES = [
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
    ];

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
     * How high a given number of floors got, in words. Falls back to the first
     * milestone, which is where every run starts.
     */
    public function altitude(int $floors): string
    {
        $label = self::MILESTONES[0][1];

        foreach (self::MILESTONES as [$at, $name]) {
            if ($floors >= $at) {
                $label = $name;
            }
        }

        return $label;
    }

    /**
     * Write a run to the board. Returns null when the claim is not worth
     * keeping — a zero-floor run, or a number no tower could reach.
     *
     * The name is taken off the profile rather than sent by the browser, which
     * is the same rule the codenames enforced by another means: nothing a
     * player types can reach this column. It is stored as well as linked so a
     * deleted profile leaves a readable row behind instead of a blank one.
     */
    public function post(Profile $profile, int $score): ?ArcadeScore
    {
        if ($score < 1 || $score > self::MAX_SCORE) {
            return null;
        }

        return ArcadeScore::create([
            'household_id' => $profile->household_id,
            'profile_id' => $profile->id,
            'codename' => $profile->name,
            'score' => $score,
            'week' => $this->currentWeek(),
        ]);
    }

    /**
     * This week's board. Ties break oldest-first: getting there first is the
     * tiebreak everywhere else in this app, and it stops a new run from
     * bumping an equal one that has been sitting on the board all week.
     *
     * @return Collection<int, ArcadeScore>
     */
    public function weeklyTop(Household $household, int $limit = 10): Collection
    {
        return $this->boardFor($household, $this->currentWeek(), $limit);
    }

    /**
     * One week's board, highest first.
     *
     * @return Collection<int, ArcadeScore>
     */
    public function boardFor(Household $household, string $week, int $limit = 10): Collection
    {
        return ArcadeScore::query()
            ->with('profile')
            ->where('household_id', $household->id)
            ->where('week', $week)
            ->orderByDesc('score')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** The tallest tower this house has ever posted, or null if nobody has played. */
    public function allTimeBest(Household $household): ?ArcadeScore
    {
        return ArcadeScore::query()
            ->with('profile')
            ->where('household_id', $household->id)
            ->orderByDesc('score')
            ->orderBy('id')
            ->first();
    }

    /**
     * Pay out every finished week this household has not settled yet.
     *
     * Lazy on purpose: a scheduled command is one more thing that has to be
     * running for a kid to get what they won, and the arcade is opened often
     * enough that "settle on the way in" pays within a day of the week turning
     * over. Called from the page rather than from the shell — a prize nobody
     * has gone to look at can wait, and the shell renders on every request in
     * the console.
     *
     * @return Collection<int, ArcadeWeekPrize> what this call settled, newest week last
     */
    public function settle(Household $household): Collection
    {
        $settled = collect();

        foreach ($this->unsettledWeeks($household) as $week) {
            $prize = $this->settleWeek($household, $week);

            if ($prize !== null) {
                $settled->push($prize);
            }
        }

        return $settled;
    }

    /**
     * The most recent settled week that actually had a winner.
     *
     * What the board prints under "last week". A week nobody played is settled
     * with no profile and is skipped here — "nobody won last week" is not news
     * worth a line on the page.
     */
    public function lastChampion(Household $household): ?ArcadeWeekPrize
    {
        return ArcadeWeekPrize::query()
            ->with('profile')
            ->where('household_id', $household->id)
            ->whereNotNull('profile_id')
            ->orderByDesc('week')
            ->first();
    }

    /**
     * Settle one finished week, or return null if somebody else got there first.
     *
     * The row is written *before* the tickets are minted, and the unique key on
     * (household, week) is what makes this exactly-once: two kids opening the
     * arcade at the same moment on a Monday both find the week unpaid, and the
     * second one's insert fails rather than paying it twice. The cost of that
     * order is that a crash between the two lines loses a payout — three
     * tickets, once, in a case that needs the process to die inside a
     * millisecond — which is the better way round to be wrong.
     */
    private function settleWeek(Household $household, string $week): ?ArcadeWeekPrize
    {
        $winner = $this->boardFor($household, $week, 1)->first();
        $profile = $winner?->profile;

        // Kids only. A parent still wins the week — the row records it, and the
        // board still says so — they just do not get paid for it.
        $tickets = $profile?->isKid() ? self::PRIZE_TICKETS : 0;

        try {
            $prize = ArcadeWeekPrize::create([
                'household_id' => $household->id,
                'week' => $week,
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
                'Tallest tower of the week — '.$winner->score.' floors',
                $winner,
            );
        }

        return $prize;
    }

    /**
     * Finished weeks with runs on them that have never been settled.
     *
     * Weeks are read off the scores rather than counted back from today, so a
     * week nobody played is never settled and never has to be: there is
     * nothing to pay and nothing to say about it.
     *
     * @return Collection<int, string>
     */
    private function unsettledWeeks(Household $household): Collection
    {
        $current = $this->currentWeek();
        $earliest = $this->currentWeek(now()->subWeeks(self::SETTLE_WEEKS_BACK));

        $played = ArcadeScore::query()
            ->where('household_id', $household->id)
            ->where('week', '<', $current)
            ->where('week', '>=', $earliest)
            ->distinct()
            ->orderBy('week')
            ->pluck('week');

        $paid = ArcadeWeekPrize::query()
            ->where('household_id', $household->id)
            ->whereIn('week', $played)
            ->pluck('week');

        return $played->diff($paid)->values();
    }
}
