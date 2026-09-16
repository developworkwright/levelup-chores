<?php

namespace App\Models;

use App\Services\ChoreService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One chore a Quest Charm lit up, for one kid, for one household day.
 *
 * A row per chore rather than a charm row holding a list, because every read
 * is "is this chore charmed for this kid today" — the board asks it once per
 * render for the whole list, and a claim asks it for one chore. A JSON column
 * of ids would make both of those a decode.
 *
 * Deliberately per-kid. The charm is bought with one child's tickets and
 * changes what the board pays *them*; a sibling looking at the same chore sees
 * ordinary points, which is what stops a charm being a gift to the whole house.
 *
 * Nothing ever deletes these. The date is the expiry — see
 * {@see ChoreService::charmedChoreIdsFor()} — and the history is
 * what the engagement report counts.
 */
class CharmedChore extends Model
{
    protected $fillable = [
        'profile_id',
        'chore_id',
        'charm_date',
    ];

    protected function casts(): array
    {
        return [
            'charm_date' => 'date',
        ];
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
