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
        'evening_watch_hour',
        'weekly_chore_target',
        'weekly_prize',
        'weekly_prize_note',
        'points_per_dollar',
        'spin_enabled',
        'sleep_card_enabled',
        'sleep_constellation_points',
        'sleep_points_own_bed',
        'sleep_points_visited',
        'sleep_points_rough',
        'lucky_hold_won',
    ];

    protected function casts(): array
    {
        return [
            'spin_enabled' => 'boolean',
            'sleep_card_enabled' => 'boolean',
            'lucky_hold_won' => 'boolean',
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

    /** The Lucky Block's prize pool — see LuckyBlockService. */
    public function luckyPrizes(): HasMany
    {
        return $this->hasMany(LuckyPrize::class);
    }

    /** The arena: every monster this family has faced, living or beaten. */
    public function monsters(): HasMany
    {
        return $this->hasMany(Monster::class);
    }
}
