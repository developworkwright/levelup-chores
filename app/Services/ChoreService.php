<?php

namespace App\Services;

use App\Enums\ChoreCadence;
use App\Enums\CompletionStatus;
use App\Enums\LedgerKind;
use App\Enums\ProfileRole;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyMystery;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\Profile;
use App\Notifications\ParentApprovalNeeded;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

class ChoreService
{
    /** Streak-day milestone => dollar bonus, credited the moment a kid hits it. */
    public const STREAK_BONUSES = [
        3 => 1,
        5 => 3,
        7 => 5,
        14 => 15,
        30 => 40,
    ];

    /** Bonus paid on top of whatever chore gets picked as the day's mystery. */
    public const MYSTERY_BONUS_POINTS = 500;

    /** Safety bound on the streak walk-back so odd data can't loop forever. */
    private const MAX_STREAK_DAYS = 366;

    public function __construct(
        private LedgerService $ledger,
        private SpinService $spin,
        private BadgeService $badges,
    ) {}

    public function questFor(Profile $profile): DailyQuest
    {
        $today = HouseholdClock::for($profile->household)->today();

        $quest = DailyQuest::where('profile_id', $profile->id)
            ->whereDate('quest_date', $today)
            ->first();

        if ($quest) {
            return $quest;
        }

        $choreId = $profile->household->chores()->appropriateFor($profile)->questEligible()
            ->inRandomOrder()->value('id');

        if (! $choreId) {
            throw new RuntimeException('Household has no chores to assign as a quest.');
        }

        return DailyQuest::create([
            'household_id' => $profile->household_id,
            'profile_id' => $profile->id,
            'chore_id' => $choreId,
            'quest_date' => $today,
        ]);
    }

    public function isQuestRevealedToday(Profile $profile): bool
    {
        return $this->questFor($profile)->revealed_at !== null;
    }

    public function revealQuest(Profile $profile): DailyQuest
    {
        $quest = $this->questFor($profile);

        if ($quest->revealed_at === null) {
            $quest->revealed_at = now();
            $quest->save();
        }

        return $quest;
    }

    public function isQuestDoneToday(Profile $profile): bool
    {
        return $this->questFor($profile)->completed_at !== null;
    }

    /**
     * Point chores for the board, excluding the assigned quest, each
     * annotated with ['chore' => Chore, 'state' => string]. The mystery
     * chore (if any) stays in this list, indistinguishable from the rest —
     * that's the whole point.
     */
    public function boardFor(Profile $profile): Collection
    {
        $quest = $this->questFor($profile);
        $gated = $profile->household->require_quest_first && $quest->completed_at === null;
        $todaysMystery = $this->mysteryChoreFor($profile->household);

        return $profile->household->chores
            ->filter(fn (Chore $chore) => $chore->isAppropriateFor($profile))
            ->reject(fn (Chore $chore) => $chore->id === $quest->chore_id)
            ->map(fn (Chore $chore) => [
                'chore' => $chore,
                'state' => $gated ? 'locked' : $this->stateForChore($profile, $chore, $todaysMystery),
            ])
            ->values();
    }

    /**
     * The chore randomly picked as today's household-wide mystery bonus —
     * lazily assigned (like the daily quest and the wheel's spin result)
     * the first time it's needed each day, then persisted so it stays the
     * same chore for everyone, all day, no matter how many times it's
     * looked up.
     *
     * Picked only from chores open to any age (so the youngest kid always
     * has a fair shot at it) that don't already have a claimant today —
     * picking one that's already been completed would make the "reveal"
     * moment meaningless before it even started.
     */
    public function mysteryChoreFor(Household $household): ?Chore
    {
        $today = HouseholdClock::for($household)->today();

        $existing = DailyMystery::where('household_id', $household->id)
            ->whereDate('mystery_date', $today)
            ->first();

        if ($existing) {
            return $existing->chore;
        }

        $choreId = $household->chores
            ->filter(fn (Chore $chore) => $chore->min_age === null)
            // Unlimited-cadence chores are always freely repeatable by
            // everyone — that's fundamentally at odds with "first one to
            // find it wins," so they're never in the running.
            ->reject(fn (Chore $chore) => $chore->cadence === ChoreCadence::Unlimited)
            ->reject(fn (Chore $chore) => $this->mysteryClaimant($chore) !== null)
            ->pluck('id')
            ->all();

        if (empty($choreId)) {
            return null;
        }

        $chore = Chore::find(Arr::random($choreId));

        DailyMystery::create([
            'household_id' => $household->id,
            'mystery_date' => $today,
            'chore_id' => $chore->id,
        ]);

        return $chore;
    }

