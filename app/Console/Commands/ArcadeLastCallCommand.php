<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Services\ArcadeService;
use Illuminate\Console\Command;

/**
 * The Sunday-evening last call, a few hours before bedtime.
 *
 * Run hourly, not on a fixed Sunday evening slot. The hour a household should
 * get this at is its own — a few hours before its own bedtime, on its own
 * clock — so the schedule's job is only to offer every household an hourly
 * chance to say "now", and `ArcadeService::isLastCallDue()` is what answers.
 * A single weekly slot would be right for exactly one timezone.
 *
 * Sending is exactly-once per household per week regardless, so an hourly
 * command that runs twice, or a catch-up run after an outage, cannot double up.
 */
class ArcadeLastCallCommand extends Command
{
    protected $signature = 'arcade:last-call
        {--household= : Only this household, by id}
        {--force : Send now, ignoring the timing and once-a-week gates}';

    protected $description = 'Push the arcade last call to any household whose early evening it is on the last day of the board week.';

    public function handle(ArcadeService $arcade): int
    {
        $households = Household::query()
            ->when($this->option('household'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        if ($households->isEmpty()) {
            $this->error('No households found.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $total = 0;

        foreach ($households as $household) {
            $sent = $arcade->sendLastCall($household, $force);
            $total += $sent;

            if ($sent > 0) {
                $this->line("  {$household->name}: sent to {$sent} kid(s).");
            }
        }

        // Silent on the hours it has nothing to do, which is almost all of
        // them — an hourly command that logs a line every time is a log nobody
        // reads by Wednesday.
        if ($total > 0) {
            $this->info("Arcade last call sent to {$total} kid(s).");
        }

        return self::SUCCESS;
    }
}
