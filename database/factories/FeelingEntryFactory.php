<?php

namespace Database\Factories;

use App\Enums\Feeling;
use App\Enums\FeelingVisibility;
use App\Models\FeelingEntry;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

class FeelingEntryFactory extends Factory
{
    protected $model = FeelingEntry::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'profile_id' => Profile::factory(),
            'felt_on' => now()->toDateString(),
            'feeling' => Feeling::Okay,
            'because' => null,
            'visibility' => FeelingVisibility::Private,
        ];
    }

    public function feeling(Feeling $feeling): self
    {
        return $this->state(fn () => ['feeling' => $feeling]);
    }

    /** A reason, and who may read it. */
    public function because(string $text, FeelingVisibility $visibility = FeelingVisibility::Private): self
    {
        return $this->state(fn () => [
            'because' => $text,
            'visibility' => $visibility,
        ]);
    }

    public function daysAgo(int $days): self
    {
        return $this->state(fn () => ['felt_on' => now()->subDays($days)->toDateString()]);
    }
}