    /**
     * The completion that currently "holds" the mystery chore for its
     * cadence window — pending or approved both count, since claiming (not
     * just approval) is what wins the race and locks it for everyone else.
     * A rejected claim doesn't count, so the chore reopens automatically.
     */
    public function mysteryClaimant(Chore $chore): ?ChoreCompletion
    {
        $clock = HouseholdClock::for($chore->household);
        $boundary = $chore->cadence === ChoreCadence::Weekly
            ? $clock->startOf($clock->today()->subDays(6))
            : $clock->startOf($clock->today());

        return ChoreCompletion::where('chore_id', $chore->id)
            ->where(function ($query) use ($boundary) {
                $query->where('status', CompletionStatus::Pending)
                    ->orWhere(function ($approved) use ($boundary) {
                        $approved->where('status', CompletionStatus::Approved)
                            ->where('decided_at', '>=', $boundary);
                    });
            })
            ->with('profile')
            ->oldest('submitted_at')
            ->first();
    }

    public function stateFor(Profile $profile, Chore $chore): string
    {
        return $this->stateForChore($profile, $chore, $this->mysteryChoreFor($profile->household));
    }

    /**
     * Split out from stateFor() so boardFor() can resolve today's mystery
     * chore once and reuse it across the whole board instead of re-querying
     * it per chore.
     */
    private function stateForChore(Profile $profile, Chore $chore, ?Chore $todaysMystery): string
    {
        // The mystery chore is claimed household-wide, not per-kid — once
        // anyone has it (pending or approved), it's off the board for
        // everyone else until the cadence resets.
        if ($todaysMystery && $todaysMystery->id === $chore->id) {
            $claimant = $this->mysteryClaimant($chore);

            if ($claimant === null) {
                return 'ready';
            }

            return $claimant->profile_id === $profile->id && $claimant->status === CompletionStatus::Pending
                ? 'pending'
                : 'done';
        }

        // No cooldown, no waiting on a prior pending claim — always
        // claimable, for chores that can happen more than once a day.
        if ($chore->cadence === ChoreCadence::Unlimited) {
            return 'ready';
        }

        $pending = ChoreCompletion::where('profile_id', $profile->id)
            ->where('chore_id', $chore->id)
            ->where('status', CompletionStatus::Pending)
            ->exists();

        if ($pending) {
            return 'pending';
        }

        $clock = HouseholdClock::for($profile->household);
        $boundary = $chore->cadence === ChoreCadence::Weekly
            ? $clock->startOf($clock->today()->subDays(6))
            : $clock->startOf($clock->today());

        $onCooldown = ChoreCompletion::where('profile_id', $profile->id)
            ->where('chore_id', $chore->id)
            ->where('status', CompletionStatus::Approved)
            ->where('decided_at', '>=', $boundary)
            ->exists();

        return $onCooldown ? 'done' : 'ready';
    }

