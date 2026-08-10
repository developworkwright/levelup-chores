<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Household extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'timezone',
        'day_boundary_hour',
        'points_per_dollar',
        'require_quest_first',
        'spin_enabled',
    ];

    protected function casts(): array
    {
        return [
            'require_quest_first' => 'boolean',
            'spin_enabled' => 'boolean',
        ];
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(Profile::class);
    }

    public function chores(): HasMany
    {
        return $this->hasMany(Chore::class);
    }

    public function storeItems(): HasMany
    {
        return $this->hasMany(StoreItem::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /** The arena: every monster this family has faced, living or beaten. */
    public function monsters(): HasMany
    {
        return $this->hasMany(Monster::class);
    }
}
