<?php

namespace App\Models;

use App\Enums\PrizeSlot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A snack, toy or bed a kid bought for their pet at the prize counter. Owned
 * forever; which one is out lives on the profile. See PrizeCounterService.
 */
class PetPrize extends Model
{
    protected $fillable = [
        'household_id',
        'profile_id',
        'slot',
        'key',
        'tokens_paid',
    ];

    protected function casts(): array
    {
        return [
            'slot' => PrizeSlot::class,
            'tokens_paid' => 'integer',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
