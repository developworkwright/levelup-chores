<?php

namespace Database\Factories;

use App\Models\Chore;
use App\Models\Profile;
use App\Models\RainCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RainCheck>
 */
class RainCheckFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'spin_id' => 0,
            'chore_id' => Chore::factory(),
            'multiplier' => fake()->randomElement([2, 3]),
            'for_date' => now()->addDay()->toDateString(),
        ];
    }
}
