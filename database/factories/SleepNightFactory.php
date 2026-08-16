<?php

namespace Database\Factories;

use App\Enums\SleepOutcome;
use App\Models\Household;
use App\Models\Profile;
use App\Models\SleepNight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SleepNight>
 */
class SleepNightFactory extends Factory
{
    protected $model = SleepNight::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'profile_id' => Profile::factory(),
            'night_date' => now()->toDateString(),
            'outcome' => SleepOutcome::OwnBed,
            'saved_at' => null,
        ];
    }

    public function missed(): self
    {
        return $this->state(['outcome' => SleepOutcome::Visited]);
    }
}
