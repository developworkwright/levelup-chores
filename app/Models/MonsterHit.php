<?php

namespace App\Models;

use App\Enums\MonsterHitKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One blow landed on one monster.
 *
 * Written by MonsterService and never edited: correcting a mis-aimed chore
 * deletes its rows and lands them again on the monster the kid meant, so the
 * feed always reads as what actually happened rather than what was later
 * decided to have happened.
 */
class MonsterHit extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'monster_id',
        'chore_completion_id',
        'profile_id',
        'damage',
        'kind',
    ];

    protected function casts(): array
    {
        return [
            'kind' => MonsterHitKind::class,
        ];
    }

    public function monster(): BelongsTo
    {
        return $this->belongsTo(Monster::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function completion(): BelongsTo
    {
        return $this->belongsTo(ChoreCompletion::class, 'chore_completion_id');
    }

    /** The rows that count toward a kid's share of a kill. */
    public function scopeEarned(Builder $query): void
    {
        $query->where('kind', '!=', MonsterHitKind::Adjust)->whereNotNull('profile_id');
    }
}
