<?php

namespace Database\Factories;

use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProfileFactory extends Factory
{
    protected $model = \App\Models\Profile::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => fake()->firstName(),
            'role' => 'kid',
            'age' => fake()->numberBetween(6, 15),
            'color' => 'cyan',
            'pin_hash' => bcrypt('1234'),
            'points' => 0,
            'xp' => 0,
            'streak' => 0,
        ];
    }

    public function parent(): self
    {
        return $this->state([
            'role' => 'parent',
            'age' => null,
            'color' => 'parent',
        ]);
    }
}
