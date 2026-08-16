<?php

namespace App\Services;

use App\Enums\Constellation;
use App\Enums\LedgerKind;
use App\Enums\SleepOutcome;
use App\Enums\TicketKind;
use App\Models\Household;
use App\Models\Profile;
use App\Models\SleepNight;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The own-bed card: a morning check-in for a kid who is working on sleeping in
 * their own bed.
 *
 * ## Nothing here punishes a bad night
 *
 * Three answers, all of them answers. A night that isn't own-bed simply doesn't
 * light a star — no points are clawed back, no picture is lost, the total never
 * moves down. The only thing a miss costs is the *run*, and that exists purely
 * so there is something to protect: a number that can't break gives the Night
 * Saver nothing to save and makes the best-ever meaningless.
 *
 * ## Three numbers, three jobs
 *
 * - `sleep_nights` only ever rises, and is what both rewards are paid on. That
 *   is the whole reason a bad night is free: it delays a constellation, it
 *   never undoes one.
 * - `sleep_run` is consecutive nights and does reset.
 * - `sleep_best_run` is the high-water mark, kept forever.
 *
 * ## Two payouts, two currencies
 *
 * Constellations pay **points** — seven nights is a real piece of work and
 * points are the currency that means something outside the app. Run milestones
 * pay **tickets**, which buy the Night Saver that protects the next run. That
 * loop is deliberate: the reward for a good streak is the means to survive a
 * bad night without losing it.
 *
 * Both are gated on high-water marks, because a parent can nudge these counters
 * by hand from the console and without the marks, nudging one down and back up
 * would pay the same milestone twice — the same exploit
 * `streak_milestone_paid_through` exists to close.
 */
class SleepService
{
    /**
     * What a constellation pays when a household has never said otherwise, in
     * dollars at that household's own rate. Only a starting point — the live
     * figure is `households.sleep_constellation_points`, which a parent tapers
     * from the console as the habit settles.
     */
    public const CONSTELLATION_DOLLARS = 5;

    /**
     * The step the console's ± buttons move the payouts by. The nightly rates
     * get the finer one — they are smaller numbers and land every day, so an
     * adjustment there is felt seven times a week.
     *
     * What a night itself pays lives on {@see SleepOutcome::defaultDollars()},
     * one rate per answer, each tapered independently from the console.
     */
    public const PAYOUT_STEP = 50;

    public const NIGHT_STEP = 25;

    /**
     * Run length → tickets. Escalating, and paid once each per run-length
     * reached rather than per run — see `sleep_run_paid_through`.
     */
    public const RUN_MILESTONES = [3 => 1, 7 => 2, 14 => 3, 30 => 5, 60 => 8];

    public function __construct(
        private LedgerService $ledger,
        private TicketService $tickets,
        private BadgeService $badges,
    ) {}

    /**
     * Whether this kid should be asked at all. Both switches have to be on:
     * the household's, and their own. Age is not a gate — a parent decides who
     * needs this, which is the only judgement that actually knows.
     */
    public function isEnabledFor(Profile $profile): bool
    {
        return $profile->isKid()
            && $profile->sleep_card_enabled
            && $profile->household->sleep_card_enabled;
    }

    /** The night the card is asking about: the one that ended this morning. */
    public function tonightsDate(Household $household): Carbon
    {
        return HouseholdClock::for($household)->today();
    }

    public function answerFor(Profile $profile, ?Carbon $date = null): ?SleepNight
    {
        $date ??= $this->tonightsDate($profile->household);

        return SleepNight::where('profile_id', $profile->id)
            ->whereDate('night_date', $date)
            ->first();
    }

