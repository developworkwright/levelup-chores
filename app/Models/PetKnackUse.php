<?php

namespace App\Models;

use App\Enums\PetKnack;
use Illuminate\Database\Eloquent\Model;

/**
 * One use of a pet's knack — see App\Services\KnackService.
 */
class PetKnackUse extends Model
{
    protected $fillable = [
        'household_id',
        'profile_id',
        'cosmetic_id',
        'knack',
        'payload',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'knack' => PetKnack::class,
            'payload' => 'array',
            'used_at' => 'datetime',
        ];
    }
}
