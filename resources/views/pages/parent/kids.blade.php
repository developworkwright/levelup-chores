<?php

use App\Enums\LedgerKind;
use App\Enums\ProfileRole;
use App\Models\ChoreCompletion;
use App\Models\Profile;
use App\Models\Spin;
use App\Services\BadgeService;
use App\Services\ChoreService;
use App\Services\HouseholdClock;
use App\Services\LedgerService;
use App\Services\SpinService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public Profile $profile;

    /** @var array<int, string> */
    public array $newPins = [];

    /** @var array<int, string> */
    public array $pinMessages = [];

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isParent(), 403);
    }

    private function ownedKid(int $profileId): ?Profile
    {
        return Profile::where('household_id', $this->profile->household_id)
            ->where('role', ProfileRole::Kid)
            ->find($profileId);
    }

    public function adjustPoints(int $profileId, int $delta): void
    {
        $kid = $this->ownedKid($profileId);

        if (! $kid) {
            return;
        }

        $sign = $delta > 0 ? '+' : '';
        app(LedgerService::class)->record(
            $this->profile->household,
            $kid,
            LedgerKind::Adjustment,
            $delta,
            "Parent adjustment: {$sign}{$delta} for {$kid->name}",
        );

        if ($delta > 0) {
            $this->dispatch('celebrate', message: "{$kid->name} got {$sign}{$delta} points!");
        }

        app(BadgeService::class)->evaluate($kid);
    }

    public function recordCashIn(int $profileId): void
    {
        $kid = $this->ownedKid($profileId);

        if (! $kid) {
            return;
        }

        $amount = 5 * $this->profile->household->points_per_dollar;
        app(LedgerService::class)->record(
            $this->profile->household,
            $kid,
            LedgerKind::CashIn,
            $amount,
            "{$kid->name} turned in \$5 cash",
        );

        $this->dispatch('celebrate', message: "{$kid->name} turned in \$5 cash!");

        app(BadgeService::class)->evaluate($kid);
    }

    public function recordPayout(int $profileId): void
    {
        $kid = $this->ownedKid($profileId);

        if (! $kid || $kid->points <= 0) {
            return;
        }

        $dollars = number_format($kid->points / $this->profile->household->points_per_dollar, 2);
        app(LedgerService::class)->record(
            $this->profile->household,
            $kid,
            LedgerKind::CashOut,
            -$kid->points,
            "Paid out \${$dollars} to {$kid->name}",
        );
    }

    public function resetSpin(int $profileId): void
    {
        $kid = $this->ownedKid($profileId);

        if (! $kid) {
            return;
        }

        Spin::where('profile_id', $kid->id)
            ->whereDate('spin_date', HouseholdClock::for($kid->household)->today())
            ->delete();
    }

    public function updateGoalName(string $value): void
    {
        $value = trim($value);

        if ($value === '') {
            return;
        }

        $household = $this->profile->household;
        $household->goal_name = $value;
        $household->save();
    }

    public function adjustGoalTarget(int $delta): void
    {
        $household = $this->profile->household;
        $household->goal_target = max(250, $household->goal_target + $delta);
        // Never let the target drop below progress already made.
        $household->goal_now = min($household->goal_now, $household->goal_target);
        $household->save();
    }

    public function adjustGoalNow(int $delta): void
    {
        $household = $this->profile->household;
        $household->goal_now = max(0, min($household->goal_target, $household->goal_now + $delta));
        $household->save();
    }

    public function resetGoalProgress(): void
    {
        $household = $this->profile->household;
        $household->goal_now = 0;
        $household->save();
    }

    public function changePin(int $profileId): void
    {
        $kid = $this->ownedKid($profileId) ?? ($profileId === $this->profile->id ? $this->profile : null);
        $pin = $this->newPins[$profileId] ?? '';

        if (! $kid || ! preg_match('/^\d{4}$/', $pin)) {
            $this->pinMessages[$profileId] = 'PIN must be exactly 4 digits.';

            return;
        }

        $kid->setPin($pin);
        $kid->resetPinAttempts();
        $kid->save();

        $this->newPins[$profileId] = '';
        $this->pinMessages[$profileId] = 'PIN updated.';
    }

    /**
     * What's assigned today, and how far the kid has gotten on it — lets a
     * parent see today's main quest for each kid without waiting for an
     * approval request to show up.
     */
    private function questSummaryFor(Profile $kid): array
    {
        $quest = app(ChoreService::class)->questFor($kid);

        if ($quest->completed_at === null) {
            return ['chore' => $quest->chore, 'status' => 'not_started'];
        }

        $completion = ChoreCompletion::where('profile_id', $kid->id)
            ->where('chore_id', $quest->chore_id)
            ->latest('submitted_at')
            ->first();

        return ['chore' => $quest->chore, 'status' => $completion?->status->value ?? 'pending'];
    }

    public function with(): array
    {
        $kids = Profile::where('household_id', $this->profile->household_id)
            ->where('role', ProfileRole::Kid)
            ->orderByDesc('age')
            ->get();

        return [
            'kids' => $kids,
            'questSummaries' => $kids->mapWithKeys(fn (Profile $kid) => [$kid->id => $this->questSummaryFor($kid)]),
            'spins' => $kids->mapWithKeys(fn (Profile $kid) => [$kid->id => app(SpinService::class)->today($kid)]),
            'household' => $this->profile->household,
        ];
    }
}; ?>

