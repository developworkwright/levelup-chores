<?php

use App\Enums\PerkEffect;
use App\Exceptions\PerkUnavailableException;
use App\Models\Profile;
use App\Services\BadgeService;
use App\Services\PerkInventoryService;
use App\Services\SpinService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public Profile $profile;

    public float $wheelDeg = 0;

    public bool $spinning = false;

    public bool $revealed = false;

    public ?string $perkMessage = null;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isKid(), 403);

        $service = app(SpinService::class);
        $spinToday = $service->today($this->profile);

        if ($spinToday) {
            $chores = $service->eligibleChoresFor($this->profile);
            $slice = 360 / max(1, $chores->count());
            $index = $chores->search(fn ($c) => $c->id === $spinToday->chore_id);

            $this->wheelDeg = $this->restingDeg((int) $index, $slice);
            $this->revealed = true;
        }
    }

    public function spin(): void
    {
        if ($this->spinning || $this->revealed || ! $this->profile->household->spin_enabled) {
            return;
        }

        $service = app(SpinService::class);

        if ($service->hasSpunToday($this->profile)) {
            $this->revealed = true;

            return;
        }

        // The family can clear the board before a kid gets round to spinning.
        // SpinService throws on an empty pool, and nothing here catches it, so
        // the guard has to be in front of the call rather than around it.
        if ($service->eligibleChoresFor($this->profile)->isEmpty()) {
            return;
        }

        $spinResult = $service->spin($this->profile);

        $chores = $service->eligibleChoresFor($this->profile);
        $slice = 360 / max(1, $chores->count());
        $index = $chores->search(fn ($c) => $c->id === $spinResult->chore_id);

        $target = $this->restingDeg((int) $index, $slice);

        while ($target <= $this->wheelDeg) {
            $target += 360;
        }

        $this->wheelDeg = $target + 360 * 6;
        $this->spinning = true;
    }

    public function finishSpin(): void
    {
        $this->spinning = false;
        $this->revealed = true;

        $result = app(SpinService::class)->today($this->profile);

        if ($result) {
            $this->dispatch(
                'celebrate',
                message: "{$result->multiplier}x boost on {$result->chore->name}!",
                style: 'confetti',
                big: $result->multiplier === 3,
            );
        }

        app(BadgeService::class)->evaluate($this->profile);
    }

    /**
     * The pointer sits at the top of the wheel. Segment 0 starts at local
     * angle 0° (3 o'clock, before any rotation) and runs clockwise — same
     * convention as the segment/label markup below — so resting here is
     * what actually brings a chore's slice under the pointer at the top.
     */
    private function restingDeg(int $index, float $slice): float
    {
        return -90 - ($index * $slice + $slice / 2);
    }

    public function usePerk(string $effect): void
    {
        $case = PerkEffect::tryFrom($effect);

        if ($case !== PerkEffect::WheelRespin) {
            return;
        }

        try {
            app(PerkInventoryService::class)->use($this->profile, $case);
            $this->perkMessage = null;
            // Reset the wheel so it's ready to spin again in place.
            $this->revealed = false;
            $this->spinning = false;
            $this->wheelDeg = 0;
            $this->dispatch('celebrate', message: 'Wheel reset — take another spin!');
        } catch (PerkUnavailableException $e) {
            $this->perkMessage = $e->getMessage();
        }
    }

    public function with(): array
    {
        $chores = app(SpinService::class)->eligibleChoresFor($this->profile);
        $inventory = app(PerkInventoryService::class);

        return [
            'boost' => app(SpinService::class)->today($this->profile),
            'wheelChores' => $chores,
            'wheelSlice' => 360 / max(1, $chores->count()),
            'respin' => $inventory->holds($this->profile, PerkEffect::WheelRespin)
                ? [
                    'effect' => PerkEffect::WheelRespin,
                    'count' => $inventory->countOf($this->profile, PerkEffect::WheelRespin),
                    'blocked' => $inventory->blockedReason($this->profile, PerkEffect::WheelRespin),
                ]
                : null,
        ];
    }
}; ?>

