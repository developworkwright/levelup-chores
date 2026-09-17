<?php

namespace App\Services;

use App\Enums\CosmeticSlot;
use App\Enums\CosmeticStock;
use App\Enums\ProfileRole;
use App\Exceptions\CosmeticUnavailableException;
use App\Exceptions\InsufficientTicketsException;
use App\Models\Cosmetic;
use App\Models\CosmeticDrop;
use App\Models\Household;
use App\Models\OwnedCosmetic;
use App\Models\Profile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The cosmetic locker: what is on sale this week, what a kid owns, and what
 * they are wearing.
 *
 * **Nothing here is scheduled.** The app runs on a host that scales to zero, so
 * a week's stock is worked out by whichever page asks first. Rotating stock is
 * pure arithmetic on the ISO week — the same week always picks the same items —
 * and limiteds are minted into `cosmetic_drops` on that first read, because
 * "has this one had its week" is history rather than arithmetic.
 *
 * Registered `scoped`, because the header, the feed and the boards all ask what
 * somebody is wearing on the same request, and the household's catalog is one
 * query however many times they ask.
 */
class CosmeticService
{
    /** How many rotating items are out in any one week. */
    public const ROTATION_SIZE = 15;

    /** @var array<int, Collection<int, Cosmetic>> keyed by household id */
    private array $catalogs = [];

    /** @var array<int, Collection<int, int>> keyed by profile id */
    private array $owned = [];

    /** @var array<int, Collection<int, Cosmetic>> keyed by household id */
    private array $limiteds = [];

    /** @var array<string, Collection<int, Cosmetic>> keyed by household id and week */
    private array $rotations = [];

    /** @var array<int, Household> keyed by id */
    private array $households = [];

    public function __construct(private TicketService $tickets) {}

    /**
     * Every item the household has, drafts and pulled stock included, keyed by
     * id. What somebody is wearing is looked up here, so a pulled item keeps
     * drawing on the kid who bought it.
     *
     * @return Collection<int, Cosmetic>
     */
    public function catalog(Household $household): Collection
    {
        $this->households[$household->id] = $household;

        return $this->catalogFor($household->id);
    }

    /**
     * The same, by id — what a feed row or a board row asks with, since it
     * holds a profile and nothing has loaded that profile's household.
     *
     * @return Collection<int, Cosmetic>
     */
    private function catalogFor(int $householdId): Collection
    {
        return $this->catalogs[$householdId] ??= Cosmetic::where('household_id', $householdId)
            ->orderBy('id')
            ->get()
            ->keyBy('id');
    }

    private function householdOf(Cosmetic $item): Household
    {
        return $this->households[$item->household_id] ??= $item->household;
    }

    /** The ISO week the household is in, as "2026-W38". */
    public function weekOf(Household $household): string
    {
        return HouseholdClock::for($household)->today()->format('o-\WW');
    }

    /** Days left in the household's week, counting today: 1 on a Sunday. */
    public function daysLeftInWeek(Household $household): int
    {
        return 8 - HouseholdClock::for($household)->today()->dayOfWeekIso;
    }

    /**
     * This week's rotating stock.
     *
     * Each item is ranked by a hash of the week and its own id, and the lowest
     * fifteen are out. Ranking items independently rather than shuffling the
     * pool means publishing a new item mid-week displaces at most one, instead
     * of reshuffling the whole shelf under a kid who was saving for something.
     *
     * @return Collection<int, Cosmetic>
     */
    public function rotationThisWeek(Household $household, ?string $week = null): Collection
    {
        $week ??= $this->weekOf($household);

        return $this->rotations[$household->id.'|'.$week] ??= $this->catalog($household)
            ->filter(fn (Cosmetic $item) => $item->stock === CosmeticStock::Rotating && $this->isStocked($item))
            ->sortBy(fn (Cosmetic $item) => crc32($week.'|'.$item->id))
            ->take(self::ROTATION_SIZE)
            ->sortBy('id');
    }

