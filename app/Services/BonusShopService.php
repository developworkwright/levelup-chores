<?php

namespace App\Services;

use App\Exceptions\InsufficientTicketsException;
use App\Exceptions\PerkUnavailableException;
use App\Models\BonusPerk;
use App\Models\OwnedPerk;
use App\Models\Profile;
use Illuminate\Support\Collection;

/**
 * The shop only sells. Buying a perk puts it in the kid's pocket — using it is
 * PerkInventoryService's job, and happens whenever they choose.
 *
 * That split is why the shop no longer cares whether a perk is usable right
 * now: buying a Streak Restore before anything is broken is exactly the point.
 */
class BonusShopService
{
    public function __construct(
        private TicketService $tickets,
        private PerkInventoryService $inventory,
    ) {}

    /**
     * @return Collection<int, array{perk: BonusPerk, affordable: bool, owned: int}>
     */
    public function catalogFor(Profile $profile): Collection
    {
        return BonusPerk::where('household_id', $profile->household_id)
            ->enabled()
            ->orderBy('cost')
            ->get()
            ->map(fn (BonusPerk $perk) => [
                'perk' => $perk,
                'affordable' => $profile->bonus_tickets >= $perk->cost,
                'owned' => $this->inventory->countOf($profile, $perk->effect),
            ]);
    }

    /**
     * @throws InsufficientTicketsException
     * @throws PerkUnavailableException
     */
    public function purchase(Profile $profile, BonusPerk $perk): OwnedPerk
    {
        if (! $perk->enabled || $perk->household_id !== $profile->household_id) {
            throw new PerkUnavailableException('That perk is not available.');
        }

        if ($profile->bonus_tickets < $perk->cost) {
            throw new InsufficientTicketsException($perk->cost - $profile->bonus_tickets);
        }

        $owned = $this->inventory->grant($profile, $perk->effect, OwnedPerk::SOURCE_SHOP);

        $this->tickets->spend($profile, $perk->cost, $perk->name, $perk);

        return $owned;
    }
}
