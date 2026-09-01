<?php

namespace Database\Factories;

use App\Models\Playlist;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Playlist>
 */
class PlaylistFactory extends Factory
{
    protected $model = Playlist::class;

    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            // Two words, because names collide case-insensitively and a single
            // faker word repeats often enough to fail a test on its own.
            'name' => $this->faker->unique()->words(2, true),
        ];
    }
}
