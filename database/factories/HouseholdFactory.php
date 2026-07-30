<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class HouseholdFactory extends Factory
{
    protected $model = \App\Models\Household::class;

    public function definition(): array
    {
        return [
            'name' => fake()->lastName().' Household',
            'timezone' => 'America/Chicago',
            'day_boundary_hour' => 4,
            'points_per_dollar' => 100,
            'require_quest_first' => true,
            'spin_enabled' => true,
            'goal_name' => 'Family goal',
            'goal_target' => 1000,
            'goal_now' => 0,
        ];
    }
}
