<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One funny thing somebody in the house said.
 *
 * Nothing about a quote is ever ranked — see the migration for why there is no
 * winner. A day holding more than one simply holds contenders.
 */
class Quote extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'profile_id',
        'said_by',
        'text',
        'context',
        'said_on',
        'added_by_profile_id',
    ];

    protected function casts(): array
    {
        return [
            'said_on' => 'date',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** The kid who said it, when it was one. Null for anyone without a profile. */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'added_by_profile_id');
    }

    /** Who laughed. Eager-loaded everywhere a list of quotes is drawn. */
    public function reactions(): HasMany
    {
        return $this->hasMany(QuoteReaction::class);
    }

    /**
     * Whose mouth it came out of. Reads the live profile name first so a kid
     * renaming themselves renames their quotes too, and falls back to the
     * typed-in name for everyone who hasn't got a profile.
     */
    public function attribution(): string
    {
        return $this->profile?->name
            ?? (trim((string) $this->said_by) ?: 'Someone');
    }

    /**
     * Newest day first, and oldest-first *within* a day so the contenders read
     * in the order they were said rather than backwards.
     *
     * @param  Builder<self>  $query
     */
    public function scopeNewestDayFirst(Builder $query): void
    {
        $query->orderByDesc('said_on')->orderBy('id');
    }
}
