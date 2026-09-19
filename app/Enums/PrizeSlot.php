<?php

namespace App\Enums;

/**
 * The three things the prize counter sells for a pet, and the catalog of each.
 *
 * The art is `resources/js/prizes.js`, shipped verbatim from
 * handoff/design_handoff_arcade_tokens, and this mirrors its CATALOG — keys,
 * names and prices. `PrizeCatalogTest` fails if the two drift, the same seam
 * `CosmeticCatalogTest` holds for the Locker.
 *
 * A prize with a price of 0 is the house one: everybody owns it without a row,
 * the way the free cosmetics work. Only the meat block is like that.
 */
enum PrizeSlot: string
{
    case Snack = 'snack';
    case Toy = 'toy';
    case Bed = 'bed';

    /**
     * @var array<string, list<array{key: string, name: string, cost: int}>>
     */
    public const CATALOG = [
        'snack' => [
            ['key' => 'meat', 'name' => 'Meat block', 'cost' => 0],
            ['key' => 'burger', 'name' => 'Burger', 'cost' => 15],
            ['key' => 'pizza', 'name' => 'Pizza slice', 'cost' => 20],
            ['key' => 'donut', 'name' => 'Sprinkle donut', 'cost' => 25],
            ['key' => 'taco', 'name' => 'Taco', 'cost' => 30],
            ['key' => 'sushi', 'name' => 'Sushi', 'cost' => 35],
            ['key' => 'icecream', 'name' => 'Double scoop', 'cost' => 40],
            ['key' => 'hotdog', 'name' => 'Hot dog', 'cost' => 15],
            ['key' => 'drumstick', 'name' => 'Drumstick', 'cost' => 20],
            ['key' => 'cupcake', 'name' => 'Slime cupcake', 'cost' => 25],
            ['key' => 'fishbone', 'name' => 'Fish bone', 'cost' => 30],
            ['key' => 'eyeball', 'name' => 'Eyeball jelly', 'cost' => 40],
        ],
        'toy' => [
            ['key' => 'ball', 'name' => 'Bouncy ball', 'cost' => 40],
            ['key' => 'bone', 'name' => 'Chew bone', 'cost' => 50],
            ['key' => 'yarn', 'name' => 'Yarn ball', 'cost' => 60],
            ['key' => 'frisbee', 'name' => 'Frisbee', 'cost' => 75],
            ['key' => 'mouse', 'name' => 'Clockwork mouse', 'cost' => 90],
            ['key' => 'rocket', 'name' => 'Toy rocket', 'cost' => 100],
            ['key' => 'tennis', 'name' => 'Tennis ball', 'cost' => 40],
            ['key' => 'duck', 'name' => 'Rubber duck', 'cost' => 55],
            ['key' => 'spiky', 'name' => 'Spiky ball', 'cost' => 70],
            ['key' => 'skull', 'name' => 'Squeaky skull', 'cost' => 100],
        ],
        'bed' => [
            ['key' => 'cushion', 'name' => 'Velvet cushion', 'cost' => 80],
            ['key' => 'basket', 'name' => 'Wicker basket', 'cost' => 95],
            ['key' => 'beanbag', 'name' => 'Bean bag', 'cost' => 110],
            ['key' => 'cloud', 'name' => 'Little cloud', 'cost' => 130],
            ['key' => 'racer', 'name' => 'Racing bed', 'cost' => 140],
            ['key' => 'throne', 'name' => 'Tiny throne', 'cost' => 150],
            ['key' => 'hammock', 'name' => 'Hammock', 'cost' => 80],
            ['key' => 'kennel', 'name' => 'Kennel', 'cost' => 100],
            ['key' => 'pumpkin', 'name' => 'Pumpkin', 'cost' => 115],
            ['key' => 'cauldron', 'name' => 'Cauldron', 'cost' => 130],
            ['key' => 'coffin', 'name' => 'Coffin', 'cost' => 150],
        ],
    ];

    /**
     * @return list<array{key: string, name: string, cost: int}>
     */
    public function items(): array
    {
        return self::CATALOG[$this->value];
    }

    /**
     * @return array{key: string, name: string, cost: int}|null
     */
    public function item(string $key): ?array
    {
        foreach ($this->items() as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        return null;
    }

    /** The profile column holding which one of these is out. */
    public function column(): string
    {
        return 'pet_'.$this->value;
    }

    /** The shelf's label on the counter. */
    public function shelf(): string
    {
        return match ($this) {
            self::Snack => 'Snacks',
            self::Toy => 'Toys',
            self::Bed => 'Beds',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Snack => 'Snack',
            self::Toy => 'Toy',
            self::Bed => 'Bed',
        };
    }

    /** What the tray says the thing is for — the design's words. */
    public function blurb(): string
    {
        return match ($this) {
            self::Snack => 'Feed your pet and this is what drops. Own a few and pick which one is out.',
            self::Toy => 'Your pet keeps it and bats it around every day, not only powered-up days.',
            self::Bed => 'Sits on your floor. Your pet naps in it, and sleeps in it after bedtime.',
        };
    }

    /** How big it really is next to the pet, in CSS pixels — see prizes.js. */
    public function trueSize(): int
    {
        return match ($this) {
            self::Snack => 26,
            self::Toy => 34,
            self::Bed => 96,
        };
    }
}
