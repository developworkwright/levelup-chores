<?php

namespace App\Services;

use App\Enums\ProfileRole;
use App\Exceptions\InsufficientTicketsException;
use App\Exceptions\LuckyBlockEmptyException;
use App\Models\Household;
use App\Models\LuckyHit;
use App\Models\LuckyPrize;
use App\Models\Profile;
use App\Notifications\ParentApprovalNeeded;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Three tickets, one hit, one of the pool at random.
 *
 * Two things about the economy are load-bearing and neither is an accident.
 *
 * It costs **tickets, never points**. Tickets come from the journal, streaks,
 * badges and boss kills — they are not savings — so the block can hold
 * genuinely good prizes without ever competing with the Loot Shop. A kid
 * cannot gamble away what they are saving for.
 *
 * The odds are **flat and visible**. No tiers, no weighting, no hidden table:
 * every active prize is equally likely and the whole list is shown before the
 * kid commits. That is what makes the pool's contents the entire balance
 * mechanism, and why the parent screen leads with a warning about it rather
 * than with the editor.
 */
class LuckyBlockService
{
    /** Tickets a hit costs. */
    public const TICKET_COST = 3;

    /**
     * Below this the pool starts repeating itself noticeably. A warning on the
     * parent screen rather than a hard floor: a parent halfway through
     * rewriting the list should not be locked out of saving it.
     */
    public const HEALTHY_POOL = 6;

    public function __construct(private TicketService $tickets) {}

    /**
     * Everything this kid could win — house-wide prizes plus their own.
     *
     * @return Collection<int, LuckyPrize>
     */
    public function poolFor(Profile $kid): Collection
    {
        return LuckyPrize::query()
            ->forKid($kid)
            ->active()
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * The pool the draw actually rolls against.
     *
     * With the household's hold rule on, a prize a kid has won but not yet
     * been given is out of their pool — the same thing coming out twice while
     * the first one is still owed is the repeat that reads as broken.
     *
     * Holding back the *whole* pool would leave a kid with tickets and a dead
     * button, which is worse than a repeat, so an exclusion that empties it
     * falls through to the full pool.
     *
     * @return Collection<int, LuckyPrize>
     */
    public function drawableFor(Profile $kid): Collection
    {
        $pool = $this->poolFor($kid);

        if (! $kid->household->lucky_hold_won) {
            return $pool;
        }

        $held = LuckyHit::where('profile_id', $kid->id)
            ->pending()
            ->pluck('lucky_prize_id')
            ->filter()
            ->flip();

        $drawable = $pool->reject(fn (LuckyPrize $prize) => $held->has($prize->id));

        return $drawable->isEmpty() ? $pool : $drawable->values();
    }

    /** Whether there is anything to win at all. The block hides when there isn't. */
    public function isOpenFor(Profile $kid): bool
    {
        return $this->poolFor($kid)->isNotEmpty();
    }

    /**
     * Spends the tickets and draws the prize, in that order, in one
     * transaction.
     *
     * Both halves atomic together is the point: a double tap or a connection
     * dropped mid-request must not be able to spend three tickets for nothing,
     * nor to draw twice for one payment. The balance is re-read under a lock
     * inside the transaction rather than trusted from the caller's copy, which
     * is what stops two taps both seeing three tickets.
     *
     * The draw is server-side and never inspectable. The list is deliberately
     * visible; the result must not be, or it is re-rollable.
     *
     * @throws InsufficientTicketsException
     * @throws LuckyBlockEmptyException
     */
    public function hit(Profile $kid): LuckyHit
    {
        $hit = DB::transaction(function () use ($kid) {
            $locked = Profile::whereKey($kid->id)->lockForUpdate()->firstOrFail();

            if ($locked->bonus_tickets < self::TICKET_COST) {
                throw new InsufficientTicketsException(self::TICKET_COST - $locked->bonus_tickets);
            }

            $drawable = $this->drawableFor($kid);

            if ($drawable->isEmpty()) {
                throw new LuckyBlockEmptyException;
            }

            $prize = $drawable->random();

            $this->tickets->spend($locked, self::TICKET_COST, "{$kid->name} — Lucky Block", $prize);

            return LuckyHit::create([
                'household_id' => $kid->household_id,
                'profile_id' => $kid->id,
                'lucky_prize_id' => $prize->id,
                'prize_name' => $prize->name,
                'prize_icon' => $prize->icon,
                'tickets_spent' => self::TICKET_COST,
                'won_at' => now(),
            ]);
        });

        // The caller is holding a copy of the profile whose balance the locked
        // one just changed, and it is the copy the page is about to re-render
        // from.
        $kid->refresh();

        $this->tellTheGrownUps($kid, $hit);

        return $hit->setRelation('luckyPrize', $hit->luckyPrize()->first());
    }

    /**
     * A win is a promise until somebody keeps it, so it lands in the same
     * queue a cash-out does.
     */
    private function tellTheGrownUps(Profile $kid, LuckyHit $hit): void
    {
        $parents = Profile::where('household_id', $kid->household_id)
            ->where('role', ProfileRole::Parent)
            ->get();

        // Best-effort: the tickets are spent and the hit recorded, so a failed
        // notification must not fail the draw.
        try {
            Notification::send($parents, new ParentApprovalNeeded(
                'Lucky Block hit',
                "{$kid->name} won {$hit->prize_name}.",
            ));
        } catch (Throwable $e) {
            Log::error('Parent approval notification failed for a Lucky Block hit.', [
                'lucky_hit_id' => $hit->id,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Wins still owed, oldest first.
     *
     * @return Collection<int, LuckyHit>
     */
    public function pendingFor(Household $household): Collection
    {
        return LuckyHit::where('household_id', $household->id)
            ->pending()
            ->with('profile')
            ->oldest('won_at')
            ->get();
    }

    /** Marks a win handed over. Returns false if somebody already did. */
    public function fulfill(LuckyHit $hit, Profile $approver): bool
    {
        if (! $hit->isPending()) {
            return false;
        }

        $hit->fulfilled_at = now();
        $hit->fulfilled_by_profile_id = $approver->id;
        $hit->save();

        return true;
    }

    /** How many prizes are live in a household's pool, ignoring scope. */
    public function activeCount(Household $household): int
    {
        return LuckyPrize::where('household_id', $household->id)->active()->count();
    }
}
