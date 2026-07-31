<?php

use App\Enums\AccentColor;
use App\Enums\LedgerKind;
use App\Models\BonusPerk;
use App\Models\Profile;
use App\Models\StoreItem;
use App\Services\LedgerService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    private const PRESETS = [
        ['name' => 'Lego set', 'description' => 'A Lego set of your choice.', 'cost' => 2000, 'color' => 'violet'],
        ['name' => 'Ice cream run', 'description' => 'A trip out for ice cream.', 'cost' => 300, 'color' => 'coral'],
        ['name' => 'Nerf refill pack', 'description' => 'Restock your Nerf dart supply.', 'cost' => 800, 'color' => 'gold'],
        ['name' => 'Bake a dessert together', 'description' => 'Pick a treat and bake it together.', 'cost' => 250, 'color' => 'magenta'],
        ['name' => 'Stay up 30 min late', 'description' => 'Push bedtime back half an hour.', 'cost' => 400, 'color' => 'cyan'],
        ['name' => 'New book', 'description' => 'A new book of your choice.', 'cost' => 900, 'color' => 'lime'],
    ];

    public Profile $profile;

    public string $newLootName = '';

    public string $newLootDesc = '';

    public string $newLootCost = '100';

    public string $newLootColor = 'lime';

    public ?string $flashMessage = null;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isParent(), 403);
    }

    private function ownedItem(int $itemId): ?StoreItem
    {
        return StoreItem::where('household_id', $this->profile->household_id)->find($itemId);
    }

    private function ownedPerk(int $perkId): ?BonusPerk
    {
        return BonusPerk::where('household_id', $this->profile->household_id)->find($perkId);
    }

    public function updatePerkName(int $perkId, string $value): void
    {
        $value = trim($value);

        if ($value !== '' && ($perk = $this->ownedPerk($perkId))) {
            $perk->name = $value;
            $perk->save();
        }
    }

    public function updatePerkDescription(int $perkId, string $value): void
    {
        if ($perk = $this->ownedPerk($perkId)) {
            $perk->description = trim($value);
            $perk->save();
        }
    }

    public function adjustPerkCost(int $perkId, int $delta): void
    {
        if ($perk = $this->ownedPerk($perkId)) {
            $perk->cost = max(1, $perk->cost + $delta);
            $perk->save();
        }
    }

    public function togglePerk(int $perkId): void
    {
        if ($perk = $this->ownedPerk($perkId)) {
            $perk->enabled = ! $perk->enabled;
            $perk->save();
        }
    }

    public function updateName(int $itemId, string $value): void
    {
        $value = trim($value);

        if ($value !== '' && ($item = $this->ownedItem($itemId))) {
            $item->name = $value;
            $item->save();
        }
    }

    public function updateDescription(int $itemId, string $value): void
    {
        if ($item = $this->ownedItem($itemId)) {
            $item->description = trim($value);
            $item->save();
        }
    }

    public function adjustCost(int $itemId, int $delta): void
    {
        if ($item = $this->ownedItem($itemId)) {
            $item->cost = max(25, $item->cost + $delta);
            $item->save();
        }
    }

    public function setTag(int $itemId, string $color): void
    {
        if (($item = $this->ownedItem($itemId)) && in_array($color, array_column(AccentColor::cases(), 'value'), true)) {
            $item->color_tag = $color;
            $item->save();
        }
    }

    public function removeItem(int $itemId): void
    {
        $this->ownedItem($itemId)?->delete();
    }

    public function fillPreset(int $index): void
    {
        $preset = self::PRESETS[$index] ?? null;

        if (! $preset) {
            return;
        }

        $this->newLootName = $preset['name'];
        $this->newLootDesc = $preset['description'];
        $this->newLootCost = (string) $preset['cost'];
        $this->newLootColor = $preset['color'];
    }

    public function addItem(): void
    {
        $name = trim($this->newLootName);

        if ($name === '') {
            $this->flashMessage = 'Give the reward a name first';

            return;
        }

        $cost = max(25, (int) preg_replace('/\D/', '', $this->newLootCost) ?: 25);

        $item = StoreItem::create([
            'household_id' => $this->profile->household_id,
            'name' => $name,
            'description' => trim($this->newLootDesc),
            'cost' => $cost,
            'color_tag' => $this->newLootColor,
        ]);

        app(LedgerService::class)->record(
            $this->profile->household,
            null,
            LedgerKind::Adjustment,
            0,
            "Added to Loot Shop — {$item->name}",
            $item,
        );

        $this->dispatch('celebrate', message: "{$item->name} added to the shop!");

        $this->newLootName = '';
        $this->newLootDesc = '';
        $this->newLootCost = '100';
        $this->newLootColor = 'lime';
        $this->flashMessage = null;
    }

    public function with(): array
    {
        return [
            'items' => StoreItem::where('household_id', $this->profile->household_id)->orderBy('id')->get(),
            'perks' => BonusPerk::where('household_id', $this->profile->household_id)->orderBy('cost')->get(),
            'colors' => AccentColor::cases(),
            'presets' => self::PRESETS,
        ];
    }
}; ?>

