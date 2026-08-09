<?php

namespace Database\Factories;

use App\Enums\BountyKind;
use App\Enums\BountyStatus;
use App\Enums\TradeAsset;
use App\Models\Bounty;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bounty>
 */
class BountyFactory extends Factory
{
    protected $model = Bounty::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'poster_profile_id' => Profile::factory(),
            'kind' => BountyKind::Wanted,
            'reward_asset' => TradeAsset::Points,
            'reward_amount' => 100,
            'description' => 'Make my bed',
            'status' => BountyStatus::Open,
            'expires_at' => now()->addHours(Bounty::OPEN_HOURS),
        ];
    }

    public function offered(): self
    {
        return $this->state([
            'kind' => BountyKind::Offered,
            'description' => 'Wash the car',
            'reward_amount' => 200,
        ]);
    }
}
