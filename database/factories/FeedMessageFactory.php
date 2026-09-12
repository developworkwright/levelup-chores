<?php

namespace Database\Factories;

use App\Enums\FeedMessageKind;
use App\Models\FeedMessage;
use App\Models\FeedRoom;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

class FeedMessageFactory extends Factory
{
    protected $model = FeedMessage::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'room_id' => FeedRoom::factory(),
            'profile_id' => Profile::factory(),
            'kind' => FeedMessageKind::Text,
            'body' => $this->faker->sentence(),
        ];
    }

    public function event(string $body): self
    {
        return $this->state(fn () => [
            'kind' => FeedMessageKind::Event,
            'body' => $body,
        ]);
    }
}
