<?php

namespace App\Services;

use App\Enums\CosmeticSlot;
use App\Enums\CosmeticStock;
use App\Enums\PetStage;
use App\Exceptions\CosmeticUnavailableException;
use App\Exceptions\InsufficientTicketsException;
use App\Models\Cosmetic;
use App\Models\Household;
use App\Models\OwnedCosmetic;
use App\Models\PetEgg;
use App\Models\Profile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pets growing up.
 *
 * Every approved chore grows whichever pet the kid has out by one; enough of
 * them and it goes from baby to young to grown up — see PetStage. Growth is kept
 * on the kid's own copy of the pet (its `owned_cosmetics` row), so swapping pets
 * never resets anything: the one put away is exactly as big when it comes back
 * out. The only way back to a baby is the kid asking for it.
 *
 * Nothing here takes growth away on its own. There is no hunger that runs down
 * and no pet that shrinks for a quiet week — feeding is play, and it lives
 * entirely in the browser (resources/js/pets.js).
 *
 * **Surprise eggs.** Every egg-only pet (stock 'egg', never sold as itself) is
 * one egg in the Locker, in a colour of its own; which pet is inside is the
 * surprise. The first kid to buy an egg has it, and it is gone for everybody.
 * While unhatched it is out on their pages in place of their pet, and each
 * approved chore cracks it instead of growing anything; the fifth crack hatches
 * the pet inside, as a baby. One egg at a time per kid.
 */
class PetService
{
    public function __construct(
        private CosmeticService $cosmetics,
        private TicketService $tickets,
    ) {}

    /** The kid's own copy of a pet, or null when they don't have one. */
    private function copyOf(Profile $kid, Cosmetic $pet): ?OwnedCosmetic
    {
        if ($pet->slot !== CosmeticSlot::Pet) {
            return null;
        }

        return OwnedCosmetic::where('profile_id', $kid->id)->where('cosmetic_id', $pet->id)->first();
    }

    public function growthOf(Profile $kid, Cosmetic $pet): int
    {
        return $this->copyOf($kid, $pet)?->growth ?? 0;
    }

    public function stageOf(Profile $kid, Cosmetic $pet): PetStage
    {
        return PetStage::forGrowth($this->growthOf($kid, $pet));
    }

    /**
     * Every pet this kid owns, at the stage their own copy has reached — one
     * query for a whole shelf of them.
     *
     * @return array<int, PetStage> keyed by cosmetic id
     */
    public function stagesFor(Profile $kid): array
    {
        return OwnedCosmetic::query()
            ->where('owned_cosmetics.profile_id', $kid->id)
            ->join('cosmetics', 'cosmetics.id', '=', 'owned_cosmetics.cosmetic_id')
            ->where('cosmetics.slot', CosmeticSlot::Pet->value)
            ->pluck('owned_cosmetics.growth', 'owned_cosmetics.cosmetic_id')
            ->map(fn (int $growth) => PetStage::forGrowth($growth))
            ->all();
    }

    /**
     * Chores left until the next stage, or null once it is grown up.
     */
    public function choresToGrow(Profile $kid, Cosmetic $pet): ?int
    {
        $growth = $this->growthOf($kid, $pet);
        $next = PetStage::forGrowth($growth)->next();

        return $next === null ? null : $next->startsAt() - $growth;
    }

    /**
     * One approved chore's worth, for whatever the kid has out: a crack in
     * their egg if they have one, a step of growing for their pet if not.
     *
     * @return string|null a line for the approval to say, when something
     *                     worth saying happened — a crack, a hatch, a new age
     */
    public function grow(Profile $kid): ?string
    {
        $egg = $this->eggFor($kid);

        if ($egg !== null) {
            return $this->crack($kid, $egg);
        }

        return match ($this->growPet($kid)) {
            PetStage::Young => 'Your pet is growing up!',
            PetStage::Adult => 'Your pet is all grown up!',
            default => null,
        };
    }

    /**
     * One step of growing for the pet the kid has out.
     *
     * @return PetStage|null the stage it has just grown into, if it did
     */
    private function growPet(Profile $kid): ?PetStage
    {
        $pet = $this->cosmetics->wornIn($kid, CosmeticSlot::Pet);
        $copy = $pet ? $this->copyOf($kid, $pet) : null;

        if ($copy === null) {
            return null;
        }

        $before = PetStage::forGrowth($copy->growth);
        $copy->increment('growth');
        $after = PetStage::forGrowth($copy->growth);

        return $after === $before ? null : $after;
    }

    /** Back to a baby, because the kid asked. Only ever their own copy. */
    public function raiseAgain(Profile $kid, Cosmetic $pet): void
    {
        $this->copyOf($kid, $pet)?->update(['growth' => 0]);
    }

    /** The kid's unhatched egg, if they have one out. */
    public function eggFor(Profile $kid): ?PetEgg
    {
        return PetEgg::where('profile_id', $kid->id)->whereNull('hatched_at')->latest('id')->first();
    }

    /**
     * The eggs in the Locker: one per egg-only pet in the house that nobody
     * has yet — not bought in an egg, not hatched, not owned. Published and
     * still stocked, like anything else on sale.
     *
     * @return Collection<int, Cosmetic> the pets inside, keyed by nothing
     */
    public function eggsForSale(Household $household): Collection
    {
        $taken = PetEgg::where('household_id', $household->id)
            ->whereNotNull('cosmetic_id')
            ->pluck('cosmetic_id');

        $owned = OwnedCosmetic::where('household_id', $household->id)->pluck('cosmetic_id');

        return $this->cosmetics->catalog($household)
            ->filter(fn (Cosmetic $item) => $item->slot === CosmeticSlot::Pet
                && $item->stock === CosmeticStock::Egg
                && ! $item->isDraft()
                && ! $item->isPulled()
                && ! $taken->contains($item->id)
                && ! $owned->contains($item->id))
            ->values();
    }

