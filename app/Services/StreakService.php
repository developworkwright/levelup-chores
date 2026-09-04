<?php

namespace App\Services;

use App\Enums\CompletionStatus;
use App\Enums\LedgerKind;
use App\Models\ChoreCompletion;
use App\Models\Profile;
use App\Models\StreakRepair;
use App\Models\StreakRescue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The run: what earns a day, how long the chain is, and what the chests pay.
 *
 * This lived on {@see ChoreService} for as long as the streak was a property of
 * the daily quest — the justification being that it "only ever fires from the
 * quest flow". That stopped being true when any approved chore started earning
 * the day, and the code went with it: nothing in here reaches back into chores,
 * quests or the board. It needs the household clock, three tables and the
 * ledger, and that is all.
 *
 * The dependency runs one way — ChoreService calls this on approval, never the
 * reverse — so there is no cycle to be careful about.
 */
class StreakService
{
    /**
     * Streak-day milestone => dollar bonus, credited the moment a kid hits it.
     *
     * This is one lap. The chests repeat every {@see self::STREAK_CYCLE_DAYS}
     * days rather than stopping at the last one — a kid who reached day 30 used
     * to find the track had simply run out, which is the worst possible moment
     * to stop paying attention to them.
     */
    public const STREAK_BONUSES = [
        3 => 1,
        5 => 3,
        7 => 5,
        14 => 15,
        30 => 40,
    ];

    /** Length of one lap of the chest track — the last milestone in the map. */
    public const STREAK_CYCLE_DAYS = 30;

    /**
     * What every lap after the first pays, against the base map above.
     *
     * Flat rather than compounding, deliberately. Doubling per lap is the
     * obvious reading of "the chests get bigger each time round" and it is a
     * money bug: points are backed by `points_per_dollar`, so a year-long
     * streak would reach five figures on a single chest. One step up, held
     * there, keeps the day-33 "it got bigger" moment without the tail.
     */
    public const STREAK_REPEAT_MULTIPLIER = 2;

    /** Safety bound on the streak walk-back so odd data can't loop forever. */
    private const MAX_STREAK_DAYS = 366;

    public function __construct(private LedgerService $ledger) {}

    /**
     * Drops a cached streak that has quietly died.
     *
     * `profiles.streak` is a cache, and only an approval used to refresh it —
     * so a kid who skipped a day carried yesterday's number on their header
     * until they next got something signed off, and a repair bought at that
     * point stapled the old run onto the new one.
     *
     * This is the read-side half, and it's O(1) on purpose so it can run on
     * every page load: a live chain always ends on today or yesterday, so if
     * neither day counts there is nothing left to walk back through.
     *
     * The milestone high-water mark is deliberately left alone — it is what
     * stops a lapse-and-repair cycle from paying every bonus twice.
     */
    public function syncStreak(Profile $profile): void
    {
        if ($profile->streak === 0) {
            return;
        }

        $today = HouseholdClock::for($profile->household)->today();
        $yesterday = $today->copy()->subDay();

        // A two-day window, not the run's — this is the read-side check that
        // runs on every kid page load, and a live chain always ends on today or
        // yesterday, so nothing older can keep it alive.
        $earned = $this->earnedDaysBetween($profile, $yesterday, $today);

        if (isset($earned[$today->toDateString()]) || isset($earned[$yesterday->toDateString()])) {
            return;
        }

        $profile->streak = 0;
        $profile->save();
    }

    /**
     * The day a streak repair would actually buy back, or null when there's
     * nothing worth fixing — yesterday already counts, today is already in the
     * bag, or there was no live chain to save.
     */
    public function repairableStreakDate(Profile $profile): ?Carbon
    {
        // A restore is a rescue, not a top-up. Once today is secured the kid is
        // on a fresh one-day streak and the broken day sits behind it; buying it
        // back there would splice a finished run onto a new one and hand over
        // days that were never saved.
        if ($this->streakDaySecuredToday($profile)) {
            return null;
        }

        $yesterday = HouseholdClock::for($profile->household)->today()->subDay();
        $before = $yesterday->copy()->subDay();

        $earned = $this->earnedDaysBetween($profile, $before, $yesterday);

        if (isset($earned[$yesterday->toDateString()])) {
            return null;
        }

        // Only a break in a running chain is worth buying back; repairing a
        // day with nothing behind it just manufactures a one-day streak.
        return isset($earned[$before->toDateString()]) ? $yesterday : null;
    }

