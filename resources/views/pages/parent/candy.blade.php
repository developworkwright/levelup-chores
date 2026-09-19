<?php

use App\Models\Candy;
use App\Models\CandyOrder;
use App\Models\Profile;
use App\Services\PrizeCounterService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * Parent console → Candy: the one shelf of the arcade's prize counter a
 * grown-up touches. 2f in handoff/design_handoff_arcade_tokens.
 *
 * Left, add a sweet: a name, a token price, how many are in the cupboard, and
 * a colour for its wrapper. There are no photos yet — every sweet is the drawn
 * candy from prizes.js in the colour picked here, so there is never an empty
 * box on the counter. Right, the queue: sweets the kids bought and are waiting
 * on, oldest first, with HANDED OVER and REFUND. It is the Loot Shop's
 * fulfilment pattern with tokens in place of points, so a parent already
 * knows how to work it.
 */
new class extends Component
{
    /** Wrapper colours to pick from, as hues — see FQPrizes.candy(). */
    public const HUES = [330, 24, 50, 120, 190, 270];

    /** How long a sweet can wait before the queue calls it overdue, in days. */
    private const OVERDUE_DAYS = 2;

    public Profile $profile;

    public string $name = '';

    public string $tokens = '30';

    public string $stock = '2';

    public int $hue = 330;

    public ?string $flashMessage = null;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();

        abort_unless($this->profile->isParent(), 403);
    }

    public function addCandy(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'tokens' => ['required', 'integer', 'min:1', 'max:999'],
            'stock' => ['required', 'integer', 'min:0', 'max:99'],
            'hue' => ['required', 'integer', 'in:'.implode(',', self::HUES)],
        ]);

        Candy::create([
            'household_id' => $this->profile->household_id,
            'name' => trim($this->name),
            'tokens' => (int) $this->tokens,
            'stock' => (int) $this->stock,
            'hue' => $this->hue,
        ]);

        $this->flashMessage = trim($this->name).' is on the counter.';
        $this->name = '';
    }

    /** One more in the cupboard, or one fewer. */
    public function restock(int $candyId, int $by): void
    {
        $candy = $this->ownedCandy($candyId);

        if ($candy === null) {
            return;
        }

        $candy->update(['stock' => max(0, min(99, $candy->stock + ($by < 0 ? -1 : 1)))]);
    }

    /** Off the counter. Orders already waiting for it are left alone. */
    public function retire(int $candyId): void
    {
        $this->ownedCandy($candyId)?->update(['retired_at' => now()]);
    }

    public function handOver(int $orderId): void
    {
        $order = $this->ownedOrder($orderId);

        if ($order !== null && app(PrizeCounterService::class)->handOver($order, $this->profile)) {
            $this->flashMessage = $order->profile->name.'’s '.$order->name.' — handed over.';
        }
    }

    public function refund(int $orderId): void
    {
        $order = $this->ownedOrder($orderId);

        if ($order !== null && app(PrizeCounterService::class)->refund($order, $this->profile)) {
            $this->flashMessage = $order->tokens.' tokens back to '.$order->profile->name.'.';
        }
    }

    private function ownedCandy(int $candyId): ?Candy
    {
        return Candy::where('household_id', $this->profile->household_id)->find($candyId);
    }

    private function ownedOrder(int $orderId): ?CandyOrder
    {
        return CandyOrder::with('profile')->where('household_id', $this->profile->household_id)->find($orderId);
    }

    public function with(): array
    {
        $counter = app(PrizeCounterService::class);

        return [
            'candies' => $counter->candiesFor($this->profile->household),
            'queue' => $counter->queueFor($this->profile->household),
            'overdueBefore' => now()->subDays(self::OVERDUE_DAYS),
            'hues' => self::HUES,
        ];
    }
}; ?>

