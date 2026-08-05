<?php

namespace App\Services;

use App\Enums\CompletionStatus;
use App\Enums\LedgerKind;
use App\Enums\ProfileRole;
use App\Enums\SiblingOfferStatus;
use App\Models\Badge;
use App\Models\ChoreCompletion;
use App\Models\DailyChest;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\OwnedPerk;
use App\Models\Profile;
use App\Models\Redemption;
use App\Models\SiblingOffer;
use App\Models\Spin;
use App\Models\StreakRepair;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BadgeService
{
    /**
     * Approved chores, all time.
     *
     * @var array<string, int>
     */
    private const CHORE_MILESTONES = [
        'chores_10' => 10,
        'chores_50' => 50,
        'chores_100' => 100,
        'chores_365' => 365,
    ];

    /**
     * Consecutive days the daily quest has been cleared.
     *
     * @var array<string, int>
     */
    private const STREAK_MILESTONES = [
        'streak_3' => 3,
        'streak_7' => 7,
        'streak_14' => 14,
        'streak_30' => 30,
    ];

    /**
     * Daily quests cleared, all time.
     *
     * @var array<string, int>
     */
    private const QUEST_MILESTONES = [
        'quest_10' => 10,
        'quest_50' => 50,
    ];

    /**
     * Points earned all time. Reads the Earn ledger only, so cash turned in at
     * the kitchen table and parent top-ups don't buy a badge that's meant to
     * say "I worked for this".
     *
     * @var array<string, int>
     */
    private const EARNED_MILESTONES = [
        'earner_1000' => 1000,
        'earner_5000' => 5000,
        'earner_20000' => 20000,
    ];

    /**
     * Level reached.
     *
     * @var array<string, int>
     */
    private const LEVEL_MILESTONES = [
        'level_10' => 10,
        'level_25' => 25,
    ];

    /**
     * Bonus Wheel spins taken.
     *
     * @var array<string, int>
     */
    private const SPIN_MILESTONES = [
        'spin_25' => 25,
    ];

    /**
     * Daily bonus chests opened.
     *
     * @var array<string, int>
     */
    private const CHEST_MILESTONES = [
        'chest_7' => 7,
        'chest_30' => 30,
    ];

    /**
     * Sibling trades that actually settled.
     *
     * @var array<string, int>
     */
    private const TRADE_MILESTONES = [
        'dealmaker' => 1,
        'trade_10' => 10,
    ];

    /**
     * Loot Shop rewards claimed.
     *
     * @var array<string, int>
     */
    private const REDEMPTION_MILESTONES = [
        'first_reward' => 1,
    ];

    /** Cumulative points spent in the Loot Shop to earn "Big Spender". */
    private const BIG_SPENDER_THRESHOLD = 1000;

    /** Balance held at once to earn "Big Saver". */
    private const BIG_SAVER_THRESHOLD = 500;

    /** More than this many chores approved in one household day earns "Busy Bee". */
    private const BUSY_BEE_THRESHOLD = 3;

    /** Chores approved in one household day to earn "Overachiever". */
    private const OVERACHIEVER_THRESHOLD = 8;

    /** Household-local hour before which a claim counts as "Early Bird". */
    private const EARLY_BIRD_HOUR = 7;

    /** Household-local hour at/after which a claim counts as "Night Owl". */
    private const NIGHT_OWL_HOUR = 22;

    /** How quickly the main quest must be claimed after reveal to earn "Speed Runner". */
    private const SPEED_RUNNER_SECONDS = 300;

    /** 3x spins landed to earn "Triple Threat". */
    private const TRIPLE_THREAT_SPINS = 3;

    /** Cost of a single reward that earns "Big Ticket". */
    private const BIG_TICKET_COST = 500;

    /** Perks spent out of the pocket to earn "Gadgeteer". */
    private const GADGETEER_PERKS = 5;

    /** Below this many chores on the board, "All-Rounder" isn't worth winning. */
    private const ALL_ROUNDER_MIN_CHORES = 3;

    public function __construct(private TicketService $tickets) {}

    public function evaluate(Profile $profile): void
    {
        $this->evaluateQuestBadges($profile);
        $this->evaluateChoreBadges($profile);
        $this->evaluatePointBadges($profile);
        $this->evaluateProgressBadges($profile);
        $this->evaluateBonusBadges($profile);
    }

    /**
     * "Team Effort" is household-wide, not tied to whichever kid's action
     * happened to cross the finish line — award it to every kid once the
     * family goal is reached.
     */
    public function evaluateHouseholdGoal(Household $household): void
    {
        if ($household->goal_target <= 0 || $household->goal_now < $household->goal_target) {
            return;
        }

        Profile::where('household_id', $household->id)
            ->where('role', ProfileRole::Kid)
            ->get()
            ->each(fn (Profile $kid) => $this->maybeAward($kid, 'team_effort', fn () => true));
    }

    private function evaluateQuestBadges(Profile $profile): void
    {
        $this->maybeAward($profile, 'first_quest', fn () => DailyQuest::where('profile_id', $profile->id)
            ->whereNotNull('completed_at')
            ->exists());

        $this->awardMilestones($profile, self::QUEST_MILESTONES, fn () => DailyQuest::where('profile_id', $profile->id)
            ->whereNotNull('completed_at')
            ->count());

        $this->maybeAward($profile, 'perfect_board', fn () => $this->clearedWholeBoardToday($profile));

        $this->maybeAward($profile, 'speed_runner', function () use ($profile) {
            $clock = HouseholdClock::for($profile->household);
            $quest = DailyQuest::where('profile_id', $profile->id)
                ->whereDate('quest_date', $clock->today())
                ->first();

            return $quest
                && $quest->revealed_at
                && $quest->completed_at
                && $quest->revealed_at->diffInSeconds($quest->completed_at) <= self::SPEED_RUNNER_SECONDS;
        });
    }

    private function evaluateChoreBadges(Profile $profile): void
    {
        $this->awardMilestones($profile, self::CHORE_MILESTONES, fn () => ChoreCompletion::where('profile_id', $profile->id)
            ->where('status', CompletionStatus::Approved)
            ->count());

        // One walk of the history serves both day-shaped badges, and only
        // happens at all while one of them is still unwon.
        $byDay = null;
        $days = function () use ($profile, &$byDay): Collection {
            return $byDay ??= $this->approvedByDay($profile);
        };

        $this->maybeAward($profile, 'busy_bee', function () use ($profile) {
            $clock = HouseholdClock::for($profile->household);
            $startOfToday = $clock->startOf($clock->today());

            return ChoreCompletion::where('profile_id', $profile->id)
                ->where('status', CompletionStatus::Approved)
                ->where('decided_at', '>=', $startOfToday)
                ->count() > self::BUSY_BEE_THRESHOLD;
        });

        $this->maybeAward($profile, 'overachiever', fn () => (int) $days()->max() >= self::OVERACHIEVER_THRESHOLD);

        $this->maybeAward($profile, 'weekend_warrior', fn () => $this->clearedAWholeWeekend($days()));

        $this->maybeAward($profile, 'all_rounder', fn () => $this->hasDoneEveryChore($profile));

        $this->maybeAward($profile, 'early_bird', function () use ($profile) {
            $tz = $profile->household->timezone;

            return ChoreCompletion::where('profile_id', $profile->id)
                ->where('status', CompletionStatus::Approved)
                ->get()
                ->contains(fn (ChoreCompletion $c) => $c->submitted_at->copy()->setTimezone($tz)->hour < self::EARLY_BIRD_HOUR);
        });

        $this->maybeAward($profile, 'night_owl', function () use ($profile) {
            $tz = $profile->household->timezone;

            return ChoreCompletion::where('profile_id', $profile->id)
                ->where('status', CompletionStatus::Approved)
                ->get()
                ->contains(fn (ChoreCompletion $c) => $c->submitted_at->copy()->setTimezone($tz)->hour >= self::NIGHT_OWL_HOUR);
        });
    }

    private function evaluatePointBadges(Profile $profile): void
    {
        $this->maybeAward($profile, 'big_spender', function () use ($profile) {
            $spent = (int) $profile->ledgerEntries()->where('kind', LedgerKind::Spend)->sum('amount');

            return abs($spent) >= self::BIG_SPENDER_THRESHOLD;
        });

        $this->maybeAward($profile, 'big_saver', fn () => $profile->points >= self::BIG_SAVER_THRESHOLD);

        $this->awardMilestones($profile, self::EARNED_MILESTONES, fn () => (int) $profile->ledgerEntries()
            ->where('kind', LedgerKind::Earn)
            ->sum('amount'));
    }

    /**
     * Streak and level badges cost nothing to check — both numbers are already
     * on the profile — so they're tested one at a time rather than against a
     * snapshot. That matters for levels: a badge pays XP, which can carry a kid
     * past the next milestone in the same pass.
     */
    private function evaluateProgressBadges(Profile $profile): void
    {
        foreach (self::STREAK_MILESTONES as $key => $days) {
            $this->maybeAward($profile, $key, fn () => $profile->streak >= $days);
        }

        foreach (self::LEVEL_MILESTONES as $key => $level) {
            $this->maybeAward($profile, $key, fn () => $profile->level() >= $level);
        }

        $this->maybeAward($profile, 'comeback_kid', fn () => StreakRepair::where('profile_id', $profile->id)->exists());
    }

    private function evaluateBonusBadges(Profile $profile): void
    {
        $this->maybeAward($profile, 'wheel_winner', fn () => Spin::where('profile_id', $profile->id)
            ->where('multiplier', 3)
            ->exists());

        $this->maybeAward($profile, 'triple_threat', fn () => Spin::where('profile_id', $profile->id)
            ->where('multiplier', 3)
            ->count() >= self::TRIPLE_THREAT_SPINS);

        $this->awardMilestones($profile, self::SPIN_MILESTONES, fn () => Spin::where('profile_id', $profile->id)->count());

        $this->awardMilestones($profile, self::CHEST_MILESTONES, fn () => DailyChest::where('profile_id', $profile->id)->count());

        $this->awardMilestones($profile, self::REDEMPTION_MILESTONES, fn () => Redemption::where('profile_id', $profile->id)->count());

        $this->maybeAward($profile, 'big_ticket', fn () => Redemption::where('profile_id', $profile->id)
            ->where('cost_snapshot', '>=', self::BIG_TICKET_COST)
            ->exists());

        // Either side of a trade is the same event to the kid living it, and
        // only the deals that actually settled count as done.
        $this->awardMilestones($profile, self::TRADE_MILESTONES, fn () => SiblingOffer::where('status', SiblingOfferStatus::Accepted)
            ->where(fn ($query) => $query
                ->where('from_profile_id', $profile->id)
                ->orWhere('to_profile_id', $profile->id))
            ->count());

        $this->maybeAward($profile, 'gadgeteer', fn () => OwnedPerk::where('profile_id', $profile->id)
            ->whereNotNull('consumed_at')
            ->count() >= self::GADGETEER_PERKS);
    }

    /**
     * Awards every badge in a tiered set off a single reading of whatever they
     * all count. The reading is only taken once a badge in the set is still
     * unwon, so a finished tier costs nothing to walk past.
     *
     * @param  array<string, int>  $milestones  badge key => threshold
     * @param  Closure(): int  $measure
     */
    private function awardMilestones(Profile $profile, array $milestones, Closure $measure): void
    {
        if (! $this->anyMissing($profile, array_keys($milestones))) {
            return;
        }

        $actual = $measure();

        foreach ($milestones as $key => $threshold) {
            $this->maybeAward($profile, $key, fn () => $actual >= $threshold);
        }
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function anyMissing(Profile $profile, array $keys): bool
    {
        return $profile->badges()->whereIn('key', $keys)->count() < count($keys);
    }

    /**
     * Approved chores per household day, keyed by date string — grouped by the
     * day the chore was *claimed* on, the same day the quest and streak logic
     * attributes it to.
     *
     * @return Collection<string, int>
     */
    private function approvedByDay(Profile $profile): Collection
    {
        $clock = HouseholdClock::for($profile->household);

        return ChoreCompletion::where('profile_id', $profile->id)
            ->where('status', CompletionStatus::Approved)
            ->pluck('submitted_at')
            ->groupBy(fn (Carbon $moment) => $clock->dayFor($moment)->toDateString())
            ->map(fn (Collection $day) => $day->count());
    }

    /**
     * Both halves of one weekend worked — a Saturday with the Sunday that
     * follows it, not merely a Saturday somewhere and a Sunday somewhere else.
     *
     * @param  Collection<string, int>  $byDay
     */
    private function clearedAWholeWeekend(Collection $byDay): bool
    {
        return $byDay->keys()->contains(function (string $date) use ($byDay) {
            $day = Carbon::parse($date);

            return $day->isSaturday() && $byDay->has($day->copy()->addDay()->toDateString());
        });
    }

    /**
     * Every chore on this kid's board done at least once. Scoped to the board
     * they can actually see — a chore aimed at an older sibling was never
     * theirs to do — and only worth winning on a board with some breadth to it.
     */
    private function hasDoneEveryChore(Profile $profile): bool
    {
        $board = $profile->household->chores()
            ->appropriateFor($profile)
            ->available()
            ->pluck('id');

        if ($board->count() < self::ALL_ROUNDER_MIN_CHORES) {
            return false;
        }

        $done = ChoreCompletion::where('profile_id', $profile->id)
            ->where('status', CompletionStatus::Approved)
            ->distinct()
            ->pluck('chore_id');

        return $board->diff($done)->isEmpty();
    }

    private function clearedWholeBoardToday(Profile $profile): bool
    {
        $household = $profile->household;
        $clock = HouseholdClock::for($household);
        $today = $clock->today();
        $startOfToday = $clock->startOf($today);

        $quest = DailyQuest::where('profile_id', $profile->id)
            ->whereDate('quest_date', $today)
            ->first();

        if (! $quest || $quest->completed_at === null) {
            return false;
        }

        // A one-time chore a sibling already took isn't on this kid's board to
        // clear, so counting it would make a perfect board unwinnable. Same for
        // a chore a parent's deadline has closed for the day.
        $totalChores = $household->chores()
            ->appropriateFor($profile)
            ->available()
            ->notExpiredAt(now(), $startOfToday)
            ->count();

        if ($totalChores <= 1) {
            return false;
        }

        $approvedOtherChoresToday = ChoreCompletion::where('profile_id', $profile->id)
            ->where('status', CompletionStatus::Approved)
            ->where('decided_at', '>=', $startOfToday)
            ->where('chore_id', '!=', $quest->chore_id)
            ->distinct('chore_id')
            ->count('chore_id');

        return $approvedOtherChoresToday >= ($totalChores - 1);
    }

    private function maybeAward(Profile $profile, string $key, Closure $condition): void
    {
        if ($profile->badges()->where('key', $key)->exists()) {
            return;
        }

        if (! $condition()) {
            return;
        }

        $badge = Badge::where('key', $key)->first();

        if (! $badge) {
            return;
        }

        $profile->badges()->attach($badge->id, ['earned_at' => now()]);

        // The exists() check above is what keeps this from paying twice —
        // a badge can only ever be attached once, so its XP lands once.
        if ($badge->xp_reward > 0) {
            $profile->xp += $badge->xp_reward;
            $profile->save();
        }

        $this->tickets->awardForBadge($profile, $badge);

        // That XP may have crossed a level, which mints again on its own.
        $this->tickets->syncLevelTickets($profile);
    }
}
