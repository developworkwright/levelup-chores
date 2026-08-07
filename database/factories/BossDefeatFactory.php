<?php

namespace Database\Factories;

use App\Enums\BossSkin;
use App\Models\BossDefeat;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BossDefeat>
 */
class BossDefeatFactory extends Factory
{
    protected $model = BossDefeat::class;

    public function definition(): array
    {
        $skin = fake()->randomElement(BossSkin::cases());

        return [
            'household_id' => Household::factory(),
            'boss_key' => $skin,
            'boss_name' => $skin->label(),
            'battle' => 1,
            'health' => 1000,
            'goal_name' => 'Family goal',
            'started_at' => now()->subDays(9),
            'defeated_at' => now(),
            'finisher_profile_id' => null,
            'contributions' => [],
        ];
    }
}
