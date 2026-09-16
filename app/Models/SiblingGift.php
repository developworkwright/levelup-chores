<?php

namespace App\Models;

use App\Services\GiftService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One kid's daily gift to a sibling — see {@see GiftService}.
 *
 * Announced to the house in the family feed's Everyone room, and pushed to
 * the recipient. `seen_at` is when the recipient opened the gift row on Home.
 */
class SiblingGift extends Model
{
    protected $fillable = [
        'household_id',
        'giver_id',
        'recipient_id',
        'gift_date',
        'seen_at',
    ];

    protected function casts(): array
    {
        return [
            'gift_date' => 'date',
            'seen_at' => 'datetime',
        ];
    }

    public function giver(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'giver_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'recipient_id');
    }
}
