<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cosmetic a kid bought. Written once, never deleted — owned is forever.
 * Free house items have no row; everybody owns those.
 */
class OwnedCosmetic extends Model
{
    protected $fillable = [
        'household_id',
        'profile_id',
        'cosmetic_id',
        'tickets_paid',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function cosmetic(): BelongsTo
    {
        return $this->belongsTo(Cosmetic::class);
    }
}
