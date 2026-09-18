<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A surprise egg — see the pet_eggs migrations and App\Services\PetService.
 *
 * Every egg-only pet is one egg in the shop, in a colour of its own; which pet
 * is inside is the surprise.
 */
class PetEgg extends Model
{
    /** What an egg costs, in tickets. */
    public const PRICE = 15;

    /** Approved chores it takes to hatch — one crack each. */
    public const CRACKS_TO_HATCH = 5;

    protected $fillable = [
        'household_id',
        'profile_id',
        'cosmetic_id',
        'tickets_paid',
        'cracks',
        'hatched_cosmetic_id',
        'hatched_at',
        'revealed_at',
    ];

    protected function casts(): array
    {
        return [
            'tickets_paid' => 'integer',
            'cracks' => 'integer',
            'hatched_at' => 'datetime',
            'revealed_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** The pet inside, chosen when the egg was bought. */
    public function pet(): BelongsTo
    {
        return $this->belongsTo(Cosmetic::class, 'cosmetic_id');
    }

    public function hatchedInto(): BelongsTo
    {
        return $this->belongsTo(Cosmetic::class, 'hatched_cosmetic_id');
    }

    public function isHatched(): bool
    {
        return $this->hatched_at !== null;
    }

    /** Chores still to go before it hatches. */
    public function choresToHatch(): int
    {
        return max(0, self::CRACKS_TO_HATCH - $this->cracks);
    }

    /** This egg's colour — the colour of the pet inside it. */
    public function hue(): int
    {
        return self::hueFor($this->cosmetic_id ?? 0);
    }

    /**
     * The colour of the egg a pet comes in, as a hue.
     *
     * Stepped round the colour wheel by the golden angle from the pet's id, so
     * the eggs in a shop come out spread across the wheel rather than bunched,
     * and an egg keeps its colour from the shelf to the kid's page to the day
     * it hatches. Nothing about it gives away what is inside.
     */
    public static function hueFor(int $cosmeticId): int
    {
        return (int) round(fmod($cosmeticId * 137.508, 360));
    }

    /** What a kid calls that colour. */
    public static function colourName(int $hue): string
    {
        return match (true) {
            $hue < 15, $hue >= 345 => 'Red',
            $hue < 40 => 'Orange',
            $hue < 65 => 'Gold',
            $hue < 160 => 'Green',
            $hue < 195 => 'Teal',
            $hue < 250 => 'Blue',
            $hue < 290 => 'Purple',
            default => 'Pink',
        };
    }
}
