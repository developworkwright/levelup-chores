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
use App\Models\MysteryHintPurchase;
use App\Models\Profile;
use App\Models\StreakRepair;
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
        private TicketService $tickets,
    ) {}

    public function questFor(Profile $profile): DailyQuest
    {
        $today = HouseholdClock::for($profile->household)->today();

        $quest = DailyQuest::where('profile_id', $profile->id)
            ->whereDate('quest_date', $today)
            ->first();

        if ($quest) {
            return $this->rerollIfTaken($profile, $quest);
        }

        $candidates = $this->questCandidates($profile);

        if ($candidates->isEmpty()) {
            throw new RuntimeException('Household has no chores to assign as a quest.');
        }

        $free = $this->unclaimed($candidates);

        // Prefer something the kid can actually do, but fall back to the full
        // list so they always get a quest — on a day the family has already
        // cleared the board, a blocked quest beats no quest and a crash.
        $choreId = ($free->isNotEmpty() ? $free : $candidates)->random()->id;

        return DailyQuest::create([
            'household_id' => $profile->household_id,
            'profile_id' => $profile->id,
            'chore_id' => $choreId,
            'quest_date' => $today,
        ]);
    }

    /**
     * Swaps today's quest for a different chore. Shared by the kid's ticket
     * purchase and the parent's override button so both behave identically.
     *
     * Returns null when there's nothing to do — the quest is already cleared,
     * or the household has no other eligible chore to offer — which is how
     * callers know not to charge for it.
     */
    public function rerollQuest(Profile $profile): ?DailyQuest
    {
        $quest = $this->questFor($profile);

        if ($quest->completed_at !== null) {
            return null;
        }

        return $this->assignDifferentChore($profile, $quest);
    }

    /**
     * Swaps a quest a sibling has taken out from under the kid.
     *
     * Cooldowns are household-wide, so another kid finishing your quest chore
     * would otherwise leave you unable to clear your quest at all — no streak,
     * and a board that stays gated. Rerolling keeps the day recoverable
     * without anyone having to intervene.
     */
    private function rerollIfTaken(Profile $profile, DailyQuest $quest): DailyQuest
    {
        if ($quest->completed_at !== null) {
            return $quest;
        }

        // Unlimited chores never lock, so a claim on one blocks nobody.
        if ($quest->chore->cadence === ChoreCadence::Unlimited) {
            return $quest;
        }

        $claimant = $this->claimantFor($quest->chore);

        // Nobody holds it, or the kid holds it themselves — nothing to fix.
        if (! $claimant || $claimant->profile_id === $profile->id) {
            return $quest;
        }

        return $this->assignDifferentChore($profile, $quest) ?? $quest;
    }

    /** Moves a quest onto a different, actually-doable chore. Null when there isn't one. */
    private function assignDifferentChore(Profile $profile, DailyQuest $quest): ?DailyQuest
    {
        // Swapping onto a chore someone else has already claimed would leave
        // the kid exactly as stuck, so only free chores count here.
        $free = $this->unclaimed($this->questCandidates($profile, $quest->chore_id));

        if ($free->isEmpty()) {
            return null;
        }

        $quest->chore_id = $free->random()->id;
        // Re-hidden on purpose — a new quest deserves the chest animation
        // again, so the swap lands as a fresh reveal rather than a silent
        // relabel. Also means the board stays gated until they open it.
        $quest->revealed_at = null;
        $quest->save();

        return $quest->refresh();
    }

    /** @return Collection<int, Chore> */
    private function questCandidates(Profile $profile, ?int $excludeChoreId = null): Collection
    {
        return $profile->household->chores()
            ->appropriateFor($profile)
            ->questEligible()
            ->when($excludeChoreId !== null, fn ($query) => $query->where('id', '!=', $excludeChoreId))
            ->get();
    }

    /**
     * @param  Collection<int, Chore>  $chores
     * @return Collection<int, Chore>
     */
    private function unclaimed(Collection $chores): Collection
    {
        return $chores->reject(fn (Chore $chore) => $chore->cadence !== ChoreCadence::Unlimited
            && $this->claimantFor($chore) !== null);
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

        // Resolved here even though the board no longer needs it for state:
        // this is the call that lazily assigns the day's mystery chore, and
        // dropping it would leave that to whichever page happened to ask first.
        $this->mysteryChoreFor($profile->household);

        return $profile->household->chores
            ->filter(fn (Chore $chore) => $chore->isAppropriateFor($profile))
            ->reject(fn (Chore $chore) => $chore->id === $quest->chore_id)
            ->map(fn (Chore $chore) => [
                'chore' => $chore,
                'state' => $gated ? 'locked' : $this->stateFor($profile, $chore),
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
     *
     * Chores with a parent-written hint win the draw outright, so the Bonus
     * Shop's mystery hint always has something to sell. Only when none of the
     * eligible chores has a hint does the pick fall back to the whole pool.
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

        $chore = $this->drawMysteryChore($household);

        if (! $chore) {
            return null;
        }

        DailyMystery::create([
            'household_id' => $household->id,
            'mystery_date' => $today,
            'chore_id' => $chore->id,
        ]);

        return $chore;
    }

    /**
     * Swaps today's mystery for a different eligible chore. Returns null when
     * there's nothing to swap to, or when someone has already won it — once
     * the race is over, moving the finish line would rob the winner.
     */
    public function rerollMysteryChore(Household $household): ?Chore
    {
        $today = HouseholdClock::for($household)->today();

        $existing = DailyMystery::where('household_id', $household->id)
            ->whereDate('mystery_date', $today)
            ->first();

        if ($existing && $this->claimantFor($existing->chore)) {
            return null;
        }

        $chore = $this->drawMysteryChore($household, $existing?->chore_id);

        if (! $chore) {
            return null;
        }

        if ($existing) {
            $existing->chore_id = $chore->id;
            $existing->save();
        } else {
            DailyMystery::create([
                'household_id' => $household->id,
                'mystery_date' => $today,
                'chore_id' => $chore->id,
            ]);
        }

        return $chore;
    }

    /**
     * Picks a chore that may serve as the mystery, applying every fairness
     * rule. Shared by the daily draw and the parent's reroll so the two can't
     * drift apart on what counts as eligible.
     */
    private function drawMysteryChore(Household $household, ?int $excludeChoreId = null): ?Chore
    {
        $eligible = $household->chores
            ->filter(fn (Chore $chore) => $chore->min_age === null)
            // Unlimited-cadence chores are always freely repeatable by
            // everyone — that's fundamentally at odds with "first one to
            // find it wins," so they're never in the running.
            ->reject(fn (Chore $chore) => $chore->cadence === ChoreCadence::Unlimited)
            ->reject(fn (Chore $chore) => $this->claimantFor($chore) !== null)
            ->reject(fn (Chore $chore) => $excludeChoreId !== null && $chore->id === $excludeChoreId);

        $hinted = $eligible->filter(fn (Chore $chore) => filled($chore->hint));

        $choreId = ($hinted->isNotEmpty() ? $hinted : $eligible)->pluck('id')->all();

        return empty($choreId) ? null : Chore::find(Arr::random($choreId));
    }

    /** Whether this kid has already bought today's mystery hint. */
    public function hasBoughtMysteryHint(Profile $profile): bool
    {
        return MysteryHintPurchase::where('profile_id', $profile->id)
            ->whereDate('hint_date', HouseholdClock::for($profile->household)->today())
            ->exists();
    }

    /**
     * The hint for today's mystery chore, but only for a kid who has paid for
     * it — hints are per-kid so one sibling buying doesn't clue in the rest.
     */
    public function mysteryHintFor(Profile $profile): ?string
    {
        if (! $this->hasBoughtMysteryHint($profile)) {
            return null;
        }

        return $this->mysteryChoreFor($profile->household)?->hint;
    }

    /** Records the purchase. Returns the revealed hint, or null if there's nothing to reveal. */
    public function buyMysteryHint(Profile $profile): ?string
    {
        $chore = $this->mysteryChoreFor($profile->household);

        if (! $chore || blank($chore->hint)) {
            return null;
        }

        MysteryHintPurchase::firstOrCreate([
            'profile_id' => $profile->id,
            'hint_date' => HouseholdClock::for($profile->household)->today(),
        ]);

        return $chore->hint;
    }

    /**
     * The completion that currently "holds" a chore for its cadence window.
     *
     * Household-wide, not per-kid: the dishes only need doing once, so whoever
     * claims a daily chore first takes it off everyone's board until the
     * cadence resets. Pending and approved both count, since claiming — not
     * approval — is what wins the race. A rejected claim doesn't, so the chore
     * reopens on its own.
     */
    public function claimantFor(Chore $chore): ?ChoreCompletion
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

    /**
     * How a chore reads on a kid's board.
     *
     * Cooldowns are household-wide: a once-a-day chore is done once by the
     * family, not once per kid. That makes the mystery chore's "first one to
     * find it wins" exclusivity the ordinary rule rather than a special case,
     * which is why there's no separate branch for it here.
     */
    public function stateFor(Profile $profile, Chore $chore): string
    {
        // No cooldown and no waiting on a prior claim — several kids can do
        // an unlimited chore, repeatedly, on the same day.
        if ($chore->cadence === ChoreCadence::Unlimited) {
            return 'ready';
        }

        $claimant = $this->claimantFor($chore);

        if ($claimant === null) {
            return 'ready';
        }

        // 'pending' is the kid's own claim awaiting approval; anyone else
        // holding it just means the chore is spoken for.
        return $claimant->profile_id === $profile->id && $claimant->status === CompletionStatus::Pending
            ? 'pending'
            : 'done';
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
     * The day a streak repair would actually buy back, or null when there's
     * nothing worth fixing — yesterday already counts, or there was no live
     * chain to save in the first place.
     */
    public function repairableStreakDate(Profile $profile): ?Carbon
    {
        $yesterday = HouseholdClock::for($profile->household)->today()->subDay();

        if ($this->questApprovedOn($profile, $yesterday)) {
            return null;
        }

        // Only a break in a running chain is worth buying back; repairing a
        // day with nothing behind it just manufactures a one-day streak.
        return $this->questApprovedOn($profile, $yesterday->copy()->subDay())
            ? $yesterday
            : null;
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
        // A high-water mark, not the current streak. Gating on the live value
        // would let a kid lapse a streak and buy a repair to collect every
        // milestone a second time.
        $paidThrough = $profile->streak_milestone_paid_through;
        $profile->streak = $this->currentStreak($profile);

        $reached = null;

        foreach (self::STREAK_BONUSES as $day => $bonusDollars) {
            // Only milestones never paid before pay out, so recomputing — or
            // repairing — can never double-credit one already banked.
            if ($day <= $paidThrough || $day > $profile->streak) {
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
            $profile->streak_milestone_paid_through = $reached;
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

    /**
     * Whether the quest assigned for a given household-day counts toward the
     * streak — either it was approved, or the day was bought back with a
     * streak repair, which the walk-back treats identically.
     */
    private function questApprovedOn(Profile $profile, Carbon $date): bool
    {
        $repaired = StreakRepair::where('profile_id', $profile->id)
            ->whereDate('repaired_date', $date)
            ->exists();

        if ($repaired) {
            return true;
        }

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

        // After badges, so a level crossed by badge XP is caught in the same
        // pass. Idempotent, so the badge path having already synced is fine.
        $this->tickets->syncLevelTickets($profile);
    }

    public function sendBack(ChoreCompletion $completion, Profile $approver): void
    {
        $completion->status = CompletionStatus::Rejected;
        $completion->decided_at = now();
        $completion->decided_by_profile_id = $approver->id;
        $completion->save();
    }
}