    /**
     * What a Streak Restore is worth right now: the day it buys back and the
     * streak the kid would be left holding, so the offer can say so before
     * they spend a perk on it.
     *
     * @return array{date: Carbon, restoresTo: int}|null
     */
    public function repairPreview(Profile $profile): ?array
    {
        $date = $this->repairableStreakDate($profile);

        if (! $date) {
            return null;
        }

        // The bought-back day, plus the unbroken run behind it. Today is not
        // secured — that's a precondition for offering this at all — so the
        // restored chain ends on the day being repaired.
        $behind = $date->copy()->subDay();

        $restoresTo = 1 + $this->walkBackFrom(
            $behind,
            $this->earnedDaysBetween($profile, $this->walkWindowFor($behind), $behind),
        );

        return ['date' => $date, 'restoresTo' => min($restoresTo, self::MAX_STREAK_DAYS)];
    }

    /** Buys back the missed day and recomputes. Null when there was nothing to repair. */
    public function repairStreak(Profile $profile): ?Carbon
    {
        $date = $this->repairableStreakDate($profile);

        if (! $date) {
            return null;
        }

        StreakRepair::create([
            'profile_id' => $profile->id,
            'repaired_date' => $date,
        ]);

        $this->refreshStreak($profile);

        return $date;
    }

    /**
     * Recomputes the streak and queues a chest for any milestone newly crossed.
     * Driven by approval, not by claiming, so a kid can't bank a bonus for
     * work a parent hasn't signed off on.
     *
     * Deliberately a recompute rather than an increment: a parent working
     * through several days of backlog can approve them in any order, and
     * every one of those approvals still has to land on the same number.
     *
     * Nothing is paid here. The bonus used to land in the ledger the moment the
     * milestone was reached, which meant a kid logging in the next morning saw
     * the points already in their balance and then opened a chest that gave
     * them nothing — the reveal was spoiled by the thing it was revealing. The
     * money is now spent by {@see openStreakChest()}.
     */
    private function refreshStreak(Profile $profile): void
    {
        $run = $this->currentRun($profile);
        $profile->streak = $run['length'];

        // A high-water mark of what has been *paid*, not of what has been
        // reached — and not the current streak either. Gating on the live value
        // would let a kid lapse a streak and buy a repair to collect every
        // milestone a second time.
        //
        // Scoped to the *run*, though, because a lifetime mark quietly turned
        // every chest into a once-ever prize: break a seven-night run, build it
        // back to seven, and days 3, 5 and 7 all pay nothing while the track
        // draws them as reached. A repair leaves the restored run starting on
        // the date it always did, so it keeps its mark and the lapse-and-repair
        // exploit stays shut.
        //
        // Both halves are frozen while a chest is sitting unopened. That chest
        // belongs to the run that just ended, and clearing the mark out from
        // under it would re-open every milestone beneath its lid to a second
        // payout — $9 for a chest worth $5. The recorded run has to be held
        // back with it: stamping the *new* run's start date while deferring the
        // reset would leave the two matching, and the reset would then never
        // fire at all. So they move together, on the first recompute after the
        // chest is collected.
        if ($profile->pending_streak_chest === null && $run['startsOn'] !== null && ! $run['truncated']) {
            if ($this->runIsANewOne($profile, $run)) {
                $profile->streak_milestone_paid_through = 0;
            }

            $profile->streak_milestone_run_started_on = $run['startsOn']->toDateString();
        }

        $paidThrough = $profile->streak_milestone_paid_through;

        $reached = null;

        // The ladder is climbed on *earned* nights only. A sibling's rescue
        // keeps the run standing — streakDayEarnedOn() says so — but buying
        // somebody a milestone is a different thing entirely, and the copy on
        // the rescue button promises it doesn't happen. A repair is deliberately
        // not subtracted here: it is bought by the kid whose run it is, out of
        // their own tickets, and has always counted.
        $ladder = $profile->streak - $this->rescuedNightsInRun($profile);

        // Walked day by day rather than over a fixed map, because the track
        // repeats and the milestone days are unbounded. Only the highest day
        // is kept: the chest carries everything behind it, and the walk from
        // the mark up to the lid is redone when it is opened.
        for ($day = $paidThrough + 1; $day <= $ladder; $day++) {
            if ($this->streakBonusOn($day) !== null) {
                $reached = $day;
            }
        }

        if ($reached !== null) {
            // Queued, not credited. The mark stays where it is until the lid
            // comes off, which is also what makes this idempotent: a second
            // recompute before the chest is opened re-queues the same day
            // rather than paying for it twice.
            $profile->pending_streak_chest = $reached;
        }

        $profile->save();
    }

