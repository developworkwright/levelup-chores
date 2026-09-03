<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProfileFactory extends Factory
{
    protected $model = Profile::class;

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
            /*
             * Already been to the arcade, so a factory profile carries no news
             * it never asked for. Null here would mean every game is new to
             * every profile in the suite, and any test counting what a kid has
             * waiting would be counting games it has never heard of — which is
             * how this default was found.
             *
             * The same line the migration draws for profiles that existed
             * before the marker did. A test about the flash sets the column
             * itself; see ArcadeNewGameTest.
             */
            'arcade_seen_at' => now(),
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
