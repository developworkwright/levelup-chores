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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

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

        Notification::send($parents, new ParentApprovalNeeded(
            'Chore ready for approval',
            "{$profile->name} finished {$chore->name}.",
        ));

        return $completion;
    }

    /**
     * Claiming (not approval) is what unlocks the rest of the board —
     * deliberate, so a kid isn't blocked by a parent's response time.
     */
    public function claimQuest(Profile $profile): DailyQuest
    {
        $quest = $this->questFor($profile);

        if ($quest->completed_at === null) {
            $quest->completed_at = now();
            $quest->save();

            $this->bumpStreak($profile, $quest);
            $this->claim($profile, $quest->chore);
        }

        return $quest;
    }

    private function bumpStreak(Profile $profile, DailyQuest $quest): void
    {
        $completedYesterday = DailyQuest::where('profile_id', $profile->id)
            ->whereDate('quest_date', $quest->quest_date->copy()->subDay())
            ->whereNotNull('completed_at')
            ->exists();

        $profile->streak = $completedYesterday ? $profile->streak + 1 : 1;

        $bonusDollars = self::STREAK_BONUSES[$profile->streak] ?? null;

        if ($bonusDollars !== null) {
            $bonusPoints = $bonusDollars * $profile->household->points_per_dollar;

            $this->ledger->record(
                $profile->household,
                $profile,
                LedgerKind::Earn,
                $bonusPoints,
                "{$profile->name} — {$profile->streak}-day streak bonus (\${$bonusDollars})",
            );

            // Credited immediately above, but the reveal waits for the kid
            // to open the streak chest — that's the surprise moment.
            $profile->pending_streak_chest = $profile->streak;
        }

        $profile->save();
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
