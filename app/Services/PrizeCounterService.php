<?php

namespace App\Services;

use App\Enums\CandyOrderStatus;
use App\Enums\PrizeSlot;
use App\Enums\ProfileRole;
use App\Enums\TokenKind;
use App\Exceptions\InsufficientTokensException;
use App\Models\Candy;
use App\Models\CandyOrder;
use App\Models\Household;
use App\Models\PetPrize;
use App\Models\Profile;
use App\Notifications\ParentApprovalNeeded;
use App\Notifications\RedemptionDecided;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

/**
 * The arcade's prize counter: what tokens buy.
 *
 * Laid out from handoff/design_handoff_arcade_tokens (turn 3 for the counter,
 * turn 2 for the rest). Four shelves:
 *
 * - **Pet snacks, toys and beds** — bought once, owned forever, and one of
 *   each out at a time. Buying something puts it out, because a kid who just
 *   paid for a frisbee wants to see the frisbee.
 * - **Tickets**, 10 tokens each — see TokenService::buyTicket().
 * - **Sweets**, the one shelf a grown-up touches. The Loot Shop's redemption
 *   pattern in tokens: paid on the tap, then waiting in the parent's queue to
 *   be handed over or refunded.
 */
class PrizeCounterService
{
    public function __construct(private TokenService $tokens) {}

    public function owns(Profile $kid, PrizeSlot $slot, string $key): bool
    {
        $item = $slot->item($key);

        if ($item === null) {
            return false;
        }

        if ($item['cost'] === 0) {
            return true;
        }

        return PetPrize::where('profile_id', $kid->id)->where('slot', $slot)->where('key', $key)->exists();
    }

    /**
     * Keys this kid owns in each slot, the free ones included.
     *
     * @return array<string, list<string>>
     */
    public function ownedKeys(Profile $kid): array
    {
        $bought = PetPrize::where('profile_id', $kid->id)->get(['slot', 'key']);
        $owned = [];

        foreach (PrizeSlot::cases() as $slot) {
            $free = array_column(array_filter($slot->items(), fn (array $item) => $item['cost'] === 0), 'key');
            $paid = $bought->where('slot', $slot)->pluck('key')->all();

            $owned[$slot->value] = array_values(array_unique([...$free, ...$paid]));
        }

        return $owned;
    }

    /** What the pet has out in a slot. The snack falls back to the house one. */
    public function inUse(Profile $kid, PrizeSlot $slot): ?string
    {
        $key = $kid->{$slot->column()};

        if ($key !== null && $slot->item($key) !== null) {
            return $key;
        }

        return $slot === PrizeSlot::Snack ? 'meat' : null;
    }

    /**
     * The whole of what the pet has out, for the pet layer.
     *
     * @return array{snack: string, toy: ?string, bed: ?string}
     */
    public function gearFor(Profile $kid): array
    {
        return [
            'snack' => $this->inUse($kid, PrizeSlot::Snack) ?? 'meat',
            'toy' => $this->inUse($kid, PrizeSlot::Toy),
            'bed' => $this->inUse($kid, PrizeSlot::Bed),
        ];
    }

    /**
     * Buys a pet prize and puts it out.
     *
     * @throws InsufficientTokensException
     * @throws RuntimeException when the prize does not exist or is already owned
     */
    public function buy(Profile $kid, PrizeSlot $slot, string $key): PetPrize
    {
        $item = $slot->item($key);

        if (! $kid->isKid() || $item === null || $item['cost'] === 0) {
            throw new RuntimeException('That is not for sale.');
        }

        if ($this->owns($kid, $slot, $key)) {
            throw new RuntimeException('You already have that one.');
        }

        try {
            return DB::transaction(function () use ($kid, $slot, $item) {
                // Written before the spend so the unique key stops a double tap
                // paying twice for one thing — the second insert fails and its
                // transaction, spend and all, goes with it.
                $prize = PetPrize::create([
                    'household_id' => $kid->household_id,
                    'profile_id' => $kid->id,
                    'slot' => $slot,
                    'key' => $item['key'],
                    'tokens_paid' => $item['cost'],
                ]);

                $this->tokens->spend($kid, $item['cost'], TokenKind::Prize, $item['name'].' for your pet', $prize);

                $kid->forceFill([$slot->column() => $item['key']])->save();

                return $prize;
            });
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('You already have that one.');
        }
    }

    /** Puts an owned prize out. Swapping is free. */
    public function putOut(Profile $kid, PrizeSlot $slot, string $key): bool
    {
        if (! $this->owns($kid, $slot, $key)) {
            return false;
        }

        $kid->forceFill([$slot->column() => $key])->save();

        return true;
    }

    /** Puts a toy or bed away, so the pet has none out. The snack cannot be. */
    public function putAway(Profile $kid, PrizeSlot $slot): void
    {
        if ($slot === PrizeSlot::Snack) {
            return;
        }

        $kid->forceFill([$slot->column() => null])->save();
    }

