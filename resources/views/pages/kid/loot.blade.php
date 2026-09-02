<?php

use App\Enums\LootCategory;
use App\Exceptions\InsufficientPointsException;
use App\Exceptions\InsufficientTicketsException;
use App\Exceptions\LevelTooLowException;
use App\Exceptions\LuckyBlockEmptyException;
use App\Models\Chore;
use App\Models\LuckyHit;
use App\Models\Profile;
use App\Models\StoreItem;
use App\Services\GratitudeService;
use App\Services\LuckyBlockService;
use App\Services\StoreService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Session;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * The three shelves the catalog is split across. `min` is inclusive, `max`
     * exclusive, and the last band is open-ended. Sub-labels are derived from
     * these bounds rather than written out, so moving a threshold can't leave a
     * heading advertising the old one.
     *
     * @var array<int, array{label: string, min: int, max: ?int}>
     */
    private const BANDS = [
        ['label' => 'Treat yourself', 'min' => 0, 'max' => 150],
        ['label' => 'Worth a few days', 'min' => 150, 'max' => 1000],
        ['label' => 'Big ticket', 'min' => 1000, 'max' => null],
    ];

    /** e.g. "Under 150 pts", "150 – 999 pts", "1000 pts and up". */
    private function bandLabel(int $min, ?int $max): string
    {
        return match (true) {
            $min === 0 => "Under {$max} pts",
            $max === null => "{$min} pts and up",
            default => $min.' – '.($max - 1).' pts',
        };
    }

    public Profile $profile;

    public ?string $flashMessage = null;

    /** Transient — the goal picker is open for this visit only. */
    public bool $pickingSaving = false;

    public string $search = '';

    /**
     * Which rewards were new when this visit started.
     *
     * Snapshotted in mount() and *not* recomputed, for the same reason the
     * chest animations are: marking the shop seen has to happen the moment
     * they arrive so the tab badge clears, but the NEW chips have to stay up
     * for the whole visit. Recomputing in with() would strip every chip off
     * the page the first time they tapped anything.
     *
     * @var array<int, int>
     */
    public array $newItemIds = [];

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isKid(), 403);

        $store = app(StoreService::class);

        $this->newItemIds = StoreItem::where('household_id', $this->profile->household_id)
            ->when(
                $this->profile->loot_seen_at !== null,
                fn ($query) => $query->where('created_at', '>', $this->profile->loot_seen_at),
            )
            ->pluck('id')
            ->all();

        // After the snapshot, never before — this is the call that closes the
        // gap the snapshot exists to describe.
        $store->markShopSeen($this->profile);
    }

    public function clearSearch(): void
    {
        $this->search = '';
    }

    /**
     * The hit this visit produced, if any.
     *
     * Transient by design: the reveal is the answer to a tap, so it belongs to
     * the tap. Reloading the page puts the block back rather than replaying a
     * win — the pending prize is a grown-up's problem from that point, and it
     * is in their approvals queue.
     */
    public ?int $luckyHitId = null;

    /**
     * Three tickets in, one prize out. The deduction and the draw happen
     * together, server-side, in one transaction — see LuckyBlockService::hit().
     */
    public function hitLuckyBlock(): void
    {
        try {
            $hit = app(LuckyBlockService::class)->hit($this->profile);

            $this->luckyHitId = $hit->id;
            $this->flashMessage = null;
        } catch (InsufficientTicketsException|LuckyBlockEmptyException $e) {
            $this->flashMessage = $e->getMessage();
        }
    }

    public function dismissLuckyBlock(): void
    {
        $this->luckyHitId = null;
    }

    public function togglePicker(): void
    {
        $this->pickingSaving = ! $this->pickingSaving;
    }

    public function saveFor(int $itemId): void
    {
        $item = StoreItem::where('household_id', $this->profile->household_id)->find($itemId);

        if (! $item) {
            return;
        }

        $this->profile->saving_for_store_item_id = $item->id;
        $this->profile->save();

        $this->pickingSaving = false;
    }

    public function redeem(int $itemId): void
    {
        $item = StoreItem::find($itemId);

        if (! $item || $item->household_id !== $this->profile->household_id) {
            return;
        }

        try {
            app(StoreService::class)->redeem($this->profile, $item);
            $this->flashMessage = 'Ask a parent to release it.';
            $this->dispatch('celebrate', message: "{$item->name} cashed out!");
        } catch (InsufficientPointsException|LevelTooLowException $e) {
            $this->flashMessage = $e->getMessage();
        }
    }

    public function with(): array
    {
        $items = StoreItem::where('household_id', $this->profile->household_id)
            ->orderBy('cost')
            ->get();

        $saving = $this->profile->savingFor;

        // "How many more chores is that?" is the only unit of effort a six-year
        // old can actually price things in. Floored at 25 so a household of
        // cheap chores can't turn a 2,000pt goal into a four-figure estimate.
        $averageChorePoints = max(25, (int) round(
            Chore::where('household_id', $this->profile->household_id)->avg('points') ?? 0
        ));

        $remaining = $saving ? max(0, $saving->cost - $this->profile->points) : 0;

        // Only the shelves narrow. The goal picker keeps the whole catalog:
        // it answers "what am I aiming at next?", which a search typed to find
        // something else has no business shortening.
        $matching = $items->filter(fn (StoreItem $item) => $item->matches($this->search));

        $lucky = app(LuckyBlockService::class);

        $store = app(StoreService::class);

        $favoriteIds = $store->favoriteIdsFor($this->profile);
        $boughtBefore = $store->boughtBeforeFor($this->profile);

        // Read off the mount snapshot, not off loot_seen_at — that marker has
        // already been moved to now.
        $isNew = array_flip($this->newItemIds);
        $newItems = $matching->filter(fn (StoreItem $item) => isset($isNew[$item->id]));

        return [
            // The Lucky Block's whole state. Only three things here move: the
            // ticket count, the journal boolean and the pool — everything else
            // on that card is fixed copy.
            'luckyPool' => $lucky->poolFor($this->profile),
            'luckyHit' => $this->luckyHitId === null
                ? null
                : LuckyHit::where('profile_id', $this->profile->id)
                    ->with('luckyPrize')
                    ->find($this->luckyHitId),
            'luckyJournalDone' => ! app(GratitudeService::class)->isAvailable($this->profile),
            'items' => $items,
            'matchCount' => $matching->count(),
            'catalogCount' => $items->count(),
            'favoriteIds' => $favoriteIds,
            'boughtBefore' => $boughtBefore,
            'newItems' => $newItems,
            'isNew' => $isNew,
            // Starred first, then whatever they keep coming back to. Two
            // different signals, one shelf: a star is a wish, a repeat buy is
            // a habit, and both belong above the wall rather than inside it.
            'pinned' => $matching
                ->filter(fn (StoreItem $item) => isset($favoriteIds[$item->id]) || isset($boughtBefore[$item->id]))
                ->sortByDesc(fn (StoreItem $item) => [
                    isset($favoriteIds[$item->id]) ? 1 : 0,
                    $boughtBefore[$item->id] ?? 0,
                ])
                ->values(),
            'categories' => collect(LootCategory::cases())
                ->map(fn (LootCategory $category) => [
                    'category' => $category,
                    'items' => $matching->filter(fn (StoreItem $item) => $item->category === $category),
                ])
                ->reject(fn (array $shelf) => $shelf['items']->isEmpty())
                // Anything nobody has filed still has to be reachable, or the
                // category view would quietly hide part of the shop.
                ->push([
                    'category' => null,
                    'items' => $matching->filter(fn (StoreItem $item) => $item->category === null),
                ])
                ->reject(fn (array $shelf) => $shelf['items']->isEmpty())
                ->values(),
            'saving' => $saving,
            'savingPercent' => $saving && $saving->cost > 0
                ? min(100, (int) round($this->profile->points / $saving->cost * 100))
                : 0,
            'savingRemaining' => $remaining,
            'choresToGo' => $averageChorePoints > 0 ? (int) ceil($remaining / $averageChorePoints) : 0,
            'bands' => collect(self::BANDS)
                ->map(fn (array $band) => [
                    ...$band,
                    'sub' => $this->bandLabel($band['min'], $band['max']),
                    'items' => $matching->filter(fn (StoreItem $item) => $item->cost >= $band['min']
                        && ($band['max'] === null || $item->cost < $band['max'])),
                ])
                ->reject(fn (array $band) => $band['items']->isEmpty()),
        ];
    }

    /**
     * How the shelves are grouped. Price is the old view and still the right
     * one for "what can I afford"; category answers "what kind of thing do I
     * want", which is the question a kid who won't read the shop actually has.
     *
     * Kept in the session because the Shop is two panels behind one rail
     * button now: flipping to Bonus and back has to return the kid to the shop
     * they left, and the grouping is the largest thing about it. It is a
     * preference besides — nobody picks a way of reading the shelves and means
     * it for one visit.
     */
    #[Session]
    public string $view = 'category';

    public function setView(string $view): void
    {
        $this->view = $view === 'price' ? 'price' : 'category';
    }

    public function toggleFavorite(int $itemId): void
    {
        $item = StoreItem::where('household_id', $this->profile->household_id)->find($itemId);

        if ($item) {
            app(StoreService::class)->toggleFavorite($this->profile, $item);
        }
    }
}; ?>