    /**
     * Everything the card draws, or null when this kid isn't being asked.
     *
     * @return array{answered: ?SleepNight, nights: int, run: int, bestRun: int,
     *               completed: int, starsLit: int, drawing: Constellation,
     *               nextMilestone: ?int, previousMilestone: int, pendingChest: ?int,
     *               runPaidThrough: int,
     *               prizes: array{night: int, nights: array<string, int>, constellation: int, toGo: int},
     *               pointsPerDollar: int, earned: array<int, Constellation>}|null
     */
    public function cardFor(Profile $profile): ?array
    {
        if (! $this->isEnabledFor($profile)) {
            return null;
        }

        $nights = (int) $profile->sleep_nights;

        return [
            'answered' => $this->answerFor($profile),
            'nights' => $nights,
            'run' => (int) $profile->sleep_run,
            'bestRun' => (int) $profile->sleep_best_run,
            'completed' => Constellation::completedFrom($nights),
            'starsLit' => Constellation::starsInProgress($nights),
            // The picture currently being drawn — the next one up, not the last
            // one finished, so a kid mid-week sees where tonight's star lands.
            'drawing' => Constellation::number(Constellation::completedFrom($nights) + 1),
            'nextMilestone' => $this->nextRunMilestone(
                (int) $profile->sleep_run,
                (int) $profile->sleep_run_paid_through,
            ),
            // The leg the chest rail draws: from the last milestone banked to
            // the next one, rather than from zero. A kid on night eight is
            // one-seventh of the way to fourteen, not eight-fourteenths.
            'previousMilestone' => $this->previousRunMilestone((int) $profile->sleep_run),
            'pendingChest' => $profile->pending_sleep_chest,
            // Whether a chest has ever been opened. The card draws a chest only
            // when one is actually waiting, so this decides nothing about the
            // artwork — it picks the word: "First chest at 3 in a row" for a
            // kid who has never had one, "Next chest at 3" for everyone else.
            'runPaidThrough' => (int) $profile->sleep_run_paid_through,
            'prizes' => $this->prizesFor($profile->household, $nights),
            'pointsPerDollar' => (int) $profile->household->points_per_dollar,
            // Every picture already finished, for the shelf on the card and the
            // sky behind it — the collection was the reward and until now there
            // was nowhere to see it.
            'earned' => $this->earnedConstellations($profile),
        ];
    }

    /**
     * Answer last night. Returns what to celebrate, if anything.
     *
     * Idempotent by the unique index on (profile_id, night_date) and by the
     * check below: the card is on the page a kid lands on, and answering twice
     * because they tapped twice must not light two stars.
     *
     * @return array{outcome: SleepOutcome, constellation: ?Constellation,
     *               nightPoints: int, constellationPoints: int, chest: ?int}
     */
    public function record(Profile $profile, SleepOutcome $outcome): array
    {
        if (! $this->isEnabledFor($profile)) {
            throw new RuntimeException('The own-bed card is not switched on for this kid.');
        }

        $household = $profile->household;
        $date = $this->tonightsDate($household);

        if ($this->answerFor($profile, $date)) {
            throw new RuntimeException('Last night is already answered.');
        }

        $earned = DB::transaction(function () use ($profile, $household, $outcome, $date) {
            SleepNight::create([
                'household_id' => $household->id,
                'profile_id' => $profile->id,
                'night_date' => $date,
                'outcome' => $outcome,
            ]);

            // Every answer is paid at its own rate, including the ones that
            // don't light a star — a cuddle at 3am is not nothing. Paid per
            // night rather than per counter, which is what makes it safe
            // without a high-water mark: the unique (profile, night_date) row
            // above is the guard, so a parent nudging `sleep_nights` later
            // moves the picture along without paying for a night nobody slept.
            $nightPoints = $this->pointsFor($household, $outcome);

            if ($nightPoints > 0) {
                $this->ledger->record(
                    $household,
                    $profile,
                    LedgerKind::Earn,
                    $nightPoints,
                    "{$profile->name} — {$outcome->shortLabel()}",
                );
            }

            if (! $outcome->countsAsOwnBed()) {
                // The whole of the "no punishment" rule, in one line: the run
                // stops, and not a single other number moves. The night still
                // paid; what it doesn't do is advance anything.
                $profile->sleep_run = 0;
                $profile->save();

                return ['constellation' => null, 'nightPoints' => $nightPoints, 'chest' => null];
            }

            $profile->sleep_nights++;
            $profile->sleep_run++;
            $profile->sleep_best_run = max((int) $profile->sleep_best_run, (int) $profile->sleep_run);
            $profile->save();

            return [
                'constellation' => $this->payConstellations($profile, $household),
                'nightPoints' => $nightPoints,
                'chest' => $this->payRunMilestones($profile),
            ];
        });

        // A constellation pays points, and `big_saver` is balance-based.
        $this->badges->evaluate($profile->refresh());

        return [
            'outcome' => $outcome,
            'constellation' => $earned['constellation'],
            'nightPoints' => $earned['nightPoints'],
            'constellationPoints' => $earned['constellation'] ? $this->constellationPoints($household) : 0,
            'chest' => $earned['chest'],
        ];
    }

