<?php

namespace App\Models;

use App\Enums\ArcadeGame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One finished week of one arcade game, settled.
 *
 * A row here means that week has been dealt with *for that game*, not that
 * anybody was paid — see the migration for why those are different questions,
 * and for why the unique key had to grow a third column when the second
 * game arrived.
 */
class ArcadeWeekPrize extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'household_id',
        'week',
        'game',
        'profile_id',
        'score',
        'tickets',
        'tokens',
        'paid_profile_id',
        'seen_at',
    ];

    protected function casts(): array
    {
        return [
            'game' => ArcadeGame::class,
            'score' => 'integer',
            'tickets' => 'integer',
            'tokens' => 'integer',
            'seen_at' => 'datetime',
        ];
    }

    /**
     * Who the week's tokens went to. Usually the winner; the best-placed kid
     * below them when a grown-up took the week. Null when nobody was paid.
     */
    public function paidProfile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'paid_profile_id');
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** The winner. Null for a week nobody played. */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
