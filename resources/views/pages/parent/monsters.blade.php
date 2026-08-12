<?php

use App\Enums\BossSkin;
use App\Enums\MonsterTier;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Monster;
use App\Models\Profile;
use App\Services\MonsterService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * The monster deck: what each of the three is guarding, what it costs, and how
 * much work it takes to bring down.
 *
 * The pricing readout is the reason this page exists rather than three fields
 * on the Kids screen. Kids choose which monster to hit, so the only thing
 * stopping that choice from being an arbitrage is the three tiers costing about
 * the same per point of work — and that is not a number anybody can hold in
 * their head while typing a health figure. So it is on screen, per tier, live.
 */
new class extends Component
{
    /** Bounds on a monster's health, so a typo can't make one unkillable. */
    private const MIN_HEALTH = 50;

    private const MAX_HEALTH = 1000000;

    public Profile $profile;

    /** Draft rewards, costs and healths, keyed by tier. */
    public array $rewardNames = [];

    public array $rewardCosts = [];

    public array $healths = [];

    public array $messages = [];

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isParent(), 403);

        foreach (MonsterTier::cases() as $tier) {
            $this->rewardNames[$tier->value] = '';
            $this->rewardCosts[$tier->value] = '';
            $this->healths[$tier->value] = '';
        }
    }

    private function arena(): MonsterService
    {
        return app(MonsterService::class);
    }

    private function monsterAt(int $tier): ?Monster
    {
        $tier = MonsterTier::tryFrom($tier);

        return $tier ? $this->arena()->at($this->profile->household, $tier) : null;
    }

    /**
     * Stands a new monster up at an empty tier.
     *
     * Health is required and the reward is required; a monster with no name on
     * its prize is a health bar the kids have no reason to pick.
     */
    public function spawn(int $tier): void
    {
        $tierCase = MonsterTier::tryFrom($tier);
        $name = trim((string) ($this->rewardNames[$tier] ?? ''));
        $health = (int) ($this->healths[$tier] ?? 0);

        if (! $tierCase || $name === '' || $health < self::MIN_HEALTH) {
            $this->messages[$tier] = 'Needs a reward and at least '.self::MIN_HEALTH.' points of health.';

            return;
        }

        if ($this->arena()->at($this->profile->household, $tierCase) !== null) {
            return;
        }

        $this->arena()->spawn(
            $this->profile->household,
            $tierCase,
            $name,
            min($health, self::MAX_HEALTH),
            $this->centsFrom($this->rewardCosts[$tier] ?? null),
        );

        $this->rewardNames[$tier] = '';
        $this->rewardCosts[$tier] = '';
        $this->healths[$tier] = '';
        $this->messages[$tier] = null;
    }

    /** Dollars as typed, stored as cents. Blank stays blank rather than zero. */
    private function centsFrom(mixed $dollars): ?int
    {
        $value = trim((string) $dollars);

        return $value === '' ? null : (int) round(((float) $value) * 100);
    }

    public function renameReward(int $tier, string $value): void
    {
        $monster = $this->monsterAt($tier);
        $value = trim($value);

        if ($monster && $value !== '') {
            $monster->forceFill(['reward_name' => $value])->save();
        }
    }

    public function setRewardCost(int $tier, string $value): void
    {
        $this->monsterAt($tier)?->forceFill(['reward_cost_cents' => $this->centsFrom($value)])->save();
    }

    /**
     * Health can move under a fight already in progress, but never below the
     * damage already done — that would leave a monster on negative health that
     * nobody can finish off, and the kids would watch a bar refuse to empty.
     */
    public function adjustHealth(int $tier, int $delta): void
    {
        $monster = $this->monsterAt($tier);

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

    public function adjustDamage(int $tier, int $delta): void
    {
        $monster = $this->monsterAt($tier);

        if ($monster) {
            $this->arena()->adjust($monster, $delta);
            $this->arena()->settle($monster);
        }
    }

    /**
     * Takes a kid's name back off a monster, which is the veto that makes the
     * naming perk safe to sell — a name sits on the family's screen for a
     * fortnight, and somebody has to be able to end that.
     */
    public function clearNickname(int $tier): void
    {
        $monster = $this->monsterAt($tier);

        if ($monster) {
            $this->arena()->clearNickname($monster);
        }
    }

    public function setSkin(int $tier, string $key): void
    {
        $monster = $this->monsterAt($tier);
        $skin = BossSkin::tryFrom($key);

        if ($monster && $skin) {
            $monster->forceFill(['skin' => $skin])->save();
        }
    }

    public function setWeakness(int $tier, string $choreId): void
    {
        $monster = $this->monsterAt($tier);

        if (! $monster) {
            return;
        }

        $chore = $choreId === '' ? null : Chore::find((int) $choreId);

        $this->arena()->setWeakness($monster, $chore);
    }

    /** Sends a hit to the monster the kid meant. */
    public function reaim(int $completionId, int $tier): void
    {
        $completion = ChoreCompletion::with('profile')->find($completionId);
        $tierCase = MonsterTier::tryFrom($tier);

        if (! $completion || ! $tierCase || $completion->profile?->household_id !== $this->profile->household_id) {
            return;
        }

        $this->messages['reaim'] = $this->arena()->reaim($completion, $tierCase)
            ? "Moved to {$tierCase->label()}."
            : "Couldn't move that one — the monster it landed on has already been beaten.";
    }

    public function with(): array
    {
        $household = $this->profile->household;
        $arena = $this->arena();
        $live = $arena->rotateWeaknesses($household);

        return [
            'household' => $household,
            'live' => $live,
            'states' => $live->map(fn (Monster $monster) => $arena->stateFor($monster))->all(),
            'weakOptions' => $live
                ->map(fn (Monster $monster) => $arena->weakChoreOptions($household, $monster))
                ->all(),
            'contributions' => $live
                ->map(fn (Monster $monster) => $arena->contributionsFor($monster))
                ->all(),
            'hits' => $arena->recentHits($household),
            'shelf' => $arena->shelf($household, 8),
        ];
    }
}; ?>

