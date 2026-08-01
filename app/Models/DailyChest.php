<?php

namespace App\Models;

use App\Enums\PerkEffect;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyChest extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'profile_id',
        'chest_date',
        'reward_kind',
        'reward_amount',
        'reward_effect',
        'quest_was_done',
    ];

    protected function casts(): array
    {
        return [
            'chest_date' => 'date',
            'reward_effect' => PerkEffect::class,
            'quest_was_done' => 'boolean',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
