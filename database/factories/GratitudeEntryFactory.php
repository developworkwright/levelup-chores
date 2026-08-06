<?php

namespace Database\Factories;

use App\Models\GratitudeEntry;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

class GratitudeEntryFactory extends Factory
{
    protected $model = GratitudeEntry::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'profile_id' => Profile::factory(),
            'entry_date' => now()->toDateString(),
            'items' => [
                'My dog',
                'Pancakes for breakfast',
                'My best friend at school',
            ],
        ];
    }

    /** Written on an earlier household-day, for testing the journal. */
    public function daysAgo(int $days): self
    {
        return $this->state(fn () => ['entry_date' => now()->subDays($days)->toDateString()]);
    }
}
