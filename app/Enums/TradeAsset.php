<?php

namespace App\Enums;

/**
 * What one side of a sibling trade puts up. Two of these make a trade: what
 * the sender gives and what they want back.
 *
 * `Favour` is the odd one out on purpose — it moves no balance, it is just the
 * free text the two kids agreed on. Everything else here is a currency the app
 * actually holds, so a trade with a currency on both sides is a straight swap.
 */
enum TradeAsset: string
{
    case Points = 'points';

    case Tickets = 'tickets';

    case Favour = 'favour';

    /** Whether accepting this side moves a balance rather than a promise. */
    public function isCurrency(): bool
    {
        return $this !== self::Favour;
    }

    public function label(): string
    {
        return match ($this) {
            self::Points => 'Points',
            self::Tickets => 'Tickets',
            self::Favour => 'A favour',
        };
    }

    public function minAmount(): int
    {
        return match ($this) {
            self::Points, self::Tickets => 1,
            self::Favour => 0,
        };
    }

    /**
     * A ceiling low enough that a mistyped extra zero can't empty a balance.
     * Tickets get a far lower one than points because they are far scarcer —
     * a kid mints one per level and one per badge, so 25 is already a hoard.
     */
    public function maxAmount(): int
    {
        return match ($this) {
            self::Points => 1000,
            self::Tickets => 25,
            self::Favour => 0,
        };
    }

    /** How an amount of this asset reads on a card: "100 pts", "1 ticket". */
    public function format(int $amount): string
    {
        return match ($this) {
            self::Points => "{$amount} pts",
            self::Tickets => $amount === 1 ? '1 ticket' : "{$amount} tickets",
            self::Favour => 'a favour',
        };
    }

    /** The colour a card prices this asset in, matching the tab it is earned on. */
    public function cssVar(): string
    {
        return match ($this) {
            self::Points => 'var(--fq-gold)',
            self::Tickets => 'var(--fq-lime)',
            self::Favour => 'var(--fq-text-2)',
        };
    }

    /**
     * The assets that carry an amount, in the order the compose form offers them.
     *
     * @return array<int, self>
     */
    public static function currencies(): array
    {
        return [self::Points, self::Tickets];
    }
}
