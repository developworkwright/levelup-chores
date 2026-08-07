<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyMystery extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'mystery_date',
        'chore_id',
        'found_by_profile_id',
        'found_at',
    ];

    protected function casts(): array
    {
        return [
            'mystery_date' => 'date',
            'found_at' => 'datetime',
        ];
    }

    /** Whether the race is over — set by a parent's approval, not by a claim. */
    public function isFound(): bool
    {
        return $this->found_by_profile_id !== null;
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function chore(): BelongsTo
    {
        return $this->belongsTo(Chore::class);
    }

    public function foundBy(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'found_by_profile_id');
    }
}
