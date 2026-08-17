<?php

namespace App\Services;

use App\Enums\CompletionStatus;
use App\Enums\ProfileRole;
use App\Exceptions\InsufficientTicketsException;
use App\Models\Bounty;
use App\Models\ChoreCompletion;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\Nudge;
use App\Models\Profile;
use App\Models\StreakRescue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Arena: the household's night, seen from outside any one kid.
 *
 * Everything here is household-wide by design. It is the one kid page that
 * isn't about the kid looking at it, which is why it is also the page they
 * land on — whose run is on the line tonight is news, and their own board is
 * one tap away.
 *
 * The streak this page is about turns on **one thing: today's quest**. Not the
 * whole board. Chores earn points and land monster damage; the quest is what
 * keeps a run alive.
 */
class ArenaService
{
    /**
     * How long the at-risk urgency ramps for before it holds.
     *
     * It holds rather than running to zero because nothing actually expires at
     * the end of it. The household day rolls at `day_boundary_hour` and a
     * quest open at 9pm is not late — so a flame that guttered out at 10pm
     * would be the screen telling a lie the rules don't back up.
     */
    public const RISK_RAMP_HOURS = 3;

    /** Quest cleared today. */
    public const STATE_SAFE = 'safe';

    /** Quest still open, and it isn't late enough to say anything about it. */
    public const STATE_OPEN = 'open';

    /** Quest still open, past the household's evening watch hour. */
    public const STATE_AT_RISK = 'at_risk';

    /** A run that ended at the last rollover. A morning fact, not a warning. */
    public const STATE_BROKEN = 'broken';

    /** What a rescuer pays, in bonus tickets, out of their own pocket. */
    public const RESCUE_COST = 3;

    /**
     * Per-household memos, for the life of one request.
     *
     * The Arena asks the same three questions from five places — raceFor(),
     * superlatives(), crown() (through superlatives *and* choresToday),
     * prizeStanding() and the ticker's broken-run rows all want tonightFor().
     * Unmemoised that ran the whole per-kid walk five times on the page every
     * kid lands on: syncStreak, questFor, two questApprovedOn lookups each
     * hitting five tables, and a day-by-day run walk, times every kid, times
     * five.
     *
     * Keyed by household id and never cleared, which is safe because the memo
     * lives only as long as the instance: the container hands out a new
     * ArenaService per `app()` call, and the page resolves one and reuses it
     * for the whole render. An action that writes — nudge(), rescue() — runs
     * on its own instance and so cannot leave a stale memo behind for the
     * re-render that follows it.
     *
     * @var array<int, Collection<int, array<string, mixed>>>
     */
    private array $tonight = [];

    /** @var array<int, Collection<int, array<string, mixed>>> */
    private array $todayTally = [];

    /** @var array<int, array<string, ?array{profile: Profile, note: string}>> */
    private array $superlatives = [];

    /** @var array<int, ?array<string, mixed>> */
    private array $week = [];

    public function __construct(
        private ChoreService $chores,
        private TicketService $tickets,
    ) {}

