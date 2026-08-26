<?php

use App\Enums\BossSkin;
use App\Models\Chore;
use App\Models\Monster;
use App\Models\Profile;
use App\Services\MonsterService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * The monster deck: what the house is fighting for, what it costs, and how much
 * work it takes to bring down.
 *
 * The pricing readout is the reason this page exists rather than two fields on
 * the Kids screen. It is the one number that says whether a reward is priced
 * like the last one, and it is not something anybody can hold in their head
 * while typing a health figure — so it is on screen, live.
 */
new class extends Component
{
    /** Bounds on a monster's health, so a typo can't make one unkillable. */
    private const MIN_HEALTH = 50;

    private const MAX_HEALTH = 1000000;

    public Profile $profile;

    public string $rewardName = '';

    public string $rewardCost = '';

    public string $health = '';

    public ?string $message = null;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isParent(), 403);
    }

    private function arena(): MonsterService
    {
        return app(MonsterService::class);
    }

    private function monster(): ?Monster
    {
        return $this->arena()->current($this->profile->household);
    }

    /**
     * Stands the next monster up.
     *
     * Health is required and the reward is required; a monster with no name on
     * its prize is a health bar the kids have no reason to care about.
     */
    public function spawn(): void
    {
        $name = trim($this->rewardName);
        $health = (int) $this->health;

        if ($name === '' || $health < self::MIN_HEALTH) {
            $this->message = 'Needs a reward and at least '.self::MIN_HEALTH.' points of health.';

            return;
        }

        if ($this->monster() !== null) {
            return;
        }

        $this->arena()->spawn(
            $this->profile->household,
            $name,
            min($health, self::MAX_HEALTH),
            $this->centsFrom($this->rewardCost),
        );

        $this->rewardName = '';
        $this->rewardCost = '';
        $this->health = '';
        $this->message = null;
    }

    /** Dollars as typed, stored as cents. Blank stays blank rather than zero. */
    private function centsFrom(mixed $dollars): ?int
    {
        $value = trim((string) $dollars);

        return $value === '' ? null : (int) round(((float) $value) * 100);
    }

    public function renameReward(string $value): void
    {
        $monster = $this->monster();
        $value = trim($value);

        if ($monster && $value !== '') {
            $monster->forceFill(['reward_name' => $value])->save();
        }
    }

    public function setRewardCost(string $value): void
    {
        $this->monster()?->forceFill(['reward_cost_cents' => $this->centsFrom($value)])->save();
    }

    /**
     * Health can move under a fight already in progress, but never below the
     * damage already done — that would leave a monster on negative health that
     * nobody can finish off, and the kids would watch a bar refuse to empty.
     */
    public function adjustHealth(int $delta): void
    {
        $monster = $this->monster();

        if (! $monster) {
            return;
        }

        $monster->forceFill([
            'max_health' => max(
                max(self::MIN_HEALTH, $monster->damage()),
                min(self::MAX_HEALTH, $monster->max_health + $delta),
            ),
        ])->save();
    }

    public function adjustDamage(int $delta): void
    {
        $monster = $this->monster();

        if ($monster) {
            $this->arena()->adjust($monster, $delta);
            $this->arena()->settle($monster);
        }
    }

    /**
     * Takes a kid's name back off the monster, which is the veto that makes the
     * naming perk safe to sell — a name sits on the family's screen for a
     * fortnight, and somebody has to be able to end that.
     */
    public function clearNickname(): void
    {
        $monster = $this->monster();

        if ($monster) {
            $this->arena()->clearNickname($monster);
        }
    }

    public function setSkin(string $key): void
    {
        $monster = $this->monster();
        $skin = BossSkin::tryFrom($key);

        if ($monster && $skin) {
            $monster->forceFill(['skin' => $skin])->save();
        }
    }

    public function setWeakness(string $choreId): void
    {
        $monster = $this->monster();

        if (! $monster) {
            return;
        }

        $this->arena()->setWeakness($monster, $choreId === '' ? null : Chore::find((int) $choreId));
    }

    public function with(): array
    {
        $household = $this->profile->household;
        $arena = $this->arena();
        $monster = $arena->rotateWeakness($household);

        return [
            'household' => $household,
            'monster' => $monster,
            'state' => $monster ? $arena->stateFor($monster) : null,
            'weakOptions' => $arena->weakChorePool($household),
            'contributions' => $monster ? $arena->contributionsFor($monster) : collect(),
            'shelf' => $arena->shelf($household, 8),
        ];
    }
}; ?>

