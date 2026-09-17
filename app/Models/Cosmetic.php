<?php

namespace App\Models;

use App\Enums\CosmeticEffect;
use App\Enums\CosmeticFlavor;
use App\Enums\CosmeticMotion;
use App\Enums\CosmeticSlot;
use App\Enums\CosmeticStock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a kid can wear — see the cosmetics migration.
 *
 * Drawn one of two ways. A catalog item names a `recipe` in
 * resources/js/cosmetics.js, which generates the art in the browser, so the
 * catalog costs no storage however large it grows. An uploaded item carries
 * `art_path` instead. `art_path ?? recipe` is the whole rule, and both kinds
 * sit in the same grid at the same prices.
 */
class Cosmetic extends Model
{
    protected $fillable = [
        'household_id',
        'slot',
        'recipe',
        'art_path',
        'name',
        'cost',
        'stock',
        'flavor',
        'motion',
        'effect',
        'checks',
        'published_at',
        'pulled_at',
    ];

    protected function casts(): array
    {
        return [
            'slot' => CosmeticSlot::class,
            'stock' => CosmeticStock::class,
            'flavor' => CosmeticFlavor::class,
            'motion' => CosmeticMotion::class,
            'effect' => CosmeticEffect::class,
            'checks' => 'array',
            'cost' => 'integer',
            'published_at' => 'datetime',
            'pulled_at' => 'datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * Published and still stocked — what a kid can see in the shop. Owned
     * items that were pulled are still worn, so ownership never goes through
     * this scope.
     *
     * @param  Builder<Cosmetic>  $query
     */
    public function scopeOnSale(Builder $query): void
    {
        $query->whereNotNull('published_at')->whereNull('pulled_at');
    }

    /** @param  Builder<Cosmetic>  $query */
    public function scopeDrafts(Builder $query): void
    {
        $query->whereNull('published_at');
    }

    public function isDraft(): bool
    {
        return $this->published_at === null;
    }

    public function isPulled(): bool
    {
        return $this->pulled_at !== null;
    }

    /** Free house items belong to everybody, so nobody needs a row to own one. */
    public function isFree(): bool
    {
        return $this->cost === 0;
    }

    public function isUpload(): bool
    {
        return $this->art_path !== null;
    }

    /** A pet is a sprite sheet the engine cuts up, not one picture. */
    public function isSheet(): bool
    {
        return $this->slot->poseGrid() !== null;
    }

    public function isLimited(): bool
    {
        return $this->stock === CosmeticStock::Limited;
    }

    /** No flavor is a house basic, which is a chip of its own. */
    public function flavorOrHouse(): CosmeticFlavor
    {
        return $this->flavor ?? CosmeticFlavor::House;
    }

    /**
     * Where an uploaded picture is fetched from. Versioned on the row's own
     * timestamp, so a replaced picture is never served from a stale cache.
     */
    public function artUrl(): ?string
    {
        if (! $this->isUpload()) {
            return null;
        }

        return route('cosmetics.art', ['cosmetic' => $this->id, 'v' => $this->updated_at?->timestamp]);
    }

    /**
     * Whether every hard check on an upload passed. A catalog item was never
     * checked because there was never a picture to check.
     */
    public function passesChecks(): bool
    {
        foreach ($this->checks ?? [] as $check) {
            if (($check['status'] ?? null) === 'fail') {
                return false;
            }
        }

        return true;
    }

    /**
     * The seven colours a theme repaints the app with, or null for anything
     * that isn't a theme.
     *
     * @return array{bg: string, panel: string, line: string, ink: string, muted: string, accent: string, accent2: string}|null
     */
    public function themeTokens(): ?array
    {
        if ($this->slot !== CosmeticSlot::Theme) {
            return null;
        }

        return self::THEMES[$this->recipe] ?? null;
    }

    /**
     * `FQCosmetics.THEMES` in cosmetics.js, repeated here because a theme is
     * the one cosmetic the server has to paint itself: it becomes CSS custom
     * properties on the page, and doing that in the browser would flash the
     * default colours on every navigation. `CosmeticCatalogTest` keeps the two
     * copies equal.
     *
     * @var array<string, array{bg: string, panel: string, line: string, ink: string, muted: string, accent: string, accent2: string}>
     */
    public const THEMES = [
        'midnight' => ['bg' => '#07030f', 'panel' => '#0e0719', 'line' => '#2e1b4d', 'ink' => '#f7f0ff', 'muted' => '#8c7bab', 'accent' => '#ffc93d', 'accent2' => '#c9a0ff'],
        'lagoon' => ['bg' => '#04121a', 'panel' => '#07202c', 'line' => '#12465c', 'ink' => '#effbff', 'muted' => '#6f9fb0', 'accent' => '#54e8d0', 'accent2' => '#6bd5ff'],
        'ember' => ['bg' => '#160604', 'panel' => '#240c07', 'line' => '#5a1e12', 'ink' => '#fff2ec', 'muted' => '#b08272', 'accent' => '#ff8a4d', 'accent2' => '#ffd06b'],
        'swamp' => ['bg' => '#060e07', 'panel' => '#0c1a0e', 'line' => '#1f4426', 'ink' => '#f0fff3', 'muted' => '#78a884', 'accent' => '#7dffb0', 'accent2' => '#d6ff4d'],
        'bubblegum' => ['bg' => '#1a0614', 'panel' => '#2a0a21', 'line' => '#5d1846', 'ink' => '#fff0fa', 'muted' => '#c084a8', 'accent' => '#ff8ac7', 'accent2' => '#ffe14d'],
        'hollow' => ['bg' => '#0b0603', 'panel' => '#160c05', 'line' => '#42200a', 'ink' => '#fff3e6', 'muted' => '#a8734a', 'accent' => '#ff7a18', 'accent2' => '#ffc93d'],
        'boneyard' => ['bg' => '#080809', 'panel' => '#121215', 'line' => '#2c2c33', 'ink' => '#f4f2ec', 'muted' => '#8a8a94', 'accent' => '#e8e2d2', 'accent2' => '#c2182f'],
        'cottontail' => ['bg' => '#170610', 'panel' => '#260c1b', 'line' => '#5d1846', 'ink' => '#fff0fa', 'muted' => '#c084a8', 'accent' => '#ff5fa2', 'accent2' => '#ffffff'],
        'witchlight' => ['bg' => '#06090a', 'panel' => '#0d1613', 'line' => '#22453a', 'ink' => '#eafff6', 'muted' => '#7ba394', 'accent' => '#7dffb0', 'accent2' => '#c9a0ff'],
        'safari' => ['bg' => '#120d06', 'panel' => '#1e160c', 'line' => '#4a3418', 'ink' => '#fff8ec', 'muted' => '#ab8f66', 'accent' => '#e0a243', 'accent2' => '#7dffb0'],
    ];

    /**
     * The opening catalog — `FQCosmetics.ITEMS`, row for row.
     *
     * @return array<int, array{slot: string, key: string, name: string, cost: int, stock: string, flavor: string|null, motion: string|null}>
     */
    public static function defaults(): array
    {
        return [
            ['slot' => 'frame', 'key' => 'hairline', 'name' => 'Hairline', 'cost' => 2, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'frame', 'key' => 'double', 'name' => 'Double Ring', 'cost' => 3, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'frame', 'key' => 'dashed', 'name' => 'Dot Dash', 'cost' => 3, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'frame', 'key' => 'ticket', 'name' => 'Ticket Stub', 'cost' => 5, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'frame', 'key' => 'spikes', 'name' => 'Spikes', 'cost' => 7, 'stock' => 'weekly', 'flavor' => null, 'motion' => 'throb'],
            ['slot' => 'frame', 'key' => 'bolts', 'name' => 'Four Bolts', 'cost' => 5, 'stock' => 'weekly', 'flavor' => null, 'motion' => null],
            ['slot' => 'frame', 'key' => 'orbit', 'name' => 'Orbit', 'cost' => 8, 'stock' => 'weekly', 'flavor' => null, 'motion' => 'spin'],
            ['slot' => 'frame', 'key' => 'crown', 'name' => 'Paper Crown', 'cost' => 8, 'stock' => 'limited', 'flavor' => null, 'motion' => 'tick'],
            ['slot' => 'frame', 'key' => 'beads', 'name' => 'Bead String', 'cost' => 4, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'frame', 'key' => 'fangs', 'name' => 'Fangs', 'cost' => 5, 'stock' => 'shelf', 'flavor' => 'monster', 'motion' => null],
            ['slot' => 'frame', 'key' => 'stitch', 'name' => 'Stitches', 'cost' => 4, 'stock' => 'shelf', 'flavor' => 'monster', 'motion' => null],
            ['slot' => 'frame', 'key' => 'paws', 'name' => 'Paw Ring', 'cost' => 4, 'stock' => 'shelf', 'flavor' => 'animal', 'motion' => null],
            ['slot' => 'frame', 'key' => 'candy', 'name' => 'Candy Stripe', 'cost' => 7, 'stock' => 'weekly', 'flavor' => 'pink', 'motion' => 'spinfast'],
            ['slot' => 'avatar', 'key' => 'bean', 'name' => 'Bean', 'cost' => 2, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'avatar', 'key' => 'ghost', 'name' => 'Sheet Ghost', 'cost' => 3, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'avatar', 'key' => 'slime', 'name' => 'Slime', 'cost' => 6, 'stock' => 'shelf', 'flavor' => null, 'motion' => 'bob'],
            ['slot' => 'avatar', 'key' => 'cat', 'name' => 'Alley Cat', 'cost' => 6, 'stock' => 'shelf', 'flavor' => null, 'motion' => 'blink'],
            ['slot' => 'avatar', 'key' => 'cloud', 'name' => 'Rain Cloud', 'cost' => 4, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'avatar', 'key' => 'robot', 'name' => 'Tin Robot', 'cost' => 6, 'stock' => 'weekly', 'flavor' => null, 'motion' => null],
            ['slot' => 'avatar', 'key' => 'star', 'name' => 'Gold Star', 'cost' => 6, 'stock' => 'weekly', 'flavor' => null, 'motion' => null],
            ['slot' => 'avatar', 'key' => 'skull', 'name' => 'Bonehead', 'cost' => 8, 'stock' => 'limited', 'flavor' => 'monster', 'motion' => 'blink'],
            ['slot' => 'avatar', 'key' => 'wolf', 'name' => 'Wolf', 'cost' => 5, 'stock' => 'shelf', 'flavor' => 'animal', 'motion' => null],
            ['slot' => 'avatar', 'key' => 'bunny', 'name' => 'Bunny', 'cost' => 4, 'stock' => 'shelf', 'flavor' => 'pink', 'motion' => null],
            ['slot' => 'avatar', 'key' => 'pumpkin', 'name' => 'Jack', 'cost' => 7, 'stock' => 'shelf', 'flavor' => 'orange', 'motion' => 'flicker'],
            ['slot' => 'avatar', 'key' => 'moth', 'name' => 'The Moth', 'cost' => 8, 'stock' => 'weekly', 'flavor' => 'monster', 'motion' => 'sway'],
            ['slot' => 'theme', 'key' => 'midnight', 'name' => 'Midnight', 'cost' => 0, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'theme', 'key' => 'lagoon', 'name' => 'Lagoon', 'cost' => 5, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'theme', 'key' => 'swamp', 'name' => 'Swamp', 'cost' => 5, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'theme', 'key' => 'ember', 'name' => 'Ember', 'cost' => 6, 'stock' => 'weekly', 'flavor' => null, 'motion' => null],
            ['slot' => 'theme', 'key' => 'bubblegum', 'name' => 'Bubblegum', 'cost' => 6, 'stock' => 'weekly', 'flavor' => null, 'motion' => null],
            ['slot' => 'theme', 'key' => 'witchlight', 'name' => 'Witchlight', 'cost' => 8, 'stock' => 'limited', 'flavor' => 'monster', 'motion' => null],
            ['slot' => 'theme', 'key' => 'hollow', 'name' => 'Hollow Night', 'cost' => 6, 'stock' => 'shelf', 'flavor' => 'orange', 'motion' => null],
            ['slot' => 'theme', 'key' => 'boneyard', 'name' => 'Boneyard', 'cost' => 6, 'stock' => 'shelf', 'flavor' => 'monster', 'motion' => null],
            ['slot' => 'theme', 'key' => 'cottontail', 'name' => 'Cottontail', 'cost' => 7, 'stock' => 'shelf', 'flavor' => 'pink', 'motion' => null],
            ['slot' => 'theme', 'key' => 'safari', 'name' => 'Safari', 'cost' => 6, 'stock' => 'weekly', 'flavor' => 'animal', 'motion' => null],
            ['slot' => 'plate', 'key' => 'standard', 'name' => 'Standard', 'cost' => 0, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'plate', 'key' => 'metal', 'name' => 'Brushed Metal', 'cost' => 3, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'plate', 'key' => 'pixel', 'name' => 'Pixel Plate', 'cost' => 4, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'plate', 'key' => 'neon', 'name' => 'Neon Tube', 'cost' => 5, 'stock' => 'weekly', 'flavor' => null, 'motion' => null],
            ['slot' => 'plate', 'key' => 'ribbon', 'name' => 'Ribbon', 'cost' => 6, 'stock' => 'weekly', 'flavor' => null, 'motion' => null],
            ['slot' => 'plate', 'key' => 'tape', 'name' => 'Sticky Tape', 'cost' => 7, 'stock' => 'limited', 'flavor' => null, 'motion' => null],
            ['slot' => 'plate', 'key' => 'bone', 'name' => 'Bone', 'cost' => 4, 'stock' => 'shelf', 'flavor' => 'monster', 'motion' => null],
            ['slot' => 'plate', 'key' => 'jack', 'name' => 'Jack-o-plate', 'cost' => 5, 'stock' => 'shelf', 'flavor' => 'orange', 'motion' => null],
            ['slot' => 'plate', 'key' => 'candyplate', 'name' => 'Candy Stripe', 'cost' => 5, 'stock' => 'shelf', 'flavor' => 'pink', 'motion' => null],
            ['slot' => 'plate', 'key' => 'pawplate', 'name' => 'Pawprint', 'cost' => 4, 'stock' => 'weekly', 'flavor' => 'animal', 'motion' => null],
            ['slot' => 'pattern', 'key' => 'plain', 'name' => 'Plain Dark', 'cost' => 0, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'pattern', 'key' => 'dots', 'name' => 'Dot Field', 'cost' => 2, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'pattern', 'key' => 'grid', 'name' => 'Blueprint', 'cost' => 3, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'pattern', 'key' => 'carbon', 'name' => 'Carbon', 'cost' => 3, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'pattern', 'key' => 'bricks', 'name' => 'Dungeon Brick', 'cost' => 4, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'pattern', 'key' => 'tickets', 'name' => 'Ticket Run', 'cost' => 4, 'stock' => 'weekly', 'flavor' => null, 'motion' => null],
            ['slot' => 'pattern', 'key' => 'slimewave', 'name' => 'Slime Wave', 'cost' => 7, 'stock' => 'weekly', 'flavor' => null, 'motion' => 'drift'],
            ['slot' => 'pattern', 'key' => 'stars', 'name' => 'Night Sky', 'cost' => 7, 'stock' => 'limited', 'flavor' => null, 'motion' => 'drift'],
            ['slot' => 'pattern', 'key' => 'pumpkinstripe', 'name' => 'Trick or Treat', 'cost' => 6, 'stock' => 'shelf', 'flavor' => 'orange', 'motion' => 'drift'],
            ['slot' => 'pattern', 'key' => 'cobweb', 'name' => 'Cobwebs', 'cost' => 5, 'stock' => 'shelf', 'flavor' => 'monster', 'motion' => null],
            ['slot' => 'pattern', 'key' => 'candystripe', 'name' => 'Candy Stripe', 'cost' => 4, 'stock' => 'shelf', 'flavor' => 'pink', 'motion' => null],
            ['slot' => 'pattern', 'key' => 'leopard', 'name' => 'Leopard', 'cost' => 5, 'stock' => 'shelf', 'flavor' => 'animal', 'motion' => null],
            ['slot' => 'pattern', 'key' => 'pawtrail', 'name' => 'Paw Trail', 'cost' => 3, 'stock' => 'weekly', 'flavor' => 'animal', 'motion' => null],
            ['slot' => 'cabinet', 'key' => 'house', 'name' => 'House Standard', 'cost' => 0, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'cabinet', 'key' => 'chrome', 'name' => 'Chrome Diner', 'cost' => 4, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'cabinet', 'key' => 'firewood', 'name' => 'Firewood', 'cost' => 5, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'cabinet', 'key' => 'sticker', 'name' => 'Sticker Bombed', 'cost' => 6, 'stock' => 'weekly', 'flavor' => null, 'motion' => null],
            ['slot' => 'cabinet', 'key' => 'voidcab', 'name' => 'The Void', 'cost' => 8, 'stock' => 'limited', 'flavor' => null, 'motion' => null],
            ['slot' => 'cabinet', 'key' => 'hauntcab', 'name' => 'Haunted', 'cost' => 6, 'stock' => 'shelf', 'flavor' => 'monster', 'motion' => null],
            ['slot' => 'cabinet', 'key' => 'pumpkincab', 'name' => 'Pumpkin', 'cost' => 5, 'stock' => 'shelf', 'flavor' => 'orange', 'motion' => null],
            ['slot' => 'cabinet', 'key' => 'furcab', 'name' => 'Leopard', 'cost' => 6, 'stock' => 'weekly', 'flavor' => 'animal', 'motion' => null],
            ['slot' => 'spark', 'key' => 'coins', 'name' => 'Coin Burst', 'cost' => 0, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'spark', 'key' => 'stars', 'name' => 'Star Pop', 'cost' => 3, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'spark', 'key' => 'confetti', 'name' => 'Confetti', 'cost' => 3, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'spark', 'key' => 'bubbles', 'name' => 'Bubbles', 'cost' => 4, 'stock' => 'shelf', 'flavor' => null, 'motion' => null],
            ['slot' => 'spark', 'key' => 'shards', 'name' => 'Shards', 'cost' => 6, 'stock' => 'weekly', 'flavor' => null, 'motion' => 'radiate'],
            ['slot' => 'spark', 'key' => 'drips', 'name' => 'Slime Drips', 'cost' => 7, 'stock' => 'limited', 'flavor' => null, 'motion' => 'radiate'],
            ['slot' => 'spark', 'key' => 'bats', 'name' => 'Bat Swarm', 'cost' => 6, 'stock' => 'shelf', 'flavor' => 'monster', 'motion' => 'radiate'],
            ['slot' => 'spark', 'key' => 'hearts', 'name' => 'Hearts', 'cost' => 4, 'stock' => 'shelf', 'flavor' => 'pink', 'motion' => null],
            ['slot' => 'spark', 'key' => 'pawpops', 'name' => 'Paw Pops', 'cost' => 4, 'stock' => 'weekly', 'flavor' => 'animal', 'motion' => null],
        ];
    }

    /**
     * Gives a household the opening catalog if it hasn't got one.
     *
     * Called from the migration, the household factory and the seeder, for the
     * same reason LuckyPrize::seedDefaults() is. One insert rather than
     * seventy-five, because the factory runs this for every household the test
     * suite builds.
     */
    public static function seedDefaults(Household $household): void
    {
        if (self::where('household_id', $household->id)->exists()) {
            return;
        }

        $now = now();

        self::insert(array_map(fn (array $item) => [
            'household_id' => $household->id,
            'slot' => $item['slot'],
            'recipe' => $item['key'],
            'name' => $item['name'],
            'cost' => $item['cost'],
            'stock' => $item['stock'],
            'flavor' => $item['flavor'],
            'motion' => $item['motion'],
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::defaults()));
    }
}