    /**
     * Pokes a sibling about tonight's quest.
     *
     * Free, and capped at one per nudger per target per household night. The
     * cap is what keeps it a poke: three kids each nudging four times is a
     * pile-on, and the thing being nudged is a seven-year-old.
     *
     * Returns false when it didn't land — already nudged tonight, aimed at
     * themselves, or aimed at a kid who has nothing on the line.
     */
    public function nudge(Profile $from, Profile $to): bool
    {
        if (! $this->isPeer($from, $to)) {
            return false;
        }

        $today = HouseholdClock::for($to->household)->today();

        if ($this->chores->isQuestDoneToday($to)) {
            return false;
        }

        if ($this->hasNudged($from, $to)) {
            return false;
        }

        try {
            Nudge::create([
                'from_profile_id' => $from->id,
                'to_profile_id' => $to->id,
                'quest_date' => $today,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A double-tap that beat its own hasNudged() check. The cap held,
            // which is the point — so this is "already nudged", not an error.
            return false;
        }

        return true;
    }

    /**
     * Two different kids in the same house — the only pairing either peer
     * action makes sense for.
     *
     * Checked in the service as well as at the caller because nudge() and
     * rescue() are reachable from public Livewire methods that take an id.
     * Both roles matter: a *parent* on either end is the case that would send
     * questFor() off to build a daily quest for a grown-up.
     */
    private function isPeer(Profile $from, Profile $to): bool
    {
        return ! $from->is($to)
            && $from->household_id === $to->household_id
            && $from->isKid()
            && $to->isKid();
    }

    public function hasNudged(Profile $from, Profile $to): bool
    {
        return Nudge::where('from_profile_id', $from->id)
            ->where('to_profile_id', $to->id)
            ->whereDate('quest_date', HouseholdClock::for($to->household)->today())
            ->exists();
    }

    /**
     * The public stamp on a target's lane — who poked them and when.
     *
     * The most recent one only. A list of every sibling who piled on is the
     * pile-on the cap exists to prevent, rendered.
     */
    public function lastNudgeFor(Profile $kid): ?Nudge
    {
        return Nudge::where('to_profile_id', $kid->id)
            ->whereDate('quest_date', HouseholdClock::for($kid->household)->today())
            ->with('from')
            ->latest('created_at')
            ->first();
    }

    /**
     * Buys a sibling's run through tonight's rollover, at the rescuer's cost.
     *
     * Two things this deliberately does **not** do, and the button's copy
     * promises both: the night pays the rescued kid nothing, and it does not
     * advance their milestone ladder — see
     * {@see ChoreService::refreshStreak()}. A rescue keeps a run alive; it
     * does not hand anybody a night they didn't work.
     *
     * @throws InsufficientTicketsException
     */
    public function rescue(Profile $rescuer, Profile $target): bool
    {
        if (! $this->isPeer($rescuer, $target)) {
            return false;
        }

        $today = HouseholdClock::for($target->household)->today();

        // Nothing to rescue: the quest is in, or somebody already bought
        // tonight. The unique index backs the second one up against a race.
        if ($this->chores->isQuestDoneToday($target) || $this->chores->wasRescuedOn($target, $today)) {
            return false;
        }

        // A run that doesn't exist can't be saved, and charging three tickets
        // to keep a zero at zero is the sort of thing a kid only falls for
        // once.
        if ($target->streak < 1) {
            return false;
        }

        if ($rescuer->bonus_tickets < self::RESCUE_COST) {
            throw new InsufficientTicketsException(self::RESCUE_COST - $rescuer->bonus_tickets);
        }

        // Both writes or neither. The row saves a run and the spend pays for
        // it, and a failure between them would leave the run permanently
        // saved with nobody charged.
        try {
            DB::transaction(function () use ($rescuer, $target, $today) {
                StreakRescue::create([
                    'profile_id' => $target->id,
                    'rescued_by_profile_id' => $rescuer->id,
                    'rescued_date' => $today,
                    'tickets_paid' => self::RESCUE_COST,
                ]);

                $this->tickets->spend(
                    $rescuer,
                    self::RESCUE_COST,
                    "Rescued {$target->name}'s run",
                );
            });
        } catch (UniqueConstraintViolationException) {
            // Two rescuers tapping the same sibling in the same second — a
            // real shape in a house where everyone is looking at one screen.
            // The wasRescuedOn() check above cannot close that window, so the
            // unique index does, and losing the race is refused rather than
            // being a 500. Nothing was charged: the transaction rolled back.
            return false;
        }

        return true;
    }

    /** Why this kid can't rescue that one right now, or null when they can. */
    public function rescueBlockedReason(Profile $rescuer, Profile $target): ?string
    {
        $today = HouseholdClock::for($target->household)->today();

        return match (true) {
            $rescuer->is($target) => 'You can\'t rescue your own run',
            $target->streak < 1 => 'No run to save yet',
            $this->chores->wasRescuedOn($target, $today) => 'Already rescued tonight',
            $rescuer->bonus_tickets < self::RESCUE_COST => 'Not enough tickets',
            default => null,
        };
    }

    /**
     * Every kid in the house with tonight's state resolved, oldest first —
     * the same order the login row and the parent console use.
     *
     * @return Collection<int, array{
     *     profile: Profile,
     *     state: string,
     *     streak: int,
     *     quest: string,
     *     clearedAt: ?Carbon,
     *     brokenFrom: int,
     *     risk: float,
     * }>
     */
    public function tonightFor(Household $household): Collection
    {
        if (isset($this->tonight[$household->id])) {
            return $this->tonight[$household->id];
        }

        $clock = HouseholdClock::for($household);
        $watch = $clock->eveningWatch();
        $risk = $this->riskRamp($household);

        return $this->tonight[$household->id] = Profile::query()
            ->where('household_id', $household->id)
            ->where('role', ProfileRole::Kid)
            ->orderByDesc('age')
            ->get()
            ->map(function (Profile $kid) use ($risk, $watch) {
                // Expired here rather than trusted: `profiles.streak` is a
                // cache that only the kid's own page load refreshes, and this
                // page shows every kid at once — including the ones who
                // haven't opened the app since their run died.
                $this->chores->syncStreak($kid);

                $quest = $this->chores->questFor($kid);
                $state = $this->stateFor($kid, $quest->completed_at !== null);

                return [
                    'profile' => $kid,
                    'state' => $state,
                    'streak' => $kid->streak,
                    'quest' => $quest->isPicked() ? $quest->chore->name : 'Not picked yet',
                    'clearedAt' => $quest->completed_at,
                    'brokenFrom' => $state === self::STATE_BROKEN ? $this->runThatEnded($kid) : 0,
                    // Only an at-risk kid carries a ramp; everyone else is 0 so
                    // nothing downstream has to special-case the state again.
                    'risk' => $state === self::STATE_AT_RISK ? $risk : 0.0,
                    'watchAt' => $watch,
                ];
            });
    }

    /** The four states, in the order they outrank each other. */
    public function stateFor(Profile $kid, bool $questDone): string
    {
        if ($questDone) {
            return self::STATE_SAFE;
        }

        // Checked before the watch hour: a run that died overnight is a fact
        // about this morning, and saying "still open" over it would bury the
        // one thing the kid most needs to be told.
        if ($this->brokeAtLastRollover($kid)) {
            return self::STATE_BROKEN;
        }

        $clock = HouseholdClock::for($kid->household);
        $watch = $clock->eveningWatch();

        // No usable watch hour means nobody is ever drawn as at risk, which is
        // the right way to fail: this is a display threshold, and a household
        // without one simply doesn't get the candle.
        if (! $watch) {
            return self::STATE_OPEN;
        }

        return $clock->now()->utc()->greaterThanOrEqualTo($watch)
            ? self::STATE_AT_RISK
            : self::STATE_OPEN;
    }

    /**
     * How far into the watch window the house is, 0 through 1.
     *
     * Drives the flame: it shrinks, flickers faster and dims as this climbs,
     * then holds at 1. Clamped rather than allowed to run past the end for the
     * reason RISK_RAMP_HOURS explains.
     */
    public function riskRamp(Household $household): float
    {
        $clock = HouseholdClock::for($household);
        $watch = $clock->eveningWatch();

        if (! $watch) {
            return 0.0;
        }

        $minutes = $watch->diffInMinutes($clock->now()->utc(), false);

        return max(0.0, min(1.0, $minutes / (self::RISK_RAMP_HOURS * 60)));
    }

    /**
     * Whether this kid's run ended at the most recent rollover — as opposed to
     * simply never having started one.
     *
     * A zero streak is both of those and they could not read more differently
     * on a screen the whole house sees. The shape of a run that just died is:
     * nothing signed off yesterday, something signed off the day before.
     */
    public function brokeAtLastRollover(Profile $kid): bool
    {
        if ($kid->streak !== 0) {
            return false;
        }

        $today = HouseholdClock::for($kid->household)->today();

        return ! $this->chores->questApprovedOn($kid, $today->copy()->subDay())
            && $this->chores->questApprovedOn($kid, $today->copy()->subDays(2));
    }

    /**
     * How long the run that just ended was, for the obituary line.
     *
     * Walks back from the day before yesterday, which is the last day it can
     * have counted. Bounded by the same safety limit the streak walk uses, so
     * odd data can't spin here either.
     */
    private function runThatEnded(Profile $kid): int
    {
        $day = HouseholdClock::for($kid->household)->today()->subDays(2);
        $run = 0;

        while ($run < 366 && $this->chores->questApprovedOn($kid, $day)) {
            $run++;
            $day = $day->copy()->subDay();
        }

        return $run;
    }

    /**
     * What each kid has got done today — count, points, and who is ahead.
     *
     * Approved only. A pending claim is work a parent hasn't looked at, and a
     * board that counted it would let a kid take the lead by submitting.
     *
     * @return Collection<int, array{profile: Profile, chores: int, points: int, leader: bool}>
     */
    public function choresToday(Household $household): Collection
    {
        if (isset($this->todayTally[$household->id])) {
            return $this->todayTally[$household->id];
        }

        $clock = HouseholdClock::for($household);
        $since = $clock->startOf($clock->today());

        $rows = Profile::query()
            ->where('household_id', $household->id)
            ->where('role', ProfileRole::Kid)
            ->orderByDesc('age')
            ->get()
            ->map(function (Profile $kid) use ($since) {
                $done = ChoreCompletion::where('profile_id', $kid->id)
                    ->where('status', CompletionStatus::Approved)
                    ->where('submitted_at', '>=', $since)
                    ->get();

                return [
                    'profile' => $kid,
                    'chores' => $done->count(),
                    'points' => (int) $done->sum('points_awarded'),
                    'leader' => false,
                ];
            });

        $most = (int) $rows->max('chores');

        // Nobody leads on nothing. A gold bar at zero would crown whoever
        // happens to sort first thing in the morning.
        return $this->todayTally[$household->id] = $most === 0
            ? $rows
            : $rows->map(fn (array $row) => [...$row, 'leader' => $row['chores'] === $most]);
    }

    /**
     * Three named lines about today — first done, biggest job, last standing.
     *
     * Each is null when nothing qualifies rather than falling back to a filler
     * name: "LAST STANDING · nobody" is worse than the row not being there.
     *
     * @return array<string, ?array{profile: Profile, note: string}>
     */
    public function superlatives(Household $household): array
    {
        if (isset($this->superlatives[$household->id])) {
            return $this->superlatives[$household->id];
        }

        $clock = HouseholdClock::for($household);
        $since = $clock->startOf($clock->today());

        $done = ChoreCompletion::query()
            ->whereIn('profile_id', $household->profiles()->where('role', ProfileRole::Kid)->pluck('id'))
            ->where('status', CompletionStatus::Approved)
            ->where('submitted_at', '>=', $since)
            ->with(['profile', 'chore'])
            ->get();

        $first = $done->sortBy('submitted_at')->first();
        $biggest = $done->sortByDesc('points_awarded')->first();

        // Whoever still has their quest open. Not a shaming line — it is the
        // one the nudge buttons above are about.
        //
        // Only meaningful once somebody else has actually finished: "last
        // standing" says the others have fallen, and on a morning when nobody
        // has done anything it singles out one kid for being in exactly the
        // same position as everyone else.
        $tonight = $this->tonightFor($household);
        $anyoneSafe = $tonight->contains('state', self::STATE_SAFE);

        $stillOpen = $anyoneSafe
            ? $tonight->whereIn('state', [self::STATE_OPEN, self::STATE_AT_RISK])->last()
            : null;

        return $this->superlatives[$household->id] = [
            'first' => $first ? [
                'profile' => $first->profile,
                'note' => $first->submitted_at->timezone($household->timezone)->format('g:ia'),
            ] : null,
            'biggest' => $biggest ? [
                'profile' => $biggest->profile,
                'note' => $biggest->chore?->name.' · '.number_format($biggest->points_awarded).' pts',
            ] : null,
            'last' => $stillOpen ? [
                'profile' => $stillOpen['profile'],
                'note' => $stillOpen['quest'],
            ] : null,
        ];
    }

    /**
     * The titles the crown rotates through, in order.
     *
     * Rotating rather than fixed so the same kid doesn't win the same thing
     * every day — a board with one permanent winner stops being a board. The
     * day index picks today's, and the page names tomorrow's so everyone can
     * see what to aim at next.
     */
    public const CROWNS = [
        ['key' => 'most_chores', 'label' => 'Most chores done'],
        ['key' => 'earliest_finish', 'label' => 'Earliest finish'],
        ['key' => 'biggest_job', 'label' => 'Biggest single job'],
    ];

    /**
     * Today's crown: which title is up, and who is winning it.
     *
     * @return array{label: string, tomorrow: string, winner: ?Profile, note: string}
     */
    public function crown(Household $household): array
    {
        $clock = HouseholdClock::for($household);
        // Indexed off the household day rather than the calendar date, so the
        // title turns over at the same moment everything else on this page
        // does.
        $index = (int) $clock->today()->diffInDays(Carbon::parse('2026-01-04')) % count(self::CROWNS);
        $today = self::CROWNS[abs($index)];
        $tomorrow = self::CROWNS[(abs($index) + 1) % count(self::CROWNS)];

        $superlatives = $this->superlatives($household);
        $chores = $this->choresToday($household);

        [$winner, $note] = match ($today['key']) {
            'earliest_finish' => [
                $superlatives['first']['profile'] ?? null,
                $superlatives['first']['note'] ?? '',
            ],
            'biggest_job' => [
                $superlatives['biggest']['profile'] ?? null,
                $superlatives['biggest']['note'] ?? '',
            ],
            default => (function () use ($chores) {
                $leader = $chores->firstWhere('leader', true);

                return [
                    $leader['profile'] ?? null,
                    $leader ? $leader['chores'].' '.Str::plural('chore', $leader['chores']) : '',
                ];
            })(),
        };

        return [
            'label' => $today['label'],
            'tomorrow' => $tomorrow['label'],
            'winner' => $winner,
            'note' => $winner ? $this->crownNote($chores, $winner, $note) : '',
        ];
    }

    /**
     * The sentence under the crown: what the leader has, and how safe it is.
     *
     * The margin is the part worth saying. "Raylan is winning" ends the day;
     * "one ahead of Colton with the evening still to go" is an invitation to
     * the other two, which is the only reason to put a leaderboard in front of
     * children at four in the afternoon.
     *
     * @param  Collection<int, array<string, mixed>>  $chores
     */
    private function crownNote(Collection $chores, Profile $winner, string $fallback): string
    {
        $leader = $chores->firstWhere('profile.id', $winner->id);

        if (! $leader || $leader['chores'] === 0) {
            return $fallback;
        }

        $opening = "{$leader['chores']} done, ".number_format($leader['points']).' pts';

        $chaser = $chores
            ->reject(fn (array $row) => $row['profile']->is($winner))
            ->sortByDesc('chores')
            ->first();

        if (! $chaser) {
            return $opening.'.';
        }

        $gap = $leader['chores'] - $chaser['chores'];

        // A tie is the most interesting state on the board and the one a bare
        // "N ahead" would render as "0 ahead".
        if ($gap <= 0) {
            return $opening." — level with {$chaser['profile']->name}, so it is anyone's.";
        }

        $margin = $gap === 1 ? 'one' : (string) $gap;

        return $opening." — {$margin} ahead of {$chaser['profile']->name} with the evening still to go.";
    }

    /**
     * Who has put what into this week's target.
     *
     * Ranked on **chores this week** — the same number the house bar beside it
     * is filling. It ranked nights for a while, which made the two cards two
     * different competitions sitting side by side: a bar promising a bonus
     * "nobody has to win", and a leaderboard under it settling a prize on
     * something the bar wasn't measuring.
     *
     * So this is a contribution list, not a scoreboard. Nobody is knocked out
     * of it by being third — the prize lands on the whole house or on nobody.
     *
     * @return Collection<int, array{rank: int, profile: Profile, chores: int}>
     */
    public function prizeStanding(Household $household): Collection
    {
        $week = $this->houseWeek($household);

        $segments = $week
            ? $week['segments']
            : $this->tonightFor($household)->map(fn (array $entry) => [
                'profile' => $entry['profile'],
                'chores' => 0,
            ]);

        return $segments
            ->sortByDesc('chores')
            ->values()
            ->map(fn (array $row, int $i) => [
                'rank' => $i + 1,
                'profile' => $row['profile'],
                'chores' => $row['chores'],
            ]);
    }

    /**
     * The house's week: everyone's approved chores against one shared target.
     *
     * Segmented per kid so the bar shows who put what into it — the target is
     * shared, and a single undivided bar would hide the fact that one kid did
     * most of it.
     *
     * @return ?array{target: int, done: int, percent: int, segments: Collection<int, array<string, mixed>>, resetsAt: Carbon}
     */
    public function houseWeek(Household $household): ?array
    {
        if (array_key_exists($household->id, $this->week)) {
            return $this->week[$household->id];
        }

        if (! $household->weekly_chore_target) {
            return $this->week[$household->id] = null;
        }

        $clock = HouseholdClock::for($household);
        $weekStart = $clock->startOf($clock->today()->copy()->startOfWeek(Carbon::SUNDAY));

        $segments = Profile::query()
            ->where('household_id', $household->id)
            ->where('role', ProfileRole::Kid)
            ->orderByDesc('age')
            ->get()
            ->map(fn (Profile $kid) => [
                'profile' => $kid,
                'chores' => ChoreCompletion::where('profile_id', $kid->id)
                    ->where('status', CompletionStatus::Approved)
                    ->where('submitted_at', '>=', $weekStart)
                    ->count(),
            ]);

        $done = (int) $segments->sum('chores');
        $target = (int) $household->weekly_chore_target;

        return $this->week[$household->id] = [
            'target' => $target,
            'done' => $done,
            'percent' => $target > 0 ? min(100, (int) round($done / $target * 100)) : 0,
            'segments' => $segments,
            'resetsAt' => $clock->today()->copy()->startOfWeek(Carbon::SUNDAY)->addWeek(),
        ];
    }

    /**
     * What everyone's been up to — the last few things that happened in the
     * house, whoever they happened to.
     *
     * A read-only kid cut of the same ledger the parent Activity page reads.
     * Deliberately not filtered to the viewer: this is the one kid page that
     * is about the household, and a feed showing you only your own doings
     * would be the opposite of the point.
     *
     * @return Collection<int, array{at: Carbon, line: string}>
     */
    public function ticker(Household $household, int $limit = 5): Collection
    {
        $clock = HouseholdClock::for($household);
        $since = $clock->startOf($clock->today()->copy()->subDays(2));
        $kidIds = $household->profiles()->where('role', ProfileRole::Kid)->pluck('id');

        return collect()
            ->concat($this->tickerChores($kidIds, $since, $limit, $household))
            ->concat($this->tickerNudges($kidIds, $since, $limit))
            ->concat($this->tickerRescues($kidIds, $since, $limit))
            ->concat($this->tickerTrades($household, $since, $limit))
            ->concat($this->tickerBrokenRuns($household))
            ->sortByDesc('at')
            ->take($limit)
            ->values();
    }

    /**
     * Approved work. A completion that cleared the day's quest gets the flame
     * and the run's length instead of the tick and its points — clearing the
     * quest is a different event from doing a chore, even though one row in
     * the database covers both.
     *
     * @param  Collection<int, int>  $kidIds
     * @return Collection<int, array<string, mixed>>
     */
    private function tickerChores(Collection $kidIds, Carbon $since, int $limit, Household $household): Collection
    {
        $clock = HouseholdClock::for($household);

        return ChoreCompletion::whereIn('profile_id', $kidIds)
            ->where('status', CompletionStatus::Approved)
            ->where('decided_at', '>=', $since)
            ->with(['profile', 'chore'])
            ->latest('decided_at')
            ->limit($limit)
            ->get()
            ->map(function (ChoreCompletion $done) use ($clock) {
                $day = $clock->dayFor($done->submitted_at);
                $quest = DailyQuest::where('profile_id', $done->profile_id)
                    ->whereDate('quest_date', $day)
                    ->first();

                $clearedQuest = $quest
                    && $quest->chore_id === $done->chore_id
                    && $quest->completed_at !== null;

                // The run as it stood on the day being described, not the one
                // standing now. A kid who cleared Monday and missed Tuesday
                // otherwise reads "cleared the day — 0 nights in a row" on
                // Wednesday, directly above their own 💀 row.
                $run = $clearedQuest ? $this->chores->runLengthOn($done->profile, $day) : 0;

                return $clearedQuest
                    ? [
                        'glyph' => '🔥',
                        'profile' => $done->profile,
                        'what' => 'cleared the day — '.$run.' '.Str::plural('night', $run).' in a row',
                        'value' => '',
                        'valueInk' => 'var(--fq-text-4)',
                        'at' => $done->decided_at,
                    ]
                    : [
                        'glyph' => '✓',
                        'profile' => $done->profile,
                        'what' => 'finished '.($done->chore?->name ?? 'a chore'),
                        'value' => '+'.number_format($done->points_awarded).' pts',
                        'valueInk' => 'var(--fq-lime)',
                        'at' => $done->decided_at,
                    ];
            });
    }

    /**
     * Nudges and rescues move no balance, so neither writes a ledger row. They
     * are built from their own tables rather than invented as ledger entries
     * for the sake of appearing here — a nudge that quietly failed to show up
     * is the whole reason the public stamp exists.
     *
     * @param  Collection<int, int>  $kidIds
     * @return Collection<int, array<string, mixed>>
     */
    private function tickerNudges(Collection $kidIds, Carbon $since, int $limit): Collection
    {
        return Nudge::whereIn('to_profile_id', $kidIds)
            ->where('created_at', '>=', $since)
            ->with(['from', 'to'])
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Nudge $nudge) => [
                'glyph' => '🔔',
                'profile' => $nudge->from,
                'what' => "nudged {$nudge->to->name}",
                'value' => '',
                'valueInk' => 'var(--fq-text-4)',
                'at' => $nudge->created_at,
            ]);
    }

