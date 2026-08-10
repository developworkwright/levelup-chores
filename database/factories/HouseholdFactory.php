<?php

namespace Database\Factories;

use App\Enums\PerkEffect;
use App\Models\BonusPerk;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

class HouseholdFactory extends Factory
{
    protected $model = Household::class;

    public function definition(): array
    {
        return [
            'name' => fake()->lastName().' Household',
            'timezone' => 'America/Chicago',
            'day_boundary_hour' => 4,
            'points_per_dollar' => 100,
            'require_quest_first' => true,
            'spin_enabled' => true,
        ];
    }

    /**
     * The migration can only seed the perk catalogue for households that
     * already exist, and tests migrate an empty database — so a household
     * built here has to bring its own, exactly as the seeder does.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Household $household) {
            foreach (PerkEffect::cases() as $effect) {
                BonusPerk::firstOrCreate(
                    ['household_id' => $household->id, 'effect' => $effect],
                    $effect->defaults(),
                );
            }
        });
    }
}