    /**
     * How long the run was that ended on a given household day — the number
     * that was true *then*, not now.
     *
     * `profiles.streak` is only ever the current figure, so anything narrating
     * a past day has to ask for that day. Bounded like every other walk here.
     */
    public function runLengthOn(Profile $profile, Carbon $day): int
    {
        return $this->walkBackFrom(
            $day,
            $this->earnedDaysBetween($profile, $this->walkWindowFor($day), $day),
        );
    }

    /** Whether a sibling bought this night to keep the run alive. */
    public function wasRescuedOn(Profile $profile, Carbon $date): bool
    {
        return StreakRescue::where('profile_id', $profile->id)
            ->whereDate('rescued_date', $date)
            ->exists();
    }

    /**
     * Rescued nights inside the run currently standing.
     *
     * Subtracted from the milestone walk so a rescue keeps the run and not the
     * ladder. Walks the same days `currentRun()` does, for the same reason
     * it recomputes rather than increments: a parent clearing a backlog can
     * approve days in any order and every path has to land on one number.
     *
     * Public because it is the honest answer to "how far along the ladder am
     * I really" — a run of six with two rescues in it is four nights' worth of
     * progress, and anything showing a kid their position needs to be able to
     * say so rather than implying they bought their way up it.
     */
    public function rescuedNightsInRun(Profile $profile): int
    {
        $today = HouseholdClock::for($profile->household)->today();
        $from = $this->walkWindowFor($today);

        $earned = $this->earnedDaysBetween($profile, $from, $today);
        $rescued = $this->rescuedDaysBetween($profile, $from, $today);

        // Today being unearned doesn't end a run, it just anchors it on
        // yesterday — the same rule currentRun() walks by.
        // Copied, because Carbon is mutable and the loop below walks by
        // subtracting from this cursor in place.
        $cursor = $today->copy();

        if (! isset($earned[$cursor->toDateString()])) {
            $cursor = $cursor->subDay();
        }

        $nights = 0;

        for ($walked = 0; $walked < self::MAX_STREAK_DAYS; $walked++) {
            $day = $cursor->toDateString();

            if (! isset($earned[$day])) {
                break;
            }

            $nights += isset($rescued[$day]) ? 1 : 0;
            $cursor = $cursor->subDay();
        }

        return $nights;
    }

