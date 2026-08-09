<?php

namespace Database\Factories;

use App\Enums\SiblingOfferStatus;
use App\Enums\TradeAsset;
use App\Models\Household;
use App\Models\Profile;
use App\Models\SiblingOffer;
use App\Services\SiblingOfferService;
use Illuminate\Database\Eloquent\Factories\Factory;

class SiblingOfferFactory extends Factory
{
    protected $model = SiblingOffer::class;

    /**
     * A swap, because that is all a trade is now — work for pay moved to the
     * bounty board, and {@see SiblingOfferService::offer()}
     * refuses a favour on either side.
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'from_profile_id' => Profile::factory(),
            'to_profile_id' => Profile::factory(),
            'give_asset' => TradeAsset::Points,
            'give_amount' => 100,
            'get_asset' => TradeAsset::Tickets,
            'get_amount' => 1,
            'description' => null,
            'status' => SiblingOfferStatus::Pending,
            'expires_at' => now()->addHours(SiblingOffer::LIFETIME_HOURS),
        ];
    }

    /**
     * The recipient is the one paying points. Takes the amount rather than
     * reading it off the definition: `create()` attributes are applied after
     * states, so an override would arrive too late to move.
     */
    public function earning(int $points = 100): self
    {
        return $this->state([
            'give_asset' => TradeAsset::Tickets,
            'give_amount' => 1,
            'get_asset' => TradeAsset::Points,
            'get_amount' => $points,
        ]);
    }

    /** A swap priced explicitly on both sides. */
    public function swap(int $points = 100, int $tickets = 2): self
    {
        return $this->state([
            'give_asset' => TradeAsset::Points,
            'give_amount' => $points,
            'get_asset' => TradeAsset::Tickets,
            'get_amount' => $tickets,
            'description' => null,
        ]);
    }

    public function expired(): self
    {
        return $this->state(['expires_at' => now()->subHour()]);
    }
}
