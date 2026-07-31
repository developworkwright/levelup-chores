<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MysteryHintPurchase extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'profile_id',
        'hint_date',
    ];

    protected function casts(): array
    {
        return [
            'hint_date' => 'date',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
