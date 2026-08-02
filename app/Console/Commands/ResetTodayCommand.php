<?php

namespace App\Console\Commands;

use App\Enums\CompletionStatus;
use App\Enums\ProfileRole;
use App\Models\ChoreCompletion;
use App\Models\DailyQuest;
use App\Models\LedgerEntry;
use App\Models\Profile;
use App\Models\Redemption;
use App\Models\Spin;
use App\Services\ChoreService;
use App\Services\HouseholdClock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetTodayCommand extends Command
{
    protected $signature = 'quest:reset-today
        {--kid= : Only reset one kid, by name}
        {--dry-run : Show what would change without saving anything}';

    protected $description = "Undo today's quest/spin/chore/loot-shop activity for testing — leaves accounts, chores, store items, PINs, the family goal target, and everything from prior days untouched.";

    public function handle(): int
    {
        $kids = Profile::where('role', ProfileRole::Kid)->get();

        if ($name = $this->option('kid')) {
            $kids = $kids->filter(fn (Profile $k) => strcasecmp($k->name, $name) === 0)->values();

            if ($kids->isEmpty()) {
                $this->error("No kid named \"{$name}\" found.");

                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        foreach ($kids as $kid) {
            $rows[] = $this->resetKid($kid, $dryRun);
        }

        $this->table(['Kid', 'Points', 'XP', 'Streak', 'Quest cleared', 'Spin cleared', 'Completions removed', 'Redemptions refunded', 'Badges cleared'], $rows);
        $this->info($dryRun ? 'Dry run — nothing was actually changed.' : "Done. Today's testing state is cleared.");

        return self::SUCCESS;
    }

    private function resetKid(Profile $kid, bool $dryRun): array
    {
        $household = $kid->household;
        $clock = HouseholdClock::for($household);
        $startOfToday = $clock->startOf($clock->today());

        $pointsBefore = $kid->points;
        $xpBefore = $kid->xp;
        $streakBefore = $kid->streak;

        DB::beginTransaction();

        try {
            $quest = DailyQuest::where('profile_id', $kid->id)
                ->whereDate('quest_date', $clock->today())
                ->first();

            $questWasClaimedToday = $quest && $quest->completed_at !== null;

            $completions = ChoreCompletion::where('profile_id', $kid->id)
                ->where('submitted_at', '>=', $startOfToday)
                ->get();

            $pointsDelta = 0;
            $xpDelta = 0;

            foreach ($completions as $completion) {
                if ($completion->status === CompletionStatus::Approved) {
                    $pointsDelta -= $completion->points_awarded;
                    $xpDelta -= ChoreService::XP_PER_CHORE;
                    $household->goal_now = max(0, $household->goal_now - $completion->points_awarded);
                    // Rolled back with goal_now, or the kid keeps credit on the
                    // family goal for a day that no longer happened.
                    $kid->goal_contribution = max(0, $kid->goal_contribution - $completion->points_awarded);
                }
            }

            $redemptions = Redemption::where('profile_id', $kid->id)
                ->where('requested_at', '>=', $startOfToday)
                ->get();

            foreach ($redemptions as $redemption) {
                $pointsDelta += $redemption->cost_snapshot;
            }

            $kid->points = max(0, $kid->points + $pointsDelta);
            $kid->xp = max(0, $kid->xp + $xpDelta);

            if ($questWasClaimedToday) {
                $kid->streak = max(0, $kid->streak - 1);
            }

            $badgesCleared = DB::table('profile_badges')
                ->where('profile_id', $kid->id)
                ->where('earned_at', '>=', $startOfToday)
                ->count();

            $spinsCleared = Spin::where('profile_id', $kid->id)
                ->whereDate('spin_date', $clock->today())
                ->count();

            if (! $dryRun) {
                LedgerEntry::where('profile_id', $kid->id)
                    ->whereIn('kind', ['earn', 'spend'])
                    ->where('created_at', '>=', $startOfToday)
                    ->delete();

                ChoreCompletion::where('profile_id', $kid->id)
                    ->where('submitted_at', '>=', $startOfToday)
                    ->delete();

                Redemption::where('profile_id', $kid->id)
                    ->where('requested_at', '>=', $startOfToday)
                    ->delete();

                Spin::where('profile_id', $kid->id)
                    ->whereDate('spin_date', $clock->today())
                    ->delete();

                DB::table('profile_badges')
                    ->where('profile_id', $kid->id)
                    ->where('earned_at', '>=', $startOfToday)
                    ->delete();

                $quest?->delete();

                $kid->save();
                $household->save();
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return [
            $kid->name,
            "{$pointsBefore} → ".max(0, $pointsBefore + $pointsDelta),
            "{$xpBefore} → ".max(0, $xpBefore + $xpDelta),
            "{$streakBefore} → ".($questWasClaimedToday ? max(0, $streakBefore - 1) : $streakBefore),
            $quest ? 'yes' : 'no',
            $spinsCleared > 0 ? 'yes' : 'no',
            $completions->count(),
            $redemptions->count(),
            $badgesCleared,
        ];
    }
}
