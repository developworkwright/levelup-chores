<?php

namespace App\Services;

use App\Enums\CompletionStatus;
use App\Enums\LedgerKind;
use App\Enums\ProfileRole;
use App\Models\Badge;
use App\Models\ChoreCompletion;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\Profile;
use App\Models\Spin;
use Closure;

class BadgeService
{
    /** Cumulative points spent in the Loot Shop to earn "Big Spender". */
    private const BIG_SPENDER_THRESHOLD = 1000;

    /** Balance held at once to earn "Big Saver". */
    private const BIG_SAVER_THRESHOLD = 500;

    /** More than this many chores approved in one household day earns "Busy Bee". */
    private const BUSY_BEE_THRESHOLD = 3;

    /** Household-local hour before which a claim counts as "Early Bird". */
    private const EARLY_BIRD_HOUR = 7;

    /** Household-local hour at/after which a claim counts as "Night Owl". */
    private const NIGHT_OWL_HOUR = 22;

    /** How quickly the main quest must be claimed after reveal to earn "Speed Runner". */
    private const SPEED_RUNNER_SECONDS = 300;

    public function __construct(private TicketService $tickets) {}

    public function evaluate(Profile $profile): void
    {
        $this->maybeAward($profile, 'first_quest', fn () => DailyQuest::where('profile_id', $profile->id)
            ->whereNotNull('completed_at')
            ->exists());

        $this->maybeAward($profile, 'streak_3', fn () => $profile->streak >= 3);
        $this->maybeAward($profile, 'streak_7', fn () => $profile->streak >= 7);
        $this->maybeAward($profile, 'streak_14', fn () => $profile->streak >= 14);

        $this->maybeAward($profile, 'big_spender', function () use ($profile) {
            $spent = (int) $profile->ledgerEntries()->where('kind', LedgerKind::Spend)->sum('amount');

            return abs($spent) >= self::BIG_SPENDER_THRESHOLD;
        });

        $this->maybeAward($profile, 'big_saver', fn () => $profile->points >= self::BIG_SAVER_THRESHOLD);

        $this->maybeAward($profile, 'wheel_winner', fn () => Spin::where('profile_id', $profile->id)
            ->where('multiplier', 3)
            ->exists());

        $this->maybeAward($profile, 'busy_bee', function () use ($profile) {
            $clock = HouseholdClock::for($profile->household);
            $startOfToday = $clock->startOf($clock->today());

            return ChoreCompletion::where('profile_id', $profile->id)
                ->where('status', CompletionStatus::Approved)
                ->where('decided_at', '>=', $startOfToday)
                ->count() > self::BUSY_BEE_THRESHOLD;
        });

        $this->maybeAward($profile, 'perfect_board', fn () => $this->clearedWholeBoardToday($profile));

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
        // clear, so counting it would make a perfect board unwinnable.
        $totalChores = $household->chores()->appropriateFor($profile)->available()->count();

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
