<?php

namespace App\Console\Commands;

use App\Enums\AccentColor;
use App\Enums\ProfileRole;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Console\Command;

class SaveKidCommand extends Command
{
    protected $signature = 'kid:save
        {name : First name — also the key used to find an existing profile}
        {--age= : Age in years}
        {--color= : Accent color (lime, cyan, gold, magenta, coral, violet)}
        {--pin= : 4-digit PIN. Defaults to '.self::DEFAULT_PIN.' on a new profile}';

    protected $description = 'Create a kid\'s profile, or update an existing one matched by first name.';

    /** Sane bounds for a chore-app profile; keeps a typo from creating a 130-year-old. */
    private const MIN_AGE = 1;

    private const MAX_AGE = 25;

    /** Starter PIN for a new profile — meant to be changed from Kids & Points. */
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

        // Matched in PHP rather than SQL so the lookup is case-insensitive on
        // every database, not just the ones with a case-insensitive collation.
        $kid = Profile::where('household_id', $household->id)
            ->where('role', ProfileRole::Kid)
            ->get()
            ->first(fn (Profile $profile) => strcasecmp($profile->name, $name) === 0);

        $creating = $kid === null;

        $age = $this->resolveAge();
        $color = $this->resolveColor();

        if ($age === false || $color === false) {
            return self::FAILURE;
        }

        $pin = $this->resolvePin($creating);

        if ($pin === false) {
            return self::FAILURE;
        }

        if ($creating && $age === null) {
            $this->error('An age is required when creating a profile. Pass --age=.');

            return self::FAILURE;
        }

        $before = $kid ? ['age' => $kid->age, 'color' => $kid->color->value] : null;

        if ($creating) {
            $kid = new Profile([
                'household_id' => $household->id,
                'name' => $name,
                'role' => ProfileRole::Kid,
                'age' => $age,
                'color' => $color ?? $this->nextFreeColor($household),
            ]);
        } else {
            if ($age !== null) {
                $kid->age = $age;
            }

            if ($color !== null) {
                $kid->color = $color;
            }
        }

        if ($pin !== null) {
            $kid->setPin($pin);
            // A new PIN clears any standing lockout, matching the parent console.
            $kid->resetPinAttempts();
        }

        $kid->save();

        $this->table(
            ['Field', $creating ? 'Created' : 'Updated'],
            [
                ['Name', $kid->name],
                ['Age', $this->describeChange($before['age'] ?? null, $kid->age)],
                ['Color', $this->describeChange($before['color'] ?? null, $kid->color->value)],
                ['PIN', $this->describePin($pin, $creating)],
            ],
        );

        if ($creating) {
            $this->info("{$kid->name} can now log in from the profile picker.");

            if ($pin === self::DEFAULT_PIN) {
                $this->warn('Using the default PIN — change it from Kids & Points in the parent console.');
            }
        } else {
            $this->info("{$kid->name} updated.");
        }

        return self::SUCCESS;
    }

    /**
     * @return int|false|null The age, null when not supplied, or false when invalid.
     */
    private function resolveAge(): int|false|null
    {
        $age = $this->option('age');

        if ($age === null) {
            return null;
        }

        if (! ctype_digit((string) $age) || (int) $age < self::MIN_AGE || (int) $age > self::MAX_AGE) {
            $this->error('Age must be a whole number between '.self::MIN_AGE.' and '.self::MAX_AGE.'.');

            return false;
        }

        return (int) $age;
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

        // Parent is reserved for the console profile, so it's not offered here.
        if (! $case || $case === AccentColor::Parent) {
            $names = implode(', ', array_map(fn (AccentColor $c) => $c->value, self::kidColors()));
            $this->error("Color must be one of: {$names}.");

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

        if ($pin === null || $pin === '') {
            // A new profile needs something to log in with; an existing one
            // already has a PIN, so leave it alone unless asked.
            return $creating ? self::DEFAULT_PIN : null;
        }

        if (! preg_match('/^\d{4}$/', (string) $pin)) {
            $this->error('PIN must be exactly 4 digits.');

            return false;
        }

        return (string) $pin;
    }

    /** First palette color nobody in the household is using, so kids stay visually distinct. */
    private function nextFreeColor(Household $household): AccentColor
    {
        $taken = Profile::where('household_id', $household->id)
            ->get()
            ->map(fn (Profile $profile) => $profile->color->value)
            ->all();

        foreach (self::kidColors() as $color) {
            if (! in_array($color->value, $taken, true)) {
                return $color;
            }
        }

        return self::kidColors()[0];
    }

    /** @return array<int, AccentColor> */
    private static function kidColors(): array
    {
        return array_values(array_filter(
            AccentColor::cases(),
            fn (AccentColor $color) => $color !== AccentColor::Parent,
        ));
    }

    private function describePin(?string $pin, bool $creating): string
    {
        if ($pin === null) {
            return 'unchanged';
        }

        if ($creating) {
            return $pin === self::DEFAULT_PIN ? self::DEFAULT_PIN.' (default)' : 'set';
        }

        return 'changed';
    }

    private function describeChange(int|string|null $before, int|string $after): string
    {
        if ($before === null || (string) $before === (string) $after) {
            return (string) $after;
        }

        return "{$before} → {$after}";
    }
}
