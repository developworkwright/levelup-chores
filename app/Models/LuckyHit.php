<?php

namespace App\Models;

use App\Enums\ChoreIcon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hit of the Lucky Block: three tickets in, one prize out, waiting on a
 * grown-up to hand it over.
 *
 * The name and icon are stamped at the draw rather than read back through the
 * relation — a parent may rename or delete the prize afterwards, and what a
 * kid was told they won is not something an edit gets to rewrite.
 */
class LuckyHit extends Model
{
    protected $fillable = [
        'household_id',
        'profile_id',
        'lucky_prize_id',
        'prize_name',
        'prize_icon',
        'tickets_spent',
        'won_at',
        'fulfilled_at',
        'fulfilled_by_profile_id',
    ];

    protected function casts(): array
    {
        return [
            'won_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function luckyPrize(): BelongsTo
    {
        return $this->belongsTo(LuckyPrize::class);
    }

    public function fulfilledBy(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'fulfilled_by_profile_id');
    }

    /** @param  Builder<LuckyHit>  $query */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('fulfilled_at');
    }

    public function isPending(): bool
    {
        return $this->fulfilled_at === null;
    }

    public function iconClass(): string
    {
        return ChoreIcon::normalizeClass($this->prize_icon) ?? LuckyPrize::FALLBACK_ICON;
    }
}
