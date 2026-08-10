<?php

namespace App\Models;

use App\Enums\CompletionStatus;
use App\Enums\MonsterTier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChoreCompletion extends Model
{
    use HasFactory;

    protected $fillable = [
        'chore_id',
        'profile_id',
        'status',
        'points_awarded',
        'target_tier',
        'struck_weak_point',
        'submitted_at',
        'decided_at',
        'decided_by_profile_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => CompletionStatus::class,
            'target_tier' => MonsterTier::class,
            'struck_weak_point' => 'boolean',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function chore(): BelongsTo
    {
        return $this->belongsTo(Chore::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'decided_by_profile_id');
    }
}
