<?php

namespace App\Services;

use App\Enums\LedgerKind;
use App\Enums\PerkEffect;
use App\Enums\TicketKind;
use App\Models\BonusPerk;
use App\Models\DailyChest;
use App\Models\OwnedPerk;
use App\Models\Profile;
use Illuminate\Support\Arr;

/**
 * The daily bonus chest — one per kid per household-day, on the days a streak
 * milestone chest isn't already waiting.
 *
 * Everyone gets one whether or not they've done anything, because the habit of
 * turning up is worth building. Clearing the day's quest doesn't unlock it, it
 * improves the roll — so effort shifts the odds rather than the entitlement.
 */
class ChestService
{
    public const KIND_TICKETS = 'tickets';

    public const KIND_PERK = 'perk';

    public const KIND_POINTS = 'points';

    public const KIND_XP = 'xp';

    /**
     * Rolled when the day's quest is still outstanding.
     *
     * @var array<int, array{kind: string, amount: int, weight: int}>
     */
    private const BASE_TABLE = [
        ['kind' => self::KIND_TICKETS, 'amount' => 1, 'weight' => 40],
        ['kind' => self::KIND_TICKETS, 'amount' => 2, 'weight' => 12],
        ['kind' => self::KIND_POINTS, 'amount' => 50, 'weight' => 20],
        ['kind' => self::KIND_XP, 'amount' => 25, 'weight' => 20],
        ['kind' => self::KIND_PERK, 'amount' => 1, 'weight' => 8],
    ];

    /**
     * Rolled once the quest is cleared — more tickets, and a perk lands far
     * more often.
     *
     * @var array<int, array{kind: string, amount: int, weight: int}>
     */
    private const BOOSTED_TABLE = [
        ['kind' => self::KIND_TICKETS, 'amount' => 1, 'weight' => 22],
        ['kind' => self::KIND_TICKETS, 'amount' => 2, 'weight' => 20],
        ['kind' => self::KIND_TICKETS, 'amount' => 3, 'weight' => 10],
        ['kind' => self::KIND_POINTS, 'amount' => 150, 'weight' => 18],
        ['kind' => self::KIND_XP, 'amount' => 50, 'weight' => 10],
        ['kind' => self::KIND_PERK, 'amount' => 1, 'weight' => 20],
    ];

    public function __construct(
        private TicketService $tickets,
        private LedgerService $ledger,
        private PerkInventoryService $inventory,
    ) {}

    public function openedToday(Profile $profile): ?DailyChest
    {
        return DailyChest::where('profile_id', $profile->id)
            ->whereDate('chest_date', HouseholdClock::for($profile->household)->today())
            ->first();
    }

    /**
     * A bonus chest is available on any day the kid hasn't already opened one
     * and doesn't have a streak chest waiting — one chest a day, and the
     * milestone one takes precedence when both would apply.
     */
    public function isAvailable(Profile $profile): bool
    {
        return $profile->pending_streak_chest === null && ! $this->openedToday($profile);
    }

    /** Rolls, grants and records. Null when there's no chest to open. */
    public function open(Profile $profile): ?DailyChest
    {
        if (! $this->isAvailable($profile)) {
            return null;
        }

        $questDone = app(ChoreService::class)->isQuestDoneToday($profile);
        $roll = $this->roll($profile, $questDone);

        $chest = DailyChest::create([
            'profile_id' => $profile->id,
            'chest_date' => HouseholdClock::for($profile->household)->today(),
            'reward_kind' => $roll['kind'],
            'reward_amount' => $roll['amount'],
            'reward_effect' => $roll['effect'] ?? null,
            'quest_was_done' => $questDone,
        ]);

        $this->grant($profile, $chest);

        return $chest;
    }

    /** @return array{kind: string, amount: int, effect?: PerkEffect} */
    private function roll(Profile $profile, bool $questDone): array
    {
        $table = $questDone ? self::BOOSTED_TABLE : self::BASE_TABLE;
        $picked = $this->weightedPick($table);

        if ($picked['kind'] !== self::KIND_PERK) {
            return ['kind' => $picked['kind'], 'amount' => $picked['amount']];
        }

        $effect = $this->randomPerkEffect($profile);

        // A household with every perk switched off still deserves a prize,
        // so fall back to tickets rather than handing over nothing.
        return $effect
            ? ['kind' => self::KIND_PERK, 'amount' => 1, 'effect' => $effect]
            : ['kind' => self::KIND_TICKETS, 'amount' => 2];
    }

    /** @param array<int, array{kind: string, amount: int, weight: int}> $table */
    private function weightedPick(array $table): array
    {
        $total = array_sum(array_column($table, 'weight'));
        $target = random_int(1, $total);

        foreach ($table as $row) {
            $target -= $row['weight'];

            if ($target <= 0) {
                return $row;
            }
        }

        return $table[0];
    }

    private function randomPerkEffect(Profile $profile): ?PerkEffect
    {
        $effects = BonusPerk::where('household_id', $profile->household_id)
            ->enabled()
            ->pluck('effect')
            ->all();

        return $effects === [] ? null : Arr::random($effects);
    }

    private function grant(Profile $profile, DailyChest $chest): void
    {
        match ($chest->reward_kind) {
            self::KIND_TICKETS => $this->tickets->record(
                $profile,
                TicketKind::Adjustment,
                $chest->reward_amount,
                'Daily chest',
                $chest,
            ),
            self::KIND_POINTS => $this->ledger->record(
                $profile->household,
                $profile,
                LedgerKind::Earn,
                $chest->reward_amount,
                "{$profile->name} — daily chest",
                $chest,
            ),
            self::KIND_XP => $this->grantXp($profile, $chest->reward_amount),
            self::KIND_PERK => $this->inventory->grant($profile, $chest->reward_effect, OwnedPerk::SOURCE_CHEST),
            default => null,
        };
    }

    private function grantXp(Profile $profile, int $amount): void
    {
        $profile->xp += $amount;
        $profile->save();

        // XP can cross a level, which mints a ticket of its own.
        $this->tickets->syncLevelTickets($profile);
    }

    /** Short human-readable summary of what a chest paid out. */
    public function describe(DailyChest $chest): string
    {
        return match ($chest->reward_kind) {
            self::KIND_TICKETS => $chest->reward_amount === 1
                ? '1 ticket'
                : "{$chest->reward_amount} tickets",
            self::KIND_POINTS => "+{$chest->reward_amount} points",
            self::KIND_XP => "+{$chest->reward_amount} XP",
            self::KIND_PERK => $chest->reward_effect?->defaults()['name'] ?? 'A perk',
            default => 'A prize',
        };
    }
}
