<?php

namespace App\Enums;

/**
 * What one side of a deal puts up, and what a bounty is priced in.
 *
 * `Favour` is **legacy and unwritable**. Work-for-pay used to be a sibling
 * trade with a favour on one side, which paid the moment it was accepted —
 * before any of the work. That whole idea now lives on the bounty board, where
 * a job is claimed, reported done and confirmed, whether it is aimed at one
 * sibling or open to the household. {@see SiblingOfferService::offer()} refuses
 * a favour outright, so no new row can carry one.
 *
 * The case stays because rows written before the move still do, and the trade
 * history a kid scrolls past has to keep rendering. Do not price anything in
 * it, and do not add it back to {@see self::currencies()}.
 */
enum TradeAsset: string
{
    case Points = 'points';

    case Tickets = 'tickets';

    case Favour = 'favour';

    /**
     * One limited cosmetic, named by id rather than counted.
     *
     * The only thing in the locker that can change hands, and the reason a
     * limited is worth chasing: it was on sale for one week ever, so the only
     * way to get one afterwards is from whoever bought it.
     */
    case Cosmetic = 'cosmetic';

    /** Whether accepting this side moves a balance rather than a promise. */
    public function isCurrency(): bool
    {
        return $this === self::Points || $this === self::Tickets;
    }

    /**
     * Whether this side is one named thing rather than an amount of something.
     *
     * A currency has a balance and a number; an item has an id. Both change
     * hands on accept, which is why this is asked separately from isCurrency()
     * — a favour is neither, and is legacy besides.
     */
    public function isItem(): bool
    {
        return $this === self::Cosmetic;
    }

    public function label(): string
    {
        return match ($this) {
            self::Points => 'Points',
            self::Tickets => 'Tickets',
            self::Favour => 'A favour',
            self::Cosmetic => 'An item',
        };
    }

    public function minAmount(): int
    {
        return match ($this) {
            self::Points, self::Tickets => 1,
            self::Favour, self::Cosmetic => 0,
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
            self::Favour, self::Cosmetic => 0,
        };
    }

    /** How an amount of this asset reads on a card: "100 pts", "1 ticket". */
    public function format(int $amount): string
    {
        return match ($this) {
            self::Points => "{$amount} pts",
            self::Tickets => $amount === 1 ? '1 ticket' : "{$amount} tickets",
            self::Favour => 'a favour',
            // An item says its own name, which the offer holds rather than
            // this enum — see SiblingOffer::giveText().
            self::Cosmetic => 'an item',
        };
    }

    /** The colour a card prices this asset in, matching the tab it is earned on. */
    public function cssVar(): string
    {
        return match ($this) {
            self::Points => 'var(--fq-gold)',
            self::Tickets => 'var(--fq-lime)',
            self::Favour => 'var(--fq-text-2)',
            self::Cosmetic => 'var(--fq-coral)',
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

    /**
     * Everything a side of a trade can be today: two currencies and an item.
     *
     * @return array<int, self>
     */
    public static function tradable(): array
    {
        return [self::Points, self::Tickets, self::Cosmetic];
    }
}
