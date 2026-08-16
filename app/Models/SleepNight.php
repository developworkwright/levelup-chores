<?php

namespace App\Models;

use App\Enums\SleepOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One night, as answered. See the create migration for why this is a record
 * rather than the score.
 */
class SleepNight extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'profile_id',
        'night_date',
        'outcome',
        'saved_at',
    ];

    protected function casts(): array
    {
        return [
            'night_date' => 'date',
            'outcome' => SleepOutcome::class,
            'saved_at' => 'datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** Whether a Night Saver has already been spent on this one. */
    public function wasSaved(): bool
    {
        return $this->saved_at !== null;
    }
}
