<?php

namespace App\Services;

use App\Enums\CosmeticSlot;
use App\Enums\PetStage;
use App\Models\Cosmetic;
use App\Models\OwnedCosmetic;
use App\Models\Profile;

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
 */
class PetService
{
    public function __construct(private CosmeticService $cosmetics) {}

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
     * One approved chore's worth of growing, for the pet the kid has out.
     *
     * @return PetStage|null the stage it has just grown into, when this chore
     *                       was the one that did it — so the approval can say so
     */
    public function grow(Profile $kid): ?PetStage
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

    /**
     * What the pet layer needs to draw a kid's pet out: the sheet for its
     * stage, the effect laid over it, and how big to draw it.
     *
     * @return array{src: string, effect: ?string, scale: float, stage: string}|null
     */
    public function spriteFor(Profile $owner): ?array
    {
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