    /**
     * The run as it stands: how many consecutive household-days ending today
     * (or yesterday, if nothing is approved yet today) earned their day — see
     * streakDayEarnedOn() — and which day it began on.
     *
     * The start date is the only thing that can tell one run from the next. The
     * length can't: a kid who breaks a seven-night run and builds another one
     * is back on `streak = 7` with the same number on the same card, and the
     * milestone mark had no way of noticing it was looking at a different run.
     * See {@see refreshStreak()}.
     *
     * @return array{length: int, startsOn: ?Carbon, truncated: bool}
     */
    private function currentRun(Profile $profile): array
    {
        $today = HouseholdClock::for($profile->household)->today();
        $earned = $this->earnedDaysBetween($profile, $this->walkWindowFor($today), $today);

        // Today being unearned doesn't end a streak — it just means the chain
        // is still anchored on yesterday.
        $anchor = isset($earned[$today->toDateString()]) ? $today : $today->copy()->subDay();

        $length = $this->walkBackFrom($anchor, $earned);

        return [
            'length' => $length,
            'startsOn' => $length > 0 ? $anchor->copy()->subDays($length - 1) : null,
            // The walk is bounded, so a run sitting *on* the bound has a start
            // date that creeps forward a day at a time while the run itself has
            // not restarted at all. Left unflagged that reads as a new run every
            // single day, which would re-open the whole track once a day forever.
            'truncated' => $length >= self::MAX_STREAK_DAYS,
        ];
    }

    /**
     * Whether the run on the board is a different one from the run the
     * milestone high-water mark was paid to.
     *
     * Strictly *later*, not merely different: a parent clearing a backlog of
     * older days can extend a run backwards, which moves its start date earlier
     * without any new run having begun. Resetting on that would pay the whole
     * track a second time to the same run.
     *
     * @param  array{length: int, startsOn: ?Carbon, truncated: bool}  $run
     */
    private function runIsANewOne(Profile $profile, array $run): bool
    {
        $paidFor = $profile->streak_milestone_run_started_on;

        // No recorded run means the mark predates this column. Adopt the run
        // rather than reset it — the mark may well have been earned in the run
        // the kid is standing in, and inventing a second payout is the worse of
        // the two mistakes to make with money.
        if ($paidFor === null || $run['startsOn'] === null || $run['truncated']) {
            return false;
        }

        // Compared as `Y-m-d` strings rather than as instants: both sides are
        // household days, and the boundary hour the clock hangs on them is
        // exactly the sort of thing that makes two equal days compare unequal.
        return $run['startsOn']->toDateString() > $paidFor->toDateString();
    }

    /**
     * Whether a given household day counts toward the run.
     *
     * **Any approved chore earns the day** — the main quest has no special
     * standing here. Gating the run on the quest alone meant a kid could clear
     * six side quests and still watch their streak die overnight, which taught
     * exactly the wrong lesson about doing the work. The quest keeps its own
     * pull through the chest, the bold card and the charm; it no longer needs
     * to hold the streak hostage as well.
     *
     * Keyed on `submitted_at`, not `decided_at`: the day belongs to the kid who
     * did the work, not to the evening a parent got round to signing it off.
     *
     * Public for Household, which has to tell a run that is merely *young*
     * from one that died at the last rollover — the difference between a kid
     * on nothing and a kid who just lost nine nights, and the two must never
     * read the same on a screen the whole house is looking at.
     */
    public function streakDayEarnedOn(Profile $profile, Carbon $date): bool
    {
        return isset($this->earnedDaysBetween($profile, $date, $date)[$date->toDateString()]);
    }

    /**
     * Which household days in `[$from, $to]` this profile earned, as a set
     * keyed by `Y-m-d`.
     *
     * **This is the one place the rule lives.** `streakDayEarnedOn()` is a
     * one-day window onto it, and every walk-back asks for the whole run's
     * window at once — which is the point. Asking day by day meant three
     * queries per day walked: a single approval on a 60-day run cost 622, and
     * a parent clearing a backlog paid that for each chore. Windowed, any walk
     * is three queries flat however long the run is.
     *
     * Days are bucketed in PHP rather than SQL because the boundary belongs to
     * the household, not the database: `HouseholdClock::dayFor()` already knows
     * how a 4am rollover and a timezone combine, and no portable SQL between
     * SQLite (tests) and MySQL (production) does.
     *
     * Public so a caller asking about several adjacent days — Household wants
     * two of them per kid, on a page that draws every kid in the house — can
     * pay for one window instead of one per day.
     *
     * @return array<string, true>
     */
    public function earnedDaysBetween(Profile $profile, Carbon $from, Carbon $to): array
    {
        $clock = HouseholdClock::for($profile->household);
        $days = [];

        ChoreCompletion::where('profile_id', $profile->id)
            ->where('status', CompletionStatus::Approved)
            ->where('submitted_at', '>=', $clock->startOf($from))
            ->where('submitted_at', '<', $clock->startOf($to->copy()->addDay()))
            ->get(['submitted_at'])
            ->each(function (ChoreCompletion $completion) use ($clock, &$days) {
                $days[$clock->dayFor($completion->submitted_at)->toDateString()] = true;
            });

        // A repair and a sibling's rescue both answer the same question — does
        // this day count without the work being done — so they join the set
        // here rather than being asked separately by every caller. A rescue is
        // kept out of the *milestone ladder* instead, in refreshStreak(), since
        // answering "no" here would end the run a rescue was bought to save.
        foreach ($this->repairedDaysBetween($profile, $from, $to) as $day => $_) {
            $days[$day] = true;
        }

        foreach ($this->rescuedDaysBetween($profile, $from, $to) as $day => $_) {
            $days[$day] = true;
        }

        return $days;
    }

