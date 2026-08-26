<?php

namespace Database\Factories;

use App\Enums\BossSkin;
use App\Models\Household;
use App\Models\Monster;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Monster>
 */
class MonsterFactory extends Factory
{
    protected $model = Monster::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'battle' => 1,
            'skin' => fake()->randomElement(BossSkin::cases()),
            'reward_name' => 'Ice cream outing',
            'reward_cost_cents' => 1500,
            'max_health' => 500,
            'weak_chore_id' => null,
            'weak_rotated_on' => null,
            'started_at' => now(),
            'defeated_at' => null,
            'finisher_profile_id' => null,
            'contributions' => null,
        ];
    }

    /** On the shelf, with the leaderboard already frozen onto it. */
    public function beaten(array $contributions = []): static
    {
        return $this->state(fn () => [
            'defeated_at' => now(),
            'contributions' => $contributions,
        ]);
    }
}
