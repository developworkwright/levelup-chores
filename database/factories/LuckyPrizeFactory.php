<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\LuckyPrize;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LuckyPrize>
 */
class LuckyPrizeFactory extends Factory
{
    protected $model = LuckyPrize::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'profile_id' => null,
            'name' => fake()->words(2, true),
            'flavor' => fake()->sentence(),
            'icon' => 'fa-solid fa-gift',
            'active' => true,
            'position' => 0,
        ];
    }

    /** Switched off — visible to the parent, never drawn. */
    public function inactive(): self
    {
        return $this->state(fn () => ['active' => false]);
    }

    /** Scoped to one kid rather than the whole house. */
    public function forKid(Profile $kid): self
    {
        return $this->state(fn () => [
            'household_id' => $kid->household_id,
            'profile_id' => $kid->id,
        ]);
    }
}
