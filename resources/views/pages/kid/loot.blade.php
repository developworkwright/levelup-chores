<?php

use App\Exceptions\InsufficientPointsException;
use App\Models\Profile;
use App\Models\StoreItem;
use App\Services\StoreService;
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

    public function redeem(int $itemId): void
    {
        $item = StoreItem::find($itemId);

        if (! $item || $item->household_id !== $this->profile->household_id) {
            return;
        }

        try {
            app(StoreService::class)->redeem($this->profile, $item);
            $this->flashMessage = "Ask a parent to release it.";
            $this->dispatch('celebrate', message: "{$item->name} cashed out!");
        } catch (InsufficientPointsException $e) {
            $this->flashMessage = $e->getMessage();
        }
    }

    public function with(): array
    {
        return [
            'items' => StoreItem::where('household_id', $this->profile->household_id)->get(),
            'dollars' => number_format($this->profile->points / $this->profile->household->points_per_dollar, 2),
        ];
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

    <div class="mt-4 grid grid-cols-[repeat(auto-fit,minmax(216px,1fr))] gap-3">
        @foreach ($items as $item)
            @php $affordable = $profile->points >= $item->cost; @endphp
            <div
                wire:key="item-{{ $item->id }}"
                class="flex flex-col gap-3 rounded-[20px] border bg-fq-panel p-4"
                style="border-color: {{ $affordable ? 'var(--fq-line-focus)' : 'var(--fq-line)' }}"
            >
                <span class="h-[6px] rounded-full" style="background:{{ $item->color_tag->cssVar() }}"></span>
                <div class="flex-1">
                    <p class="text-[16px] font-semibold">{{ $item->name }}</p>
                    <p class="mt-1 text-[13px] leading-[1.35] text-fq-text-4">{{ $item->description }}</p>
                </div>
                <div class="flex items-center justify-between">
                    <span class="font-baloo text-[19px] font-extrabold text-fq-gold">{{ $item->cost }} pts</span>
                    @if ($affordable)
                        <button
                            type="button"
                            wire:click="redeem({{ $item->id }})"
                            class="rounded-[13px] px-4 py-[10px] text-[13px] font-semibold text-fq-bg"
                            style="background:var(--fq-cyan)"
                        >Cash out</button>
                    @else
                        <button type="button" disabled class="cursor-default rounded-[13px] bg-fq-panel-alt-2 px-4 py-[10px] text-[13px] font-semibold text-fq-text-4">
                            Need {{ $item->cost - $profile->points }}
                        </button>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</x-kid.shell>
