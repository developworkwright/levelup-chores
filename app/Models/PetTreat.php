<?php

namespace App\Models;

use App\Enums\PetKnack;
use Illuminate\Database\Eloquent\Model;

/**
 * A Power Treat fed to a pet — see the pet_treats migration and
 * App\Services\KnackService.
 */
class PetTreat extends Model
{
    protected $fillable = [
        'household_id',
        'profile_id',
        'cosmetic_id',
        'knack',
        'tickets_paid',
        'day',
        'knack_use_id',
    ];

    protected function casts(): array
    {
        return [
            'knack' => PetKnack::class,
            'tickets_paid' => 'integer',
            'day' => 'date',
        ];
    }

    /** Whether it is still waiting to pay for a use. */
    public function isBanked(): bool
    {
        return $this->knack_use_id === null;
    }
}
