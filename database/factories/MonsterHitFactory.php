<?php

namespace Database\Factories;

use App\Enums\MonsterHitKind;
use App\Models\Monster;
use App\Models\MonsterHit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonsterHit>
 */
class MonsterHitFactory extends Factory
{
    protected $model = MonsterHit::class;

    public function definition(): array
    {
        $monster = Monster::factory();

        return [
            'household_id' => fn (array $attributes) => Monster::find($attributes['monster_id'])?->household_id,
            'monster_id' => $monster,
            'chore_completion_id' => null,
            'profile_id' => null,
            'damage' => 50,
            'kind' => MonsterHitKind::Hit,
        ];
    }
}