    /**
     * The sweets on this household's counter, cheapest first.
     *
     * @return Collection<int, Candy>
     */
    public function candiesFor(Household $household): Collection
    {
        return Candy::where('household_id', $household->id)
            ->onCounter()
            ->orderBy('tokens')
            ->orderBy('name')
            ->get();
    }

    /**
     * Buys a sweet: tokens off now, a row in the grown-ups' queue.
     *
     * @throws InsufficientTokensException
     * @throws RuntimeException when it is gone from the counter or the cupboard
     */
    public function buyCandy(Profile $kid, Candy $candy): CandyOrder
    {
        if (! $kid->isKid() || $candy->household_id !== $kid->household_id) {
            throw new RuntimeException('That is not for sale.');
        }

        $order = DB::transaction(function () use ($kid, $candy) {
            // Locked so the last one in the cupboard can only be bought once.
            $fresh = Candy::whereKey($candy->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->retired_at !== null) {
                throw new RuntimeException('That one is off the counter.');
            }

            if (! $fresh->isInStock()) {
                throw new RuntimeException('None left in the cupboard.');
            }

            $order = CandyOrder::create([
                'household_id' => $kid->household_id,
                'profile_id' => $kid->id,
                'candy_id' => $fresh->id,
                'name' => $fresh->name,
                'tokens' => $fresh->tokens,
                'hue' => $fresh->hue,
                'status' => CandyOrderStatus::Waiting,
            ]);

            $this->tokens->spend($kid, $fresh->tokens, TokenKind::Candy, $fresh->name, $order);

            $fresh->decrement('stock');

            return $order;
        });

        $parents = Profile::where('household_id', $kid->household_id)
            ->where('role', ProfileRole::Parent)
            ->get();

        // Best-effort, as in StoreService: the tokens are spent and the order is
        // in the queue, so a failed push must not fail the purchase.
        try {
            Notification::send($parents, new ParentApprovalNeeded(
                'Sweets bought',
                "{$kid->name} bought {$order->name} at the arcade counter.",
            ));
        } catch (Throwable $e) {
            Log::error('Parent notification failed for a candy order.', [
                'candy_order_id' => $order->id,
                'exception' => $e,
            ]);
        }

        return $order;
    }

    /**
     * Sweets waiting on a grown-up, oldest first — the oldest is the one a kid
     * has been waiting longest for.
     *
     * @return Collection<int, CandyOrder>
     */
    public function queueFor(Household $household): Collection
    {
        return CandyOrder::with('profile')
            ->where('household_id', $household->id)
            ->where('status', CandyOrderStatus::Waiting)
            ->oldest()
            ->oldest('id')
            ->get();
    }

    /** How many of this kid's sweets are still waiting. */
    public function waitingCountFor(Profile $kid): int
    {
        return CandyOrder::where('profile_id', $kid->id)
            ->where('status', CandyOrderStatus::Waiting)
            ->count();
    }

    public function handOver(CandyOrder $order, Profile $parent): bool
    {
        if (! $order->isWaiting()) {
            return false;
        }

        $order->update([
            'status' => CandyOrderStatus::HandedOver,
            'decided_by_profile_id' => $parent->id,
            'decided_at' => now(),
        ]);

        try {
            $order->profile->notify(new RedemptionDecided(
                'Ready for you!',
                "{$order->name} is yours — go and collect it.",
            ));
        } catch (Throwable $e) {
            Log::error('Kid notification failed for a handed-over candy order.', [
                'candy_order_id' => $order->id,
                'exception' => $e,
            ]);
        }

        return true;
    }

    /**
     * Turns sweets down and gives the tokens back, in the Loot Shop's words so
     * there is one way of saying "sorry, not this one". The sweet goes back in
     * the cupboard too, since it never left it.
     */
    public function refund(CandyOrder $order, Profile $parent): bool
    {
        if (! $order->isWaiting()) {
            return false;
        }

        DB::transaction(function () use ($order, $parent) {
            $order->update([
                'status' => CandyOrderStatus::Refunded,
                'decided_by_profile_id' => $parent->id,
                'decided_at' => now(),
            ]);

            $this->tokens->record($order->profile, TokenKind::Refund, $order->tokens, $order->name.' refunded', null, $order);

            Candy::whereKey($order->candy_id)->increment('stock');
        });

        try {
            $order->profile->notify(new RedemptionDecided(
                'Not this time',
                "{$order->name} was turned down. Your {$order->tokens} tokens are back.",
            ));
        } catch (Throwable $e) {
            Log::error('Kid notification failed for a refunded candy order.', [
                'candy_order_id' => $order->id,
                'exception' => $e,
            ]);
        }

        return true;
    }
}
