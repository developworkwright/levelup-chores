<?php

namespace App\Services;

use App\Enums\ArcadeGame;
use App\Enums\CompletionStatus;
use App\Enums\TicketKind;
use App\Enums\TokenKind;
use App\Exceptions\InsufficientTokensException;
use App\Models\ArcadeScore;
use App\Models\ChoreCompletion;
use App\Models\Profile;
use App\Models\TokenEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Arcade tokens: what every game pays the moment a run ends, and what the
 * prize counter takes.
 *
 * Mirrors TicketService: `token_entries` is the source of truth and
 * `profiles.arcade_tokens` a cache kept in step inside one transaction.
 *
 * The thing this class exists to hold is the **daily cap**. A game that pays
 * the app's currencies without limit is a game the kids play instead of doing
 * chores, so the machines pay out at most `BASE_CAP` a household day, plus
 * `CAP_PER_CHORE` for every chore claimed that day. Claimed rather than
 * approved: a kid who did the job must not be stood at an empty machine
 * waiting on a grown-up to get round to the queue. A claim that is sent back
 * stops counting, because it was not the job it said it was.
 *
 * The cap is on tokens, never on playing. A run past it still posts to the
 * board; it just pays nothing, and the payout card says why.
 *
 * Kids only, throughout. Grown-ups play, top boards and are paid nothing.
 */
class TokenService
{
    /** What the machines pay a kid who has not claimed a chore today. */
    public const BASE_CAP = 30;

    /** How much room each chore claimed today adds. */
    public const CAP_PER_CHORE = 15;

    /** What one bonus ticket costs at the counter. Never haggled. */
    public const TICKET_PRICE = 10;

    /** For having a go, win or lose. */
    public const PER_RUN = 1;

    /** For each rung of a ladder reached for the first time today. */
    public const PER_RUNG = 1;

    /**
     * Opening the toy, the first time in a day. It keeps no score, so there is
     * no ladder to pay from — it pays flat, once.
     */
    public const TOY_TOKENS = 2;

    /** Topping a finished week's board, over and above the day's cap. */
    public const WEEKLY_PRIZE = 30;

    public function record(
        Profile $profile,
        TokenKind $kind,
        int $amount,
        string $description,
        ?ArcadeGame $game = null,
        ?Model $related = null,
    ): TokenEntry {
        return DB::transaction(function () use ($profile, $kind, $amount, $description, $game, $related) {
            $entry = TokenEntry::create([
                'household_id' => $profile->household_id,
                'profile_id' => $profile->id,
                'kind' => $kind,
                'amount' => $amount,
                'description' => $description,
                'game' => $game,
                'day' => HouseholdClock::for($profile->household)->today()->toDateString(),
                'related_type' => $related?->getMorphClass(),
                'related_id' => $related?->getKey(),
            ]);

            // In the database rather than in memory, for the reason
            // TicketService gives: two copies of one profile in one request.
            $profile->increment('arcade_tokens', $amount);

            return $entry;
        });
    }

    /**
     * Takes tokens off a kid, or refuses.
     *
     * The balance is read under a row lock, so two taps on two prizes cannot
     * both pass the check against the same tokens.
     *
     * @throws InsufficientTokensException
     */
    public function spend(Profile $profile, int $cost, TokenKind $kind, string $description, ?Model $related = null): TokenEntry
    {
        return DB::transaction(function () use ($profile, $cost, $kind, $description, $related) {
            $balance = (int) Profile::whereKey($profile->id)->lockForUpdate()->value('arcade_tokens');

            if ($balance < $cost) {
                throw new InsufficientTokensException($cost - $balance);
            }

            return $this->record($profile, $kind, -$cost, $description, null, $related);
        });
    }

    /** Chores this kid has claimed today and not had sent back. */
    public function choresClaimedToday(Profile $kid): int
    {
        $clock = HouseholdClock::for($kid->household);

        return ChoreCompletion::query()
            ->where('profile_id', $kid->id)
            ->where('submitted_at', '>=', $clock->startOf($clock->today()))
            ->where('status', '!=', CompletionStatus::Rejected)
            ->count();
    }

    /** The most the machines will pay this kid today. */
    public function capFor(Profile $kid): int
    {
        return self::BASE_CAP + self::CAP_PER_CHORE * $this->choresClaimedToday($kid);
    }

    /** What the machines have paid this kid today — the part the cap limits. */
    public function earnedToday(Profile $kid): int
    {
        return (int) TokenEntry::query()
            ->where('profile_id', $kid->id)
            ->whereDate('day', HouseholdClock::for($kid->household)->today()->toDateString())
            ->whereIn('kind', TokenKind::cappedValues())
            ->sum('amount');
    }

    /**
     * Everything the meter needs, in one read.
     *
     * @return array{balance: int, today: int, cap: int, room: int, chores: int, empty: bool}
     */
    public function meterFor(Profile $kid): array
    {
        $chores = $this->choresClaimedToday($kid);
        $cap = self::BASE_CAP + self::CAP_PER_CHORE * $chores;
        $today = $this->earnedToday($kid);

        return [
            'balance' => (int) $kid->fresh()->arcade_tokens,
            'today' => $today,
            'cap' => $cap,
            'room' => max(0, $cap - $today),
            'chores' => $chores,
            'empty' => $today >= $cap,
        ];
    }

