<?php

namespace App\Models;

use App\Enums\BossSkin;
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
        'goal_name',
        'goal_target',
        'goal_now',
        'boss_key',
        'boss_started_at',
        'boss_battle',
    ];

    protected function casts(): array
    {
        return [
            'require_quest_first' => 'boolean',
            'spin_enabled' => 'boolean',
            'boss_key' => BossSkin::class,
            'boss_started_at' => 'datetime',
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

    /** The trophy shelf: every monster this family has already put down. */
    public function bossDefeats(): HasMany
    {
        return $this->hasMany(BossDefeat::class);
    }
}
