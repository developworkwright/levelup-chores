<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The week a limited item went on sale — its only week, ever. See the
 * cosmetic_drops migration.
 */
class CosmeticDrop extends Model
{
    protected $fillable = [
        'household_id',
        'cosmetic_id',
        'week',
    ];

    public function cosmetic(): BelongsTo
    {
        return $this->belongsTo(Cosmetic::class);
    }
}