    /**
     * The date columns below are compared with `whereDate`, never a plain
     * `whereBetween` on the raw strings. Laravel writes a `date` column through
     * the model's datetime format, so the stored value carries a `00:00:00`
     * time — and `'2026-05-01 00:00:00' BETWEEN '2026-05-01' AND '2026-05-01'`
     * is false as a string comparison. `whereDate` normalises both sides on
     * SQLite and MySQL alike.
     *
     * @return array<string, true>
     */
    private function repairedDaysBetween(Profile $profile, Carbon $from, Carbon $to): array
    {
        return $this->datesAsSet(
            StreakRepair::where('profile_id', $profile->id)
                ->whereDate('repaired_date', '>=', $from->toDateString())
                ->whereDate('repaired_date', '<=', $to->toDateString())
                ->pluck('repaired_date'),
        );
    }

    /** @return array<string, true> */
    private function rescuedDaysBetween(Profile $profile, Carbon $from, Carbon $to): array
    {
        return $this->datesAsSet(
            StreakRescue::where('profile_id', $profile->id)
                ->whereDate('rescued_date', '>=', $from->toDateString())
                ->whereDate('rescued_date', '<=', $to->toDateString())
                ->pluck('rescued_date'),
        );
    }

    /**
     * @param  Collection<int, mixed>  $dates
     * @return array<string, true>
     */
    private function datesAsSet(Collection $dates): array
    {
        return $dates
            ->mapWithKeys(fn ($date) => [Carbon::parse((string) $date)->toDateString() => true])
            ->all();
    }

    /**
     * How many consecutive earned days run back from `$from`, inclusive.
     *
     * The one walk. There were five of these, all identical bar the variable
     * names, and the `MAX_STREAK_DAYS` bound had already been copied wrong once
     * (`HouseholdService` hardcoded 366 beside a constant that was also 366 — right
     * by luck rather than by reference).
     *
     * @param  array<string, true>  $earned
     */
    private function walkBackFrom(Carbon $from, array $earned): int
    {
        $cursor = $from->copy();
        $run = 0;

        while ($run < self::MAX_STREAK_DAYS && isset($earned[$cursor->toDateString()])) {
            $run++;
            $cursor = $cursor->subDay();
        }

        return $run;
    }

    /** The window any walk-back starting at `$from` could possibly reach. */
    private function walkWindowFor(Carbon $from): Carbon
    {
        return $from->copy()->subDays(self::MAX_STREAK_DAYS);
    }

    /**
     * Whether today's link in the chain is already safe.
     *
     * Deliberately more generous than {@see streakDayEarnedOn()}: work sitting
     * in the approvals queue counts. The kid has done their part, and a screen
     * that shows them at risk over a parent's response time is blaming them for
     * somebody else's inbox. This is the read Household and the repair window
     * want; the walk-back itself must keep using the strict one, or a pending
     * claim would prop up a run that was never signed off.
     */
    public function streakDaySecuredToday(Profile $profile): bool
    {
        $clock = HouseholdClock::for($profile->household);
        $today = $clock->today();

        if ($this->streakDayEarnedOn($profile, $today)) {
            return true;
        }

        return ChoreCompletion::where('profile_id', $profile->id)
            ->where('status', CompletionStatus::Pending)
            ->where('submitted_at', '>=', $clock->startOf($today))
            ->where('submitted_at', '<', $clock->startOf($today->copy()->addDay()))
            ->exists();
    }

