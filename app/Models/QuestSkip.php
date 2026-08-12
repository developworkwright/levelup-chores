<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A day a kid bought off. The board opens without the quest being done and the
 * streak treats the day as kept — see ChoreService::questApprovedOn(), which
 * reads these alongside streak repairs.
 */
class QuestSkip extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'profile_id',
        'skip_date',
    ];

    protected function casts(): array
    {
        return [
            'skip_date' => 'date',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
