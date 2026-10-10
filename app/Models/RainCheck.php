<?php

namespace App\Models;

use Database\Factories\RainCheckFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A wheel boost saved for a later day. Copied off the spin rather than
 * pointing at it for its chore and multiplier, since a respin deletes the spin.
 */
class RainCheck extends Model
{
    /** @use HasFactory<RainCheckFactory> */
    use HasFactory;

    protected $fillable = [
        'profile_id',
        'spin_id',
        'chore_id',
        'multiplier',
        'for_date',
    ];

    protected function casts(): array
    {
        return [
            'for_date' => 'date',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function chore(): BelongsTo
    {
        return $this->belongsTo(Chore::class);
    }
}
