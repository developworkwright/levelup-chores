<?php

namespace App\Models;

use App\Enums\AccentColor;
use App\Enums\MonsterTier;
use App\Enums\ProfileRole;
use App\Enums\TradeAsset;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use NotificationChannels\WebPush\HasPushSubscriptions;

class Profile extends Model implements Authenticatable
{
    use AuthenticatableTrait, HasFactory, HasPushSubscriptions, Notifiable;

    /**
     * How many consecutive failed PIN attempts trigger a lockout.
     */
    public const LOCKOUT_THRESHOLD = 5;

    /**
     * Base lockout duration; doubles for each additional lockout threshold crossed, capped below.
     */
    public const LOCKOUT_BASE_MINUTES = 15;

    public const LOCKOUT_MAX_MINUTES = 240;

    protected $fillable = [
        'household_id',
        'name',
        'role',
        'age',
        'color',
        'pin_hash',
        'points',
        'xp',
        'bonus_tickets',
        'tickets_granted_through_level',
        'streak',
        'pending_streak_chest',
        'badges_seen_at',
        'level_seen',
        'pending_mystery_celebration',
        'saving_for_store_item_id',
        'daily_points_goal',
        'last_monster_tier',
        'monsters_seen',
        'pending_monster_kills',
    ];

    protected $hidden = [
        'pin_hash',
    ];

    protected function casts(): array
    {
        return [
            'role' => ProfileRole::class,
            'color' => AccentColor::class,
            'last_monster_tier' => MonsterTier::class,
            'monsters_seen' => 'array',
            'pending_monster_kills' => 'array',
            'locked_until' => 'datetime',
            'badges_seen_at' => 'datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** The reward this kid is saving toward, pinned atop their Loot Shop. */
    public function savingFor(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class, 'saving_for_store_item_id');
    }

    public function choreCompletions(): HasMany
    {
        return $this->hasMany(ChoreCompletion::class);
    }

    public function dailyQuests(): HasMany
    {
        return $this->hasMany(DailyQuest::class);
    }

    public function spins(): HasMany
    {
        return $this->hasMany(Spin::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function bonusTicketEntries(): HasMany
    {
        return $this->hasMany(BonusTicketEntry::class);
    }

    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(Badge::class, 'profile_badges')->withPivot('earned_at');
    }

    /**
     * The balance a trade side is priced against. Only ever asked about a
     * currency — {@see TradeAsset::isCurrency()} gates every caller — so a
     * favour answering zero is a formality, not a balance of nothing.
     */
    public function balanceOf(TradeAsset $asset): int
    {
        return match ($asset) {
            TradeAsset::Points => $this->points,
            TradeAsset::Tickets => $this->bonus_tickets,
            TradeAsset::Favour => 0,
        };
    }

    public function siblingOffersSent(): HasMany
    {
        return $this->hasMany(SiblingOffer::class, 'from_profile_id');
    }

    public function siblingOffersReceived(): HasMany
    {
        return $this->hasMany(SiblingOffer::class, 'to_profile_id');
    }

    /**
     * The other kids in the household — who a sibling offer can be pointed at.
     * Parents are excluded: these are kid-to-kid trades.
     *
     * @return Collection<int, self>
     */
    public function siblings(): Collection
    {
        return self::where('household_id', $this->household_id)
            ->where('role', ProfileRole::Kid)
            ->whereKeyNot($this->getKey())
            ->orderBy('name')
            ->get();
    }

    public function isParent(): bool
    {
        return $this->role === ProfileRole::Parent;
    }

    public function isKid(): bool
    {
        return $this->role === ProfileRole::Kid;
    }

    /** Flat curve on purpose — "200 XP is a level" is something a six-year-old can hold. */
    public const XP_PER_LEVEL = 200;

    public function level(): int
    {
        return 1 + intdiv($this->xp, self::XP_PER_LEVEL);
    }

    public function xpBarPercent(): float
    {
        return ($this->xp % self::XP_PER_LEVEL) / (self::XP_PER_LEVEL / 100);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function checkPin(string $pin): bool
    {
        return Hash::check($pin, $this->pin_hash);
    }

    public function setPin(string $pin): void
    {
        $this->pin_hash = Hash::make($pin);
    }

    public function resetPinAttempts(): void
    {
        $this->failed_pin_attempts = 0;
        $this->locked_until = null;
    }

    /**
     * Record a failed PIN attempt, locking the profile out with an
     * exponentially increasing duration once the threshold is crossed.
     */
    public function recordFailedPinAttempt(): void
    {
        $this->failed_pin_attempts++;

        if ($this->failed_pin_attempts % self::LOCKOUT_THRESHOLD === 0) {
            $lockoutsTriggered = intdiv($this->failed_pin_attempts, self::LOCKOUT_THRESHOLD);
            $minutes = min(
                self::LOCKOUT_BASE_MINUTES * (2 ** ($lockoutsTriggered - 1)),
                self::LOCKOUT_MAX_MINUTES
            );
            $this->locked_until = Carbon::now()->addMinutes($minutes);
        }
    }
}