    /**
     * Tonight's window: what the countdown counts to, when the run actually
     * dies, whether the day is already in the bag, and how close to the line
     * the house would call it.
     *
     * The kids could not tell when a day ended, so the pages that show a run
     * show the clock on it too. One method rather than four lookups per page,
     * because the answers have to agree — a strip reading "safe" over a running
     * countdown is worse than no countdown at all.
     *
     * **`closesAt` is bedtime, and `resetsAt` is the deadline.** They are
     * different times and the split is the whole point. Nothing expires at
     * bedtime; a chore signed off at 11pm still earns the day. But 4am is not a
     * time anybody is going to do a chore, so a countdown pointed at it reads
     * as "loads of time" all evening and then eats the run while everyone is
     * asleep. Bedtime is the time a kid can actually budget against, so that is
     * what ticks — the number on screen is the time they really have.
     *
     * **`closesAt` is null once bedtime passes, and that is the honest answer.**
     * The obvious move is to re-point the timer at `resetsAt`, and it is wrong
     * for the same reason 4am was wrong to begin with: a kid who has run out of
     * evening would be handed a fresh six-hour number, which is the "loads of
     * time" feeling this exists to remove, at the worst possible moment for it.
     * So the countdown stops and `overtime` takes over, which says in words
     * that the day is still winnable without putting a big reassuring number
     * next to it. A house with no bedtime set has nothing better to count, so
     * it gets `closesAt === resetsAt` and never goes overtime — exactly what
     * this did before bedtime existed.
     *
     * The times come back in UTC, like everything {@see HouseholdClock::startOf()}
     * returns, so they can be handed straight to a client-side timer.
     *
     * `secured` is the generous read ({@see streakDaySecuredToday()}): work
     * waiting on a parent stops the clock, because it stopped being the kid's
     * clock to beat the moment they claimed it. `urgent` is the house's
     * evening watch hour — the same threshold Household lights its candle on,
     * so the two screens never disagree about who is on the line.
     *
     * @return array{closesAt: ?Carbon, resetsAt: Carbon, bedtime: ?Carbon, secured: bool, urgent: bool, overtime: bool}
     */
    public function streakWindowFor(Profile $profile): array
    {
        $clock = HouseholdClock::for($profile->household);
        $now = $clock->now()->utc();
        $secured = $this->streakDaySecuredToday($profile);

        $resetsAt = $clock->startOf($clock->today()->copy()->addDay());
        $bedtime = $clock->bedtime();
        $overtime = $bedtime !== null && $now->greaterThanOrEqualTo($bedtime);
        $watch = $clock->eveningWatch();

        return [
            // Null rather than the rollover once bedtime has gone: see above.
            'closesAt' => match (true) {
                $bedtime === null => $resetsAt,
                $overtime => null,
                default => $bedtime,
            },
            'resetsAt' => $resetsAt,
            // Handed back so the copy can name the hour even in the states that
            // aren't counting towards it.
            'bedtime' => $bedtime,
            'secured' => $secured,
            // A household with no usable watch hour never turns the strip red,
            // the same way it never lights Household's candle.
            'urgent' => ! $secured && $watch !== null && $now->greaterThanOrEqualTo($watch),
            'overtime' => ! $secured && $overtime,
        ];
    }

    /**
     * Tells the run that a chore was signed off.
     *
     * The single entry point for the approval path — `ChoreService::approve()`
     * hands every approval here and this decides whether the run actually
     * changed, rather than the caller having to know the rule.
     */
    public function recordApproval(ChoreCompletion $completion, Profile $profile): void
    {
        if ($this->approvalCouldMoveTheRun($completion, $profile)) {
            $this->refreshStreak($profile);
        }
    }