<x-parent.shell :profile="$profile" active="monsters">
    @php
        $perHundred = $monster && $monster->reward_cost_cents && $monster->max_health > 0
            ? $monster->reward_cost_cents / 100 / $monster->max_health * 100
            : null;
    @endphp

    <div class="mb-[14px] rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="font-baloo text-lg font-bold">Monster Deck</h3>

            @if ($perHundred !== null)
                {{-- The number that keeps one monster priced like the last. --}}
                <span class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-gold uppercase">
                    ${{ number_format($perHundred, 2) }} PER 100 PTS
                </span>
            @endif
        </div>

        <p class="mt-1 max-w-[640px] text-[13px] text-fq-text-3">
            One family goal at a time. Every approved chore lands on it, so the health
            you set is simply how many points of work the reward is worth.
        </p>
    </div>

    <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
        @if (! $monster)
            <h3 class="font-baloo text-lg font-bold">Nothing standing</h3>
            <p class="mt-1 text-[13px] text-fq-text-3">
                Name what beating the next one buys, and how much work that is worth.
            </p>

            <div class="mt-3 flex flex-wrap items-end gap-3">
                <label class="min-w-[200px] flex-[2_1_220px]">
                    <span class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Reward</span>
                    <input
                        type="text"
                        wire:model="rewardName"
                        placeholder="Ice cream outing"
                        class="mt-1 w-full rounded-[12px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-sm outline-none focus:border-fq-cyan"
                    >
                </label>

                <label class="min-w-[110px] flex-[1_1_120px]">
                    <span class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Costs ($)</span>
                    <input
                        type="number" step="0.01" min="0"
                        wire:model="rewardCost"
                        placeholder="15.00"
                        class="mt-1 w-full rounded-[12px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-sm outline-none focus:border-fq-cyan"
                    >
                </label>

                <label class="min-w-[110px] flex-[1_1_120px]">
                    <span class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Health</span>
                    <input
                        type="number" min="50"
                        wire:model="health"
                        placeholder="500"
                        class="mt-1 w-full rounded-[12px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-sm outline-none focus:border-fq-cyan"
                    >
                </label>

                <button
                    type="button"
                    wire:click="spawn"
                    class="rounded-[12px] px-4 py-2 font-baloo text-sm font-extrabold text-fq-bg transition hover:brightness-110"
                    style="background: var(--fq-lime)"
                >Send it in</button>
            </div>

            @if ($message)
                <p class="mt-2 text-[13px] text-fq-coral">{{ $message }}</p>
            @endif
        @else
            <div class="flex flex-wrap gap-[18px]">
                <div
                    wire:key="deck-art-{{ $monster->skin->value }}-{{ $state['stage']->value }}"
                    wire:ignore
                    x-data="fqMonster(@js($monster->skin->value), @js($state['stage']->value))"
                    x-html="svg"
                    :style="{ background: cardBg }"
                    class="fq-boss aspect-square w-[110px] shrink-0 overflow-hidden rounded-[16px]"
                ></div>

                <div class="min-w-[240px] flex-1">
                    <input
                        type="text" value="{{ $monster->reward_name }}"
                        wire:blur="renameReward($event.target.value)"
                        class="w-full border-0 border-b border-fq-line-2 bg-transparent py-[3px] text-[15px] font-semibold outline-none focus:border-fq-cyan"
                    >

                    <div class="mt-3 h-4 overflow-hidden rounded-full border border-fq-line bg-fq-track">
                        <div
                            class="h-full rounded-full transition-[width] duration-500"
                            style="width:{{ $state['damagePercent'] }}%;background:linear-gradient(90deg, var(--fq-cyan), var(--fq-lime), var(--fq-gold))"
                        ></div>
                    </div>

                    <p class="mt-2 font-mono-fq text-[10px] text-fq-text-4">
                        {{ $monster->displayName() }} &middot;
                        {{ number_format($state['damage']) }} / {{ number_format($state['maxHealth']) }} PTS &middot;
                        {{ $state['damagePercent'] }}%
                        @if ($state['defeated'])
                            &middot; <span class="text-fq-lime">BEATEN</span>
                        @endif
                    </p>

                    @if ($monster->nickname)
                        <p class="mt-1 font-mono-fq text-[10px] text-fq-text-5">
                            Named by a kid &middot; really {{ $monster->skin->label() }}
                            <button
                                type="button"
                                wire:click="clearNickname"
                                class="ml-1 underline transition hover:text-fq-text"
                            >Take the name off</button>
                        </p>
                    @endif

                    <div class="mt-3 flex flex-wrap items-center gap-6">
                        <div>
                            <p class="mb-1 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Progress</p>
                            <div class="flex items-center gap-2">
                                <button type="button" wire:click="adjustDamage(-100)" class="h-8 w-8 rounded-[10px] border border-fq-line-3 bg-fq-sunk text-lg">&minus;</button>
                                <span class="w-20 text-center font-baloo text-[17px] font-extrabold text-fq-lime">{{ number_format($state['damage']) }}</span>
                                <button type="button" wire:click="adjustDamage(100)" class="h-8 w-8 rounded-[10px] border border-fq-line-3 bg-fq-sunk text-lg">+</button>
                            </div>
                        </div>

                        <div>
                            <p class="mb-1 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Health</p>
                            <div class="flex items-center gap-2">
                                <button type="button" wire:click="adjustHealth(-250)" class="h-8 w-8 rounded-[10px] border border-fq-line-3 bg-fq-sunk text-lg">&minus;</button>
                                <span class="w-20 text-center font-baloo text-[17px] font-extrabold text-fq-gold">{{ number_format($monster->max_health) }}</span>
                                <button type="button" wire:click="adjustHealth(250)" class="h-8 w-8 rounded-[10px] border border-fq-line-3 bg-fq-sunk text-lg">+</button>
                            </div>
                        </div>

                        <label>
                            <span class="mb-1 block font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Costs ($)</span>
                            <input
                                type="number" step="0.01" min="0"
                                value="{{ $monster->reward_cost_cents !== null ? number_format($monster->reward_cost_cents / 100, 2, '.', '') : '' }}"
                                wire:blur="setRewardCost($event.target.value)"
                                placeholder="—"
                                class="w-[110px] rounded-[12px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-sm outline-none focus:border-fq-cyan"
                            >
                        </label>
                    </div>

                    {{-- Swapping this overrides the week's draw without locking
                         it — next week rolls on as normal. --}}
                    <div class="mt-4">
                        <p class="mb-1 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                            Flinches at &middot; double damage
                        </p>
                        <select
                            wire:change="setWeakness($event.target.value)"
                            class="w-full max-w-[320px] rounded-[12px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-sm outline-none focus:border-fq-cyan"
                        >
                            <option value="">Nothing this week</option>
                            @foreach ($weakOptions as $chore)
                                <option value="{{ $chore->id }}" @selected($monster->weak_chore_id === $chore->id)>
                                    {{ $chore->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mt-4">
                        <p class="mb-[6px] font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Monster</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach (App\Enums\BossSkin::cases() as $skin)
                                <button
                                    type="button"
                                    wire:key="skin-{{ $skin->value }}"
                                    wire:click="setSkin('{{ $skin->value }}')"
                                    @disabled($skin === $monster->skin)
                                    class="rounded-[12px] border px-3 py-2 text-xs transition {{ $skin === $monster->skin ? 'border-fq-lime text-fq-lime' : 'border-fq-line-3 bg-fq-sunk text-fq-text-3 hover:border-fq-line-focus' }}"
                                >{{ $skin->label() }}</button>
                            @endforeach
                        </div>
                    </div>

                    @if ($contributions->isNotEmpty())
                        <div class="mt-4 border-t border-fq-divider pt-[14px]">
                            <x-goal-mvp :contributors="$contributions" label="Who's hitting it" />
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    @if ($shelf->isNotEmpty())
        <div class="mt-[14px] rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
            <h3 class="font-baloo text-lg font-bold">Trophy shelf</h3>

            <div class="mt-3 flex flex-col gap-[6px]">
                @foreach ($shelf as $beaten)
                    <div
                        wire:key="shelf-{{ $beaten->id }}"
                        class="flex flex-wrap items-center gap-2 rounded-[12px] border border-fq-line-2 bg-fq-sunk px-3 py-2"
                    >
                        <span class="text-[13px] font-semibold">{{ $beaten->displayName() }}</span>
                        <span class="font-mono-fq text-[10px] text-fq-text-4">
                            {{ number_format($beaten->max_health) }} HP
                            &middot; {{ $beaten->reward_name }}
                            @if ($beaten->finisher)
                                &middot; FINISHED BY {{ Str::upper($beaten->finisher->name) }}
                            @endif
                        </span>
                        <span class="ml-auto font-mono-fq text-[10px] text-fq-text-5">
                            {{ $beaten->defeated_at->toFormattedDateString() }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</x-parent.shell>
