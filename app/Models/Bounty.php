<?php

namespace App\Models;

use App\Enums\BountyKind;
use App\Enums\BountyStatus;
use App\Enums\TradeAsset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One job on the board. Either a kid paying for work or offering to do it —
 * see {@see BountyKind}.
 *
 * The two roles that matter are worker and payer, and neither is a column:
 * both are read off the kind, so a row can never disagree with itself about
 * who owes whom.
 */
class Bounty extends Model
{
    use HasFactory;

    /** How long an untaken bounty sits on the board. */
    public const OPEN_HOURS = 48;

    /** How long a taker has to report the work done before it goes back up. */
    public const CLAIM_HOURS = 24;

    /** How long the payer has to answer before it settles itself. */
    public const CONFIRM_HOURS = 24;

    public const MAX_DESCRIPTION = 120;

    protected $fillable = [
        'household_id',
        'poster_profile_id',
        'target_profile_id',
        'kind',
        'reward_asset',
        'reward_amount',
        'description',
        'status',
        'claimed_by_profile_id',
        'claimed_at',
        'done_at',
        'settled_at',
        'hired_chore_id',
        'expires_at',
        'claim_expires_at',
        'auto_release_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => BountyKind::class,
            'reward_asset' => TradeAsset::class,
            'reward_amount' => 'integer',
            'status' => BountyStatus::class,
            'claimed_at' => 'datetime',
            'done_at' => 'datetime',
            'settled_at' => 'datetime',
            'expires_at' => 'datetime',
            'claim_expires_at' => 'datetime',
            'auto_release_at' => 'datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'poster_profile_id');
    }

    public function claimant(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'claimed_by_profile_id');
    }

    /** The one sibling this job is aimed at, or null for the open board. */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'target_profile_id');
    }

    public function hiredChore(): BelongsTo
    {
        return $this->belongsTo(Chore::class, 'hired_chore_id');
    }

    /**
     * Whoever is doing the job. On a wanted bounty that is whoever took it; on
     * an offered one it is the kid who posted it. Null until taken, for a
     * wanted bounty that nobody has picked up.
     */
    public function worker(): ?Profile
    {
        return $this->kind->posterPays() ? $this->claimant : $this->poster;
    }

    /** Whoever hands over the reward. The mirror of {@see self::worker()}. */
    public function payer(): ?Profile
    {
        return $this->kind->posterPays() ? $this->poster : $this->claimant;
    }

    public function isWorker(Profile $profile): bool
    {
        return $this->worker()?->is($profile) ?? false;
    }

    public function isPayer(Profile $profile): bool
    {
        return $this->payer()?->is($profile) ?? false;
    }

    /**
     * Still on the board and not out of time. Every read path goes through
     * this rather than the status alone, so a row the sweep has not reached yet
     * can't render as takeable.
     */
    public function scopeTakeable(Builder $query): Builder
    {
        return $query->where('status', BountyStatus::Open)
            ->where('expires_at', '>', now());
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [BountyStatus::Open, BountyStatus::Claimed, BountyStatus::Done]);
    }

    public function isTakeable(): bool
    {
        return $this->status === BountyStatus::Open && $this->expires_at?->isFuture();
    }

    /** Aimed at one sibling rather than posted to the whole household. */
    public function isTargeted(): bool
    {
        return $this->target_profile_id !== null;
    }

    /**
     * Whether this kid is allowed to take this job on. A targeted job is
     * between two people; everything else is a race.
     */
    public function isOpenTo(Profile $profile): bool
    {
        if ($this->poster_profile_id === $profile->id) {
            return false;
        }

        return ! $this->isTargeted() || $this->target_profile_id === $profile->id;
    }

    /**
     * Whether this kid should see it at all. A job aimed at somebody else is
     * none of their business — the two of them are doing a deal.
     */
    public function isVisibleTo(Profile $profile): bool
    {
        return ! $this->isTargeted()
            || $this->target_profile_id === $profile->id
            || $this->poster_profile_id === $profile->id;
    }

    /** The reward as it reads on a card: "100 pts", "2 tickets". */
    public function rewardText(): string
    {
        return $this->reward_asset->format($this->reward_amount);
    }

    /**
     * The deal in one line from the poster's side. Ledger and ticket entries
     * carry this, so a parent reading the activity feed sees what was agreed
     * rather than just a number moving between two kids.
     */
    public function summary(): string
    {
        return $this->kind->posterPays()
            ? "{$this->rewardText()} for \"{$this->description}\""
            : "\"{$this->description}\" for {$this->rewardText()}";
    }

    /** How much short a kid is of taking on the paying side of this. */
    public function shortfallFor(Profile $profile): int
    {
        return max(0, $this->reward_amount - $profile->balanceOf($this->reward_asset));
    }
}
