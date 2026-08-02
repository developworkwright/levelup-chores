<?php

namespace App\Models;

use App\Enums\SiblingOfferKind;
use App\Enums\SiblingOfferStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiblingOffer extends Model
{
    use HasFactory;

    /** How long a kid has to answer before the trade lapses. */
    public const LIFETIME_HOURS = 24;

    protected $fillable = [
        'household_id',
        'from_profile_id',
        'to_profile_id',
        'kind',
        'description',
        'points',
        'status',
        'expires_at',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => SiblingOfferKind::class,
            'status' => SiblingOfferStatus::class,
            'points' => 'integer',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function fromProfile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'from_profile_id');
    }

    public function toProfile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'to_profile_id');
    }

    /**
     * Still answerable. Every read path goes through this rather than checking
     * the status alone, so a row the expiry sweep has not reached yet can never
     * render as actionable.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', SiblingOfferStatus::Pending)
            ->where('expires_at', '>', now());
    }

    public function isLive(): bool
    {
        return $this->status === SiblingOfferStatus::Pending && $this->expires_at->isFuture();
    }

    /** Only a `Paying` offer takes points out of a balance up front. */
    public function isEscrowed(): bool
    {
        return $this->kind === SiblingOfferKind::Paying;
    }

    /** The kid whose balance the points come out of. */
    public function payer(): Profile
    {
        return $this->isEscrowed() ? $this->fromProfile : $this->toProfile;
    }

    /** The kid who does the favour and gets paid for it. */
    public function worker(): Profile
    {
        return $this->isEscrowed() ? $this->toProfile : $this->fromProfile;
    }
}
