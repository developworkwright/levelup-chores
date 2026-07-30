<?php

namespace App\Models;

use App\Enums\AccentColor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'name',
        'description',
        'cost',
        'color_tag',
    ];

    protected function casts(): array
    {
        return [
            'color_tag' => AccentColor::class,
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }
}
