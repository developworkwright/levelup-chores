<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\StoreItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class StoreItemFactory extends Factory
{
    protected $model = StoreItem::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'cost' => 100,
            'color_tag' => 'lime',
            'min_level' => 0,
        ];
    }

    /** A reward a kid can see but not have until they reach `$level`. */
    public function lockedUntilLevel(int $level): self
    {
        return $this->state(fn () => ['min_level' => $level]);
    }
}
