<?php

namespace App\Models;

use App\Enums\SleepBand;
use App\Enums\SleepOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One night, as answered. See the create migration for why this is a record
 * rather than the score.
 *
 * Two shapes in one table: an own-bed row carries an `outcome`, an hours row
 * carries `minutes`, and neither carries both. Kept together so a kid's sleep
 * history reads as one list across the graduation from one card to the other
 * rather than stopping and starting again.
 */
class SleepNight extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'profile_id',
        'night_date',
        'outcome',
        'minutes',
        'saved_at',
    ];

    protected function casts(): array
    {
        return [
            'night_date' => 'date',
            'outcome' => SleepOutcome::class,
            'minutes' => 'integer',
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

    /**
     * The band an hours row falls in, or null on an own-bed row. Derived every
     * time rather than stored — see {@see SleepBand}.
     */
    public function band(): ?SleepBand
    {
        return $this->minutes === null ? null : SleepBand::fromMinutes($this->minutes);
    }

    /**
     * Whether this night advanced the run, whichever card recorded it.
     *
     * The one question both card types have to answer, and what lets the
     * service share the run arithmetic between them instead of keeping two
     * copies that can disagree.
     */
    public function counted(): bool
    {
        return $this->band()?->counts()
            ?? $this->outcome?->countsAsOwnBed()
            ?? false;
    }

    /** Whether a Night Saver has already been spent on this one. */
    public function wasSaved(): bool
    {
        return $this->saved_at !== null;
    }
}
