<?php

namespace App\Models;

use App\Enums\BossSkin;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One monster, beaten. Written by BossService the moment an approval crosses
 * the family goal, and never updated afterwards — the trophy shelf is history.
 */
class BossDefeat extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'boss_key',
        'boss_name',
        'battle',
        'health',
        'goal_name',
        'started_at',
        'defeated_at',
        'finisher_profile_id',
        'contributions',
    ];

    protected function casts(): array
    {
        return [
            'boss_key' => BossSkin::class,
            'started_at' => 'datetime',
            'defeated_at' => 'datetime',
            'contributions' => 'array',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function finisher(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'finisher_profile_id');
    }

    /**
     * How long the family took to bring it down, or null for a battle whose
     * start was never stamped (a household that predates the feature).
     */
    public function daysTaken(): ?int
    {
        return $this->started_at?->diffInDays($this->defeated_at);
    }
}
