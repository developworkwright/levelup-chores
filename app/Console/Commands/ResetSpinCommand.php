<?php

namespace App\Console\Commands;

use App\Enums\ProfileRole;
use App\Models\Profile;
use App\Services\SpinService;
use Illuminate\Console\Command;

class ResetSpinCommand extends Command
{
    protected $signature = 'wheel:reset-spin
        {--kid= : Only reset one kid, by name}
        {--dry-run : Show what would change without saving anything}';

    protected $description = "Clear today's bonus wheel spin so a kid can spin again — leaves points, chores, quests, and everything else untouched.";

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

        $spins = app(SpinService::class);

        foreach ($kids as $kid) {
            $spin = $dryRun ? $spins->today($kid) : $spins->clearToday($kid);

            $rows[] = [
                $kid->name,
                $spin ? "yes ({$spin->multiplier}x)" : 'no',
            ];
        }

        $this->table(['Kid', 'Spin cleared'], $rows);
        $this->info($dryRun ? 'Dry run — nothing was actually changed.' : 'Done.');

        return self::SUCCESS;
    }
}
