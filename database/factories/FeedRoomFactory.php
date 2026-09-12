<?php

namespace Database\Factories;

use App\Enums\FeedRoomKind;
use App\Models\FeedRoom;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

class FeedRoomFactory extends Factory
{
    protected $model = FeedRoom::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'kind' => FeedRoomKind::Everyone,
        ];
    }

    public function kids(): self
    {
        return $this->state(fn () => ['kind' => FeedRoomKind::Kids]);
    }

    /** A kid's line to the grown-ups. `$profileId` is whose it is. */
    public function parents(int $profileId): self
    {
        return $this->state(fn () => [
            'kind' => FeedRoomKind::Parents,
            'for_profile_id' => $profileId,
        ]);
    }

    /**
     * A one-to-one. Membership still has to be written — see
     * FeedService::directRoomWith(), which is what tests should normally use.
     */
    public function direct(): self
    {
        return $this->state(fn () => ['kind' => FeedRoomKind::Direct]);
    }
}
