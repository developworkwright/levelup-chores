<?php

use App\Enums\ChoreIcon;
use App\Enums\LootCategory;
use App\Enums\ProfileRole;
use App\Models\LuckyPrize;
use App\Models\Profile;
use App\Services\LuckyBlockService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * The grown-up side of the Lucky Block.
 *
 * Because the odds are flat, **the list carries all of the balance**. There is
 * no weighting to tune and no tier to demote a prize into, so the only lever
 * is what's in the pool — which is why this screen leads with a standing note
 * about keeping the prizes level in value rather than with the editor.
 *
 * No price field anywhere, deliberately. Half the point of the block is that
 * it can hold things the Loot Shop can't price: "a grown-up does your Saturday
 * job" is worth having and worth nothing.
 */
new class extends Component
{
    /** Names a parent can start from rather than facing an empty field. */
    private const PRESETS = [
        ['name' => 'Pick the takeaway', 'icon' => 'fa-solid fa-pizza-slice'],
        ['name' => 'Milkshake', 'icon' => 'fa-solid fa-glass-water'],
        ['name' => 'Bike ride, just you two', 'icon' => 'fa-solid fa-bicycle'],
        ['name' => 'Skip a chore', 'icon' => 'fa-solid fa-broom'],
        ['name' => 'Stay up late', 'icon' => 'fa-solid fa-moon'],
        ['name' => 'Front seat', 'icon' => 'fa-solid fa-car-side'],
    ];

    public Profile $profile;

    public bool $adding = false;

    public string $newPrizeName = '';

    public string $newPrizeFlavor = '';

    public string $newPrizeIcon = '';

    /** '' for everyone, otherwise a kid's profile id as a string. */
    public string $newPrizeScope = '';

    public ?string $flashMessage = null;

    /** Which prize's icon picker is open. One at a time — it is a wall of buttons. */
    public ?int $editingPrizeId = null;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isParent(), 403);
    }

    private function ownedPrize(int $prizeId): ?LuckyPrize
    {
        return LuckyPrize::where('household_id', $this->profile->household_id)->find($prizeId);
    }

    public function toggleAdding(): void
    {
        $this->adding = ! $this->adding;
    }

    public function toggleIconPicker(int $prizeId): void
    {
        $this->editingPrizeId = $this->editingPrizeId === $prizeId ? null : $prizeId;
    }

    public function updatePrizeName(int $prizeId, string $value): void
    {
        $value = trim($value);

        if ($value !== '' && ($prize = $this->ownedPrize($prizeId))) {
            $prize->name = $value;
            // Refiled from the new words: the category is only ever read for
            // the icon's color on the kid's card, so it should follow the
            // name rather than stick to whatever the old one suggested.
            $prize->category = LootCategory::forText($value.' '.$prize->flavor)?->value;
            $prize->save();
        }
    }

    public function updatePrizeFlavor(int $prizeId, string $value): void
    {
        if ($prize = $this->ownedPrize($prizeId)) {
            $prize->flavor = trim($value) ?: null;
            $prize->save();
        }
    }

    /** Sets a prize's face, or clears it when the same icon is tapped again. */
    public function setPrizeIcon(int $prizeId, string $icon): void
    {
        $prize = $this->ownedPrize($prizeId);
        $class = ChoreIcon::normalizeClass($icon);

        if (! $prize || $class === null) {
            return;
        }

        $prize->icon = $prize->icon === $class ? null : $class;
        $prize->save();
    }

    /** Everyone, or one kid. This is how one pool covers a house of siblings. */
    public function setPrizeScope(int $prizeId, ?int $kidId): void
    {
        $prize = $this->ownedPrize($prizeId);

        if (! $prize) {
            return;
        }

        $prize->profile_id = $kidId === null ? null : $this->kids()->firstWhere('id', $kidId)?->id;
        $prize->save();
    }

    public function togglePrize(int $prizeId): void
    {
        if ($prize = $this->ownedPrize($prizeId)) {
            $prize->active = ! $prize->active;
            $prize->save();
        }
    }

    /**
     * Moves a prize up or down the list.
     *
     * Cosmetic, and it has to stay that way: the odds are flat, so a prize at
     * the top is no likelier than one at the bottom. This orders the chips a
     * kid reads before they commit, nothing else.
     */
    public function movePrize(int $prizeId, int $direction): void
    {
        $ordered = $this->prizes()->values();
        $index = $ordered->search(fn (LuckyPrize $prize) => $prize->id === $prizeId);

        if ($index === false) {
            return;
        }

        $target = $index + $direction;

        if ($target < 0 || $target >= $ordered->count()) {
            return;
        }

        // Rewritten across the whole list rather than swapping two rows: the
        // seeded pool and anything added since can share a position, and a
        // swap between two zeroes moves nothing.
        $ordered->splice($index, 1);
        $ordered->splice($target, 0, [$this->ownedPrize($prizeId)]);

        foreach ($ordered as $position => $prize) {
            $prize->position = $position;
            $prize->save();
        }
    }

    public function removePrize(int $prizeId): void
    {
        $this->ownedPrize($prizeId)?->delete();
    }

    public function fillPreset(int $index): void
    {
        if ($preset = self::PRESETS[$index] ?? null) {
            $this->newPrizeName = $preset['name'];
            $this->newPrizeIcon = $preset['icon'];
        }
    }

    public function addPrize(): void
    {
        $name = trim($this->newPrizeName);

        if ($name === '') {
            $this->flashMessage = 'Give the prize a name first.';

            return;
        }

        $flavor = trim($this->newPrizeFlavor);
        $scope = $this->newPrizeScope === '' ? null : (int) $this->newPrizeScope;

        LuckyPrize::create([
            'household_id' => $this->profile->household_id,
            'profile_id' => $scope === null ? null : $this->kids()->firstWhere('id', $scope)?->id,
            'name' => $name,
            'flavor' => $flavor ?: null,
            'icon' => ChoreIcon::normalizeClass($this->newPrizeIcon),
            // Filed from its own words, exactly as a store item is — the only
            // thing it is read for is the icon's color on the kid's card.
            'category' => LootCategory::forText($name.' '.$flavor)?->value,
            'position' => (int) LuckyPrize::where('household_id', $this->profile->household_id)->max('position') + 1,
        ]);

        $this->newPrizeName = '';
        $this->newPrizeFlavor = '';
        $this->newPrizeIcon = '';
        $this->newPrizeScope = '';
        $this->flashMessage = null;
    }

    public function toggleHoldWon(): void
    {
        $household = $this->profile->household;
        $household->lucky_hold_won = ! $household->lucky_hold_won;
        $household->save();
    }

    /** @return \Illuminate\Support\Collection<int, Profile> */
    private function kids(): \Illuminate\Support\Collection
    {
        return Profile::where('household_id', $this->profile->household_id)
            ->where('role', ProfileRole::Kid)
            ->orderByDesc('age')
            ->get()
            ->collect();
    }

    /** @return \Illuminate\Support\Collection<int, LuckyPrize> */
    private function prizes(): \Illuminate\Support\Collection
    {
        return LuckyPrize::where('household_id', $this->profile->household_id)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->collect();
    }

    public function with(): array
    {
        $prizes = $this->prizes();
        $activeCount = $prizes->where('active', true)->count();

        return [
            'prizes' => $prizes,
            'kids' => $this->kids(),
            'activeCount' => $activeCount,
            // Not an error state — a pool this thin still works, it just starts
            // repeating itself where a kid will notice.
            'poolThin' => $activeCount < LuckyBlockService::HEALTHY_POOL,
            'healthyPool' => LuckyBlockService::HEALTHY_POOL,
            'ticketCost' => LuckyBlockService::TICKET_COST,
            'holdWon' => (bool) $this->profile->household->lucky_hold_won,
            'presets' => self::PRESETS,
        ];
    }
}; ?>