    /**
     * @param  Collection<int, int>  $kidIds
     * @return Collection<int, array<string, mixed>>
     */
    private function tickerRescues(Collection $kidIds, Carbon $since, int $limit): Collection
    {
        return StreakRescue::whereIn('profile_id', $kidIds)
            ->where('created_at', '>=', $since)
            ->with(['profile', 'rescuedBy'])
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (StreakRescue $rescue) => [
                'glyph' => '♡',
                'profile' => $rescue->rescuedBy,
                // Never "saved a night for" — the night pays nothing and was
                // not earned, and the ticker is read by the kid it happened to.
                'what' => "kept {$rescue->profile->name}'s run alive",
                'value' => $rescue->tickets_paid.' tickets',
                'valueInk' => 'var(--fq-cyan)',
                'at' => $rescue->created_at,
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function tickerTrades(Household $household, Carbon $since, int $limit): Collection
    {
        return Bounty::where('household_id', $household->id)
            ->whereNotNull('claimed_at')
            ->where('claimed_at', '>=', $since)
            ->with(['claimant', 'poster'])
            ->latest('claimed_at')
            ->limit($limit)
            ->get()
            ->filter(fn (Bounty $bounty) => $bounty->claimant && $bounty->poster)
            ->map(fn (Bounty $bounty) => [
                'glyph' => '⇄',
                'profile' => $bounty->claimant,
                'what' => "took {$bounty->poster->name}'s job: {$bounty->description}",
                'value' => number_format($bounty->reward_amount).' pts',
                'valueInk' => 'var(--fq-cyan)',
                'at' => $bounty->claimed_at,
            ]);
    }

    /**
     * Runs that died at the last rollover.
     *
     * Stamped at the rollover itself rather than "now", because that is when
     * it happened — and it is why the whole house logs in to it in the
     * morning. Deliberately kept in the feed: a run ending is the loudest
     * thing that happens on this page and hiding it would make the streak
     * race consequence-free.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function tickerBrokenRuns(Household $household): Collection
    {
        $clock = HouseholdClock::for($household);

        return $this->tonightFor($household)
            ->where('state', self::STATE_BROKEN)
            ->map(fn (array $entry) => [
                'glyph' => '💀',
                'profile' => $entry['profile'],
                'what' => 'lost a run of '.$entry['brokenFrom'].' at rollover',
                'value' => '',
                'valueInk' => 'var(--fq-text-4)',
                'at' => $clock->startOf($clock->today()),
            ])
            ->values();
    }

    /**
     * The race: one lane per kid, positioned against the milestone ladder.
     *
     * @return array{flags: array<int, array{nights: int, reward: int, left: float}>, lanes: Collection<int, array<string, mixed>>}
     */
    public function raceFor(Household $household): array
    {
        $flags = $this->flags();

        return [
            'flags' => $flags,
            'lanes' => $this->tonightFor($household)->map(fn (array $entry) => [
                ...$entry,
                'position' => $this->positionFor($entry['streak'], $flags),
            ]),
        ];
    }

    /**
     * Where the milestone flags sit along the track, as percentages.
     *
     * Deliberately **not** linear on nights. The ladder is 3/5/7/14/30, so a
     * linear track would bunch the first four flags into its left tenth and
     * leave two thirds of the lane empty — which reads as "nobody is anywhere"
     * on precisely the streaks most kids actually hold.
     *
     * @return array<int, array{nights: int, reward: int, left: float}>
     */
    public function flags(): array
    {
        $spacing = [14.0, 30.0, 46.0, 68.0, 94.0];
        $flags = [];

        foreach (array_values(ChoreService::STREAK_BONUSES) as $i => $reward) {
            $flags[] = [
                'nights' => array_keys(ChoreService::STREAK_BONUSES)[$i],
                'reward' => $reward,
                'left' => $spacing[$i] ?? 94.0,
            ];
        }

        return $flags;
    }

    /**
     * A streak's place on the track, interpolating between the two flags it
     * sits between. Zero pins just off the start rather than to it, so a lane
     * with nothing in it still shows a token to move.
     *
     * @param  array<int, array{nights: int, reward: int, left: float}>  $flags
     */
    public function positionFor(int $streak, array $flags): float
    {
        if ($streak <= 0) {
            return 2.0;
        }

        $previousNights = 0;
        $previousLeft = 2.0;

        foreach ($flags as $flag) {
            // Checked before the interpolation, not folded into it: a streak
            // sitting exactly on a milestone belongs *on* the flag. Reaching
            // day 30 and finding the token parked past the last one — because
            // `30 < 30` is false and the loop ran out — is the single most
            // visible night of the ladder drawn wrong.
            if ($streak === $flag['nights']) {
                return $flag['left'];
            }

            if ($streak < $flag['nights']) {
                $span = $flag['nights'] - $previousNights;
                $through = $span > 0 ? ($streak - $previousNights) / $span : 0;

                return round($previousLeft + ($flag['left'] - $previousLeft) * $through, 2);
            }

            $previousNights = $flag['nights'];
            $previousLeft = $flag['left'];
        }

        // Past the last flag the track has run out, which is correct: the
        // ladder repeats every STREAK_CYCLE_DAYS and the lap is what advances,
        // not the position.
        return 100.0;
    }
}
