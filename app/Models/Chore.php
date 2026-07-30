<?php

namespace App\Models;

use App\Enums\ChoreCadence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chore extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'name',
        'points',
        'cadence',
        'min_age',
        'quest_eligible',
    ];

    protected function casts(): array
    {
        return [
            'cadence' => ChoreCadence::class,
            'quest_eligible' => 'boolean',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function completions(): HasMany
    {
        return $this->hasMany(ChoreCompletion::class);
    }

    public function cooldownDays(): int
    {
        return $this->cadence === ChoreCadence::Weekly ? 7 : 1;
    }

    public function isAppropriateFor(Profile $profile): bool
    {
        return $this->min_age === null || ($profile->age !== null && $profile->age >= $this->min_age);
    }

    public function scopeAppropriateFor(Builder $query, Profile $profile): Builder
    {
        return $query->where(function (Builder $q) use ($profile) {
            $q->whereNull('min_age')->orWhere('min_age', '<=', $profile->age ?? 0);
        });
    }

    public function scopeQuestEligible(Builder $query): Builder
    {
        return $query->where('quest_eligible', true);
    }
}
