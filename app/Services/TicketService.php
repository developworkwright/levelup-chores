<?php

namespace App\Services;

use App\Enums\TicketKind;
use App\Exceptions\InsufficientTicketsException;
use App\Models\Badge;
use App\Models\BonusTicketEntry;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Bonus tickets are minted by XP but never spend it — XP is a permanent
 * record of everything a kid has done, so it only ever goes up.
 *
 * Mirrors LedgerService: bonus_ticket_entries is the source of truth and
 * profiles.bonus_tickets is a cache the service keeps in step inside one
 * transaction, so the two can't drift.
 */
class TicketService
{
    /** Tickets handed out for crossing a single level. */
    public const PER_LEVEL = 1;

    /** Tickets handed out for unlocking a single badge. */
    public const PER_BADGE = 1;

    public function record(
        Profile $profile,
        TicketKind $kind,
        int $amount,
        string $description,
        ?Model $related = null,
    ): BonusTicketEntry {
        return DB::transaction(function () use ($profile, $kind, $amount, $description, $related) {
            $entry = BonusTicketEntry::create([
                'household_id' => $profile->household_id,
                'profile_id' => $profile->id,
                'kind' => $kind,
                'amount' => $amount,
                'description' => $description,
                'related_type' => $related?->getMorphClass(),
                'related_id' => $related?->getKey(),
            ]);

            // Incremented in the database, not in memory. A single request can
            // hold two instances of the same profile — evaluateHouseholdGoal()
            // loads its own while approve() still holds the caller's — and
            // `$profile->bonus_tickets + $amount` on a stale one silently
            // overwrites whatever the other just banked.
            $profile->increment('bonus_tickets', $amount);

            if ($profile->bonus_tickets < 0) {
                $profile->bonus_tickets = 0;
                $profile->save();
            }

            return $entry;
        });
    }

    /**
     * Mints tickets for any level the profile has reached but not yet been
     * paid for. Safe to call after every XP change: the high-water mark makes
     * it a no-op when nothing new has been crossed, and it never claws back
     * if XP later drops.
     */
    public function syncLevelTickets(Profile $profile): void
    {
        $level = $profile->level();
        $paidThrough = $profile->tickets_granted_through_level;

        if ($level <= $paidThrough) {
            return;
        }

        $levelsGained = $level - $paidThrough;

        // Advance the mark first so a failure partway can't pay twice.
        $profile->tickets_granted_through_level = $level;
        $profile->save();

        $this->record(
            $profile,
            TicketKind::LevelUp,
            $levelsGained * self::PER_LEVEL,
            $levelsGained === 1
                ? "Reached level {$level}"
                : "Reached level {$level} (+{$levelsGained} levels)",
        );
    }

    /** Called once as a badge is attached, so it can't pay out twice. */
    public function awardForBadge(Profile $profile, Badge $badge): void
    {
        $this->record(
            $profile,
            TicketKind::Badge,
            self::PER_BADGE,
            "Earned the {$badge->name} badge",
            $badge,
        );
    }

    /**
     * @throws InsufficientTicketsException
     */
    public function spend(Profile $profile, int $cost, string $description, ?Model $related = null): BonusTicketEntry
    {
        if ($profile->bonus_tickets < $cost) {
            throw new InsufficientTicketsException($cost - $profile->bonus_tickets);
        }

        return $this->record($profile, TicketKind::Purchase, -$cost, $description, $related);
    }
}