    /** Whether this kid may buy an egg right now: a kid, with no egg out. */
    public function canBuyEgg(Profile $kid): bool
    {
        return $kid->isKid() && $this->eggFor($kid) === null;
    }

    /**
     * Buys one particular egg — the pet inside decided now, by which egg was
     * picked — and puts it straight out on the kid's pages.
     *
     * @throws InsufficientTicketsException
     * @throws CosmeticUnavailableException
     */
    public function buyEgg(Profile $kid, Cosmetic $pet): PetEgg
    {
        if (! $kid->isKid() || $pet->household_id !== $kid->household_id) {
            throw new CosmeticUnavailableException('Eggs are for the kids.');
        }

        if ($this->eggFor($kid) !== null) {
            throw new CosmeticUnavailableException('One egg at a time — hatch the one you have first.');
        }

        if (! $this->eggsForSale($kid->household)->contains('id', $pet->id)) {
            throw new CosmeticUnavailableException('Somebody got to that egg first.');
        }

        try {
            return DB::transaction(function () use ($kid, $pet) {
                // Fresh, inside the transaction: the header can hold a stale copy.
                $kid->refresh();

                $egg = PetEgg::create([
                    'household_id' => $kid->household_id,
                    'profile_id' => $kid->id,
                    'cosmetic_id' => $pet->id,
                    'tickets_paid' => PetEgg::PRICE,
                ]);

                $this->tickets->spend($kid, PetEgg::PRICE, 'Bought a surprise egg', $egg);

                return $egg;
            });
        } catch (UniqueConstraintViolationException) {
            // A sibling bought it in the same moment; the index settled it.
            throw new CosmeticUnavailableException('Somebody got to that egg first.');
        }
    }

    /** A crack in the egg, and the hatch on the last one. */
    private function crack(Profile $kid, PetEgg $egg): string
    {
        $egg->update(['cracks' => min(PetEgg::CRACKS_TO_HATCH, $egg->cracks + 1)]);

        if ($egg->cracks < PetEgg::CRACKS_TO_HATCH) {
            $left = $egg->choresToHatch();

            return "Your egg cracked! {$left} more ".($left === 1 ? 'chore' : 'chores').' and it hatches.';
        }

        $pet = $this->hatch($kid, $egg);

        return $pet
            ? "Your egg hatched — meet {$pet->name}!"
            : 'Your egg is ready to hatch — it is waiting for its pet.';
    }

    /**
     * Hatches a fully cracked egg into the pet inside it, as a baby, and puts
     * it out. The pet was fixed when the egg was bought, so a grown-up pulling
     * it from stock afterwards changes nothing: it was already theirs.
     *
     * An egg bought before eggs held a particular pet hatches into whichever
     * egg pet is still going; if there is none, it waits, fully cracked, and
     * hatches on a later chore.
     */
    public function hatch(Profile $kid, PetEgg $egg): ?Cosmetic
    {
        if ($egg->isHatched() || $egg->cracks < PetEgg::CRACKS_TO_HATCH) {
            return null;
        }

        $pet = $egg->cosmetic_id !== null
            ? $this->cosmetics->catalog($kid->household)->get($egg->cosmetic_id)
            : $this->eggsForSale($kid->household)->first();

        if ($pet === null) {
            return null;
        }

        DB::transaction(function () use ($kid, $egg, $pet) {
            OwnedCosmetic::firstOrCreate(
                ['profile_id' => $kid->id, 'cosmetic_id' => $pet->id],
                ['household_id' => $kid->household_id, 'tickets_paid' => 0, 'growth' => 0],
            );

            $egg->update(['hatched_cosmetic_id' => $pet->id, 'hatched_at' => now()]);
        });

        $this->cosmetics->forget();
        $this->cosmetics->wear($kid->fresh(), $pet);

        return $pet;
    }

    /**
     * An egg that hatched and hasn't been watched hatching yet — marked seen
     * as it is handed over, so the hatching plays on one page and not again.
     */
    public function takeHatchReveal(Profile $kid): ?PetEgg
    {
        $egg = PetEgg::where('profile_id', $kid->id)
            ->whereNotNull('hatched_at')
            ->whereNull('revealed_at')
            ->latest('id')
            ->first();

        $egg?->update(['revealed_at' => now()]);

        return $egg;
    }

    /**
     * What the pet layer needs to draw a kid's pet out: the sheet for its
     * stage, the effect laid over it, and how big to draw it — or, while an
     * egg is out, the egg and how cracked it is.
     *
     * @return array{src: ?string, effect: ?string, scale: float, stage: string, egg?: int, hue?: int}|null
     */
    public function spriteFor(Profile $owner): ?array
    {
        $egg = $this->eggFor($owner);

        if ($egg !== null) {
            return ['src' => null, 'effect' => null, 'scale' => PetStage::Baby->scale(), 'stage' => 'egg', 'egg' => $egg->cracks, 'hue' => $egg->hue()];
        }

        $pet = $this->cosmetics->wornIn($owner, CosmeticSlot::Pet);

        if ($pet === null) {
            return null;
        }

        $stage = $this->stageOf($owner, $pet);

        return [
            'src' => $pet->artUrl($stage),
            'effect' => $pet->effect?->cssClass(),
            'scale' => $pet->drawScale($stage),
            'stage' => $stage->value,
        ];
    }
}
