<?php

namespace App\Models;

use App\Enums\QuestCharmEffect;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyQuest extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'profile_id',
        'chore_id',
        'offered_chore_ids',
        'charmed_at',
        'charm_effect',
        'charm_payout_percent',
        'quest_date',
        'dealt_at',
        'revealed_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'offered_chore_ids' => 'array',
            'charmed_at' => 'datetime',
            'charm_effect' => QuestCharmEffect::class,
            'quest_date' => 'date',
            'dealt_at' => 'datetime',
            'revealed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** Whether a charm was spent on today's quest, whatever it has done so far. */
    public function isCharmed(): bool
    {
        return $this->charmed_at !== null;
    }

    /**
     * The hand dealt for the day, as chore ids in the order they're laid out.
     *
     * Null means a quest written before the hand existed — those days were a
     * single chore handed down, so that is the hand they get. Every read goes
     * through here rather than touching the column, so no caller has to know
     * the difference.
     *
     * @return array<int, int>
     */
    public function offeredChoreIds(): array
    {
        return $this->offered_chore_ids ?: [$this->chore_id];
    }

    /** Whether the kid has actually chosen, as opposed to holding a placeholder. */
    public function isPicked(): bool
    {
        return $this->revealed_at !== null;
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function chore(): BelongsTo
    {
        return $this->belongsTo(Chore::class);
    }
}
