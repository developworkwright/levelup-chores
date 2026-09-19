<?php

namespace App\Models;

use App\Enums\ArcadeGame;
use App\Enums\TokenKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One movement of a kid's arcade tokens. See App\Services\TokenService.
 */
class TokenEntry extends Model
{
    protected $fillable = [
        'household_id',
        'profile_id',
        'kind',
        'amount',
        'description',
        'game',
        'day',
        'related_type',
        'related_id',
    ];

    protected function casts(): array
    {
        return [
            'kind' => TokenKind::class,
            'game' => ArcadeGame::class,
            'amount' => 'integer',
            'day' => 'date',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
