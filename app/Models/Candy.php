<?php

namespace App\Models;

use Database\Factories\CandyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A real sweet a grown-up put on the prize counter. See the candy migration.
 */
class Candy extends Model
{
    /** @use HasFactory<CandyFactory> */
    use HasFactory;

    protected $fillable = [
        'household_id',
        'name',
        'tokens',
        'stock',
        'hue',
        'retired_at',
    ];

    protected function casts(): array
    {
        return [
            'tokens' => 'integer',
            'stock' => 'integer',
            'hue' => 'integer',
            'retired_at' => 'datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * On the counter: not taken off it. A sweet with none left in the cupboard
     * is still on it, marked as gone, so a kid can see it will come back.
     *
     * @param  Builder<Candy>  $query
     */
    public function scopeOnCounter(Builder $query): void
    {
        $query->whereNull('retired_at');
    }

    public function isInStock(): bool
    {
        return $this->stock > 0;
    }
}
