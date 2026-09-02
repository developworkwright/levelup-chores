<?php

namespace App\Console\Commands;

use App\Enums\AccentColor;
use App\Enums\ProfileRole;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Console\Command;

/**
 * The parent-side counterpart to `kid:save`.
 *
 * Deliberately a command and not a screen. Kids come and go from a household
 * over years and their profiles get edited constantly, which is why they have a
 * console; the grown-ups are set up once and never again, and a permanent piece
 * of UI for a thing done twice in the life of the app is a worse trade than
 * running this.
 *
 * There is no delete. Removing a parent would orphan everything they approved,
 * wrote or replied to, and the one time it is genuinely wanted is rare enough to
 * be worth doing deliberately rather than behind a flag that could be mistyped.
 */
class SaveParentCommand extends Command
{
    protected $signature = 'parent:save
        {name : First name — also the key used to find an existing profile}
        {--color= : Accent color (lime, cyan, gold, magenta, coral, violet, parent)}
        {--pin= : 4-digit PIN. Defaults to '.self::DEFAULT_PIN.' on a new profile}
        {--rename-from= : Rename this existing parent instead of creating a new one}';

    protected $description = 'Create a parent login, or update an existing one matched by first name.';

    /** Starter PIN for a new profile — change it with --pin as soon as it exists. */
    private const DEFAULT_PIN = '1111';

    public function handle(): int
    {
        $household = Household::first();

        if (! $household) {
            $this->error('No household exists yet. Run `php artisan migrate --seed` first.');

            return self::FAILURE;
        }

        $name = trim((string) $this->argument('name'));

        if ($name === '') {
            $this->error('A name is required.');

            return self::FAILURE;
        }

        $parent = $this->findParent($household, (string) $this->option('rename-from') ?: $name);

        if ($this->option('rename-from') && ! $parent) {
            $this->error("No parent named \"{$this->option('rename-from')}\" to rename.");

            return self::FAILURE;
        }

        $creating = $parent === null;

        // A second parent called the same thing as the first would make the
        // profile picker unusable and this command ambiguous forever after.
        //
        // Checked against whoever currently holds the name rather than against
        // `$creating`: a rename has already matched a profile, so a guard that
        // only ran when creating would happily rename "Parent" to "Mom" beside
        // the "Mom" that was already there.
        $clash = $this->findParent($household, $name);

        if ($clash && ! $clash->is($parent)) {
            $this->error("A parent named \"{$name}\" already exists.");

            return self::FAILURE;
        }

        $color = $this->resolveColor();

        if ($color === false) {
            return self::FAILURE;
        }

        $pin = $this->resolvePin($creating);

        if ($pin === false) {
            return self::FAILURE;
        }

        $before = $parent ? ['name' => $parent->name, 'color' => $parent->color->value] : null;

        if ($creating) {
            $parent = new Profile([
                'household_id' => $household->id,
                'name' => $name,
                'role' => ProfileRole::Parent,
                // The shared console color unless told otherwise. Two parents
                // are easier to tell apart with their own, which is the whole
                // reason this command exists.
                'color' => $color ?? AccentColor::Parent,
            ]);
        } else {
            // Renaming keeps the PIN, the profile id and everything attached to
            // it — approvals, quotes, replies — which is the point of renaming
            // rather than making a new one and abandoning the old.
            $parent->name = $name;

            if ($color !== null) {
                $parent->color = $color;
            }
        }

        if ($pin !== null) {
            $parent->setPin($pin);
            $parent->resetPinAttempts();
        }

        $parent->save();

        $this->table(
            ['Field', $creating ? 'Created' : 'Updated'],
            [
                ['Name', $this->describeChange($before['name'] ?? null, $parent->name)],
                ['Color', $this->describeChange($before['color'] ?? null, $parent->color->value)],
                ['PIN', $pin === null ? 'unchanged' : ($creating && $pin === self::DEFAULT_PIN ? self::DEFAULT_PIN.' (default)' : 'set')],
            ],
        );

        if ($creating) {
            $this->info("{$parent->name} can now log in from the profile picker.");

            if ($pin === self::DEFAULT_PIN) {
                $this->warn('Using the default PIN — set a real one with: php artisan parent:save '.$parent->name.' --pin=####');
            }
        } else {
            $this->info("{$parent->name} updated.");
        }

        return self::SUCCESS;
    }

    /**
     * Matched in PHP rather than SQL so the lookup is case-insensitive on every
     * database, not only the ones with a case-insensitive collation — same
     * reason `kid:save` does it this way.
     */
    private function findParent(Household $household, string $name): ?Profile
    {
        return Profile::where('household_id', $household->id)
            ->where('role', ProfileRole::Parent)
            ->get()
            ->first(fn (Profile $profile) => strcasecmp($profile->name, $name) === 0);
    }

    /**
     * @return AccentColor|false|null The color, null when not supplied, or false when invalid.
     */
    private function resolveColor(): AccentColor|false|null
    {
        $color = $this->option('color');

        if ($color === null) {
            return null;
        }

        $case = AccentColor::tryFrom(strtolower((string) $color));

        if (! $case) {
            $this->error('Color must be one of: '.implode(', ', array_column(AccentColor::cases(), 'value')).'.');

            return false;
        }

        return $case;
    }

    /**
     * @return string|false|null The PIN, null to leave it alone, or false when invalid.
     */
    private function resolvePin(bool $creating): string|false|null
    {
        $pin = $this->option('pin');

        if ($pin === null) {
            return $creating ? self::DEFAULT_PIN : null;
        }

        $pin = (string) $pin;

        if (! preg_match('/^\d{4}$/', $pin)) {
            $this->error('PIN must be exactly 4 digits.');

            return false;
        }

        return $pin;
    }

    private function describeChange(?string $before, ?string $after): string
    {
        if ($before === null || $before === $after) {
            return (string) $after;
        }

        return "{$before} → {$after}";
    }
}
