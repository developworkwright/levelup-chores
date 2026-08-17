<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A night one kid bought to keep another kid's run alive.
 *
 * Counts toward the run exactly as a {@see StreakRepair} does, and toward the
 * milestone ladder not at all — see `ChoreService::refreshStreak()`. Nothing
 * anywhere may describe a rescued night as earned.
 */
class StreakRescue extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'profile_id',
        'rescued_by_profile_id',
        'rescued_date',
        'tickets_paid',
    ];

    protected function casts(): array
    {
        return [
            'rescued_date' => 'date',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function rescuedBy(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'rescued_by_profile_id');
    }
}
