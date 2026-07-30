<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyQuest extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'profile_id',
        'chore_id',
        'quest_date',
        'revealed_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'quest_date' => 'date',
            'revealed_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function chore(): BelongsTo
    {
        return $this->belongsTo(Chore::class);
    }
}
