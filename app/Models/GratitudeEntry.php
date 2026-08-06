<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GratitudeEntry extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'household_id',
        'profile_id',
        'entry_date',
        'items',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'items' => 'array',
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
}
