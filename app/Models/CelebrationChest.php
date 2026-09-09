<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One kid's row for one celebration day: what they said about it, and what the
 * chest paid. See the create migration for why the answer never touches the
 * payout.
 */
class CelebrationChest extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'profile_id',
        'celebration_key',
        'answer',
        'note',
        'answered_at',
        'opened_at',
        'reward_points',
        'reward_tickets',
        'reward_xp',
    ];

    protected function casts(): array
    {
        return [
            'answered_at' => 'datetime',
            'opened_at' => 'datetime',
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

    public function isAnswered(): bool
    {
        return $this->answered_at !== null;
    }

    public function isOpened(): bool
    {
        return $this->opened_at !== null;
    }
}
