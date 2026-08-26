<?php

namespace App\Models;

use App\Enums\ChoreIcon;
use App\Enums\LootCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing the Lucky Block can give out.
 *
 * Unpriced on purpose — see the migration. The only balance mechanism is the
 * parent keeping the list roughly level, because the odds are flat and every
 * active prize is equally likely.
 */
class LuckyPrize extends Model
{
    use HasFactory;

    /** The face a prize with no icon of its own wears. */
    public const FALLBACK_ICON = 'fa-solid fa-gift';

    protected $fillable = [
        'household_id',
        'profile_id',
        'name',
        'flavor',
        'icon',
        'category',
        'active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
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

    /** The kid this prize is for, or null when it's for everyone. */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** @param  Builder<LuckyPrize>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * Everything this kid could win: the house-wide prizes plus their own.
     *
     * @param  Builder<LuckyPrize>  $query
     */
    public function scopeForKid(Builder $query, Profile $kid): void
    {
        $query->where('household_id', $kid->household_id)
            ->where(fn (Builder $scoped) => $scoped
                ->whereNull('profile_id')
                ->orWhere('profile_id', $kid->id));
    }

    public function iconClass(): string
    {
        return ChoreIcon::normalizeClass($this->icon) ?? self::FALLBACK_ICON;
    }

    /**
     * The color the icon is drawn in. Borrowed from the shelf the name files
     * it under, so a treat is coral in the Lucky Block for the same reason it
     * is coral in the shop — and gold when nothing fits, rather than guessing.
     */
    public function colorVar(): string
    {
        return $this->category?->colorVar() ?? 'var(--fq-gold)';
    }

    /**
     * The opening pool. Ten things, none of them worth much more than any
     * other — which is the whole brief for a flat list.
     *
     * @return array<int, array{name: string, flavor: string, icon: string}>
     */
    public static function defaults(): array
    {
        return [
            ['name' => 'Pizza night, your topping', 'flavor' => 'You call it. Everyone else lives with it.', 'icon' => 'fa-solid fa-pizza-slice'],
            ['name' => 'Ice cream', 'flavor' => 'Out for one, whichever flavor you like.', 'icon' => 'fa-solid fa-ice-cream'],
            ['name' => 'You pick Friday\'s film', 'flavor' => 'Nobody gets to argue.', 'icon' => 'fa-solid fa-film'],
            ['name' => 'A grown-up does your job', 'flavor' => 'One chore, done for you, no questions.', 'icon' => 'fa-solid fa-broom'],
            ['name' => 'Stay up late', 'flavor' => 'Half an hour past bedtime, tonight or tomorrow.', 'icon' => 'fa-solid fa-moon'],
            ['name' => 'Extra hour of screen time', 'flavor' => 'On top of whatever you already had.', 'icon' => 'fa-solid fa-gamepad'],
            ['name' => 'Front seat', 'flavor' => 'Next trip out, the good seat is yours.', 'icon' => 'fa-solid fa-car-side'],
            ['name' => 'Hot chocolate', 'flavor' => 'The proper kind, with the whole lot on top.', 'icon' => 'fa-solid fa-mug-hot'],
            ['name' => 'Bake something together', 'flavor' => 'You choose what. A grown-up is on washing up.', 'icon' => 'fa-solid fa-cookie-bite'],
            ['name' => 'Skip a chore', 'flavor' => 'One off the board, no questions asked.', 'icon' => 'fa-solid fa-shirt'],
        ];
    }

    /**
     * Gives a household the opening pool if it hasn't got one.
     *
     * Called from three places for the same reason the bonus perk catalog
     * is: the migration can only reach households that already exist, the
     * factory builds households the migration never saw, and the seeder builds
     * the demo one.
     */
    public static function seedDefaults(Household $household): void
    {
        if (self::where('household_id', $household->id)->exists()) {
            return;
        }

        foreach (self::defaults() as $position => $prize) {
            self::create([
                'household_id' => $household->id,
                'name' => $prize['name'],
                'flavor' => $prize['flavor'],
                'icon' => $prize['icon'],
                'category' => LootCategory::forText($prize['name'].' '.$prize['flavor'])?->value,
                'position' => $position,
            ]);
        }
    }
}
