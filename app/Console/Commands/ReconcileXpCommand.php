<?php

namespace App\Console\Commands;

use App\Enums\CompletionStatus;
use App\Enums\ProfileRole;
use App\Models\ChoreCompletion;
use App\Models\DailyChest;
use App\Models\Profile;
use App\Services\ChestService;
use App\Services\ChoreService;
use App\Services\TicketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes each kid's XP from the records that actually earned it.
 *
 * Two things make stored XP drift from what a kid is owed. Badge XP is granted
 * at the moment the badge is attached, so badges earned before `xp_reward`
 * existed paid nothing and — because maybeAward() won't re-award a badge that's
 * already attached — never will. And changing XP_PER_CHORE reprices every past
 * approval, which nothing else goes back and applies.
 *
 * Both are fixed by rebuilding the total rather than patching it.
 */
class ReconcileXpCommand extends Command
{
    protected $signature = 'xp:reconcile
        {--kid= : Only reconcile one kid, by name}
        {--dry-run : Show what would change without saving anything}
        {--allow-decrease : Also lower XP when the rebuilt total is smaller}';

    protected $description = "Rebuild each kid's XP from approved chores, earned badges and XP chests, then mint any level-up tickets that fall out of it.";

    public function handle(): int
    {
        $kids = Profile::where('role', ProfileRole::Kid)->orderByDesc('age')->get();

        if ($name = $this->option('kid')) {
            $kids = $kids->filter(fn (Profile $kid) => strcasecmp($kid->name, $name) === 0)->values();

            if ($kids->isEmpty()) {
                $this->error("No kid named \"{$name}\" found.");

                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $allowDecrease = (bool) $this->option('allow-decrease');

        $rows = [];
        $changed = 0;

        foreach ($kids as $kid) {
            $breakdown = $this->breakdownFor($kid);
            $rebuilt = $breakdown['total'];
            $delta = $rebuilt - $kid->xp;

            // Raise-only by default. Taking a level back off a kid who has
            // already seen it is a worse outcome than leaving them a little
            // over-credited, so shrinking has to be asked for explicitly.
            $apply = $delta > 0 || ($allowDecrease && $delta < 0);

            $rows[] = [
                $kid->name,
                $kid->xp,
                'LVL '.$kid->level(),
                $breakdown['chores'],
                $breakdown['badges'],
                $breakdown['chests'],
                $breakdown['adjustment'] === 0 ? '—' : $breakdown['adjustment'],
                $rebuilt,
                $delta === 0 ? '—' : sprintf('%+d', $delta),
                $apply ? 'LVL '.$this->levelFor($rebuilt) : 'unchanged',
            ];

            if (! $apply || $dryRun) {
                continue;
            }

            DB::transaction(function () use ($kid, $rebuilt) {
                $kid->xp = $rebuilt;
                $kid->save();

                // XP isn't the whole reward — crossing a level mints a ticket,
                // and this is what pays out the ones just crossed. Idempotent,
                // so re-running the command can't double up.
                app(TicketService::class)->syncLevelTickets($kid);
            });

            $changed++;
        }

        $this->table(
            ['Kid', 'XP was', 'Level was', 'Chores', 'Badges', 'Chests', 'Curve', 'Rebuilt', 'Delta', 'Level now'],
            $rows,
        );

        $this->line('Chore XP valued at '.ChoreService::XP_PER_CHORE.' per approval.');

        if ($dryRun) {
            $this->warn('Dry run — nothing was saved.');

            return self::SUCCESS;
        }

        $this->info($changed === 0
            ? 'Every kid already matches. Nothing to do.'
            : "Reconciled {$changed} ".str('kid')->plural($changed).'.');

        return self::SUCCESS;
    }

    /**
     * Every XP source that leaves a durable record behind. ResetTodayCommand
     * deletes the completions it reverses, so counting approvals stays a
     * truthful reconstruction rather than double-counting undone days.
     *
     * @return array{chores: int, badges: int, chests: int, adjustment: int, total: int}
     */
    private function breakdownFor(Profile $kid): array
    {
        $approvals = ChoreCompletion::where('profile_id', $kid->id)
            ->where('status', CompletionStatus::Approved)
            ->count();

        $chores = $approvals * ChoreService::XP_PER_CHORE;
        $badges = (int) $kid->badges->sum('xp_reward');

        $chests = (int) DailyChest::where('profile_id', $kid->id)
            ->where('reward_kind', ChestService::KIND_XP)
            ->sum('reward_amount');

        // XP granted by a curve change rather than earned. It has no source
        // record to count, so it's carried on the profile — leaving it out
        // would make the rebuild look short by exactly the conversion and
        // hand back the levels the conversion existed to protect.
        $adjustment = (int) $kid->xp_adjustment;

        return [
            'chores' => $chores,
            'badges' => $badges,
            'chests' => $chests,
            'adjustment' => $adjustment,
            'total' => $chores + $badges + $chests + $adjustment,
        ];
    }

    private function levelFor(int $xp): int
    {
        return Profile::levelForXp($xp);
    }
}
