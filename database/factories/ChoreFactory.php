<?php

namespace Database\Factories;

use App\Models\Chore;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChoreFactory extends Factory
{
    protected $model = Chore::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => fake()->words(2, true),
            'points' => 100,
            'cadence' => 'daily',
        ];
    }
}
