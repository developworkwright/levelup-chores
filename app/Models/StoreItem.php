<?php

namespace App\Models;

use App\Enums\AccentColor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'name',
        'description',
        'cost',
        'color_tag',
        'min_level',
    ];

    protected function casts(): array
    {
        return [
            'color_tag' => AccentColor::class,
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }

    /**
     * Whether a level stands between this reward and the kid looking at it.
     *
     * Locked rewards stay on the shelf rather than being filtered out — the
     * whole point of the gate is that a kid can see what they're climbing
     * towards.
     */
    public function isLockedFor(Profile $profile): bool
    {
        return $profile->level() < $this->min_level;
    }

    /**
     * Columns free-text search looks at.
     *
     * Wider than Chore::SEARCHABLE deliberately — a reward's description is
     * where the actual thing lives ("a trip out for ice cream" under a name
     * like "Sweet Friday"), so a kid hunting for it by the obvious word has to
     * be able to find it.
     *
     * @var array<int, string>
     */
    public const SEARCHABLE = ['name', 'description'];

    /**
     * Free-text search across a reward's searchable columns.
     *
     * In-memory rather than a scope: the catalog is a single household's worth
     * of rows, already loaded and grouped into shelves by the time the filter
     * applies, so a second query would buy nothing.
     */
    public function matches(?string $term): bool
    {
        $term = trim((string) $term);

        if ($term === '') {
            return true;
        }

        $needle = mb_strtolower($term);

        foreach (self::SEARCHABLE as $column) {
            if (str_contains(mb_strtolower((string) $this->{$column}), $needle)) {
                return true;
            }
        }

        return false;
    }
}
