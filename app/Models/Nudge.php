<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One kid poking another about tonight's quest.
 *
 * Capped at one per nudger per target per household night. The point is the
 * public stamp on the target's lane, not a notification — a shared screen the
 * whole house is looking at does the work.
 */
class Nudge extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'from_profile_id',
        'to_profile_id',
        'quest_date',
    ];

    protected function casts(): array
    {
        return [
            'quest_date' => 'date',
        ];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'from_profile_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'to_profile_id');
    }
}
