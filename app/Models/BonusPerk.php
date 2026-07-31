<?php

namespace App\Models;

use App\Enums\PerkEffect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BonusPerk extends Model
{
    protected $fillable = [
        'household_id',
        'effect',
        'name',
        'description',
        'cost',
        'glyph',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'effect' => PerkEffect::class,
            'enabled' => 'boolean',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }
}
