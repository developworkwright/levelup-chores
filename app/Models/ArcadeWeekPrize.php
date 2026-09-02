<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One finished week of the arcade, settled.
 *
 * A row here means the week has been dealt with, not that anybody was paid —
 * see the migration for why those are different questions.
 */
class ArcadeWeekPrize extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'household_id',
        'week',
        'profile_id',
        'score',
        'tickets',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'tickets' => 'integer',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** The winner. Null for a week nobody played. */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
