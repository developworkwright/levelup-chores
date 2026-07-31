<?php

namespace App\Models;

use App\Enums\TicketKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BonusTicketEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'household_id',
        'profile_id',
        'kind',
        'amount',
        'description',
        'related_type',
        'related_id',
    ];

    protected function casts(): array
    {
        return [
            'kind' => TicketKind::class,
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
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
