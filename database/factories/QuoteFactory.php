<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'profile_id' => null,
            'said_by' => $this->faker->firstName(),
            'text' => $this->faker->sentence(),
            'context' => null,
            'said_on' => now()->toDateString(),
            'added_by_profile_id' => null,
        ];
    }

    /** Filed under an earlier household-day, for testing the archive. */
    public function daysAgo(int $days): self
    {
        return $this->state(fn () => ['said_on' => now()->subDays($days)->toDateString()]);
    }
}
