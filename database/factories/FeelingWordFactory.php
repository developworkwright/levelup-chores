<?php

namespace Database\Factories;

use App\Models\FeelingWord;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

class FeelingWordFactory extends Factory
{
    protected $model = FeelingWord::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'profile_id' => Profile::factory(),
            'label' => 'Wobbly',
            'glyph' => null,
            'active' => true,
        ];
    }

    public function retired(): self
    {
        return $this->state(fn () => ['active' => false]);
    }
}
