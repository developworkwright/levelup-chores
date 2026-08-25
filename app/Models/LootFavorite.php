<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reward a kid has starred.
 *
 * Deliberately thin. The interesting half of "favorites" — what they buy over
 * and over — is derived from `redemptions` rather than stored, so this table
 * only ever holds the wish.
 */
class LootFavorite extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'profile_id',
        'store_item_id',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function storeItem(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class);
    }
}