<x-parent.shell :profile="$profile" active="kids">
    @php
        $goalPercent = $household->goal_target > 0
            ? min(100, round($household->goal_now / $household->goal_target * 100))
            : 0;
    @endphp

    <div class="mb-[14px] rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
        <div class="flex items-center justify-between">
            <h3 class="font-baloo text-lg font-bold">Family Goal</h3>
            <span class="font-mono-fq text-[10px] text-fq-lime">{{ $goalPercent }}%</span>
        </div>

        <input
            type="text" value="{{ $household->goal_name }}"
            wire:blur="updateGoalName($event.target.value)"
            placeholder="What are you all working toward?"
            class="mt-3 w-full border-0 border-b border-fq-line-2 bg-transparent py-[3px] text-[15px] font-semibold outline-none focus:border-fq-cyan"
        >

        <div class="mt-3 h-4 overflow-hidden rounded-full border" style="background:#242c4d; border-color:#2f3960">
            <div
                class="h-full rounded-full transition-[width] duration-500"
                style="width:{{ $goalPercent }}%;background:linear-gradient(90deg, var(--fq-cyan), var(--fq-lime), var(--fq-gold))"
            ></div>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-6">
            <div>
                <p class="mb-1 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Progress</p>
                <div class="flex items-center gap-2">
                    <button type="button" wire:click="adjustGoalNow(-100)" class="h-8 w-8 rounded-[10px] border border-fq-line-3 bg-fq-sunk text-lg">&minus;</button>
                    <span class="w-16 text-center font-baloo text-[17px] font-extrabold text-fq-lime">{{ $household->goal_now }}</span>
                    <button type="button" wire:click="adjustGoalNow(100)" class="h-8 w-8 rounded-[10px] border border-fq-line-3 bg-fq-sunk text-lg">+</button>
                </div>
            </div>

            <div>
                <p class="mb-1 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Target</p>
                <div class="flex items-center gap-2">
                    <button type="button" wire:click="adjustGoalTarget(-250)" class="h-8 w-8 rounded-[10px] border border-fq-line-3 bg-fq-sunk text-lg">&minus;</button>
                    <span class="w-16 text-center font-baloo text-[17px] font-extrabold text-fq-gold">{{ $household->goal_target }}</span>
                    <button type="button" wire:click="adjustGoalTarget(250)" class="h-8 w-8 rounded-[10px] border border-fq-line-3 bg-fq-sunk text-lg">+</button>
                </div>
            </div>

            <button
                type="button"
                wire:click="resetGoalProgress"
                wire:confirm="Reset progress back to 0? Good for starting a new goal once this one's done."
                class="ml-auto rounded-[12px] border border-dashed border-fq-line-4 bg-fq-sunk px-3 py-2 text-xs text-fq-text-3"
            >Start a new goal</button>
        </div>
    </div>

    <div class="grid grid-cols-[repeat(auto-fit,minmax(300px,1fr))] gap-[14px]">
        @foreach ($kids as $kid)
            @php
                $dollars = number_format($kid->points / $profile->household->points_per_dollar, 2);
                $quest = $questSummaries[$kid->id];
                $spin = $spins[$kid->id];
                $questLabels = [
                    'not_started' => ['label' => 'Not opened yet', 'color' => 'var(--fq-text-4)'],
                    'pending' => ['label' => 'Waiting on you', 'color' => 'var(--fq-gold)'],
                    'approved' => ['label' => 'Cleared', 'color' => 'var(--fq-lime)'],
                    'rejected' => ['label' => 'Sent back', 'color' => 'var(--fq-danger)'],
                ][$quest['status']];
            @endphp
            <div wire:key="kid-{{ $kid->id }}" class="flex flex-col gap-[14px] rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
                <div class="flex items-center gap-3">
                    <div
                        class="flex h-[44px] w-[44px] items-center justify-center rounded-[14px] font-baloo text-lg font-extrabold text-fq-bg"
                        style="background:{{ $kid->color->cssVar() }}"
                    >{{ mb_substr($kid->name, 0, 1) }}</div>
                    <div>
                        <p class="font-baloo text-[19px] font-bold">{{ $kid->name }}</p>
                        <p class="font-mono-fq text-[10px] text-fq-text-4 uppercase">AGE {{ $kid->age }} · LVL {{ $kid->level() }} · {{ $kid->streak }}D STREAK</p>
                    </div>
                </div>

                <div class="rounded-[14px] border border-fq-line-2 bg-fq-sunk px-3 py-[10px]">
                    <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Today's Quest</p>
                    <div class="mt-1 flex items-center justify-between gap-2">
                        <span class="text-sm font-semibold">{{ $quest['chore']->name }}</span>
                        <span class="font-mono-fq text-[10px] font-semibold whitespace-nowrap" style="color: {{ $questLabels['color'] }}">{{ $questLabels['label'] }}</span>
                    </div>
                </div>

                <div class="flex items-baseline justify-between rounded-[14px] border border-fq-line-2 bg-fq-sunk p-[14px]">
                    <span class="font-baloo text-[26px] font-extrabold text-fq-lime">{{ $kid->points }}</span>
                    <span class="font-mono-fq text-[10px] text-fq-text-4">PTS · ${{ $dollars }}</span>
                </div>

                <div>
                    <p class="mb-2 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Adjust Points</p>
                    <div class="flex gap-2">
                        @foreach ([-100, -25, 25, 100, 500] as $delta)
                            @php $positive = $delta > 0; @endphp
                            <button
                                type="button"
                                wire:click="adjustPoints({{ $kid->id }}, {{ $delta }})"
                                class="min-w-[56px] flex-1 rounded-[12px] px-[6px] py-[10px] font-mono-fq text-xs font-semibold"
                                style="
                                    background: {{ $positive ? 'oklch(0.7 0.14 140 / 0.22)' : 'oklch(0.6 0.14 20 / 0.18)' }};
                                    border: 1px solid {{ $positive ? 'oklch(0.7 0.16 140 / 0.5)' : 'oklch(0.65 0.16 20 / 0.45)' }};
                                    color: {{ $positive ? 'var(--fq-lime)' : 'oklch(0.78 0.14 25)' }};
                                "
                            >{{ $delta > 0 ? '+' : '' }}{{ $delta }}</button>
                        @endforeach
                    </div>
                </div>

                <button
                    type="button"
                    wire:click="recordCashIn({{ $kid->id }})"
                    class="rounded-[14px] border border-dashed border-fq-line-4 bg-fq-sunk py-[10px] text-sm text-fq-text-3"
                >Record $5 cash turned in (+500)</button>

                <button
                    type="button"
                    wire:click="recordPayout({{ $kid->id }})"
                    wire:confirm="Pay out ${{ $dollars }} and zero {{ $kid->name }}'s balance?"
                    class="rounded-[14px] border border-fq-line-3 bg-fq-sunk py-[10px] text-sm text-fq-text-3"
                >Record payout — zero balance</button>

                <div class="flex items-center justify-between gap-2 rounded-[14px] border border-fq-line-2 bg-fq-sunk px-3 py-[10px]">
                    <span class="text-sm text-fq-text-3">
                        @if ($spin)
                            Spun today &middot; {{ $spin->multiplier }}x
                        @else
                            Hasn't spun today
                        @endif
                    </span>
                    <button
                        type="button"
                        wire:click="resetSpin({{ $kid->id }})"
                        @disabled(! $spin)
                        class="rounded-[12px] border border-fq-line-3 bg-fq-panel px-3 py-[6px] text-xs text-fq-text-3 disabled:opacity-40"
                    >Reset spin</button>
                </div>

                <div class="border-t border-fq-divider pt-3">
                    <p class="mb-2 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Change PIN</p>
                    <div class="flex gap-2">
                        <input
                            type="text" inputmode="numeric" maxlength="4"
                            wire:model="newPins.{{ $kid->id }}"
                            placeholder="4-digit PIN"
                            class="flex-1 rounded-[14px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-sm outline-none"
                        >
                        <button type="button" wire:click="changePin({{ $kid->id }})" class="rounded-[14px] border border-fq-line-3 bg-fq-sunk px-3 py-2 text-sm text-fq-text-3">Update</button>
                    </div>
                    @if (! empty($pinMessages[$kid->id]))
                        <p class="mt-1 text-xs text-fq-text-4">{{ $pinMessages[$kid->id] }}</p>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-[14px] rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
        <p class="mb-2 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Your PIN (Parent Console)</p>
        <div class="flex max-w-[320px] gap-2">
            <input
                type="text" inputmode="numeric" maxlength="4"
                wire:model="newPins.{{ $profile->id }}"
                placeholder="4-digit PIN"
                class="flex-1 rounded-[14px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-sm outline-none"
            >
            <button type="button" wire:click="changePin({{ $profile->id }})" class="rounded-[14px] border border-fq-line-3 bg-fq-sunk px-3 py-2 text-sm text-fq-text-3">Update</button>
        </div>
        @if (! empty($pinMessages[$profile->id]))
            <p class="mt-1 text-xs text-fq-text-4">{{ $pinMessages[$profile->id] }}</p>
        @endif
    </div>
</x-parent.shell>