<x-parent.shell :profile="$profile" active="lucky">
    <div class="flex flex-col gap-3 rounded-[28px] border border-fq-line bg-fq-bg p-[16px_14px]">
        <div class="flex flex-wrap items-center gap-3">
            <div class="min-w-0 flex-1">
                <h2 class="font-baloo text-xl font-extrabold">Lucky Block prizes</h2>
                <p class="mt-[3px] text-xs text-fq-text-3">
                    {{ $activeCount }} active &middot; equal chance each &middot; {{ $ticketCost }} tickets a hit
                </p>
            </div>

            <button
                type="button"
                wire:click="toggleAdding"
                class="shrink-0 rounded-[11px] px-3 py-[9px] font-baloo text-[13px] font-extrabold transition hover:brightness-110"
                style="background: var(--fq-fill-gold-soft); color: var(--fq-ink)"
            >{{ $adding ? 'Close' : 'Add prize' }}</button>
        </div>

        {{-- Standing advice, not an error. With no tiers, this screen *is* the
             balance, and the number six is not arbitrary — below it the same
             prize starts coming out often enough for a kid to notice. --}}
        <div
            class="flex gap-[10px] rounded-[16px] border px-[13px] py-3"
            style="border-color: var(--fq-ticket-line); background: var(--fq-ticket-bg)"
        >
            <i aria-hidden="true" class="fa-fw fa-solid fa-scale-balanced mt-[2px] text-sm" style="color: var(--fq-lime)"></i>
            <div class="min-w-0 flex-1">
                <p class="text-[13px] font-semibold" style="color: var(--fq-lime)">Keep these roughly level in value.</p>
                <p class="mt-[3px] text-[12.5px] text-fq-notice-text">
                    With no tiers, one big prize among small ones makes every other pull feel like a loss.
                    {{ $healthyPool }} is the fewest that still feels random.
                </p>
            </div>
        </div>

        @if ($poolThin)
            <p class="font-mono-fq text-[10px] tracking-[0.14em] uppercase" style="color: var(--fq-coral)">
                {{ $activeCount === 0
                    ? 'Nothing active — the block is hidden from the kids'
                    : 'Thin pool · '.$activeCount.' of '.$healthyPool }}
            </p>
        @endif

        @if ($flashMessage)
            <p class="text-sm font-semibold text-fq-danger">{{ $flashMessage }}</p>
        @endif

        <div class="flex flex-col gap-2">
            @foreach ($prizes as $prize)
                <div
                    wire:key="prize-{{ $prize->id }}"
                    class="flex flex-col gap-[10px] rounded-[14px] border px-3 py-[10px] {{ $prize->active ? '' : 'opacity-60' }}"
                    style="border-color: {{ $prize->active ? 'var(--fq-line-2)' : 'var(--fq-line)' }};
                           background: {{ $prize->active ? 'var(--fq-panel)' : '#0b0616' }}"
                >
                    <div class="flex flex-wrap items-center gap-[10px]">
                        {{-- Arrows rather than a drag handle. Reordering is
                             cosmetic — it sorts the chips a kid reads and has
                             no effect whatever on the odds — and two buttons
                             say that more honestly than a grip does. --}}
                        <div class="flex shrink-0 flex-col gap-[2px]">
                            <button
                                type="button"
                                wire:click="movePrize({{ $prize->id }}, -1)"
                                aria-label="Move {{ $prize->name }} up"
                                class="grid h-[15px] w-[18px] place-items-center rounded-[4px] text-[9px] text-fq-text-5 hover:text-fq-text"
                            ><i aria-hidden="true" class="fa-solid fa-chevron-up"></i></button>
                            <button
                                type="button"
                                wire:click="movePrize({{ $prize->id }}, 1)"
                                aria-label="Move {{ $prize->name }} down"
                                class="grid h-[15px] w-[18px] place-items-center rounded-[4px] text-[9px] text-fq-text-5 hover:text-fq-text"
                            ><i aria-hidden="true" class="fa-solid fa-chevron-down"></i></button>
                        </div>

                        <button
                            type="button"
                            wire:click="toggleIconPicker({{ $prize->id }})"
                            title="Picture"
                            class="grid h-[34px] w-[34px] shrink-0 place-items-center rounded-[10px] border transition hover:border-fq-lime"
                            style="border-color: {{ $prize->icon ? 'var(--fq-line-3)' : 'var(--fq-line)' }};
                                   background: var(--fq-sunk); color: {{ $prize->colorVar() }}"
                        >
                            @if ($prize->icon)
                                <x-chore-icon :icon="$prize->icon" class="text-[18px]" />
                            @else
                                <i aria-hidden="true" class="fa-solid fa-icons text-[14px] text-fq-text-5"></i>
                            @endif
                        </button>

                        <div class="min-w-[160px] flex-1">
                            <input
                                type="text"
                                value="{{ $prize->name }}"
                                wire:blur="updatePrizeName({{ $prize->id }}, $event.target.value)"
                                class="w-full border-0 border-b border-fq-line-2 bg-transparent py-[2px] text-[13px] font-semibold outline-none focus:border-fq-cyan"
                            >
                            {{-- The line the kid reads under the prize on the
                                 reveal. Optional — the card has a fallback,
                                 because a parent adding a prize in ten seconds
                                 shouldn't have to write copy for it. --}}
                            <input
                                type="text"
                                value="{{ $prize->flavor }}"
                                wire:blur="updatePrizeFlavor({{ $prize->id }}, $event.target.value)"
                                placeholder="A line to go with it (optional)"
                                class="mt-[2px] w-full border-0 bg-transparent py-[2px] text-[11.5px] text-fq-text-4 outline-none focus:text-fq-text"
                            >
                        </div>

                        {{-- On/off rather than delete: switching a prize off is
                             how a parent parks something that is too big for a
                             flat pool without losing the wording. --}}
                        <button
                            type="button"
                            wire:click="togglePrize({{ $prize->id }})"
                            role="switch"
                            aria-checked="{{ $prize->active ? 'true' : 'false' }}"
                            aria-label="{{ $prize->active ? 'Switch off' : 'Switch on' }} {{ $prize->name }}"
                            class="relative h-[19px] w-[34px] shrink-0 rounded-full transition"
                            style="background: {{ $prize->active ? 'var(--fq-green)' : 'var(--fq-line)' }}"
                        >
                            <span
                                class="absolute top-[2px] h-[15px] w-[15px] rounded-full transition-all"
                                style="{{ $prize->active
                                    ? 'right: 2px; background: var(--fq-ink-green)'
                                    : 'left: 2px; background: var(--fq-text-5)' }}"
                            ></span>
                        </button>

                        <button
                            type="button"
                            wire:click="removePrize({{ $prize->id }})"
                            wire:confirm="Delete '{{ $prize->name }}' from the Lucky Block?"
                            class="shrink-0 rounded-[9px] border border-fq-danger-border px-[10px] py-1 text-[11px] text-fq-danger hover:bg-fq-danger-bg"
                        >Remove</button>
                    </div>

                    {{-- Who it's for. One pool with per-kid scoping rather than
                         a separate list per child: a house that wants the same
                         ten things for both kids writes them once. --}}
                    <div class="flex flex-wrap items-center gap-[6px]">
                        <span class="font-mono-fq text-[9px] tracking-[0.14em] text-fq-text-5 uppercase">Who for</span>

                        @foreach ([[null, 'Everyone'], ...$kids->map(fn ($kid) => [$kid->id, $kid->name])] as $option)
                            @php $chosen = $prize->profile_id === $option[0]; @endphp
                            <button
                                type="button"
                                wire:click="setPrizeScope({{ $prize->id }}, {{ $option[0] === null ? 'null' : $option[0] }})"
                                class="rounded-full border px-[10px] py-[4px] text-[11.5px] transition hover:brightness-125"
                                style="border-color: {{ $chosen ? 'var(--fq-gold)' : 'var(--fq-line)' }};
                                       background: {{ $chosen ? '#2a2405' : 'transparent' }};
                                       color: {{ $chosen ? 'var(--fq-lime)' : 'var(--fq-text-3)' }}"
                            >{{ $option[1] }}</button>
                        @endforeach

                        @unless ($prize->active)
                            <span class="ml-auto font-mono-fq text-[9px] tracking-[0.14em] uppercase" style="color: #ff9db1">
                                Off &middot; not in the pool
                            </span>
                        @endunless
                    </div>

                    @if ($editingPrizeId === $prize->id)
                        <div class="rounded-[12px] border border-fq-line-2 bg-fq-sunk p-3">
                            <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Picture</p>

                            <div class="mt-2 grid grid-cols-8 gap-2">
                                @foreach (ChoreIcon::cases() as $option)
                                    <button
                                        type="button"
                                        wire:click="setPrizeIcon({{ $prize->id }}, '{{ $option->faClass() }}')"
                                        title="{{ $option->label() }}"
                                        class="grid aspect-square place-items-center rounded-[10px] border transition hover:border-fq-lime"
                                        style="border-color: {{ $prize->icon === $option->faClass() ? 'var(--fq-gold)' : 'var(--fq-line-3)' }};
                                               background: var(--fq-panel);
                                               color: {{ $prize->icon === $option->faClass() ? 'var(--fq-gold)' : 'var(--fq-text-3)' }}"
                                    >
                                        <x-chore-icon :icon="$option" class="text-[16px]" />
                                    </button>
                                @endforeach
                            </div>

                            {{-- The presets are a shortlist. A prize pool wants
                                 pictures a chore board never needs, so any free
                                 Font Awesome class is accepted here too — and
                                 normalized on the way in, which is what keeps
                                 the class attribute safe. --}}
                            <input
                                type="text"
                                value="{{ $prize->icon }}"
                                wire:blur="setPrizeIcon({{ $prize->id }}, $event.target.value)"
                                placeholder="or paste any Font Awesome class — fa-solid fa-ice-cream"
                                class="mt-3 w-full rounded-[11px] border border-dashed border-fq-line-2 bg-fq-panel px-3 py-2 text-[12.5px] outline-none focus:border-fq-cyan"
                            >

                            <p class="mt-2 font-mono-fq text-[10px] text-fq-text-5">Tap the gold one again to clear it.</p>
                        </div>
                    @endif
                </div>
            @endforeach

            @if ($prizes->isEmpty())
                <div class="rounded-[14px] border border-dashed border-fq-line-3 bg-fq-panel p-6 text-center text-sm text-fq-text-5">
                    Nothing in the pool. The block is hidden from the kids until there is.
                </div>
            @endif
        </div>

        @if ($adding)
            <div class="rounded-[14px] border border-dashed p-[13px]" style="border-color: var(--fq-line-3); background: var(--fq-panel)">
                <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">New prize</p>

                <div class="mt-[10px] flex items-center gap-[10px]">
                    <span
                        class="grid h-[42px] w-[42px] shrink-0 place-items-center rounded-[12px] border border-fq-line-2 bg-fq-sunk"
                        style="color: var(--fq-gold)"
                    >
                        @if (ChoreIcon::normalizeClass($newPrizeIcon))
                            <x-chore-icon :icon="$newPrizeIcon" class="text-[20px]" />
                        @else
                            <i aria-hidden="true" class="fa-solid fa-icons text-[16px] text-fq-text-5"></i>
                        @endif
                    </span>

                    <input
                        type="text"
                        wire:model="newPrizeName"
                        placeholder="What do they get?"
                        class="min-w-0 flex-1 rounded-[12px] border border-fq-line-2 bg-fq-sunk px-3 py-[11px] text-[14px] outline-none focus:border-fq-cyan"
                    >
                </div>

                <input
                    type="text"
                    wire:model="newPrizeFlavor"
                    placeholder="A line to go with it (optional)"
                    class="mt-2 w-full rounded-[12px] border border-fq-line-2 bg-fq-sunk px-3 py-[10px] text-[13px] outline-none focus:border-fq-cyan"
                >

                <input
                    type="text"
                    wire:model.live="newPrizeIcon"
                    placeholder="Picture — fa-solid fa-ice-cream"
                    class="mt-2 w-full rounded-[12px] border border-dashed border-fq-line-2 bg-fq-sunk px-3 py-[10px] text-[12.5px] outline-none focus:border-fq-cyan"
                >

                <div class="mt-3">
                    <p class="font-mono-fq text-[9px] tracking-[0.14em] text-fq-text-5 uppercase">Who for</p>
                    <div class="mt-[6px] flex flex-wrap gap-[6px]">
                        @foreach ([['', 'Everyone'], ...$kids->map(fn ($kid) => [(string) $kid->id, $kid->name])] as $option)
                            @php $chosen = $newPrizeScope === $option[0]; @endphp
                            <button
                                type="button"
                                wire:click="$set('newPrizeScope', '{{ $option[0] }}')"
                                class="rounded-full border px-[11px] py-[5px] text-[12px] transition hover:brightness-125"
                                style="border-color: {{ $chosen ? 'var(--fq-gold)' : 'var(--fq-line)' }};
                                       background: {{ $chosen ? '#2a2405' : 'transparent' }};
                                       color: {{ $chosen ? 'var(--fq-lime)' : 'var(--fq-text-3)' }}"
                            >{{ $option[1] }}</button>
                        @endforeach
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($presets as $index => $preset)
                        <button
                            type="button"
                            wire:click="fillPreset({{ $index }})"
                            class="rounded-[11px] border border-dashed border-fq-line-3 bg-fq-sunk px-[11px] py-[7px] text-[12px] text-fq-text-2-c hover:border-solid"
                        >{{ $preset['name'] }}</button>
                    @endforeach
                </div>

                <button
                    type="button"
                    wire:click="addPrize"
                    class="mt-3 w-full rounded-[12px] py-[11px] font-baloo text-[15px] font-extrabold transition hover:brightness-110"
                    style="background: var(--fq-fill-gold-soft); color: var(--fq-ink)"
                >Put it in the block</button>
            </div>
        @endif

        {{-- The one pool-level rule. On by default: with a near-daily hit, the
             same prize twice in a week is the repeat that makes the block feel
             broken, and the one still owed is where it stings most. --}}
        <div class="flex items-center gap-[10px] rounded-[16px] border border-fq-line bg-fq-panel px-[13px] py-3">
            <i aria-hidden="true" class="fa-fw fa-solid fa-rotate text-sm" style="color: var(--fq-cyan)"></i>

            <div class="min-w-0 flex-1">
                <p class="text-[13px] font-semibold">Won prizes leave the pool until redeemed</p>
                <p class="mt-[2px] text-[11.5px] text-fq-text-4">
                    Stops the same thing coming out twice while the first one is still owed.
                </p>
            </div>

            <button
                type="button"
                wire:click="toggleHoldWon"
                role="switch"
                aria-checked="{{ $holdWon ? 'true' : 'false' }}"
                aria-label="Won prizes leave the pool until redeemed"
                class="relative h-[19px] w-[34px] shrink-0 rounded-full transition"
                style="background: {{ $holdWon ? 'var(--fq-green)' : 'var(--fq-line)' }}"
            >
                <span
                    class="absolute top-[2px] h-[15px] w-[15px] rounded-full transition-all"
                    style="{{ $holdWon
                        ? 'right: 2px; background: var(--fq-ink-green)'
                        : 'left: 2px; background: var(--fq-text-5)' }}"
                ></span>
            </button>
        </div>
    </div>
</x-parent.shell>
