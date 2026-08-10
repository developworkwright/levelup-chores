<?php

namespace App\Models;

use App\Enums\BossSkin;
use App\Enums\MonsterTier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One family goal, drawn as a monster. Three stand at a time, one per tier.
 *
 * The health left on it is not stored — it is summed from the hits landed on
 * it, which is the same table the leaderboard underneath the bar is grouped
 * from. That is the point: a bar and a set of names that come from one query
 * cannot end up telling two different stories about the same fight.
 */
class Monster extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'tier',
        'battle',
        'skin',
        'reward_name',
        'reward_cost_cents',
        'max_health',
        'weak_chore_id',
        'weak_rotated_on',
        'started_at',
        'defeated_at',
        'finisher_profile_id',
        'contributions',
    ];

    protected function casts(): array
    {
        return [
            'tier' => MonsterTier::class,
            'skin' => BossSkin::class,
            'weak_rotated_on' => 'date',
            'started_at' => 'datetime',
            'defeated_at' => 'datetime',
            'contributions' => 'array',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function hits(): HasMany
    {
        return $this->hasMany(MonsterHit::class);
    }

    /** The chore it flinches at this week, if one is set. */
    public function weakChore(): BelongsTo
    {
        return $this->belongsTo(Chore::class, 'weak_chore_id');
    }

    public function finisher(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'finisher_profile_id');
    }

    /** Still standing. */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('defeated_at');
    }

    /** On the shelf. */
    public function scopeBeaten(Builder $query): void
    {
        $query->whereNotNull('defeated_at');
    }

    /**
     * Damage taken, floored at zero.
     *
     * Prefers a sum already loaded by the query — the arena draws three
     * monsters at once, and `withSum('hits', 'damage')` is what keeps that from
     * being three extra round trips. Floored because a parent can nudge a tier
     * back down past where it started, and a monster on negative damage would
     * render with a health bar longer than its own body.
     */
    public function damage(): int
    {
        // A null under a *present* key is withSum's answer for a monster
        // nothing has hit yet, so the key's existence is the test — reading the
        // value would send it back to the database for a zero it already has.
        if (array_key_exists('hits_sum_damage', $this->attributes)) {
            return max(0, (int) $this->attributes['hits_sum_damage']);
        }

        return max(0, (int) $this->hits()->sum('damage'));
    }

    public function healthLeft(): int
    {
        return max(0, $this->max_health - $this->damage());
    }

    /**
     * Beaten is a fact about the row, not about the numbers.
     *
     * Once a monster falls the celebration has fired and the reward is owed, so
     * a parent nudging its damage back down afterwards corrects a bar rather
     * than undoing a kill.
     */
    public function isDefeated(): bool
    {
        return $this->defeated_at !== null;
    }

    /** How long the family took to bring it down, for the trophy shelf. */
    public function daysTaken(): ?int
    {
        return $this->defeated_at === null
            ? null
            : (int) $this->started_at->diffInDays($this->defeated_at);
    }
}