<x-kid.shell :profile="$profile" active="wheel">
    <div
        x-data
        x-init="$watch('$wire.spinning', (value) => { if (value) setTimeout(() => $wire.finishSpin(), 6100) })"
        class="grid grid-cols-[repeat(auto-fit,minmax(300px,1fr))] gap-4"
    >
        <div class="flex flex-col items-center gap-[18px] rounded-[24px] border border-fq-line bg-fq-panel p-[22px] text-center">
            <p class="font-mono-fq text-[10px] tracking-[0.24em] text-fq-magenta uppercase">One Spin Per Day</p>
            <h2 class="font-baloo text-[28px] font-extrabold">Bonus Wheel</h2>

            <div class="relative h-[290px] w-[290px]">
                <div
                    class="absolute top-0 left-1/2 z-[3] -translate-x-1/2 drop-shadow-[0_3px_6px_#000]"
                    style="width:0;height:0;border-left:12px solid transparent;border-right:12px solid transparent;border-top:20px solid var(--fq-gold)"
                ></div>

                {{-- Shadow lives on this static wrapper — box-shadow rotates along with
                     its element, so it has to stay off the div that spins. --}}
                <div class="absolute inset-0 rounded-full" style="box-shadow:0 0 0 3px #3a2a18, 0 26px 50px -24px #000;">
                    <div
                        class="absolute inset-0 rounded-full"
                        style="
                            background: repeating-conic-gradient(#2b1c10 0deg 3deg, #1c120a 3deg 6deg);
                            transform: rotate({{ $wheelDeg }}deg);
                            transition: transform 6s cubic-bezier(.11,.85,.1,1);
                        "
                    >
                        {{-- Colorful cookie face — a wedge per chore, cycling through the
                             app's accent palette, muted under a dark cookie-toned overlay so
                             it still reads as chocolatey rather than a plain rainbow. --}}
                        @php
                            $wheelPalette = ['var(--fq-lime)', 'var(--fq-cyan)', 'var(--fq-gold)', 'var(--fq-magenta)', 'var(--fq-coral)', 'var(--fq-violet)', 'var(--fq-sky)'];
                            $wheelStops = $wheelChores->values()->map(
                                fn ($wc, $i) => $wheelPalette[$i % count($wheelPalette)] . ' ' . ($i * $wheelSlice) . 'deg ' . (($i + 1) * $wheelSlice) . 'deg'
                            )->implode(', ');
                        @endphp
                        <div class="absolute inset-[13px] rounded-full" style="background: conic-gradient(from 90deg, {{ $wheelStops }})"></div>
                        <div class="absolute inset-[13px] rounded-full" style="background: radial-gradient(circle at 38% 32%, rgba(40,25,12,.45), rgba(20,12,6,.72) 75%)"></div>

                        {{-- Segment dividers — one per chore boundary, radiating from the
                             center plate out to the rim, so it reads as an actual wheel
                             of distinct outcomes instead of plain decoration. --}}
                        @for ($i = 0; $i < $wheelChores->count(); $i++)
                            @php $boundaryDeg = $i * $wheelSlice; @endphp
                            <div
                                class="absolute"
                                style="
                                    left:145px; top:145px; width:2px; height:132px;
                                    background: rgba(226,204,163,.4);
                                    transform-origin: top center;
                                    transform: translateX(-1px) rotate({{ $boundaryDeg - 90 }}deg);
                                "
                            ></div>
                        @endfor

                        {{-- Chore name per segment, running from the center plate out to
                             the rim along its slice — reads outward, tilt-your-head style,
                             which fits far more of each name than a horizontal label would. --}}
                        @foreach ($wheelChores as $i => $wheelChore)
                            @php $midDeg = $i * $wheelSlice + $wheelSlice / 2; @endphp
                            <div
                                class="absolute overflow-hidden text-ellipsis whitespace-nowrap font-mono-fq font-semibold"
                                style="
                                    left:145px; top:145px; margin-top:-6px; width:98px; height:12px;
                                    transform-origin: left center;
                                    transform: rotate({{ $midDeg }}deg) translateX(30px);
                                    font-size:9px; letter-spacing:.01em; color:#e2cca3;
                                    text-shadow: 0 1px 1px rgba(0,0,0,.5);
                                "
                            >{{ $wheelChore->name }}</div>
                        @endforeach

                        {{-- Small decorative hub — just the wheel's center pin, not a
                             click target (the real spin action is the button below). --}}
                        <div
                            class="absolute top-1/2 left-1/2 z-[2] rounded-full"
                            style="width:22px; height:22px; transform: translate(-50%, -50%); background:#3a2716; border: 2px solid #4a3420; box-shadow: inset 0 1px 3px rgba(0,0,0,.5)"
                        ></div>
                    </div>
                </div>
            </div>

            @if ($revealed && $boost && ! $spinning)
                @php $boostIsBig = $boost->multiplier === 3; @endphp
                <div
                    wire:key="landed-{{ $boost->id }}"
                    class="w-full max-w-[300px] rounded-[16px] border px-4 py-3 text-center"
                    style="animation: fq-pop .3s ease both; background: {{ $boostIsBig ? 'oklch(0.84 0.16 85 / .2)' : 'oklch(0.55 0.19 320 / 0.2)' }}; border-color: {{ $boostIsBig ? 'oklch(0.84 0.16 85 / .55)' : 'oklch(0.65 0.19 320 / 0.5)' }}"
                >
                    <p class="font-mono-fq text-[10px] tracking-[0.2em] uppercase" style="color: {{ $boostIsBig ? 'var(--fq-gold)' : 'var(--fq-magenta)' }}">You landed on</p>
                    <p class="mt-1 font-baloo text-lg font-extrabold">{{ $boost->chore->name }} &mdash; {{ $boost->multiplier }}x</p>
                </div>
            @endif

            @if ($spinning)
                <button type="button" disabled class="w-full max-w-[300px] cursor-default rounded-[18px] bg-fq-line-2 py-4 font-baloo text-[19px] font-extrabold text-fq-text-3">
                    Spinning&hellip;
                </button>
            @elseif ($revealed)
                <button type="button" disabled class="w-full max-w-[300px] cursor-default rounded-[18px] bg-fq-line-2 py-4 font-baloo text-[19px] font-extrabold text-fq-text-3">
                    Used today &mdash; back tomorrow
                </button>
            @else
                <button
                    type="button"
                    wire:click="spin"
                    class="w-full max-w-[300px] rounded-[18px] py-4 font-baloo text-[19px] font-extrabold text-fq-bg shadow-[0_14px_30px_-16px_var(--fq-magenta)]"
                    style="background:var(--fq-magenta)"
                >SPIN</button>
            @endif

            <p class="max-w-[300px] text-[13px] text-fq-text-4">
                @if ($revealed)
                    One spin a day. Your boost is locked in below.
                @else
                    Land on a chore, get 2x or 3x its points — plus a sweet treat when you finish it. Do it today.
                @endif
            </p>
        </div>

        <div class="flex flex-col gap-4">
            <div class="rounded-[24px] border p-5" style="background:linear-gradient(135deg, #2a2050, #171c38); border-color:#45407f">
                <h3 class="font-baloo text-lg font-bold">How it works</h3>
                <div class="mt-3 flex flex-col gap-2 text-sm text-fq-text-2-b">
                    <p>1 &mdash; Spin once a day, any time.</p>
                    <p>2 &mdash; The wheel picks one chore and multiplies its payout 2x or 3x.</p>
                    <p>3 &mdash; Do that chore today to bank the extra points and a sweet treat. Miss it and both are gone.</p>
                </div>
            </div>

            <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
                <h3 class="font-baloo text-lg font-bold">Active Boost</h3>
                @if ($revealed && $boost)
                    @php $boostIsBig = $boost->multiplier === 3; @endphp
                    <div class="mt-3 flex items-center justify-between rounded-[16px] border p-[14px]" style="background: {{ $boostIsBig ? 'oklch(0.84 0.16 85 / .2)' : 'oklch(0.55 0.19 320 / 0.2)' }}; border-color: {{ $boostIsBig ? 'oklch(0.84 0.16 85 / .55)' : 'oklch(0.65 0.19 320 / 0.5)' }}">
                        <span class="text-sm font-semibold">{{ $boost->chore->name }}</span>
                        <span class="font-baloo text-[22px] font-extrabold" style="color: {{ $boostIsBig ? 'var(--fq-gold)' : 'var(--fq-magenta)' }}">{{ $boost->multiplier }}x</span>
                    </div>
                @else
                    <p class="mt-3 text-[13px] text-fq-text-5">No boost yet today.</p>
                @endif

                @if ($respin)
                    <div class="mt-3">
                        <x-perk-button :entry="$respin" />
                    </div>
                @endif

                @if ($perkMessage)
                    <p class="mt-2 text-[13px] text-fq-text-4">{{ $perkMessage }}</p>
                @endif
            </div>
        </div>
    </div>
</x-kid.shell>