<x-parent.shell :profile="$profile" active="monsters">
    <div class="mb-[14px] rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
        <h3 class="font-baloo text-lg font-bold">Monster Deck</h3>
        <p class="mt-1 max-w-[640px] text-[13px] text-fq-text-3">
            Three family goals, standing at once. The kids pick which one each finished chore
            hits, so keep the cost per point roughly level across the three &mdash; otherwise
            one tier becomes the cheap way to earn and they will find it.
        </p>
    </div>

    <div class="flex flex-col gap-[14px]">
        @foreach (App\Enums\MonsterTier::cases() as $tier)
            @php
                $monster = $live[$tier->value] ?? null;
                $state = $states[$tier->value] ?? null;
                $perHundred = $monster && $monster->reward_cost_cents && $monster->max_health > 0
                    ? $monster->reward_cost_cents / 100 / $monster->max_health * 100
                    : null;
            @endphp

            <div wire:key="deck-tier-{{ $tier->value }}" class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="font-baloo text-lg font-bold">{{ $tier->label() }}</h3>

                    @if ($perHundred !== null)
                        {{-- The number that keeps the three tiers honest. --}}
                        <span class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-gold uppercase">
                            ${{ number_format($perHundred, 2) }} PER 100 PTS
                        </span>
                    @endif
                </div>

                @if (! $monster)
                    <p class="mt-1 text-[13px] text-fq-text-3">
                        Nothing standing. {{ $tier->blurb() }} Name what beating it buys.
                    </p>

                    <div class="mt-3 flex flex-wrap items-end gap-3">
                        <label class="min-w-[200px] flex-[2_1_220px]">
                            <span class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Reward</span>
                            <input
                                type="text"
                                wire:model="rewardNames.{{ $tier->value }}"
                                placeholder="Ice cream outing"
                                class="mt-1 w-full rounded-[12px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-sm outline-none focus:border-fq-cyan"
                            >
                        </label>

                        <label class="min-w-[110px] flex-[1_1_120px]">
                            <span class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Costs ($)</span>
                            <input
                                type="number" step="0.01" min="0"
                                wire:model="rewardCosts.{{ $tier->value }}"
                                placeholder="15.00"
                                class="mt-1 w-full rounded-[12px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-sm outline-none focus:border-fq-cyan"
                            >
                        </label>

                        <label class="min-w-[110px] flex-[1_1_120px]">
                            <span class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Health</span>
                            <input
                                type="number" min="50"
                                wire:model="healths.{{ $tier->value }}"
                                placeholder="500"
                                class="mt-1 w-full rounded-[12px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-sm outline-none focus:border-fq-cyan"
                            >
                        </label>

                        <button
                            type="button"
                            wire:click="spawn({{ $tier->value }})"
                            class="rounded-[12px] px-4 py-2 font-baloo text-sm font-extrabold text-fq-bg transition hover:brightness-110"
                            style="background: var(--fq-lime)"
                        >Send it in</button>
                    </div>

                    @if ($messages[$tier->value] ?? null)
                        <p class="mt-2 text-[13px] text-fq-coral">{{ $messages[$tier->value] }}</p>
                    @endif
                @else
                    <div class="mt-3 flex flex-wrap gap-[18px]">
                        <div
                            wire:key="deck-art-{{ $tier->value }}-{{ $monster->skin->value }}-{{ $state['stage']->value }}"
                            wire:ignore
                            x-data="fqMonster(@js($monster->skin->value), @js($state['stage']->value))"
                            x-html="svg"
                            :style="{ background: cardBg }"
                            class="fq-boss aspect-square w-[110px] shrink-0 overflow-hidden rounded-[16px]"
                        ></div>

                        <div class="min-w-[240px] flex-1">
                            <input
                                type="text" value="{{ $monster->reward_name }}"
                                wire:blur="renameReward({{ $tier->value }}, $event.target.value)"
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
                                        wire:click="clearNickname({{ $tier->value }})"
                                        class="ml-1 underline transition hover:text-fq-text"
                                    >Take the name off</button>
                                </p>
                            @endif

                            <div class="mt-3 flex flex-wrap items-center gap-6">
                                <div>
                                    <p class="mb-1 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Progress</p>
                                    <div class="flex items-center gap-2">
                                        <button type="button" wire:click="adjustDamage({{ $tier->value }}, -100)" class="h-8 w-8 rounded-[10px] border border-fq-line-3 bg-fq-sunk text-lg">&minus;</button>
                                        <span class="w-20 text-center font-baloo text-[17px] font-extrabold text-fq-lime">{{ number_format($state['damage']) }}</span>
                                        <button type="button" wire:click="adjustDamage({{ $tier->value }}, 100)" class="h-8 w-8 rounded-[10px] border border-fq-line-3 bg-fq-sunk text-lg">+</button>
                                    </div>
                                </div>

                                <div>
                                    <p class="mb-1 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Health</p>
                                    <div class="flex items-center gap-2">
                                        <button type="button" wire:click="adjustHealth({{ $tier->value }}, -250)" class="h-8 w-8 rounded-[10px] border border-fq-line-3 bg-fq-sunk text-lg">&minus;</button>
                                        <span class="w-20 text-center font-baloo text-[17px] font-extrabold text-fq-gold">{{ number_format($monster->max_health) }}</span>
                                        <button type="button" wire:click="adjustHealth({{ $tier->value }}, 250)" class="h-8 w-8 rounded-[10px] border border-fq-line-3 bg-fq-sunk text-lg">+</button>
                                    </div>
                                </div>

                                <label>
                                    <span class="mb-1 block font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Costs ($)</span>
                                    <input
                                        type="number" step="0.01" min="0"
                                        value="{{ $monster->reward_cost_cents !== null ? number_format($monster->reward_cost_cents / 100, 2, '.', '') : '' }}"
                                        wire:blur="setRewardCost({{ $tier->value }}, $event.target.value)"
                                        placeholder="—"
                                        class="w-[110px] rounded-[12px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-sm outline-none focus:border-fq-cyan"
                                    >
                                </label>
                            </div>

                            {{-- Swapping this overrides the week's draw without
                                 locking it — next week rolls on as normal. --}}
                            <div class="mt-4">
                                <p class="mb-1 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                                    Flinches at &middot; double damage
                                </p>
                                <select
                                    wire:change="setWeakness({{ $tier->value }}, $event.target.value)"
                                    class="w-full max-w-[320px] rounded-[12px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-sm outline-none focus:border-fq-cyan"
                                >
                                    <option value="">Nothing this week</option>
                                    @foreach ($weakOptions[$tier->value] as $chore)
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
                                            wire:key="skin-{{ $tier->value }}-{{ $skin->value }}"
                                            wire:click="setSkin({{ $tier->value }}, '{{ $skin->value }}')"
                                            @disabled($skin === $monster->skin)
                                            class="rounded-[12px] border px-3 py-2 text-xs transition {{ $skin === $monster->skin ? 'border-fq-lime text-fq-lime' : 'border-fq-line-3 bg-fq-sunk text-fq-text-3 hover:border-fq-line-focus' }}"
                                        >{{ $skin->label() }}</button>
                                    @endforeach
                                </div>
                            </div>

                            @if (($contributions[$tier->value] ?? collect())->isNotEmpty())
                                <div class="mt-4 border-t border-fq-divider pt-[14px]">
                                    <x-goal-mvp :contributors="$contributions[$tier->value]" label="Who's hitting this one" />
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- The mis-tap fix. Re-aiming moves the kid's own damage rather than
         cancelling it out with two nudges, so their name stays on the work. --}}
    <div class="mt-[14px] rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
        <h3 class="font-baloo text-lg font-bold">Recent hits</h3>
        <p class="mt-1 text-[13px] text-fq-text-3">
            Tapped the wrong monster? Move the hit &mdash; the points stay with whoever earned them.
        </p>

        @if ($messages['reaim'] ?? null)
            <p class="mt-2 text-[13px] text-fq-lime">{{ $messages['reaim'] }}</p>
        @endif

        @if ($hits->isEmpty())
            <p class="mt-3 text-[13px] text-fq-text-5">Nothing has landed yet.</p>
        @else
            <div class="mt-3 flex flex-col gap-[6px]">
                @foreach ($hits as $hit)
                    <div
                        wire:key="hit-{{ $hit->id }}"
                        class="flex flex-wrap items-center gap-2 rounded-[12px] border border-fq-line-2 bg-fq-sunk px-3 py-2"
                    >
                        @if ($hit->profile)
                            <span
                                class="grid h-[22px] w-[22px] shrink-0 place-items-center rounded-[6px] font-baloo text-[11px] font-extrabold text-fq-bg"
                                style="background: {{ $hit->profile->color->cssVar() }}"
                            >{{ mb_substr($hit->profile->name, 0, 1) }}</span>
                        @endif

                        <span class="text-[13px] font-semibold">
                            {{ $hit->completion?->chore?->name ?? $hit->kind->label() }}
                        </span>

                        <span class="font-mono-fq text-[10px] text-fq-text-4">
                            &rarr; {{ $hit->monster?->displayName() }}
                            &middot; {{ $hit->monster?->tier->label() }}
                            &middot; {{ number_format($hit->damage) }} PTS
                            @if ($hit->kind !== App\Enums\MonsterHitKind::Hit)
                                &middot; {{ Str::upper($hit->kind->label()) }}
                            @endif
                        </span>

                        @if ($hit->completion && ! ($hit->monster?->isDefeated() ?? true))
                            <span class="ml-auto flex flex-wrap items-center gap-1">
                                <span class="font-mono-fq text-[10px] text-fq-text-5">MOVE TO</span>
                                @foreach ($live as $tierValue => $candidate)
                                    @continue($candidate->is($hit->monster))
                                    <button
                                        type="button"
                                        wire:key="reaim-{{ $hit->id }}-{{ $tierValue }}"
                                        wire:click="reaim({{ $hit->completion->id }}, {{ $tierValue }})"
                                        class="rounded-[10px] border border-fq-line-3 bg-fq-panel px-[10px] py-1 text-[11px] text-fq-text-3 transition hover:border-fq-lime hover:text-fq-text"
                                    >{{ $candidate->tier->label() }}</button>
                                @endforeach
                            </span>
                        @endif
                    </div>
                @endforeach
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
                            {{ $beaten->tier->label() }}
                            &middot; {{ number_format($beaten->max_health) }} HP
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
