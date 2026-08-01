<?php

use App\Enums\PerkEffect;
use App\Exceptions\InsufficientTicketsException;
use App\Exceptions\PerkUnavailableException;
use App\Models\BonusPerk;
use App\Models\Profile;
use App\Services\BonusShopService;
use App\Services\PerkInventoryService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public Profile $profile;

    public ?string $flashMessage = null;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isKid(), 403);
    }

    public function buy(int $perkId): void
    {
        $perk = BonusPerk::where('household_id', $this->profile->household_id)->find($perkId);

        if (! $perk) {
            return;
        }

        try {
            app(BonusShopService::class)->purchase($this->profile, $perk);
            $this->flashMessage = null;
            $this->dispatch('celebrate', message: "{$perk->name} added to your perks!");
        } catch (InsufficientTicketsException|PerkUnavailableException $e) {
            $this->flashMessage = $e->getMessage();
        }
    }

    public function usePerk(string $effect): void
    {
        $case = PerkEffect::tryFrom($effect);

        if (! $case) {
            return;
        }

        try {
            $outcome = app(PerkInventoryService::class)->use($this->profile, $case);
            $this->flashMessage = null;
            $this->dispatch('celebrate', message: $outcome);
        } catch (PerkUnavailableException $e) {
            $this->flashMessage = $e->getMessage();
        }
    }

    public function with(): array
    {
        $inventory = app(PerkInventoryService::class);

        return [
            'catalog' => app(BonusShopService::class)->catalogFor($this->profile),
            // Grouped so three respins read as "×3" rather than three rows.
            'owned' => $inventory->unusedFor($this->profile)
                ->groupBy(fn ($perk) => $perk->effect->value)
                ->map(fn ($group) => [
                    'effect' => $group->first()->effect,
                    'count' => $group->count(),
                    'blocked' => $inventory->blockedReason($this->profile, $group->first()->effect),
                ])
                ->values(),
        ];
    }
}; ?>

<x-kid.shell :profile="$profile" active="bonus">
    <div class="flex flex-col gap-[14px]">
        <div class="rounded-[24px] border p-6" style="background:linear-gradient(135deg, #3a1f4d, var(--fq-panel)); border-color: oklch(0.65 0.19 320 / .5)">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: var(--fq-magenta)">Bonus Shop</p>
                    <h2 class="mt-1 font-baloo text-2xl font-extrabold">Spend your tickets</h2>
                </div>
                <div class="rounded-[14px] border px-4 py-2 text-right" style="border-color: oklch(0.65 0.19 320 / .4); background: var(--fq-sunk)">
                    <div class="font-baloo text-2xl font-extrabold" style="color: var(--fq-magenta)">{{ $profile->bonus_tickets }}</div>
                    <div class="font-mono-fq text-[9px] text-fq-text-4">TICKETS</div>
                </div>
            </div>
            <p class="mt-2 max-w-[520px] text-sm text-fq-text-2">
                Tickets come from levelling up, earning badges, and daily chests. What you buy is yours
                to keep until you want it — spending tickets never costs you XP.
            </p>
        </div>

        @if ($flashMessage)
            <div class="rounded-[16px] border border-fq-line-2 bg-fq-sunk px-4 py-3 text-sm text-fq-text-2">
                {{ $flashMessage }}
            </div>
        @endif

        @if ($owned->isNotEmpty())
            <div class="rounded-[20px] border bg-fq-panel p-5" style="border-color: oklch(0.65 0.19 320 / .4)">
                <h3 class="font-baloo text-lg font-bold">Your Perks</h3>
                <p class="mt-1 text-sm text-fq-text-3">Use them whenever you like — they don't expire.</p>

                <div class="mt-3 flex flex-col gap-2">
                    @foreach ($owned as $entry)
                        @php $defaults = $entry['effect']->defaults(); @endphp
                        <div wire:key="owned-{{ $entry['effect']->value }}" class="flex flex-wrap items-center gap-3 rounded-[14px] border border-fq-line-2 bg-fq-sunk px-3 py-[10px]">
                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[12px] font-baloo text-lg font-extrabold"
                                style="background: var(--fq-magenta); color: var(--fq-bg)"
                            >{{ $defaults['glyph'] }}</div>

                            <div class="min-w-0 flex-1">
                                <p class="text-[15px] font-semibold">
                                    {{ $defaults['name'] }}
                                    @if ($entry['count'] > 1)
                                        <span class="font-mono-fq text-xs" style="color: var(--fq-magenta)">×{{ $entry['count'] }}</span>
                                    @endif
                                </p>
                                @if ($entry['blocked'])
                                    <p class="font-mono-fq text-[10px] text-fq-text-5">{{ $entry['blocked'] }}</p>
                                @endif
                            </div>

                            <button
                                type="button"
                                wire:click="usePerk('{{ $entry['effect']->value }}')"
                                @disabled($entry['blocked'] !== null)
                                class="rounded-[12px] px-4 py-2 text-sm font-bold disabled:opacity-40"
                                style="background: {{ $entry['blocked'] ? 'var(--fq-line-2)' : 'var(--fq-magenta)' }}; color: {{ $entry['blocked'] ? 'var(--fq-text-4)' : 'var(--fq-bg)' }}"
                            >Use</button>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="grid grid-cols-[repeat(auto-fill,minmax(260px,1fr))] gap-3">
            @foreach ($catalog as $entry)
                @php $perk = $entry['perk']; @endphp

                <div
                    wire:key="perk-{{ $perk->id }}"
                    class="flex flex-col gap-3 rounded-[20px] border border-fq-line bg-fq-panel p-4 {{ $entry['affordable'] ? '' : 'opacity-70' }}"
                >
                    <div class="flex items-start gap-3">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-[14px] font-baloo text-xl font-extrabold"
                            style="background: {{ $entry['affordable'] ? 'var(--fq-magenta)' : 'var(--fq-sunk)' }}; color: {{ $entry['affordable'] ? 'var(--fq-bg)' : 'var(--fq-text-4)' }}"
                        >{{ $perk->glyph }}</div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[15px] font-semibold">
                                {{ $perk->name }}
                                @if ($entry['owned'] > 0)
                                    <span class="font-mono-fq text-xs text-fq-text-4">({{ $entry['owned'] }} held)</span>
                                @endif
                            </p>
                            <p class="mt-1 text-sm text-fq-text-3">{{ $perk->description }}</p>
                        </div>
                    </div>

                    <div class="mt-auto flex items-center justify-between gap-2">
                        <span class="font-mono-fq text-xs" style="color: {{ $entry['affordable'] ? 'var(--fq-magenta)' : 'var(--fq-text-5)' }}">
                            {{ $perk->cost }} TICKETS
                        </span>
                        <button
                            type="button"
                            wire:click="buy({{ $perk->id }})"
                            @disabled(! $entry['affordable'])
                            class="rounded-[13px] px-4 py-[10px] text-sm font-bold disabled:opacity-40"
                            style="background: {{ $entry['affordable'] ? 'var(--fq-magenta)' : 'var(--fq-sunk)' }}; color: {{ $entry['affordable'] ? 'var(--fq-bg)' : 'var(--fq-text-4)' }}"
                        >{{ $entry['affordable'] ? 'Buy' : 'Not enough' }}</button>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-kid.shell>
