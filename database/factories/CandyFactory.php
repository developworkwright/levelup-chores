<?php

namespace Database\Factories;

use App\Models\Candy;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

class CandyFactory extends Factory
{
    protected $model = Candy::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => fake()->randomElement(['Haribo bag', 'Freddo', 'Ice lolly', 'Chocolate buttons']),
            'tokens' => 30,
            'stock' => 3,
            'hue' => 330,
        ];
    }

    /** None left in the cupboard. */
    public function soldOut(): self
    {
        return $this->state(fn () => ['stock' => 0]);
    }

    /** Taken off the counter by a grown-up. */
    public function retired(): self
    {
        return $this->state(fn () => ['retired_at' => now()]);
    }
}