<x-kid.shell :profile="$profile" active="loot">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="font-baloo text-[26px] font-extrabold">Loot Shop</h2>
            <p class="text-sm text-fq-text-3">100 points = $1. Cash-outs need a parent tap to release.</p>
        </div>
        <span class="rounded-[10px] border border-fq-line-2 bg-fq-sunk px-3 py-2 font-mono-fq text-xs text-fq-lime">
            BALANCE {{ $profile->points }} PTS
        </span>
    </div>

    @if ($flashMessage)
        <p class="mt-3 text-sm font-semibold text-fq-lime">{{ $flashMessage }}</p>
    @endif

    {{-- Pinned above the goal card, the search and the whole catalog run.
         It is the only thing in the shop bought with tickets rather than
         points, so it goes where nothing has to be scrolled past to find it.
         --}}
    <x-lucky-block
        :pool="$luckyPool"
        :tickets="$profile->bonus_tickets"
        :journal-done="$luckyJournalDone"
        :hit="$luckyHit"
    />

    @if ($saving)
        @php $savingAccent = $saving->color_tag->cssVar(); @endphp

        <div
            class="relative mt-4 overflow-hidden rounded-[24px] border border-fq-line-3 p-5"
            style="background: var(--fq-wash-goal)"
        >
            <div class="flex flex-wrap items-start justify-between gap-[14px]">
                <div class="min-w-[200px]">
                    <p class="font-mono-fq text-[10px] tracking-[0.22em] text-fq-text-4 uppercase">Saving up for</p>
                    <div class="mt-[6px] flex items-center gap-[10px]">
                        <span class="h-[10px] w-[10px] shrink-0 rounded-full" style="background: {{ $savingAccent }}"></span>
                        <h3 class="font-baloo text-[27px] leading-[1.1] font-extrabold">{{ $saving->name }}</h3>
                    </div>
                </div>

                <button
                    type="button"
                    wire:click="togglePicker"
                    class="rounded-[12px] border border-dashed border-fq-line-4 bg-fq-sunk px-[14px] py-[9px] text-[13px] text-fq-text-2-b transition hover:border-solid hover:text-fq-text"
                >Change goal</button>
            </div>

            <div class="mt-4 h-[18px] overflow-hidden rounded-full border border-fq-line bg-fq-track">
                <div
                    class="h-full rounded-full transition-[width] duration-500"
                    style="width:{{ $savingPercent }}%; background: linear-gradient(90deg, var(--fq-cyan), var(--fq-lime), var(--fq-gold))"
                ></div>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-4">
                {{-- Balance clamped to the cost: an over-funded goal reading
                     "1240 / 150" looks like a bug rather than a win. --}}
                <span class="font-mono-fq text-[11px] text-fq-text-2">
                    {{ min($profile->points, $saving->cost) }} / {{ $saving->cost }} PTS
                </span>
                <span class="font-mono-fq text-[11px] text-fq-text-4">{{ $savingPercent }}%</span>

                @if ($savingRemaining > 0)
                    <span class="rounded-full border border-fq-line-3 bg-fq-sunk px-[13px] py-[7px] font-baloo text-sm font-bold text-fq-lime">
                        &approx; {{ $choresToGo }} more {{ Str::plural('chore', $choresToGo) }}
                    </span>
                @elseif ($saving->isLockedFor($profile))
                    {{-- Saving toward something still locked is allowed, and is
                         half the point of the gate. Funded but locked says so
                         rather than offering a button that would refuse. --}}
                    <span
                        class="rounded-full border bg-fq-sunk px-[13px] py-[7px] font-baloo text-sm font-bold"
                        style="border-color: {{ App\Enums\Rank::fromLevel($saving->min_level)->ringVar() }}; color: {{ App\Enums\Rank::fromLevel($saving->min_level)->ringVar() }}"
                    >Saved up — unlocks at LVL {{ $saving->min_level }}</span>
                @else
                    <button
                        type="button"
                        wire:click="redeem({{ $saving->id }})"
                        class="ml-auto rounded-[14px] px-5 py-[11px] font-baloo text-base font-extrabold text-fq-bg transition hover:brightness-110"
                        style="background: var(--fq-gold)"
                    >Cash it out</button>
                @endif
            </div>

            @if ($pickingSaving)
                <div class="mt-4 border-t border-fq-line pt-[14px]">
                    <p class="mb-[10px] font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                        Pick something to save for
                    </p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($items as $choice)
                            @php $isGoal = $choice->id === $saving->id; @endphp
                            <button
                                type="button"
                                wire:click="saveFor({{ $choice->id }})"
                                class="flex items-center gap-2 rounded-[13px] border bg-fq-sunk px-[13px] py-[9px] text-[13px] text-fq-chip-text transition hover:brightness-120"
                                style="border-color: {{ $isGoal ? $choice->color_tag->cssVar() : 'var(--fq-line-2)' }}"
                            >
                                <span class="h-2 w-2 rounded-full" style="background: {{ $choice->color_tag->cssVar() }}"></span>
                                {{ $choice->name }}
                                <span class="font-mono-fq text-[11px] text-fq-text-4">{{ $choice->cost }} pts</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif

    <div class="mt-[22px] flex flex-wrap items-center gap-2">
        <input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Find a reward"
            class="min-w-[160px] flex-1 rounded-[14px] border border-fq-line-2 bg-fq-sunk px-4 py-[10px] text-sm outline-none focus:border-fq-cyan"
        >
        @if (trim($search) !== '')
            <span class="font-mono-fq text-[10px] whitespace-nowrap text-fq-text-4">
                {{ $matchCount }} / {{ $catalogCount }}
            </span>
            <button
                type="button"
                wire:click="clearSearch"
                class="rounded-[14px] border border-fq-line-3 bg-fq-sunk px-3 py-[10px] text-xs text-fq-text-3"
            >Clear</button>
        @endif
    </div>

    @if ($bands->isEmpty() && trim($search) !== '')
        <div class="mt-3 rounded-[18px] border border-dashed border-fq-line-3 bg-fq-panel p-6 text-center text-sm text-fq-text-5">
            Nothing in the shop matches "{{ $search }}".
        </div>
    @endif

    {{-- 1. What they already want. Starred and repeat-bought rewards pinned
         above the wall, because the two things a kid is most likely to be
         after are the thing they wished for and the thing they keep having.
         Absent entirely when there is nothing in it � an empty "favorites"
         heading is just something else to scroll past. --}}
    @if ($pinned->isNotEmpty())
        <div class="mt-[22px]">
            <div class="flex items-baseline gap-[10px] border-b border-fq-line pb-[10px]">
                <h3 class="font-baloo text-[19px] font-extrabold">
                    <i aria-hidden="true" class="fa-solid fa-star mr-2 text-[15px]" style="color: var(--fq-gold)"></i>Yours
                </h3>
                <span class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Starred &amp; bought before</span>
            </div>

            <div class="mt-3 grid grid-cols-[repeat(auto-fit,minmax(216px,1fr))] gap-3">
                @foreach ($pinned as $item)
                    <x-loot-card
                        wire:key="pinned-{{ $item->id }}"
                        :item="$item"
                        :profile="$profile"
                        :saving="$saving"
                        :is-new="isset($isNew[$item->id])"
                        :favorite="isset($favoriteIds[$item->id])"
                        :bought-count="$boughtBefore[$item->id] ?? 0"
                    />
                @endforeach
            </div>
        </div>
    @endif

    {{-- 2. What has just arrived. Pinned rather than flagged in place: the
         whole reason new rewards went unnoticed is that finding one meant
         reading the entire shop, and a chip halfway down a shelf nobody
         scrolls is no better than no chip at all. --}}
    @if ($newItems->isNotEmpty())
        <div class="mt-[22px]">
            <div class="flex items-baseline gap-[10px] border-b pb-[10px]" style="border-color: color-mix(in srgb, var(--fq-lime) 40%, transparent)">
                <h3 class="font-baloo text-[19px] font-extrabold">
                    <i aria-hidden="true" class="fa-solid fa-wand-magic-sparkles mr-2 text-[15px]" style="color: var(--fq-lime)"></i>New in
                </h3>
                <span class="font-mono-fq text-[10px] tracking-[0.14em] uppercase" style="color: var(--fq-lime)">
                    Since you last looked
                </span>
            </div>

            <div class="mt-3 grid grid-cols-[repeat(auto-fit,minmax(216px,1fr))] gap-3">
                @foreach ($newItems as $item)
                    <x-loot-card
                        wire:key="new-{{ $item->id }}"
                        :item="$item"
                        :profile="$profile"
                        :saving="$saving"
                        :is-new="true"
                        :favorite="isset($favoriteIds[$item->id])"
                        :bought-count="$boughtBefore[$item->id] ?? 0"
                    />
                @endforeach
            </div>
        </div>
    @endif

    {{-- 3. The catalogue, grouped two ways.

         They answer two different questions: price answers "what can I afford
         today", kind answers "what sort of thing do I want" � and the second
         is the one a kid who won't read the shop actually has. Kind leads for
         that reason; price is still there for the day they are counting. --}}
    <div class="mt-[26px] flex flex-wrap items-center justify-between gap-3">
        <h3 class="font-baloo text-[19px] font-extrabold">Everything</h3>

        <div class="flex gap-[6px] rounded-full border border-fq-line-2 bg-fq-sunk p-[3px]">
            @foreach ([['category', 'By kind'], ['price', 'By price']] as $option)
                <button
                    type="button"
                    wire:click="setView('{{ $option[0] }}')"
                    @class([
                        'rounded-full px-[14px] py-[6px] font-mono-fq text-[10px] tracking-[0.14em] uppercase transition',
                        'font-semibold' => $view === $option[0],
                    ])
                    style="{{ $view === $option[0]
                        ? 'background: var(--fq-tab-active); color: var(--fq-lime)'
                        : 'background: transparent; color: var(--fq-text-4)' }}"
                >{{ $option[1] }}</button>
            @endforeach
        </div>
    </div>

    <div class="mt-3 flex flex-col gap-[22px]">
        @if ($view === 'category')
            @foreach ($categories as $shelf)
                <div wire:key="cat-{{ $shelf['category']?->value ?? 'other' }}">
                    <div class="flex items-baseline gap-[10px] border-b border-fq-line pb-[10px]">
                        <h4 class="font-baloo text-[17px] font-extrabold">
                            @if ($shelf['category'])
                                <x-chore-icon
                                    :icon="$shelf['category']->faClass()"
                                    class="mr-2 text-[15px]"
                                    style="color: {{ $shelf['category']->colorVar() }}"
                                />
                            @endif
                            {{ $shelf['category']?->label() ?? 'Everything else' }}
                        </h4>
                        <span class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                            {{ $shelf['category']?->blurb() ?? 'Not sorted yet' }}
                        </span>
                        <span class="ml-auto font-mono-fq text-[10px] text-fq-text-5-b">
                            {{ $shelf['items']->count() }} {{ Str::plural('item', $shelf['items']->count()) }}
                        </span>
                    </div>

                    <div class="mt-3 grid grid-cols-[repeat(auto-fit,minmax(216px,1fr))] gap-3">
                        @foreach ($shelf['items'] as $item)
                            <x-loot-card
                                wire:key="item-{{ $item->id }}"
                                :item="$item"
                                :profile="$profile"
                                :saving="$saving"
                                :is-new="isset($isNew[$item->id])"
                                :favorite="isset($favoriteIds[$item->id])"
                                :bought-count="$boughtBefore[$item->id] ?? 0"
                            />
                        @endforeach
                    </div>
                </div>
            @endforeach
        @else
            @foreach ($bands as $band)
                <div wire:key="band-{{ $band['label'] }}">
                    <div class="flex items-baseline gap-[10px] border-b border-fq-line pb-[10px]">
                        <h4 class="font-baloo text-[17px] font-extrabold">{{ $band['label'] }}</h4>
                        <span class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">{{ $band['sub'] }}</span>
                        <span class="ml-auto font-mono-fq text-[10px] text-fq-text-5-b">
                            {{ $band['items']->count() }} {{ Str::plural('item', $band['items']->count()) }}
                        </span>
                    </div>

                    <div class="mt-3 grid grid-cols-[repeat(auto-fit,minmax(216px,1fr))] gap-3">
                        @foreach ($band['items'] as $item)
                            <x-loot-card
                                wire:key="item-{{ $item->id }}"
                                :item="$item"
                                :profile="$profile"
                                :saving="$saving"
                                :is-new="isset($isNew[$item->id])"
                                :favorite="isset($favoriteIds[$item->id])"
                                :bought-count="$boughtBefore[$item->id] ?? 0"
                            />
                        @endforeach
                    </div>
                </div>
            @endforeach
        @endif
    </div>
</x-kid.shell>

