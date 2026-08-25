<?php

namespace App\Models;

use App\Enums\AccentColor;
use App\Enums\LootCategory;
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
        'icon',
        'category',
        'url',
        'cost',
        'color_tag',
        'min_level',
    ];

    protected function casts(): array
    {
        return [
            'color_tag' => AccentColor::class,
            // `icon` is deliberately not cast: the column holds a Font Awesome
            // class string and the presets are a shortlist, not the limit of
            // what a parent may type. Same rule as chores.icon.
            'category' => LootCategory::class,
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

    public function favorites(): HasMany
    {
        return $this->hasMany(LootFavorite::class);
    }

    /**
     * A pasted link, reduced to something safe to hand a child.
     *
     * **http and https only, and nothing else ever.** This column is written
     * by a parent and rendered to a kid as a live link, and the whole family
     * shares one tablet — a `javascript:` or `data:` URL saved here, by
     * accident or by a sibling who found the console, would run on tap. A
     * scheme allow-list is the only check that closes that; validating the
     * shape of the rest would not.
     *
     * A bare `shop.com/thing` gets `https://` rather than being refused: it is
     * what people paste, and a link that silently didn't save is the sort of
     * thing a parent gives up on rather than debugs.
     */
    public static function normalizeUrl(?string $input): ?string
    {
        $url = trim((string) $input);

        if ($url === '') {
            return null;
        }

        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://'.$url;
        }

        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        // A scheme with no host behind it is a typo, not a destination.
        return parse_url($url, PHP_URL_HOST) ? mb_substr($url, 0, 2048) : null;
    }

    /**
     * Whether this landed on the shelf since a kid last looked at the shop.
     *
     * A null marker means they have never looked, which reads as *everything*
     * being new — the right answer for a kid opening a restocked shop for the
     * first time, and the same convention `badges_seen_at` uses.
     */
    public function isNewTo(Profile $profile): bool
    {
        return $profile->loot_seen_at === null
            || $this->created_at?->greaterThan($profile->loot_seen_at) === true;
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
