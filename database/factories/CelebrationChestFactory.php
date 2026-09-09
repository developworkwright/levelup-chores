<?php

namespace Database\Factories;

use App\Models\CelebrationChest;
use App\Models\Household;
use App\Models\Profile;
use App\Services\CelebrationService;
use Illuminate\Database\Eloquent\Factories\Factory;

class CelebrationChestFactory extends Factory
{
    protected $model = CelebrationChest::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'profile_id' => Profile::factory(),
            'celebration_key' => array_key_first(CelebrationService::DAYS),
            'answer' => null,
            'note' => null,
            'answered_at' => null,
            'opened_at' => null,
        ];
    }

    /** Answered, but the chest not yet taken. */
    public function answered(string $answer = 'hard', ?string $note = null): self
    {
        return $this->state(fn () => [
            'answer' => $answer,
            'note' => $note,
            'answered_at' => now(),
        ]);
    }
}