    public function claim(Profile $profile, Chore $chore): ChoreCompletion
    {
        $multiplier = $this->spin->multiplierFor($profile, $chore);
        $todaysMystery = $this->mysteryChoreFor($profile->household);
        $mysteryBonus = ($todaysMystery && $todaysMystery->id === $chore->id) ? self::MYSTERY_BONUS_POINTS : 0;

        $completion = ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $profile->id,
            'status' => CompletionStatus::Pending,
            'points_awarded' => ($chore->points * $multiplier) + $mysteryBonus,
            'submitted_at' => now(),
        ]);

        $parents = Profile::where('household_id', $profile->household_id)
            ->where('role', ProfileRole::Parent)
            ->get();

        // Best-effort: a kid's claim must never fail because the parent's
        // push notification couldn't be queued or delivered.
        try {
            Notification::send($parents, new ParentApprovalNeeded(
                'Chore ready for approval',
                "{$profile->name} finished {$chore->name}.",
            ));
        } catch (Throwable $e) {
            Log::error('Parent approval notification failed for chore claim.', [
                'completion_id' => $completion->id,
                'exception' => $e,
            ]);
        }

        return $completion;
    }

    /**
     * Claiming (not approval) is what unlocks the rest of the board —
     * deliberate, so a kid isn't blocked by a parent's response time. The
     * streak is not touched here; it only moves once a parent approves.
     */
    public function claimQuest(Profile $profile): DailyQuest
    {
        $quest = $this->questFor($profile);

        if ($quest->completed_at === null) {
            $quest->completed_at = now();
            $quest->save();

            $this->claim($profile, $quest->chore);
        }

        return $quest;
    }

    /**
     * Recomputes the streak and pays out any milestone bonus newly crossed.
     * Driven by approval, not by claiming, so a kid can't bank a bonus for
     * work a parent hasn't signed off on.
     *
     * Deliberately a recompute rather than an increment: a parent working
     * through several days of backlog can approve them in any order, and
     * every one of those approvals still has to land on the same number.
     */
    private function refreshStreak(Profile $profile): void
    {
        $previous = $profile->streak;
        $profile->streak = $this->currentStreak($profile);

        $reached = null;

        foreach (self::STREAK_BONUSES as $day => $bonusDollars) {
            // Only milestones this approval newly crossed pay out, so
            // recomputing can never double-credit one already banked.
            if ($day <= $previous || $day > $profile->streak) {
                continue;
            }

            $this->ledger->record(
                $profile->household,
                $profile,
                LedgerKind::Earn,
                $bonusDollars * $profile->household->points_per_dollar,
                "{$profile->name} — {$day}-day streak bonus (\${$bonusDollars})",
            );

            $reached = $day;
        }

        if ($reached !== null) {
            // Credited immediately above, but the reveal waits for the kid
            // to open the streak chest — that's the surprise moment.
            $profile->pending_streak_chest = $reached;
        }

        $profile->save();
    }

    /**
     * Consecutive household-days ending today (or yesterday, if today's
     * quest isn't approved yet) that have an approved quest completion.
     */
    private function currentStreak(Profile $profile): int
    {
        $cursor = HouseholdClock::for($profile->household)->today();

        // Today being unapproved doesn't end a streak — it just means the
        // chain is still anchored on yesterday.
        if (! $this->questApprovedOn($profile, $cursor)) {
            $cursor = $cursor->copy()->subDay();
        }

        $streak = 0;

        while ($streak < self::MAX_STREAK_DAYS && $this->questApprovedOn($profile, $cursor)) {
            $streak++;
            $cursor = $cursor->copy()->subDay();
        }

        return $streak;
    }

    /** Whether the quest assigned for a given household-day was approved. */
    private function questApprovedOn(Profile $profile, Carbon $date): bool
    {
        $quest = DailyQuest::where('profile_id', $profile->id)
            ->whereDate('quest_date', $date)
            ->first();

        if (! $quest) {
            return false;
        }

        $clock = HouseholdClock::for($profile->household);

        return ChoreCompletion::where('profile_id', $profile->id)
            ->where('chore_id', $quest->chore_id)
            ->where('status', CompletionStatus::Approved)
            ->where('submitted_at', '>=', $clock->startOf($date))
            ->where('submitted_at', '<', $clock->startOf($date->copy()->addDay()))
            ->exists();
    }

    /** Whether this completion is the one that clears its day's main quest. */
    private function isQuestCompletion(ChoreCompletion $completion, Profile $profile): bool
    {
        $questDate = HouseholdClock::for($profile->household)->dayFor($completion->submitted_at);

        $quest = DailyQuest::where('profile_id', $profile->id)
            ->whereDate('quest_date', $questDate)
            ->first();

        return $quest !== null && $quest->chore_id === $completion->chore_id;
    }

    /**
     * Marks the pending streak-milestone chest as opened. The bonus was
     * already credited to the ledger when the milestone was reached — this
     * just unlocks the reveal animation and returns what to show for it.
     *
     * @return array{day: int, dollars: int}|null
     */
    public function openStreakChest(Profile $profile): ?array
    {
        $day = $profile->pending_streak_chest;

        if ($day === null) {
            return null;
        }

        $profile->pending_streak_chest = null;
        $profile->save();

        return ['day' => $day, 'dollars' => self::STREAK_BONUSES[$day] ?? 0];
    }

    /** Smallest streak-bonus milestone day still ahead of the profile's current streak. */
    public function nextStreakMilestone(Profile $profile): ?int
    {
        foreach (array_keys(self::STREAK_BONUSES) as $day) {
            if ($day > $profile->streak) {
                return $day;
            }
        }

        return null;
    }

    public function approve(ChoreCompletion $completion, Profile $approver): void
    {
        // The approvals screen only ever lists pending items, so this is a
        // guard rather than a real path — but approving twice would credit
        // the ledger twice, which is not something to leave to chance.
        if ($completion->status === CompletionStatus::Approved) {
            return;
        }

        $completion->status = CompletionStatus::Approved;
        $completion->decided_at = now();
        $completion->decided_by_profile_id = $approver->id;
        $completion->save();

        $profile = $completion->profile;
        $household = $profile->household;

        $this->ledger->record(
            $household,
            $profile,
            LedgerKind::Earn,
            $completion->points_awarded,
            "{$profile->name} — {$completion->chore->name}",
            $completion,
        );

        $profile->xp += 25;
        $profile->save();

        $household->goal_now = min($household->goal_target, $household->goal_now + $completion->points_awarded);
        $household->save();

        // Before badges, not after — the streak_3/7/14 badges read the
        // profile's streak, so it has to be current by the time they run.
        if ($this->isQuestCompletion($completion, $profile)) {
            $this->refreshStreak($profile);
        }

        $this->badges->evaluate($profile);
        $this->badges->evaluateHouseholdGoal($household);
    }

    public function sendBack(ChoreCompletion $completion, Profile $approver): void
    {
        $completion->status = CompletionStatus::Rejected;
        $completion->decided_at = now();
        $completion->decided_by_profile_id = $approver->id;
        $completion->save();
    }
}
