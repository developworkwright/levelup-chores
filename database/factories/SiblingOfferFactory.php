<?php

namespace Database\Factories;

use App\Enums\SiblingOfferKind;
use App\Enums\SiblingOfferStatus;
use App\Models\Household;
use App\Models\Profile;
use App\Models\SiblingOffer;
use Illuminate\Database\Eloquent\Factories\Factory;

class SiblingOfferFactory extends Factory
{
    protected $model = SiblingOffer::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'from_profile_id' => Profile::factory(),
            'to_profile_id' => Profile::factory(),
            'kind' => SiblingOfferKind::Paying,
            'description' => 'Play a game with me for 30 minutes',
            'points' => 100,
            'status' => SiblingOfferStatus::Pending,
            'expires_at' => now()->addHours(SiblingOffer::LIFETIME_HOURS),
        ];
    }

    public function earning(): self
    {
        return $this->state(['kind' => SiblingOfferKind::Earning]);
    }

    public function expired(): self
    {
        return $this->state(['expires_at' => now()->subHour()]);
    }
}
