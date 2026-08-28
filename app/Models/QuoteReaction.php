<?php

namespace App\Models;

use App\Enums\ReactionKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One kid's tap on one quote.
 *
 * Not a vote — see the migration. Never updated either: a reaction is added or
 * taken away, so there is no `updated_at` to keep.
 */
class QuoteReaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'quote_id',
        'profile_id',
        'reaction',
    ];

    protected function casts(): array
    {
        return [
            'reaction' => ReactionKind::class,
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