    /**
     * Whether this approval could actually have moved the run.
     *
     * `refreshStreak()` is a day-by-day walk — several hundred queries on a
     * long run, twice over, since `rescuedNightsInRun()` walks it again — and
     * it fires from every approval now rather than once on the quest's. When
     * the completion's day was *already* earned without it, the walk is
     * guaranteed to land on the number already stored, so it can be skipped
     * outright. A parent working through a morning's backlog then pays for the
     * first chore of each day and nothing for the rest of them.
     *
     * Safe because the first approval of any given day always recomputes, and
     * nothing else raises a streak: `syncStreak()` only ever drops one. So a
     * day that reads as already-earned has necessarily been through a
     * recompute since the last thing that could have changed it.
     *
     * Cheapest and likeliest check first, so the common case short-circuits
     * before the two rescue lookups.
     */
    private function approvalCouldMoveTheRun(ChoreCompletion $completion, Profile $profile): bool
    {
        $clock = HouseholdClock::for($profile->household);
        $day = $clock->dayFor($completion->submitted_at);

        // This completion is Approved by the time we get here, so it has to be
        // excluded by id rather than by status.
        $earnedByAnother = ChoreCompletion::where('profile_id', $profile->id)
            ->where('id', '!=', $completion->id)
            ->where('status', CompletionStatus::Approved)
            ->where('submitted_at', '>=', $clock->startOf($day))
            ->where('submitted_at', '<', $clock->startOf($day->copy()->addDay()))
            ->exists();

        if ($earnedByAnother) {
            return false;
        }

        return ! StreakRepair::where('profile_id', $profile->id)->whereDate('repaired_date', $day)->exists()
            && ! $this->wasRescuedOn($profile, $day);
    }

    /**
     * Opens the pending streak-milestone chest: credits what it is holding and
     * returns what to show for it.
     *
     * **This is where a milestone bonus is paid.** It used to be credited the
     * moment the milestone was reached, so a kid came back the next day to a
     * balance that already included a chest they hadn't opened. The points now
     * land on the tap, which is the only moment the reveal is telling the truth.
     *
     * @return array{day: int, dollars: int}|null
     */
    public function openStreakChest(Profile $profile): ?array
    {
        $day = $profile->pending_streak_chest;

        if ($day === null) {
            return null;
        }

        $dollars = $this->pendingStreakChestDollars($profile);

        foreach ($this->unpaidMilestonesUnder($profile) as $milestone => $bonusDollars) {
            $this->ledger->record(
                $profile->household,
                $profile,
                LedgerKind::Earn,
                $bonusDollars * $profile->household->points_per_dollar,
                "{$profile->name} — {$milestone}-day streak bonus (\${$bonusDollars})",
            );
        }

        $profile->pending_streak_chest = null;
        // Only ever forwards. A chest queued before the payout moved here has
        // a mark already sitting on its own day, and clearing it back would
        // re-open every milestone under it to a second payout.
        $profile->streak_milestone_paid_through = max($profile->streak_milestone_paid_through, $day);
        $profile->save();

        return ['day' => $day, 'dollars' => $dollars];
    }

    /**
     * What the pending streak chest is holding, in dollars — 0 when there
     * isn't one.
     *
     * Everything crossed since the last chest that was paid for, not just the
     * day on the lid: a parent clearing a week of backlog in one sitting can
     * take a kid past two or three milestones before any of them is opened,
     * and only the highest is kept as the chest itself.
     */
    public function pendingStreakChestDollars(Profile $profile): int
    {
        $day = $profile->pending_streak_chest;

        if ($day === null) {
            return 0;
        }

        $unpaid = array_sum($this->unpaidMilestonesUnder($profile));

        // A chest queued before the payout moved to the reveal was credited
        // when it was reached, so its mark is already on its own day and there
        // is nothing left owed — but the card still has to name the milestone
        // it is celebrating.
        return $unpaid > 0 ? $unpaid : ($this->streakBonusOn($day) ?? 0);
    }