    /**
     * Buy back the run after a missed night — the Night Saver perk.
     *
     * Restores the run to what it would have been had last night counted, which
     * is the run before it broke plus one. The night's own answer is left
     * alone: the log stays honest about what happened, and `saved_at` records
     * that it was bought rather than slept.
     *
     * Deliberately does not add to `sleep_nights` — the constellation is paid
     * in points, and a perk must not be a way to buy those.
     */
    public function saveNight(Profile $profile): bool
    {
        $night = $this->repairableNight($profile);

        if (! $night) {
            return false;
        }

        $priorRun = $this->runBefore($profile, $night->night_date);

        $night->saved_at = now();
        $night->save();

        $profile->sleep_run = $priorRun + 1;
        $profile->sleep_best_run = max((int) $profile->sleep_best_run, (int) $profile->sleep_run);
        $profile->save();

        return true;
    }

    /**
     * The night a saver would buy back: the most recent answered night that
     * didn't count, and only while it is still the thing standing between the
     * kid and their run. Once a fresh run is going there is nothing to rescue —
     * buying then would splice a finished run onto a new one, which is the
     * exploit the streak restore already guards against.
     */
    public function repairableNight(Profile $profile): ?SleepNight
    {
        $latest = SleepNight::where('profile_id', $profile->id)
            ->orderByDesc('night_date')
            ->first();

        if (! $latest || $latest->outcome->countsAsOwnBed() || $latest->wasSaved()) {
            return null;
        }

        return $latest;
    }

    public function saveReason(Profile $profile): ?string
    {
        if (! $this->isEnabledFor($profile)) {
            return 'The own-bed card is not switched on for you.';
        }

        return $this->repairableNight($profile)
            ? null
            : 'Nothing to save — your last answered night already counted.';
    }

    /** How many nights in a row led up to (but not including) a given night. */
    private function runBefore(Profile $profile, Carbon $date): int
    {
        $run = 0;
        $cursor = $date->copy()->subDay();

        while (true) {
            $night = SleepNight::where('profile_id', $profile->id)
                ->whereDate('night_date', $cursor)
                ->first();

            if (! $night || ! ($night->outcome->countsAsOwnBed() || $night->wasSaved())) {
                return $run;
            }

            $run++;
            $cursor->subDay();
        }
    }

    /**
     * Pay for any constellation finished but not yet paid for. Returns the last
     * one completed, for the celebration.
     */
    private function payConstellations(Profile $profile, Household $household): ?Constellation
    {
        $completed = Constellation::completedFrom((int) $profile->sleep_nights);
        $paid = (int) $profile->sleep_constellations_paid;

        if ($completed <= $paid) {
            return null;
        }

        // Advance the mark first, so a failure partway can't pay twice.
        $profile->sleep_constellations_paid = $completed;
        $profile->save();

        $points = $this->constellationPoints($household);

        for ($number = $paid + 1; $number <= $completed; $number++) {
            // A fully tapered household pays nothing, and a zero-amount ledger
            // row would be noise in the feed rather than a record of anything.
            // The picture still completes — that was always the other half of
            // the reward.
            if ($points === 0) {
                continue;
            }

            $this->ledger->record(
                $household,
                $profile,
                LedgerKind::Earn,
                $points,
                "{$profile->name} finished ".Constellation::number($number)->label().' — '.Constellation::NIGHTS.' nights in their own bed',
            );
        }

        return Constellation::number($completed);
    }

