<?php

namespace App\Models;

use App\Enums\CandyOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sweets a kid bought at the counter, waiting for a grown-up to hand them
 * over. Name, price and colour are copies — see the candy migration.
 */
class CandyOrder extends Model
{
    protected $fillable = [
        'household_id',
        'profile_id',
        'candy_id',
        'name',
        'tokens',
        'hue',
        'status',
        'decided_by_profile_id',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CandyOrderStatus::class,
            'tokens' => 'integer',
            'hue' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function candy(): BelongsTo
    {
        return $this->belongsTo(Candy::class);
    }

    public function isWaiting(): bool
    {
        return $this->status === CandyOrderStatus::Waiting;
    }
}