<x-parent.shell :profile="$profile" active="candy">
    <div class="flex flex-col gap-4 rounded-[28px] border border-fq-line bg-fq-bg p-[16px_14px] lg:grid lg:grid-cols-[300px_minmax(0,1fr)]">
        <div class="flex flex-col gap-[10px]">
            <h2 class="font-baloo text-[18px] font-extrabold text-fq-text">Add a sweet</h2>

            <form wire:submit="addCandy" class="flex flex-col gap-[10px]">
                <label class="flex flex-col gap-[5px] font-mono-fq text-[9px] tracking-[0.14em] text-fq-text-4 uppercase">
                    Name
                    <input
                        type="text"
                        wire:model="name"
                        maxlength="60"
                        placeholder="Haribo bag"
                        class="rounded-[11px] border border-fq-line-2 bg-fq-panel px-[11px] py-[10px] font-sans text-[14px] tracking-normal text-fq-text normal-case"
                    >
                    @error('name') <span class="font-sans text-[12px] tracking-normal text-fq-danger normal-case">{{ $message }}</span> @enderror
                </label>

                <div class="flex gap-[9px]">
                    <label class="flex flex-1 flex-col gap-[5px] font-mono-fq text-[9px] tracking-[0.14em] text-fq-text-4 uppercase">
                        Tokens
                        <input type="number" min="1" max="999" wire:model="tokens" class="rounded-[11px] border border-fq-line-2 bg-fq-panel px-[11px] py-[10px] font-mono-fq text-[14px] text-fq-lime">
                        @error('tokens') <span class="font-sans text-[12px] tracking-normal text-fq-danger normal-case">{{ $message }}</span> @enderror
                    </label>
                    <label class="flex flex-1 flex-col gap-[5px] font-mono-fq text-[9px] tracking-[0.14em] text-fq-text-4 uppercase">
                        In the cupboard
                        <input type="number" min="0" max="99" wire:model="stock" class="rounded-[11px] border border-fq-line-2 bg-fq-panel px-[11px] py-[10px] font-mono-fq text-[14px] text-fq-text">
                        @error('stock') <span class="font-sans text-[12px] tracking-normal text-fq-danger normal-case">{{ $message }}</span> @enderror
                    </label>
                </div>

                <div class="flex flex-col gap-[6px]">
                    <span class="font-mono-fq text-[9px] tracking-[0.14em] text-fq-text-4 uppercase">Wrapper</span>
                    <div class="flex gap-[6px]">
                        @foreach ($hues as $choice)
                            <button
                                type="button"
                                wire:click="$set('hue', {{ $choice }})"
                                aria-label="Wrapper colour {{ $loop->iteration }}"
                                aria-pressed="{{ $hue === $choice ? 'true' : 'false' }}"
                                @class([
                                    'grid h-[42px] w-[42px] place-items-center rounded-[11px] border-2 bg-fq-panel',
                                    'border-fq-lime' => $hue === $choice,
                                    'border-fq-line' => $hue !== $choice,
                                ])
                            ><fq-prize kind="candy" key="{{ $choice }}" class="h-[32px] w-[32px]"></fq-prize></button>
                        @endforeach
                    </div>
                </div>

                <p class="text-[11.5px] text-pretty text-fq-text-5">
                    Suggested band: 20&ndash;60 tokens. A kid earns 30&ndash;90 a day, so a sweet is one good day and a bed is a week.
                </p>

                <button
                    type="submit"
                    class="rounded-[13px] p-[12px] text-center font-baloo text-[15px] font-extrabold text-fq-ink"
                    style="background: var(--fq-fill-gold-soft)"
                >Put it on the counter</button>
            </form>

            @if ($flashMessage)
                <p class="rounded-[11px] border border-fq-line-2 bg-fq-panel px-[11px] py-[8px] text-[12.5px] text-fq-text-2">{{ $flashMessage }}</p>
            @endif

            <h3 class="mt-[6px] font-mono-fq text-[9.5px] tracking-[0.14em] text-fq-text-4 uppercase">On the counter</h3>

            @forelse ($candies as $candy)
                <div wire:key="candy-{{ $candy->id }}" class="flex items-center gap-[10px] rounded-[13px] border border-fq-line bg-fq-panel px-[10px] py-[8px]">
                    <fq-prize kind="candy" key="{{ $candy->hue }}" class="h-[32px] w-[32px] shrink-0"></fq-prize>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[13.5px] font-semibold text-fq-text">{{ $candy->name }}</span>
                        <span class="block font-mono-fq text-[9px] tracking-[0.08em] text-fq-text-4">{{ $candy->tokens }} &#10022; &middot; {{ $candy->stock > 0 ? $candy->stock.' IN THE CUPBOARD' : 'NONE LEFT' }}</span>
                    </span>
                    <button type="button" wire:click="restock({{ $candy->id }}, -1)" aria-label="One fewer" class="grid h-[28px] w-[28px] place-items-center rounded-[8px] border border-fq-line-2 text-fq-text-3">&minus;</button>
                    <button type="button" wire:click="restock({{ $candy->id }}, 1)" aria-label="One more" class="grid h-[28px] w-[28px] place-items-center rounded-[8px] border border-fq-line-2 text-fq-text-3">+</button>
                    <button
                        type="button"
                        wire:click="retire({{ $candy->id }})"
                        wire:confirm="Take {{ $candy->name }} off the counter?"
                        aria-label="Take off the counter"
                        class="grid h-[28px] w-[28px] place-items-center rounded-[8px] border border-fq-line-2 text-fq-text-4"
                    ><i class="fa-solid fa-xmark text-[11px]"></i></button>
                </div>
            @empty
                <p class="text-[12.5px] text-fq-text-5">Nothing yet. The kids see an empty sweets shelf until you add one.</p>
            @endforelse
        </div>

        <div class="flex flex-col gap-[10px]">
            <div class="flex items-baseline gap-[9px]">
                <h2 class="font-baloo text-[18px] font-extrabold text-fq-text">Waiting to be handed over</h2>
                @if ($queue->isNotEmpty())
                    <span class="rounded-full bg-fq-streak px-[8px] py-[4px] font-mono-fq text-[10px] text-fq-streak-ink">{{ $queue->count() }}</span>
                @endif
            </div>

            @forelse ($queue as $order)
                @php($overdue = $order->created_at->lt($overdueBefore))
                <div
                    wire:key="order-{{ $order->id }}"
                    @class([
                        'flex flex-wrap items-center gap-[12px] rounded-[15px] border px-[12px] py-[11px]',
                        'border-fq-line-2 bg-fq-panel' => ! $overdue,
                        'border-fq-streak' => $overdue,
                    ])
                    @if ($overdue) style="background: #1d0a14" @endif
                >
                    <fq-prize kind="candy" key="{{ $order->hue }}" class="h-[40px] w-[40px] shrink-0"></fq-prize>
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14px] font-semibold text-fq-text">{{ $order->profile->name }} &middot; {{ $order->name }}</span>
                        <span @class(['mt-[3px] block font-mono-fq text-[9px] tracking-[0.08em] uppercase', 'text-fq-text-4' => ! $overdue, 'text-[#ff7a97]' => $overdue])>
                            {{ $order->tokens }} &#10022; &middot; {{ $order->created_at->diffForHumans() }}@if ($overdue) &mdash; overdue @endif
                        </span>
                    </span>
                    <button type="button" wire:click="handOver({{ $order->id }})" class="rounded-[9px] bg-fq-green px-[12px] py-[8px] font-mono-fq text-[10px] text-[#05170c]">HANDED OVER</button>
                    <button
                        type="button"
                        wire:click="refund({{ $order->id }})"
                        wire:confirm="Refund {{ $order->tokens }} tokens to {{ $order->profile->name }}?"
                        class="rounded-[9px] border border-fq-line-2 px-[12px] py-[8px] font-mono-fq text-[10px] text-fq-text-4"
                    >REFUND</button>
                </div>
            @empty
                <p class="rounded-[13px] border border-dashed border-fq-line-2 px-[12px] py-[14px] text-[12.5px] text-fq-text-4">
                    Nobody is waiting on sweets.
                </p>
            @endforelse

            <p class="text-[11.5px] text-pretty text-fq-text-4">
                A refund puts the tokens straight back and tells the kid, in the same words the Loot Shop uses when a
                reward is turned down &mdash; one way of saying &ldquo;sorry, not this one&rdquo;.
            </p>
        </div>
    </div>
</x-parent.shell>
