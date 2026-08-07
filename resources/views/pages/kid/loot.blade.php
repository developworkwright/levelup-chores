<?php

use App\Exceptions\InsufficientPointsException;
use App\Models\Chore;
use App\Models\Profile;
use App\Models\StoreItem;
use App\Services\StoreService;
use Illuminate\Support\Facades\Auth;
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

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isKid(), 403);
    }

    public function clearSearch(): void
    {
        $this->search = '';
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
        } catch (InsufficientPointsException $e) {
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

        return [
            'items' => $items,
            'matchCount' => $matching->count(),
            'catalogCount' => $items->count(),
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

    {{-- Shelves rather than one flat grid: the catalog now spans 100 to 2,000
         points, and a price band is the fastest way to answer "what can I
         actually afford today?" --}}
    <div class="mt-[22px] flex flex-col gap-[22px]">
        @foreach ($bands as $band)
            <div wire:key="band-{{ $band['label'] }}">
                <div class="flex items-baseline gap-[10px] border-b border-fq-line pb-[10px]">
                    <h3 class="font-baloo text-[19px] font-extrabold">{{ $band['label'] }}</h3>
                    <span class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">{{ $band['sub'] }}</span>
                    <span class="ml-auto font-mono-fq text-[10px] text-fq-text-5-b">
                        {{ $band['items']->count() }} {{ Str::plural('item', $band['items']->count()) }}
                    </span>
                </div>

                <div class="mt-3 grid grid-cols-[repeat(auto-fit,minmax(216px,1fr))] gap-3">
                    @foreach ($band['items'] as $item)
                        @php
                            $affordable = $profile->points >= $item->cost;
                            $isGoal = $saving && $saving->id === $item->id;
                        @endphp

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

                            <button
                                type="button"
                                wire:click="saveFor({{ $item->id }})"
                                class="self-start rounded-full border border-fq-line bg-fq-sunk px-[11px] py-[5px] font-mono-fq text-[10px] tracking-[0.1em] uppercase transition hover:border-fq-line-4"
                                style="color: {{ $isGoal ? 'var(--fq-gold)' : 'var(--fq-text-4)' }}"
                            >{{ $isGoal ? 'Saving for this' : 'Save for this' }}</button>

                            <div class="flex items-center justify-between gap-2">
                                <span class="font-baloo text-[19px] font-extrabold text-fq-gold">{{ $item->cost }} pts</span>
                                @if ($affordable)
                                    <button
                                        type="button"
                                        wire:click="redeem({{ $item->id }})"
                                        class="rounded-[13px] px-4 py-[10px] text-[13px] font-semibold text-fq-bg transition hover:brightness-110"
                                        style="background:var(--fq-cyan)"
                                    >Cash out</button>
                                @else
                                    <button type="button" disabled class="cursor-default rounded-[13px] bg-fq-panel-alt px-4 py-[10px] text-[13px] font-semibold text-fq-text-4">
                                        Need {{ $item->cost - $profile->points }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</x-kid.shell>
