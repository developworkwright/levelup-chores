<?php

namespace App\Models;

use App\Enums\SiblingOfferStatus;
use App\Enums\TradeAsset;
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
        'give_asset',
        'give_amount',
        'get_asset',
        'get_amount',
        'description',
        'status',
        'expires_at',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'give_asset' => TradeAsset::class,
            'give_amount' => 'integer',
            'get_asset' => TradeAsset::class,
            'get_amount' => 'integer',
            'status' => SiblingOfferStatus::class,
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

    /*
     * `isEscrowed()` and `isSwap()` lived here to tell a favour trade from a
     * currency one. Every offer is now a currency swap with the sender's side
     * held, so both answered true for every row that could exist — a question
     * with one answer is worse than no question.
     */

    /** What the sender puts up, as it reads on a card. */
    public function giveText(): string
    {
        return $this->give_asset === TradeAsset::Favour
            ? (string) $this->description
            : $this->give_asset->format($this->give_amount);
    }

    /** What the sender wants back, as it reads on a card. */
    public function getText(): string
    {
        return $this->get_asset === TradeAsset::Favour
            ? (string) $this->description
            : $this->get_asset->format($this->get_amount);
    }

    /**
     * The whole trade in one line from the sender's side — "100 pts for the
     * dishes". Ledger and ticket entries are labelled with this, so a parent
     * reading the activity feed sees both halves of what was agreed.
     */
    public function summary(): string
    {
        return "{$this->giveText()} for {$this->getText()}";
    }

    /**
     * How much short the recipient is of the side they would have to hand over.
     * Zero when they are asked for a favour rather than a balance.
     */
    public function shortfallFor(Profile $profile): int
    {
        if (! $this->get_asset->isCurrency()) {
            return 0;
        }

        return max(0, $this->get_amount - $profile->balanceOf($this->get_asset));
    }
}