    /**
     * Bank the tickets for every run milestone newly reached, and queue the
     * chest that reveals them.
     *
     * Walks the whole table rather than testing the current number against it,
     * because the run does not only ever climb by one: a parent correcting the
     * record can take it from nothing to thirty in a tap, and a kid who did
     * thirty nights is owed all four milestones on the way. Answering a night
     * still crosses at most one, so the walk costs nothing there.
     */
    private function payRunMilestones(Profile $profile): ?int
    {
        $run = (int) $profile->sleep_run;
        $paid = (int) $profile->sleep_run_paid_through;

        $owed = array_filter(
            self::RUN_MILESTONES,
            fn (int $nights) => $nights > $paid && $nights <= $run,
            ARRAY_FILTER_USE_KEY,
        );

        if ($owed === []) {
            return null;
        }

        $reached = array_key_last($owed);

        // The mark goes to the highest reached, not the highest that exists —
        // and only ever upward, so a parent nudging back down can't re-pay.
        $profile->sleep_run_paid_through = $reached;
        // The chest announces the biggest one, which is the news.
        $profile->pending_sleep_chest = $reached;
        $profile->save();

        $this->tickets->record(
            $profile,
            TicketKind::Sleep,
            array_sum($owed),
            "{$reached} nights in a row in their own bed",
        );

        return $reached;
    }

    /**
     * Clear the pending chest and say what was in it. Like the streak chest,
     * this is a reveal gate and not a payment gate — the tickets landed when
     * the milestone did, and a kid who never opens it keeps them regardless.
     *
     * @return array{nights: int, tickets: int}|null
     */
    public function openChest(Profile $profile): ?array
    {
        $run = $profile->pending_sleep_chest;

        if ($run === null) {
            return null;
        }

        $profile->pending_sleep_chest = null;
        $profile->save();

        return ['nights' => $run, 'tickets' => self::RUN_MILESTONES[$run] ?? 0];
    }

    /**
     * The pictures this kid has finished, oldest first.
     *
     * @return array<int, Constellation>
     */
    public function earnedConstellations(Profile $profile): array
    {
        $completed = Constellation::completedFrom((int) $profile->sleep_nights);

        // Guarded rather than clamped: range(1, 0) counts *down* in PHP and
        // would hand back a picture nobody has finished.
        if ($completed < 1) {
            return [];
        }

        return array_map(
            fn (int $number) => Constellation::number($number),
            range(1, $completed),
        );
    }

    /**
     * The next milestone that will actually pay, or null past the last one.
     *
     * Both the run *and* the paid mark have to be cleared, and forgetting the
     * second was a card that lied. {@see self::payRunMilestones()} only pays a
     * milestone above `sleep_run_paid_through`, so after a run breaks the ones
     * already banked can never pay again — a kid who reached seven and then
     * missed a night was being promised a chest at three, then at seven, and
     * got neither. Nothing arrives until the run passes the highest already
     * paid, and that is what this has to name.
     */
    public function nextRunMilestone(int $run, int $paidThrough = 0): ?int
    {
        $cleared = max($run, $paidThrough);

        foreach (array_keys(self::RUN_MILESTONES) as $milestone) {
            if ($milestone > $cleared) {
                return $milestone;
            }
        }

        return null;
    }

    /**
     * The milestone most recently passed, or zero at the start. The floor of
     * the leg the chest rail measures progress across.
     */
    public function previousRunMilestone(int $run): int
    {
        $previous = 0;

        foreach (array_keys(self::RUN_MILESTONES) as $milestone) {
            if ($milestone <= $run) {
                $previous = $milestone;
            }
        }

        return $previous;
    }