    /**
     * This kid's best score today on one game, before whatever run is about to
     * be posted — which is what decides which rungs are new today.
     *
     * Read off the runs themselves rather than kept anywhere, so a rung is paid
     * once per game per household day with nothing to reset at the rollover.
     */
    public function bestToday(Profile $kid, ArcadeGame $game): ?int
    {
        $clock = HouseholdClock::for($kid->household);

        $best = ArcadeScore::query()
            ->where('profile_id', $kid->id)
            ->where('game', $game)
            ->where('created_at', '>=', $clock->startOf($clock->today()))
            ->max('score');

        return $best === null ? null : (int) $best;
    }

    /**
     * Pays a finished run and says how, line by line.
     *
     * One token for having a go, and one for each rung of the game's ladder the
     * run reached that the kid had not already reached on that game today. The
     * first rung is where every run starts, so it is never a line of its own —
     * "had a go" already says it.
     *
     * The lines are paid in order until the day's room runs out, and the ones
     * that did not fit stay on the card marked as such: the cap is the lever,
     * so a kid must see what it cost them rather than a total quietly rounded
     * down.
     *
     * @return array{game: string, score: int, unit: string, rung: string, lines: list<array{name: string, tokens: int, paid: bool}>, earned: int, paid: int, lost: int, from: int, to: int, cap: int, today: int}|null
     */
    public function payRun(Profile $kid, ArcadeGame $game, int $score, ?int $bestBefore, ?ArcadeScore $run = null): ?array
    {
        if (! $kid->isKid() || ! $game->isRanked()) {
            return null;
        }

        $ladder = ArcadeService::milestonesFor($game);
        $reached = $this->rungIndex($ladder, $score);
        $already = $bestBefore === null ? 0 : $this->rungIndex($ladder, $bestBefore);

        $lines = [['name' => 'Had a go', 'tokens' => self::PER_RUN]];

        for ($rung = max(1, $already + 1); $rung <= $reached; $rung++) {
            $lines[] = ['name' => $ladder[$rung][1], 'tokens' => self::PER_RUNG];
        }

        $meter = $this->meterFor($kid);
        $room = $meter['room'];
        $paid = 0;

        foreach ($lines as $i => $line) {
            $fits = $paid + $line['tokens'] <= $room;
            $lines[$i]['paid'] = $fits;

            if ($fits) {
                $paid += $line['tokens'];
            } else {
                $room = $paid;
            }
        }

        $earned = array_sum(array_column($lines, 'tokens'));

        if ($paid > 0) {
            $this->record(
                $kid,
                TokenKind::Run,
                $paid,
                $game->label().' — '.number_format($score).' '.$game->unit(),
                $game,
                $run,
            );
        }

        return [
            'game' => $game->label(),
            'score' => $score,
            'unit' => $game->unit(),
            'rung' => $ladder[$reached][1],
            'lines' => $lines,
            'earned' => $earned,
            'paid' => $paid,
            'lost' => $earned - $paid,
            'from' => $meter['balance'],
            'to' => $meter['balance'] + $paid,
            'cap' => $meter['cap'],
            'today' => $meter['today'] + $paid,
        ];
    }

    /**
     * Pays for opening the toy, the first time today. Null when it has already
     * paid today, the kid is a grown-up, or the machine is empty.
     */
    public function payToy(Profile $kid, ArcadeGame $game): ?int
    {
        if (! $kid->isKid() || $game->isRanked()) {
            return null;
        }

        $alreadyToday = TokenEntry::query()
            ->where('profile_id', $kid->id)
            ->where('kind', TokenKind::Toy)
            ->whereDate('day', HouseholdClock::for($kid->household)->today()->toDateString())
            ->exists();

        if ($alreadyToday) {
            return null;
        }

        $paid = min(self::TOY_TOKENS, $this->meterFor($kid)['room']);

        if ($paid < 1) {
            return null;
        }

        $this->record($kid, TokenKind::Toy, $paid, 'Opened '.$game->label(), $game);

        return $paid;
    }

    /**
     * Swaps tokens for one bonus ticket — the bridge to the Locker.
     *
     * @throws InsufficientTokensException
     */
    public function buyTicket(Profile $kid): void
    {
        DB::transaction(function () use ($kid) {
            $entry = $this->spend($kid, self::TICKET_PRICE, TokenKind::Ticket, 'Swapped for a ticket');

            app(TicketService::class)->record($kid, TicketKind::Tokens, 1, 'Swapped '.self::TICKET_PRICE.' arcade tokens', $entry);
        });
    }

    /**
     * Which rung of a ladder a score reached.
     *
     * @param  list<array{0: int, 1: string}>  $ladder
     */
    private function rungIndex(array $ladder, int $score): int
    {
        $index = 0;

        foreach ($ladder as $i => [$from]) {
            if ($score >= $from) {
                $index = $i;
            }
        }

        return $index;
    }
}
