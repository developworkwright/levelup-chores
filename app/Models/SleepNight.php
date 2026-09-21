<?php

namespace App\Models;

use App\Enums\SleepBand;
use App\Enums\SleepOutcome;
use App\Services\NightWindow;
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
        'asleep_minute',
        'awake_minute',
        'saved_at',
    ];

    protected function casts(): array
    {
        return [
            'night_date' => 'date',
            'outcome' => SleepOutcome::class,
            'minutes' => 'integer',
            'asleep_minute' => 'integer',
            'awake_minute' => 'integer',
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
     *
     * Takes the hours the night covered as well as its length: a night has to
     * cover all of midnight-to-6am to be a full one, and at least four hours
     * of it to pay at all. A row answered before the card asked *when* has no
     * times, and is read as having covered the window — the rule arrived after
     * those nights were slept and must not demote them now.
     */
    public function band(): ?SleepBand
    {
        return $this->minutes === null
            ? null
            : SleepBand::fromNight($this->minutes, $this->coreOverlap() ?? NightWindow::CORE_LENGTH);
    }

    /**
     * How much of midnight-to-6am the night was asleep for, or null when the
     * row doesn't say — an own-bed row, or an hours row from before the card
     * asked.
     */
    public function coreOverlap(): ?int
    {
        if ($this->asleep_minute === null || $this->awake_minute === null) {
            return null;
        }

        return NightWindow::overlapOf($this->asleep_minute, $this->awake_minute);
    }

    /** Whether the night was asleep right across midnight to 6am. */
    public function coversCoreHours(): ?bool
    {
        $overlap = $this->coreOverlap();

        return $overlap === null ? null : $overlap === NightWindow::CORE_LENGTH;
    }

    /**
     * Whether the hours rather than the length decided the band — a long night
     * outside the window, either dropped to a short one or, with less than
     * four hours inside it, paid nothing at all.
     *
     * The one answer the card has to explain, since a kid who slept eight
     * hours and was told it was a short night deserves to know why.
     */
    public function missedCoreHours(): bool
    {
        return $this->minutes !== null
            && $this->band() !== SleepBand::fromMinutes($this->minutes);
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