    /**
     * The milestones sitting under the pending chest that have not been paid
     * for, as day => dollars.
     *
     * @return array<int, int>
     */
    private function unpaidMilestonesUnder(Profile $profile): array
    {
        $day = $profile->pending_streak_chest;

        if ($day === null) {
            return [];
        }

        $milestones = [];

        for ($milestone = $profile->streak_milestone_paid_through + 1; $milestone <= $day; $milestone++) {
            $dollars = $this->streakBonusOn($milestone);

            if ($dollars !== null) {
                $milestones[$milestone] = $dollars;
            }
        }

        return $milestones;
    }

    /**
     * The dollar bonus paid for reaching exactly this streak day, or null on a
     * day that isn't a milestone.
     *
     * Days are absolute across every lap — day 33 is the second lap's first
     * chest — which is what lets `streak_milestone_paid_through` stay a plain
     * high-water mark and keep doing its job unchanged.
     */
    public function streakBonusOn(int $day): ?int
    {
        if ($day < 1) {
            return null;
        }

        // Day 30 closes the first lap rather than opening the second, so a day
        // landing exactly on the boundary belongs to the lap behind it.
        $offset = $day % self::STREAK_CYCLE_DAYS;
        $lap = $offset === 0
            ? intdiv($day, self::STREAK_CYCLE_DAYS)
            : intdiv($day, self::STREAK_CYCLE_DAYS) + 1;

        $offset = $offset === 0 ? self::STREAK_CYCLE_DAYS : $offset;

        $base = self::STREAK_BONUSES[$offset] ?? null;

        if ($base === null) {
            return null;
        }

        return $lap === 1 ? $base : $base * self::STREAK_REPEAT_MULTIPLIER;
    }

    /**
     * Smallest streak-bonus milestone day still ahead of the profile's current
     * streak. Never null now that the track repeats — there is always another
     * chest inside the next lap.
     */
    public function nextStreakMilestone(Profile $profile): int
    {
        $streak = max(0, $profile->streak);

        for ($day = $streak + 1; $day <= $streak + self::STREAK_CYCLE_DAYS; $day++) {
            if ($this->streakBonusOn($day) !== null) {
                return $day;
            }
        }

        // Unreachable while the base map has any entry in it, but a silent
        // wrong answer here would show up as a nonsense number on the track.
        throw new LogicException('No streak milestone found within a full cycle.');
    }

    /**
     * The lap of the chest track this kid is currently working through, and the
     * five milestones on it.
     *
     * Only the current lap is ever shown. The track is otherwise endless, and a
     * rail of fifteen chests says far less about "keep going" than five chests
     * with the next one lit.
     *
     * The lap turns over when the *chest* is opened rather than when the streak
     * ticks past the boundary: hitting day 30 and finding the track already
     * showing days 33-60 would swap the reward out from under the moment that
     * earned it.
     *
     * @return array{lap: int, milestones: array<int, array{day: int, dollars: int, points: int, reached: bool}>}
     */
    public function streakTrackFor(Profile $profile): array
    {
        $streak = max(0, $profile->streak);
        $lap = intdiv($streak, self::STREAK_CYCLE_DAYS) + 1;

        $closingDay = ($lap - 1) * self::STREAK_CYCLE_DAYS;

        if ($lap > 1 && $profile->pending_streak_chest === $closingDay) {
            $lap--;
        }

        $pointsPerDollar = $profile->household->points_per_dollar;
        $offsetToDay = ($lap - 1) * self::STREAK_CYCLE_DAYS;

        $milestones = [];

        foreach (array_keys(self::STREAK_BONUSES) as $offset) {
            $day = $offsetToDay + $offset;
            $dollars = (int) $this->streakBonusOn($day);

            $milestones[] = [
                'day' => $day,
                'dollars' => $dollars,
                'points' => $dollars * $pointsPerDollar,
                'reached' => $streak >= $day,
            ];
        }

        return ['lap' => $lap, 'milestones' => $milestones];
    }
}
