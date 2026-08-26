<?php

namespace App\Models;

use App\Enums\PerkEffect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnedPerk extends Model
{
    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    /** Where a perk came from — the shop, a daily chest, or a parent. */
    public const SOURCE_SHOP = 'shop';

    public const SOURCE_CHEST = 'chest';

    /**
     * Handed over from the Kids console. Kept apart from the shop's own source
     * so a gifted perk can never be read back as tickets the kid spent.
     */
    public const SOURCE_GIFT = 'gift';

    protected $fillable = [
        'profile_id',
        'effect',
        'source',
        'acquired_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'effect' => PerkEffect::class,
            'acquired_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function scopeUnused(Builder $query): Builder
    {
        return $query->whereNull('consumed_at');
    }

    public function isUsed(): bool
    {
        return $this->consumed_at !== null;
    }
}
