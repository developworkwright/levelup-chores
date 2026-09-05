<?php

namespace App\Models;

use App\Enums\PerkEffect;
use App\Services\ChestService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One opened bonus chest.
 *
 * `quest_was_done` records which table the chest rolled on, and it means *any*
 * quest — the day's main one or any of the side quests on the board. The column
 * keeps its name because the app calls the board's chores side quests, but the
 * rule it stores is {@see ChestService::isBoosted()}, not the quest card's own
 * stamp.
 */
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