    /**
     * This week's limited editions — one per slot at most, never repeated.
     *
     * Minted here on the first read of the week. A slot with nothing left in
     * its limited pool simply has no limited that week; a grown-up publishing a
     * new one mid-week fills an empty slot straight away and waits for next
     * week otherwise.
     *
     * @return Collection<int, Cosmetic>
     */
    public function limitedThisWeek(Household $household): Collection
    {
        if (isset($this->limiteds[$household->id])) {
            return $this->limiteds[$household->id];
        }

        $week = $this->weekOf($household);
        $catalog = $this->catalog($household);

        $dropped = CosmeticDrop::where('household_id', $household->id)
            ->where('week', $week)
            ->pluck('cosmetic_id');

        $filledSlots = $dropped
            ->map(fn (int $id) => $catalog->get($id)?->slot)
            ->filter()
            ->map(fn (CosmeticSlot $slot) => $slot->value)
            ->unique();

        $candidates = $catalog->filter(fn (Cosmetic $item) => $item->isLimited()
            && $this->isStocked($item)
            && ! $filledSlots->contains($item->slot->value));

        if ($candidates->isNotEmpty()) {
            $retired = CosmeticDrop::where('household_id', $household->id)
                ->whereIn('cosmetic_id', $candidates->keys())
                ->pluck('cosmetic_id');

            $minted = $candidates
                ->reject(fn (Cosmetic $item) => $retired->contains($item->id))
                ->groupBy(fn (Cosmetic $item) => $item->slot->value)
                ->map(fn (Collection $pool) => $pool->sortBy(fn (Cosmetic $item) => crc32($week.'|'.$item->id))->first());

            if ($minted->isNotEmpty()) {
                $now = now();

                // insertOrIgnore, because two kids opening the locker in the
                // same second would both try to mint the week — and the unique
                // index is exactly the rule that should settle it.
                CosmeticDrop::insertOrIgnore($minted->map(fn (Cosmetic $item) => [
                    'household_id' => $household->id,
                    'cosmetic_id' => $item->id,
                    'week' => $week,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all());

                $dropped = CosmeticDrop::where('household_id', $household->id)
                    ->where('week', $week)
                    ->pluck('cosmetic_id');
            }
        }

        return $this->limiteds[$household->id] = $dropped
            ->map(fn (int $id) => $catalog->get($id))
            ->filter(fn (?Cosmetic $item) => $item !== null && $this->isStocked($item))
            ->sortBy(fn (Cosmetic $item) => array_search($item->slot, CosmeticSlot::cases(), true))
            ->values();
    }

    /** Published and not pulled — before asking whether this is its week. */
    private function isStocked(Cosmetic $item): bool
    {
        return ! $item->isDraft() && ! $item->isPulled();
    }

    /** Whether a kid could buy this today. */
    public function isForSale(Cosmetic $item): bool
    {
        if (! $this->isStocked($item)) {
            return false;
        }

        return match ($item->stock) {
            CosmeticStock::Shelf => true,
            CosmeticStock::Rotating => $this->rotationThisWeek($this->householdOf($item))->has($item->id),
            CosmeticStock::Limited => $this->limitedThisWeek($this->householdOf($item))->contains('id', $item->id),
        };
    }

    /**
     * Ids of everything this kid has bought.
     *
     * @return Collection<int, int>
     */
    public function ownedIds(Profile $profile): Collection
    {
        return $this->owned[$profile->id] ??= OwnedCosmetic::where('profile_id', $profile->id)->pluck('cosmetic_id');
    }

    public function owns(Profile $profile, Cosmetic $item): bool
    {
        if ($item->household_id !== $profile->household_id || $item->isDraft()) {
            return false;
        }

        return $item->isFree() || $this->ownedIds($profile)->contains($item->id);
    }

    /**
     * What a kid sees in one slot's grid: this week's stock plus everything
     * they already own. Rotating items out of rotation and limiteds that have
     * had their week are left off rather than greyed out — a card a kid can
     * never tap is a card that says "you missed it".
     *
     * @return Collection<int, Cosmetic>
     */
    public function shelfFor(Profile $profile, CosmeticSlot $slot): Collection
    {
        return $this->catalog($profile->household)
            ->filter(fn (Cosmetic $item) => $item->slot === $slot)
            ->filter(fn (Cosmetic $item) => $this->owns($profile, $item) || $this->isForSale($item))
            ->values();
    }

    /**
     * Buys an item and puts it on. Buying something already owned just wears
     * it, so a double tap can never charge twice.
     *
     * @throws InsufficientTicketsException
     * @throws CosmeticUnavailableException
     */
    public function buy(Profile $profile, Cosmetic $item): void
    {
        if ($item->household_id !== $profile->household_id || ! $profile->isKid()) {
            throw new CosmeticUnavailableException('That one isn\'t in your locker.');
        }

        if ($this->owns($profile, $item)) {
            $this->wear($profile, $item);

            return;
        }

        if (! $this->isForSale($item)) {
            throw new CosmeticUnavailableException($item->isLimited()
                ? 'That one has had its week — it isn\'t coming back.'
                : 'That one isn\'t on sale this week.');
        }

        try {
            DB::transaction(function () use ($profile, $item) {
                // The balance is read fresh inside the transaction: the header
                // and the page can each hold a copy of this kid.
                $profile->refresh();

                OwnedCosmetic::create([
                    'household_id' => $profile->household_id,
                    'profile_id' => $profile->id,
                    'cosmetic_id' => $item->id,
                    'tickets_paid' => $item->cost,
                ]);

                $this->tickets->spend($profile, $item->cost, "Bought {$item->name} for the locker", $item);
            });
        } catch (UniqueConstraintViolationException) {
            // The other tap got there first and paid; this one just wears it.
        }

        unset($this->owned[$profile->id]);

        $this->wear($profile, $item);
    }

    /** @throws CosmeticUnavailableException */
    public function wear(Profile $profile, Cosmetic $item): void
    {
        if (! $this->owns($profile, $item)) {
            throw new CosmeticUnavailableException('Buy that one first.');
        }

        $profile->forceFill([$item->slot->wornColumn() => $item->id])->save();
    }

    /**
     * Back to the house default for a slot. Only means anything for a frame or
     * an avatar — every other slot has a free item that already is the default.
     */
    public function takeOff(Profile $profile, CosmeticSlot $slot): void
    {
        $profile->forceFill([$slot->wornColumn() => null])->save();
    }

    /**
     * The item worn in one slot, or null for the house default.
     */
    public function wornIn(Profile $profile, CosmeticSlot $slot): ?Cosmetic
    {
        $id = $profile->getAttribute($slot->wornColumn());

        if ($id === null || $profile->household_id === null) {
            return null;
        }

        $item = $this->catalogFor($profile->household_id)->get($id);

        return $item && ! $item->isDraft() && $item->slot === $slot ? $item : null;
    }

    /**
     * Everything a kid is wearing, keyed by slot.
     *
     * @return array<string, Cosmetic|null>
     */
    public function worn(Profile $profile): array
    {
        $set = [];

        foreach (CosmeticSlot::cases() as $slot) {
            $set[$slot->value] = $this->wornIn($profile, $slot);
        }

        return $set;
    }

    /**
     * The CSS custom properties a worn theme repaints a kid's pages with, or
     * null when they're wearing the house colours.
     *
     * Surfaces, lines and ink only. The six accents stay put, because they are
     * the kids themselves: a theme that turned lilac orange would repaint
     * whichever sibling is lilac on every page it touched.
     */
    public function themeCss(Profile $profile): ?string
    {
        return $this->themeCssFor($this->wornIn($profile, CosmeticSlot::Theme));
    }

    /**
     * The same, for any theme — which is how the locker repaints the page while
     * a kid tries one on before buying it.
     */
    public function themeCssFor(?Cosmetic $theme): ?string
    {
        $tokens = $theme?->themeTokens();

        if ($tokens === null || $theme->recipe === 'midnight') {
            return null;
        }

        $mix = fn (string $a, int $percent, string $b) => "color-mix(in srgb, {$a} {$percent}%, {$b})";

        $vars = [
            '--fq-bg' => $tokens['bg'],
            '--fq-panel' => $tokens['panel'],
            '--fq-sunk' => $mix($tokens['panel'], 78, $tokens['line']),
            '--fq-panel-alt' => $mix($tokens['panel'], 66, $tokens['line']),
            '--fq-panel-alt-2' => $mix($tokens['panel'], 66, $tokens['line']),
            '--fq-line' => $tokens['line'],
            '--fq-line-2' => $mix($tokens['line'], 86, $tokens['ink']),
            '--fq-line-3' => $mix($tokens['line'], 74, $tokens['ink']),
            '--fq-nav-line' => $mix($tokens['line'], 72, $tokens['bg']),
            '--fq-divider' => $mix($tokens['line'], 72, $tokens['bg']),
            '--fq-track' => $mix($tokens['line'], 64, $tokens['bg']),
            '--fq-text' => $tokens['ink'],
            '--fq-text-2' => $mix($tokens['ink'], 62, $tokens['muted']),
            '--fq-text-3' => $mix($tokens['ink'], 40, $tokens['muted']),
            '--fq-text-4' => $tokens['muted'],
            '--fq-text-5' => $mix($tokens['muted'], 74, $tokens['bg']),
            '--fq-text-6' => $mix($tokens['muted'], 50, $tokens['bg']),
            '--fq-bg-glow' => 'radial-gradient(1100px 700px at 12% -8%, '.$mix($tokens['accent2'], 22, $tokens['bg']).' 0%, '.$tokens['bg'].' 60%)',
        ];

        return collect($vars)->map(fn (string $value, string $name) => "{$name}: {$value};")->implode(' ');
    }

    /**
     * The three numbers across the top of the parent console.
     *
     * @return array{live: int, drafts: int, rotation: int}
     */
    public function counts(Household $household): array
    {
        $catalog = $this->catalog($household);

        return [
            'live' => $catalog->filter(fn (Cosmetic $item) => $this->isStocked($item))->count(),
            'drafts' => $catalog->filter(fn (Cosmetic $item) => $item->isDraft())->count(),
            'rotation' => $this->rotationThisWeek($household)->count() + $this->limitedThisWeek($household)->count(),
        ];
    }

    /**
     * A sibling's pet, come to visit — or null, which is most of the time.
     *
     * Worked out from the kid, the day and the hour rather than rolled, so a
     * refresh does not re-roll it and a visit lasts as long as the hour does.
     * Nothing schedules it: like everything else here, the answer is arithmetic
     * the page does on its way in.
     *
     * Roughly one hour in three has a visitor. Often enough to be a thing that
     * happens, rare enough that it is still a small event when it does.
     */
    public function visitingPet(Profile $kid): ?Cosmetic
    {
        $hour = HouseholdClock::for($kid->household)->now()->format('o-\WW-N-H');

        if (crc32('visit|'.$kid->id.'|'.$hour) % 3 !== 0) {
            return null;
        }

        $siblings = Profile::where('household_id', $kid->household_id)
            ->where('role', ProfileRole::Kid)
            ->whereKeyNot($kid->id)
            ->whereNotNull(CosmeticSlot::Pet->wornColumn())
            ->orderBy('id')
            ->get()
            ->filter(fn (Profile $sibling) => $this->wornIn($sibling, CosmeticSlot::Pet) !== null)
            ->values();

        if ($siblings->isEmpty()) {
            return null;
        }

        $visitor = $siblings[crc32('who|'.$kid->id.'|'.$hour) % $siblings->count()];

        return $this->wornIn($visitor, CosmeticSlot::Pet);
    }

    /** Drops every memo, after anything that changes the catalog or a wardrobe. */
    public function forget(): void
    {
        $this->catalogs = [];
        $this->owned = [];
        $this->limiteds = [];
        $this->rotations = [];
    }
}
