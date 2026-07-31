<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StreakRepair extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'profile_id',
        'repaired_date',
    ];

    protected function casts(): array
    {
        return [
            'repaired_date' => 'date',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
