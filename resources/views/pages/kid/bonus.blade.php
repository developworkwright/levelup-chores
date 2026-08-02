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
        <div class="flex flex-wrap items-end justify-between gap-[14px]">
            <div>
                <p class="font-mono-fq text-[10px] tracking-[0.24em] text-fq-steel-text uppercase">Bonus Shop</p>
                <h2 class="font-baloo text-[26px] font-extrabold">Spend your tickets</h2>
                <p class="mt-[2px] max-w-[520px] text-[13px] text-fq-text-4">
                    Tickets come from levelling up, earning badges and daily chests. What you buy is yours
                    to keep — spending tickets never costs you XP.
                </p>
            </div>

            <div
                class="flex items-baseline gap-[7px] rounded-full border border-fq-ticket-line px-[18px] py-[10px]"
                style="background: var(--fq-ticket-bg); box-shadow: 0 0 20px -8px var(--fq-lime)"
            >
                <span class="font-baloo text-[26px] leading-none font-extrabold text-fq-lime">{{ $profile->bonus_tickets }}</span>
                <span class="font-mono-fq text-[10px] text-fq-ticket-label">TICKETS</span>
            </div>
        </div>

        @if ($flashMessage)
            <div class="rounded-[16px] border border-fq-line-2 bg-fq-sunk px-4 py-3 text-sm text-fq-text-2">
                {{ $flashMessage }}
            </div>
        @endif

        {{-- A tray rather than a list: three slots that visibly sit empty until
             something's in them, so it reads as a hand of cards to play. --}}
        <div class="rounded-[18px] border border-fq-steel-line p-[14px]" style="background: var(--fq-steel-tray)">
            <p class="font-mono-fq text-[10px] tracking-[0.2em] text-fq-steel-label uppercase">In hand</p>

            <div class="mt-[10px] grid grid-cols-[repeat(auto-fit,minmax(160px,1fr))] gap-[10px]">
                @foreach ($owned as $entry)
                    @php
                        $defaults = $entry['effect']->defaults();
                        $blocked = $entry['blocked'];
                    @endphp
                    <div
                        wire:key="owned-{{ $entry['effect']->value }}"
                        class="flex flex-col items-center gap-[10px] rounded-[14px] border p-3 text-center"
                        style="
                            border-color: {{ $blocked ? 'var(--fq-line)' : 'rgba(195,203,212,.35)' }};
                            background: {{ $blocked ? 'var(--fq-steel-blocked)' : 'var(--fq-steel-panel)' }};
                            opacity: {{ $blocked ? '0.75' : '1' }};
                        "
                    >
                        <span
                            class="grid h-[38px] w-[38px] place-items-center rounded-full font-baloo text-lg font-extrabold"
                            style="
                                background: {{ $blocked ? 'var(--fq-panel-alt-2)' : 'var(--fq-chrome)' }};
                                color: {{ $blocked ? 'var(--fq-text-4)' : 'var(--fq-ink-steel)' }};
                            "
                        >{{ $defaults['glyph'] }}</span>

                        <span
                            class="text-[13px] font-semibold"
                            style="color: {{ $blocked ? 'var(--fq-text-3)' : 'var(--fq-steel-name)' }}"
                        >
                            {{ $defaults['name'] }}@if ($entry['count'] > 1) ×{{ $entry['count'] }}@endif
                        </span>

                        <button
                            type="button"
                            wire:click="usePerk('{{ $entry['effect']->value }}')"
                            @disabled($blocked !== null)
                            class="w-full rounded-[10px] px-[6px] py-2 text-xs font-bold transition hover:brightness-115"
                            style="
                                background: {{ $blocked ? 'var(--fq-panel-alt-2)' : 'var(--fq-fill-steel)' }};
                                color: {{ $blocked ? 'var(--fq-text-5)' : 'var(--fq-ink-steel)' }};
                            "
                        >{{ $blocked ?? 'Use' }}</button>
                    </div>
                @endforeach

                @for ($i = $owned->count(); $i < 3; $i++)
                    <div
                        wire:key="empty-slot-{{ $i }}"
                        class="grid min-h-[120px] place-items-center rounded-[14px] border border-dashed border-fq-line font-mono-fq text-[11px] text-fq-text-6"
                    >empty slot</div>
                @endfor
            </div>
        </div>

        <div class="grid grid-cols-[repeat(auto-fit,minmax(190px,1fr))] gap-[10px]">
            @foreach ($catalog as $entry)
                @php
                    $perk = $entry['perk'];
                    $affordable = $entry['affordable'];
                @endphp

                {{-- The foil edge is a 1px gradient border faked with padding —
                     a real border can't hold a gradient. --}}
                <div
                    wire:key="perk-{{ $perk->id }}"
                    class="rounded-[16px]"
                    style="
                        padding: 1px;
                        background: {{ $affordable ? 'var(--fq-foil)' : 'transparent' }};
                        border: {{ $affordable ? 'none' : '1px solid var(--fq-steel-line)' }};
                    "
                >
                    <div
                        class="flex h-full flex-col items-center gap-2 rounded-[15px] px-3 py-4 text-center"
                        style="
                            background: {{ $affordable ? 'var(--fq-steel-card)' : 'var(--fq-steel-card-dim)' }};
                            opacity: {{ $affordable ? '1' : '0.7' }};
                        "
                    >
                        <span
                            class="grid h-11 w-11 place-items-center rounded-[14px] font-baloo text-xl font-extrabold"
                            style="
                                background: {{ $affordable ? 'var(--fq-fill-gold-soft)' : 'var(--fq-sunk)' }};
                                color: {{ $affordable ? 'var(--fq-ink)' : 'var(--fq-text-4)' }};
                            "
                        >{{ $perk->glyph }}</span>

                        <span class="text-sm font-semibold" style="color: {{ $affordable ? 'var(--fq-text)' : 'var(--fq-text-3)' }}">
                            {{ $perk->name }}
                        </span>

                        @if ($entry['owned'] > 0)
                            <span class="font-mono-fq text-[10px] text-fq-text-4">{{ $entry['owned'] }} held</span>
                        @endif

                        <span
                            class="text-[12.5px] leading-[1.4]"
                            style="color: {{ $affordable ? 'var(--fq-text-4)' : 'var(--fq-text-5)' }}"
                        >{{ $perk->description }}</span>

                        <span
                            class="mt-auto pt-[6px] font-mono-fq text-[11px]"
                            style="color: {{ $affordable ? 'var(--fq-lime)' : 'var(--fq-text-5)' }}"
                        >{{ $perk->cost }} TICKETS</span>

                        <button
                            type="button"
                            wire:click="buy({{ $perk->id }})"
                            @disabled(! $affordable)
                            class="w-full rounded-[11px] py-[9px] text-xs font-extrabold transition hover:brightness-110"
                            style="
                                background: {{ $affordable ? 'var(--fq-fill-gold)' : 'var(--fq-panel-alt-2)' }};
                                color: {{ $affordable ? 'var(--fq-ink)' : 'var(--fq-text-5)' }};
                            "
                        >{{ $affordable ? 'Buy' : 'Not enough' }}</button>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-kid.shell>