<x-parent.shell :profile="$profile" active="loot">
    <div class="grid grid-cols-[repeat(auto-fit,minmax(300px,1fr))] gap-[14px]">
        <div class="flex flex-col gap-3">
            <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Tap any name to edit</p>

            @foreach ($items as $item)
                <div wire:key="loot-{{ $item->id }}" class="flex gap-3 rounded-[18px] border border-fq-line bg-fq-panel p-[14px]">
                    <span class="self-stretch rounded-full" style="width:5px;background:{{ $item->color_tag->cssVar() }}"></span>

                    <div class="flex-1">
                        <input
                            type="text" value="{{ $item->name }}"
                            wire:blur="updateName({{ $item->id }}, $event.target.value)"
                            class="w-full border-0 border-b border-fq-line-2 bg-transparent py-[3px] text-[15px] font-semibold outline-none focus:border-fq-cyan"
                        >
                        <input
                            type="text" value="{{ $item->description }}"
                            wire:blur="updateDescription({{ $item->id }}, $event.target.value)"
                            class="mt-1 w-full border-0 bg-transparent py-[3px] text-[13px] text-fq-text-4 outline-none focus:text-fq-text"
                        >

                        <div class="mt-3 flex flex-wrap items-center gap-y-2 gap-x-3">
                            <button type="button" wire:click="adjustCost({{ $item->id }}, -25)" class="h-[30px] w-[30px] shrink-0 rounded-[10px] border border-fq-line-3 bg-fq-sunk">&minus;</button>
                            <div class="shrink-0 text-center">
                                <div class="font-baloo text-[17px] font-extrabold text-fq-gold">{{ $item->cost }}</div>
                                <div class="font-mono-fq text-[9px] text-fq-text-4">PTS · ${{ number_format($item->cost / $profile->household->points_per_dollar, 2) }}</div>
                            </div>
                            <button type="button" wire:click="adjustCost({{ $item->id }}, 25)" class="h-[30px] w-[30px] shrink-0 rounded-[10px] border border-fq-line-3 bg-fq-sunk">+</button>

                            <div class="flex shrink-0 gap-1">
                                @foreach ($colors as $color)
                                    @if ($color !== \App\Enums\AccentColor::Parent)
                                        <button
                                            type="button"
                                            wire:click="setTag({{ $item->id }}, '{{ $color->value }}')"
                                            class="h-[22px] w-[22px] rounded-[8px] border-2"
                                            style="background:{{ $color->cssVar() }};border-color:{{ $item->color_tag === $color ? 'var(--fq-text)' : 'transparent' }}"
                                        ></button>
                                    @endif
                                @endforeach
                            </div>

                            <button type="button" wire:click="removeItem({{ $item->id }})" class="ml-auto shrink-0 rounded-[10px] border border-fq-danger-border px-3 py-1 text-xs text-fq-danger hover:bg-fq-danger-bg">Remove</button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="flex flex-col gap-4">
            <div class="flex flex-col gap-3 rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
                <h3 class="font-baloo text-lg font-bold">Add a reward</h3>

                @if ($flashMessage)
                    <p class="text-sm font-semibold text-fq-danger">{{ $flashMessage }}</p>
                @endif

                <input type="text" wire:model="newLootName" placeholder="Reward name — e.g. Lego set" class="rounded-[14px] border border-fq-line-2 bg-fq-sunk px-[14px] py-[13px] text-[15px] outline-none">
                <input type="text" wire:model="newLootDesc" placeholder="Description" class="rounded-[14px] border border-fq-line-2 bg-fq-sunk px-[14px] py-[13px] text-[15px] outline-none">

                <div class="flex items-center gap-3">
                    <input type="text" inputmode="numeric" wire:model.live="newLootCost" placeholder="Cost" class="flex-1 rounded-[14px] border border-fq-line-2 bg-fq-sunk px-[14px] py-[13px] text-[15px] outline-none">
                    <span class="font-mono-fq text-xs text-fq-gold">
                        = ${{ number_format(max(0, (int) preg_replace('/\D/', '', $newLootCost)) / $profile->household->points_per_dollar, 2) }}
                    </span>
                </div>

                <div>
                    <p class="mb-2 font-mono-fq text-[10px] text-fq-text-4 uppercase">Tag</p>
                    <div class="flex gap-2">
                        @foreach ($colors as $color)
                            @if ($color !== \App\Enums\AccentColor::Parent)
                                <button
                                    type="button"
                                    wire:click="$set('newLootColor', '{{ $color->value }}')"
                                    class="h-[26px] w-[26px] rounded-[8px] border-2"
                                    style="background:{{ $color->cssVar() }};border-color:{{ $newLootColor === $color->value ? 'var(--fq-text)' : 'transparent' }}"
                                ></button>
                            @endif
                        @endforeach
                    </div>
                </div>

                <button type="button" wire:click="addItem" class="rounded-[15px] py-[13px] font-baloo text-[17px] font-extrabold text-fq-bg" style="background:var(--fq-gold)">
                    Put it in the shop
                </button>
            </div>

            <div class="rounded-[22px] p-5" style="background:linear-gradient(135deg, #2a2050, #171c38)">
                <h3 class="font-baloo text-lg font-bold">Quick ideas</h3>
                <p class="mt-1 text-[13px] text-fq-text-2">Tap one to fill the form, then tweak the price.</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($presets as $i => $preset)
                        <button
                            type="button"
                            wire:click="fillPreset({{ $i }})"
                            class="rounded-[12px] border border-dashed border-fq-line-4 bg-fq-sunk px-[13px] py-[9px] text-[13px] text-fq-text-2-c hover:border-solid"
                        >{{ $preset['name'] }}</button>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="mt-[18px]">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="font-baloo text-lg font-bold">Bonus Shop</h3>
            <p class="text-xs text-fq-text-5">
                Bought with tickets, not points — and they apply instantly, so nothing lands in your approvals queue.
            </p>
        </div>

        <div class="grid grid-cols-[repeat(auto-fit,minmax(320px,1fr))] gap-3">
            @foreach ($perks as $perk)
                <div
                    wire:key="perk-{{ $perk->id }}"
                    class="flex flex-col gap-3 rounded-[18px] border p-[14px] {{ $perk->enabled ? '' : 'opacity-60' }}"
                    style="background: var(--fq-panel); border-color: {{ $perk->enabled ? 'oklch(0.65 0.19 320 / .4)' : 'var(--fq-line)' }}"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] font-baloo text-lg font-extrabold"
                            style="background: var(--fq-sunk); color: var(--fq-magenta)"
                        >{{ $perk->glyph }}</div>
                        <input
                            type="text"
                            value="{{ $perk->name }}"
                            wire:blur="updatePerkName({{ $perk->id }}, $event.target.value)"
                            class="min-w-0 flex-1 border-0 border-b border-fq-line-2 bg-transparent py-[3px] text-[15px] font-semibold outline-none focus:border-fq-magenta"
                        >
                    </div>

                    <input
                        type="text"
                        value="{{ $perk->description }}"
                        wire:blur="updatePerkDescription({{ $perk->id }}, $event.target.value)"
                        class="w-full border-0 border-b border-fq-line-2 bg-transparent py-[3px] text-[13px] text-fq-text-2 outline-none focus:border-fq-magenta"
                    >

                    <div class="flex flex-wrap items-center gap-2">
                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="adjustPerkCost({{ $perk->id }}, -1)" class="h-8 w-8 rounded-[10px] border border-fq-line-3 bg-fq-sunk text-lg">&minus;</button>
                            <span class="w-14 text-center font-baloo text-[17px] font-extrabold" style="color: var(--fq-magenta)">{{ $perk->cost }}</span>
                            <button type="button" wire:click="adjustPerkCost({{ $perk->id }}, 1)" class="h-8 w-8 rounded-[10px] border border-fq-line-3 bg-fq-sunk text-lg">+</button>
                            <span class="font-mono-fq text-[10px] text-fq-text-4">TICKETS</span>
                        </div>

                        <button
                            type="button"
                            wire:click="togglePerk({{ $perk->id }})"
                            class="ml-auto rounded-[12px] border px-3 py-2 text-xs {{ $perk->enabled ? 'border-fq-line-3 bg-fq-sunk text-fq-text-3' : 'border-fq-coral bg-fq-sunk text-fq-coral' }}"
                        >{{ $perk->enabled ? 'Disable' : 'Enable' }}</button>
                    </div>
                </div>
            @endforeach
        </div>

        <p class="mt-3 text-xs text-fq-text-5">
            What each perk actually does is built into the app, so perks can't be added or removed here — only renamed, repriced, or switched off.
        </p>
    </div>
</x-parent.shell>
