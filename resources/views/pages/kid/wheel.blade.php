<?php

use App\Enums\PerkEffect;
use App\Exceptions\PerkUnavailableException;
use App\Models\DailyQuest;
use App\Models\Profile;
use App\Models\Spin;
use App\Services\BadgeService;
use App\Services\ChoreService;
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

    /**
     * Why a tap on the boosted chore didn't take. Same reasoning as the Quests
     * board: cooldowns are household-wide, so a page left open can go stale,
     * and a button that silently no-ops reads as broken.
     */
    public ?string $claimMessage = null;

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
            $this->dispatch('celebrate', message: 'Wheel reset — take another spin!', style: $case->celebrationStyle());
        } catch (PerkUnavailableException $e) {
            $this->perkMessage = $e->getMessage();
        }
    }

    /**
     * Today's quest, or null when the household has nothing eligible to draw
     * one from. The Quests page treats that as fatal because it has nothing to
     * render without one — the wheel has a whole page that works either way, so
     * it degrades to "no quest" rather than taking the spin down with it.
     */
    private function questOrNull(): ?DailyQuest
    {
        try {
            return app(ChoreService::class)->questFor($this->profile);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * What the Active Boost card can offer for the chore the wheel landed on:
     * the claim itself, or the reason it isn't available.
     *
     * The boosted chore is the one a kid came here to do, so making them go
     * and find it again on the Quests board is a tab switch for no reason.
     *
     * @return ?array{claimable: bool, label: string, note: ?string, toQuests: bool}
     */
    private function boostClaim(?Spin $boost): ?array
    {
        if (! $boost) {
            return null;
        }

        $service = app(ChoreService::class);
        $chore = $boost->chore;
        $quest = $this->questOrNull();

        // The chest reveal is the whole ceremony of the main quest, so a boost
        // that landed on it gets pointed back there rather than quietly
        // claiming it from under the unopened chest.
        if ($quest && $quest->chore_id === $chore->id) {
            return [
                'claimable' => false,
                'label' => 'This is your main quest',
                'note' => 'Open the chest on the Quests page to claim it.',
                'toQuests' => true,
            ];
        }

        if ($this->profile->household->require_quest_first && $quest && $quest->completed_at === null) {
            return [
                'claimable' => false,
                'label' => 'Main quest first',
                'note' => 'Clear today\'s main quest and this unlocks.',
                'toQuests' => true,
            ];
        }

        $state = $service->stateFor($this->profile, $chore);
        $claimant = $service->claimantFor($chore);

        return match (true) {
            $state === 'ready' => ['claimable' => true, 'label' => 'Mark it done', 'note' => null, 'toQuests' => false],
            $state === 'pending' => ['claimable' => false, 'label' => 'Waiting on a parent', 'note' => null, 'toQuests' => false],
            $state === 'expired' => ['claimable' => false, 'label' => "Time's up", 'note' => 'A parent is taking that one.', 'toQuests' => false],
            $claimant && $claimant->profile_id !== $this->profile->id => [
                'claimable' => false,
                'label' => $claimant->profile->name.' got this one',
                'note' => null,
                'toQuests' => false,
            ],
            default => ['claimable' => false, 'label' => 'Already done today', 'note' => null, 'toQuests' => false],
        };
    }

    /**
     * Claims the boosted chore without leaving the page. Every guard the Quests
     * board applies is re-run here — a disabled button in a browser is never
     * the thing standing between a kid and a double claim.
     */
    public function claimBoostedChore(): void
    {
        $this->claimMessage = null;

        $boost = app(SpinService::class)->today($this->profile);
        $claim = $this->boostClaim($boost);

        if (! $claim) {
            return;
        }

        if (! $claim['claimable']) {
            $this->claimMessage = $claim['note'] ?? $claim['label'].'.';

            return;
        }

        $service = app(ChoreService::class);
        $chore = $boost->chore;

        if (! $chore->isAppropriateFor($this->profile)) {
            return;
        }

        // Silent about the mystery chore on purpose — the find is announced
        // once a parent approves the work, by the card the kid shell queues.
        // Saying it here made submitting a chore enough to be told the answer.
        $this->dispatch(
            'celebrate',
            message: "{$chore->name} claimed at {$boost->multiplier}x! Bonus wheel treat earned.",
            treat: 'cookie',
            motion: 'burst',
            origin: 'tap',
        );

        $service->claim($this->profile, $chore);
    }

    public function with(): array
    {
        $chores = app(SpinService::class)->eligibleChoresFor($this->profile);
        $inventory = app(PerkInventoryService::class);
        $boost = app(SpinService::class)->today($this->profile);

        return [
            'boost' => $boost,
            'boostClaim' => $this->boostClaim($boost),
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
        class="grid grid-cols-[repeat(auto-fit,minmax(300px,1fr))] items-stretch gap-4"
    >
        <div class="flex flex-col items-center justify-center gap-[18px] rounded-[24px] border border-fq-line bg-fq-panel p-[22px] text-center">
            <p class="font-mono-fq text-[10px] tracking-[0.24em] text-fq-magenta uppercase">One Spin Per Day</p>
            <h2 class="font-baloo text-[28px] font-extrabold">Bonus Wheel</h2>

            <div class="relative h-[290px] w-[290px]">
                <div
                    class="absolute top-0 left-1/2 z-[3] -translate-x-1/2 drop-shadow-[0_3px_6px_#000]"
                    style="width:0;height:0;border-left:12px solid transparent;border-right:12px solid transparent;border-top:20px solid var(--fq-gold)"
                ></div>

                {{-- Shadow lives on this static wrapper — box-shadow rotates along with
                     its element, so it has to stay off the div that spins. --}}
                <div class="absolute inset-0 rounded-full" style="box-shadow:0 0 0 3px var(--fq-wheel-ring), var(--fq-shadow-wheel);">
                    <div
                        class="absolute inset-0 rounded-full"
                        style="
                            background: var(--fq-wheel-rim);
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
                                    background: color-mix(in srgb, var(--fq-wheel-label) 40%, transparent);
                                    transform-origin: top center;
                                    transform: translateX(-1px) rotate({{ $boundaryDeg - 90 }}deg);
                                "
                            ></div>
                        @endfor

                        {{-- Chore name per segment, running from the center plate out to
                             the rim along its slice — reads outward, tilt-your-head style,
                             which fits far more of each name than a horizontal label would.

                             The name and nothing else. The points were tried here and
                             taken back out: a slice is 98px of 9px type, so a number on
                             the end is bought with the name's last few characters — and
                             the payout is stated in full the moment the wheel stops,
                             which is the only point at which one of these numbers is the
                             one that matters. --}}
                        @foreach ($wheelChores as $i => $wheelChore)
                            @php $midDeg = $i * $wheelSlice + $wheelSlice / 2; @endphp
                            <div
                                class="absolute overflow-hidden text-ellipsis whitespace-nowrap font-mono-fq font-semibold"
                                style="
                                    left:145px; top:145px; margin-top:-6px; width:98px; height:12px;
                                    transform-origin: left center;
                                    transform: rotate({{ $midDeg }}deg) translateX(30px);
                                    font-size:9px; letter-spacing:.01em; color: var(--fq-wheel-label);
                                    text-shadow: 0 1px 1px rgba(0,0,0,.5);
                                "
                            >{{ $wheelChore->name }}</div>
                        @endforeach

                        {{-- Small decorative hub — just the wheel's center pin, not a
                             click target (the real spin action is the button below). --}}
                        <div
                            class="absolute top-1/2 left-1/2 z-[2] rounded-full"
                            style="width:22px; height:22px; transform: translate(-50%, -50%); background: var(--fq-wheel-hub); border: 2px solid var(--fq-wheel-hub-line); box-shadow: inset 0 1px 3px rgba(0,0,0,.5)"
                        ></div>
                    </div>
                </div>
            </div>

            @php
                // The landed panel and the Active Boost card are the same
                // result stated twice, so they're tinted from one place.
                $boostIsBig = $boost && $boost->multiplier === 3;
                $boostTint = $boostIsBig
                    ? 'background: color-mix(in srgb, var(--fq-gold) 20%, transparent); border-color: color-mix(in srgb, var(--fq-gold) 55%, transparent)'
                    : 'background: color-mix(in srgb, var(--fq-magenta) 20%, transparent); border-color: color-mix(in srgb, var(--fq-magenta) 50%, transparent)';
                $boostColor = $boostIsBig ? 'var(--fq-gold)' : 'var(--fq-magenta)';
            @endphp

            @if ($revealed && $boost && ! $spinning)
                <div
                    wire:key="landed-{{ $boost->id }}"
                    class="w-full max-w-[300px] rounded-[16px] border px-4 py-3 text-center"
                    style="animation: fq-pop .3s ease both; {{ $boostTint }}"
                >
                    <p class="font-mono-fq text-[10px] tracking-[0.2em] uppercase" style="color: {{ $boostColor }}">You landed on</p>
                    <p class="mt-1 font-baloo text-lg font-extrabold">{{ $boost->chore->name }} &mdash; {{ $boost->multiplier }}x</p>
                    {{-- The multiplier stated as the number it actually pays.
                         "3x" is arithmetic homework; the total is the thing
                         worth getting off the sofa for. --}}
                    <p class="mt-1 font-mono-fq text-[11px] text-fq-text-3">
                        {{ number_format($boost->chore->points) }}
                        <span class="text-fq-text-5">&rarr;</span>
                        <span class="font-semibold" style="color: {{ $boostColor }}">{{ number_format($boost->chore->points * $boost->multiplier) }} PTS</span>
                    </p>
                </div>
            @endif
        </div>

        <div class="flex flex-col gap-4">
            {{-- The spin lives here rather than under the wheel: on a phone the
                 wheel fills the screen, and the button a kid came for shouldn't
                 be the thing they have to scroll past it to find. --}}
            <div class="flex flex-col gap-3 rounded-[24px] border border-fq-line bg-fq-panel p-5">
                @if ($spinning)
                    <button type="button" disabled class="w-full cursor-default rounded-[18px] bg-fq-line-2 py-4 font-baloo text-[19px] font-extrabold text-fq-text-3">
                        Spinning&hellip;
                    </button>
                @elseif ($revealed)
                    <button type="button" disabled class="w-full cursor-default rounded-[18px] bg-fq-line-2 py-4 font-baloo text-[19px] font-extrabold text-fq-text-3">
                        Used today &mdash; back tomorrow
                    </button>
                @else
                    <button
                        type="button"
                        wire:click="spin"
                        class="w-full rounded-[18px] py-4 font-baloo text-[19px] font-extrabold text-fq-bg transition hover:brightness-110"
                        style="background:var(--fq-magenta); box-shadow: var(--fq-shadow-glow-lg) var(--fq-magenta)"
                    >SPIN</button>
                @endif

                <p class="text-[13px] text-fq-text-4">
                    @if ($revealed)
                        One spin a day. Your boost is locked in below.
                    @else
                        Land on a chore, get 2x or 3x its points — plus a sweet treat when you finish it. Do it today.
                    @endif
                </p>

                @if ($respin)
                    <div class="flex flex-col items-start gap-1">
                        <x-perk-button :entry="$respin" />
                        @if ($respin['blocked'])
                            <span class="font-mono-fq text-[10px] text-fq-text-5">{{ $respin['blocked'] }}</span>
                        @endif
                    </div>
                @endif

                @if ($perkMessage)
                    <p class="text-[13px] text-fq-text-4">{{ $perkMessage }}</p>
                @endif
            </div>

            <div class="rounded-[24px] border p-5" style="background: var(--fq-wash-blue); border-color: var(--fq-line-cool)">
                <h3 class="font-baloo text-lg font-bold">How it works</h3>
                <div class="mt-3 flex flex-col gap-2 text-sm text-fq-text-2-b">
                    <p>1 &mdash; Spin once a day, any time.</p>
                    <p>2 &mdash; The wheel picks one chore and multiplies its payout 2x or 3x.</p>
                    <p>3 &mdash; Do that chore today to bank the extra points and a sweet treat. Miss it and both are gone.</p>
                </div>
            </div>

            <div class="flex flex-1 flex-col rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
                <h3 class="font-baloo text-lg font-bold">Active Boost</h3>
                @if ($revealed && $boost)
                    <div class="mt-3 flex items-center justify-between gap-3 rounded-[16px] border p-[14px]" style="{{ $boostTint }}">
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold">{{ $boost->chore->name }}</span>
                            <span class="font-mono-fq text-[10px] tracking-[0.1em] text-fq-text-4 uppercase">
                                {{ number_format($boost->chore->points) }} &rarr; {{ number_format($boost->chore->points * $boost->multiplier) }} pts
                            </span>
                        </span>
                        <span class="font-baloo text-[22px] font-extrabold whitespace-nowrap" style="color: {{ $boostColor }}">{{ $boost->multiplier }}x</span>
                    </div>

                    {{-- The claim, right here. The boosted chore is the one a
                         kid came to the wheel for; sending them off to find it
                         again on the board is a tab switch for no reason. --}}
                    @if ($boostClaim && $boostClaim['claimable'])
                        <button
                            type="button"
                            wire:click="claimBoostedChore"
                            class="mt-3 w-full rounded-[14px] py-[11px] text-sm font-semibold text-fq-bg transition hover:brightness-110"
                            style="background: var(--fq-lime)"
                        >{{ $boostClaim['label'] }}</button>
                    @elseif ($boostClaim)
                        <button
                            type="button"
                            disabled
                            class="mt-3 w-full cursor-default rounded-[14px] bg-fq-panel-alt py-[11px] text-sm font-semibold text-fq-text-4"
                        >{{ $boostClaim['label'] }}</button>
                    @endif

                    @if ($boostClaim && $boostClaim['note'])
                        <p class="mt-2 text-[13px] text-fq-text-5">{{ $boostClaim['note'] }}</p>
                    @endif

                    @if ($boostClaim && $boostClaim['toQuests'])
                        <a
                            href="{{ route('kid.quests') }}"
                            wire:navigate
                            class="mt-2 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase transition hover:text-fq-text"
                        >Go to Quests &rarr;</a>
                    @endif

                    @if ($claimMessage)
                        <p class="mt-2 text-[13px] font-semibold text-fq-gold">{{ $claimMessage }}</p>
                    @endif
                @else
                    <p class="mt-3 text-[13px] text-fq-text-5">No boost yet today.</p>
                @endif
            </div>
        </div>
    </div>
</x-kid.shell>
