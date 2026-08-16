<?php

namespace App\Enums;

use App\Services\ChoreService;

/**
 * What a Quest Charm did to the hand, rolled once when the chest is opened.
 *
 * The charm is deliberately hard to waste. Three of the four cases change the
 * cards on the table, and the fourth still leaves the payout roll at claim
 * time ({@see ChoreService::rollCharmPayout()}) — so "I spent tickets and got
 * nothing at all" is a corner of a corner rather than the coin flip a single
 * up-front roll would make it.
 *
 * The weights lean where they do for an economic reason worth keeping in mind
 * if they are ever retuned: the *generous-looking* outcomes are the cheap
 * ones. The hand already makes its most expensive card bold, so lighting up a
 * second or third card mostly makes the cheaper cards more attractive — a kid
 * who takes a charmed 100-point card over a bold 200-point one costs the
 * household less and does less work. DoubledBonus is the only case that
 * genuinely raises the ceiling, which is why it is the rarest of the three
 * that do something.
 */
enum QuestCharmEffect: string
{
    case SecondCard = 'second_card';

    case AllCards = 'all_cards';

    case DoubledBonus = 'doubled_bonus';

    case Unchanged = 'unchanged';

    /** Relative likelihood of each outcome. Need not add to 100. */
    public function weight(): int
    {
        return match ($this) {
            self::SecondCard => 40,
            self::AllCards => 30,
            self::DoubledBonus => 20,
            self::Unchanged => 10,
        };
    }

    /**
     * How many of the hand's cards go bold, cheapest-last.
     *
     * Null means "however many there are" — the caller knows the hand size and
     * this enum deliberately doesn't.
     */
    public function boldCards(): ?int
    {
        return match ($this) {
            self::AllCards => null,
            self::SecondCard => 2,
            self::DoubledBonus, self::Unchanged => 1,
        };
    }

    /** Multiplier on {@see ChoreService::BOLD_CARD_BONUS_PERCENT}. */
    public function bonusMultiplier(): int
    {
        return $this === self::DoubledBonus ? 2 : 1;
    }

    /** What the kid is told when the cards land. */
    public function announcement(): string
    {
        return match ($this) {
            self::SecondCard => 'The charm caught a second card!',
            self::AllCards => 'Fully charmed — every card is bold!',
            self::DoubledBonus => 'The charm doubled the bold bonus!',
            self::Unchanged => 'The charm is still working — it will show when you hand the quest in.',
        };
    }

    /** The strip that sits over a charmed hand. */
    public function label(): string
    {
        return match ($this) {
            self::SecondCard => 'Charmed · Second card bold',
            self::AllCards => 'Fully charmed · Every card bold',
            self::DoubledBonus => 'Charmed · Bold bonus doubled',
            self::Unchanged => 'Charmed · Saving itself for the payout',
        };
    }

    /**
     * Draws one case against the weights above.
     *
     * Genuine randomness, like the mystery chore's pick and the wheel's actual
     * result — not the wheel's deterministic *display* shuffle, which is a
     * different mechanism solving a different problem.
     */
    public static function roll(): self
    {
        $total = array_sum(array_map(fn (self $case) => $case->weight(), self::cases()));
        $ticket = random_int(1, $total);

        foreach (self::cases() as $case) {
            $ticket -= $case->weight();

            if ($ticket <= 0) {
                return $case;
            }
        }

        return self::Unchanged;
    }
}
