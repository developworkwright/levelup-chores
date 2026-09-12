<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GratitudeEntry extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'household_id',
        'profile_id',
        'entry_date',
        'items',
        'shared',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'items' => 'array',
            'shared' => 'boolean',
        ];
    }

    /**
     * Whether `$viewer` may read this list.
     *
     * Shared by default and opted out of per entry — the opposite of
     * FeelingVisibility, and deliberately so; the migration says why at length.
     * The writer always sees their own, whatever they chose.
     */
    public function visibleTo(Profile $viewer): bool
    {
        if ((int) $viewer->household_id !== (int) $this->household_id) {
            return false;
        }

        return $this->shared || (int) $viewer->id === (int) $this->profile_id;
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