    /**
     * What a constellation pays right now.
     *
     * Null-coalesced rather than trusted: a household row that has just been
     * inserted doesn't carry column defaults the database filled in, so an
     * unrefreshed model reads this as null — the same trap the boss battle's
     * counter fell into. Zero is a real answer and survives the coalesce, so a
     * fully tapered household pays nothing without the default creeping back.
     */
    public function constellationPoints(Household $household): int
    {
        return (int) ($household->sleep_constellation_points
            ?? self::CONSTELLATION_DOLLARS * (int) $household->points_per_dollar);
    }

    /**
     * Set what a constellation pays from here on.
     *
     * Deliberately changes nothing already paid: past constellations are
     * ledger history, and `sleep_constellations_paid` means lowering this
     * can't claw back or re-pay anything. Tapering only ever touches the next
     * picture.
     */
    public function setConstellationPoints(Household $household, int $points): void
    {
        $household->update(['sleep_constellation_points' => max(0, $points)]);
    }

    /**
     * What one night pays right now. Null-coalesced for the same reason
     * {@see self::constellationPoints()} is.
     */
    /**
     * What answering this way pays right now. Null-coalesced for the same
     * reason {@see self::constellationPoints()} is.
     */
    public function pointsFor(Household $household, SleepOutcome $outcome): int
    {
        return (int) ($household->{$outcome->pointsColumn()}
            ?? $outcome->defaultDollars() * (int) $household->points_per_dollar);
    }

    public function setPointsFor(Household $household, SleepOutcome $outcome, int $points): void
    {
        $household->update([$outcome->pointsColumn() => max(0, $points)]);
    }

    /**
     * The whole prize on offer, for the card to say out loud.
     *
     * The card used to mention none of this: a kid saw a dot appear and the
     * money landed silently a week later. A reward nobody knows about isn't
     * one.
     *
     * `nights` is keyed by outcome value, so the card can price each answer
     * button without knowing which outcomes exist.
     *
     * @return array{night: int, nights: array<string, int>, constellation: int, toGo: int}
     */
    public function prizesFor(Household $household, int $nights): array
    {
        $perOutcome = [];

        foreach (SleepOutcome::cases() as $outcome) {
            $perOutcome[$outcome->value] = $this->pointsFor($household, $outcome);
        }

        return [
            // The headline rate, which is the one the card leads with.
            'night' => $perOutcome[SleepOutcome::OwnBed->value],
            'nights' => $perOutcome,
            'constellation' => $this->constellationPoints($household),
            'toGo' => Constellation::NIGHTS - Constellation::starsInProgress($nights),
        ];
    }

    /**
     * A parent correcting the numbers from the console — the card is answered
     * by a five-year-old, and sometimes the answer is wrong.
     *
     * Never lowers the paid marks, so correcting a number downward and back up
     * cannot re-pay a constellation that has already been paid for.
     */
    public function adjust(Profile $profile, ?int $nights = null, ?int $run = null): void
    {
        if ($nights !== null) {
            $profile->sleep_nights = max(0, $nights);
        }

        if ($run !== null) {
            $profile->sleep_run = max(0, $run);
            $profile->sleep_best_run = max((int) $profile->sleep_best_run, (int) $profile->sleep_run);
        }

        $profile->save();

        // A correction settles up, and this is the whole reason the payouts are
        // high-water marked rather than event-driven. A parent typing in the
        // seven nights a kid actually did — because they were away, or the card
        // was switched on late, or a five-year-old forgot to tap — was
        // previously moving a number and quietly withholding the chest and the
        // picture the kid had earned.
        //
        // Safe to run on every adjustment: both marks only ever climb, so
        // nudging down and back up pays nothing a second time.
        $this->payConstellations($profile, $profile->household);
        $this->payRunMilestones($profile);

        $this->badges->evaluate($profile->refresh());
    }
}
