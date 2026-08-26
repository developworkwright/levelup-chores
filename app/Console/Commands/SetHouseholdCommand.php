<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Services\HouseholdClock;
use DateTimeZone;
use Illuminate\Console\Command;

class SetHouseholdCommand extends Command
{
    protected $signature = 'household:set
        {--name= : Display name for the household}
        {--timezone= : IANA timezone, e.g. America/New_York}
        {--day-boundary-hour= : Hour (0-23) the household day rolls over}
        {--points-per-dollar= : How many points are worth $1}
        {--spin-enabled= : true|false — turn the bonus wheel on or off}';

    protected $description = 'Show or change household settings. Run with no options to just show them.';

    public function handle(): int
    {
        $household = Household::first();

        if (! $household) {
            $this->error('No household exists yet. Run `php artisan migrate --seed` first.');

            return self::FAILURE;
        }

        $changes = $this->collectChanges();

        if ($changes === false) {
            return self::FAILURE;
        }

        $previousHour = $household->day_boundary_hour;

        if ($changes !== []) {
            $household->fill($changes)->save();
        }

        $this->showSettings($household);

        if ($changes === []) {
            $this->comment('Nothing changed. Pass options to update — see --help for the list.');

            return self::SUCCESS;
        }

        $newHour = $changes['day_boundary_hour'] ?? null;

        if ($newHour !== null && $newHour > $previousHour) {
            // A later boundary starts today later, pushing the hours in
            // between into yesterday — which hands a kid a second run at a
            // chore they already did this morning.
            $this->warn(sprintf(
                'Moving the boundary later shortens today — a chore claimed between %02d:00 and %02d:00 '.
                'now counts as yesterday, so it can come off cooldown and be claimed again.',
                $previousHour,
                $newHour,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|false The columns to update, or false when an option was invalid.
     */
    private function collectChanges(): array|false
    {
        $changes = [];

        if (($name = $this->option('name')) !== null) {
            if (trim($name) === '') {
                $this->error('Name cannot be empty.');

                return false;
            }

            $changes['name'] = trim($name);
        }

        if (($timezone = $this->option('timezone')) !== null) {
            if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
                $this->error("\"{$timezone}\" is not a valid IANA timezone. Expected something like America/New_York.");

                return false;
            }

            $changes['timezone'] = $timezone;
        }

        if (($hour = $this->option('day-boundary-hour')) !== null) {
            if (! ctype_digit((string) $hour) || (int) $hour > 23) {
                $this->error('Day boundary hour must be a whole number between 0 and 23.');

                return false;
            }

            $changes['day_boundary_hour'] = (int) $hour;
        }

        if (($rate = $this->option('points-per-dollar')) !== null) {
            if (! ctype_digit((string) $rate) || (int) $rate < 1) {
                $this->error('Points per dollar must be a whole number of at least 1.');

                return false;
            }

            $changes['points_per_dollar'] = (int) $rate;
        }

        foreach (['spin-enabled' => 'spin_enabled'] as $option => $column) {
            $raw = $this->option($option);

            if ($raw === null) {
                continue;
            }

            $value = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($value === null) {
                $this->error("--{$option} must be true or false.");

                return false;
            }

            $changes[$column] = $value;
        }

        return $changes;
    }

    private function showSettings(Household $household): void
    {
        $clock = HouseholdClock::for($household);

        $this->table(['Setting', 'Value'], [
            ['Name', $household->name],
            ['Timezone', $household->timezone],
            ['Day resets at', sprintf('%02d:00', $household->day_boundary_hour)],
            ['Points per dollar', $household->points_per_dollar],
            ['Bonus wheel', $household->spin_enabled ? 'enabled' : 'disabled'],
        ]);

        // The whole point of the command: make the abstract boundary hour
        // concrete, so nobody has to do timezone arithmetic in their head.
        $this->line(sprintf(
            '  It is <options=bold>%s</> in this household, and chores currently count toward <options=bold>%s</>.',
            $clock->now()->format('D j M Y, H:i T'),
            $clock->today()->toFormattedDateString(),
        ));
    }
}
