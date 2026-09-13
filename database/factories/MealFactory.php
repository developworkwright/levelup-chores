<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Meal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Meal>
 */
class MealFactory extends Factory
{
    protected $model = Meal::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'served_on' => now()->toDateString(),
            'name' => fake()->randomElement(['Tacos', 'Spaghetti', 'Roast chicken', 'Stir fry', 'Pizza']),
            'note' => null,
            'set_by_profile_id' => null,
        ];
    }

    /** Dinner on a particular household day. */
    public function on(Carbon|string $date): self
    {
        return $this->state(fn () => [
            'served_on' => $date instanceof Carbon ? $date->toDateString() : $date,
        ]);
    }
}
