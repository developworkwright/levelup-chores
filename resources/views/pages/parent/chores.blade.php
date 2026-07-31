<?php

use App\Enums\ChoreCadence;
use App\Models\Chore;
use App\Models\Profile;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public Profile $profile;

    public string $newChoreName = '';

    public string $newChorePoints = '100';

    public string $newChoreCadence = 'daily';

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isParent(), 403);
    }

    private function ownedChore(int $choreId): ?Chore
    {
        return Chore::where('household_id', $this->profile->household_id)->find($choreId);
    }

    public function adjustPoints(int $choreId, int $delta): void
    {
        $chore = $this->ownedChore($choreId);

        if ($chore) {
            $chore->points = max(0, $chore->points + $delta);
            $chore->save();
        }
    }

    public function setHint(int $choreId, string $hint): void
    {
        $chore = $this->ownedChore($choreId);

        if (! $chore) {
            return;
        }

        $hint = trim($hint);
        // Blank clears it, so a chore can go back to having no clue at all.
        $chore->hint = $hint === '' ? null : $hint;
        $chore->save();
    }

    public function setCadence(int $choreId, string $cadence): void
    {
        $chore = $this->ownedChore($choreId);
        $case = ChoreCadence::tryFrom($cadence);

        if ($chore && $case) {
            $chore->cadence = $case;
            $chore->save();
        }
    }

    public function toggleQuestEligible(int $choreId): void
    {
        $chore = $this->ownedChore($choreId);

        if ($chore) {
            $chore->quest_eligible = ! $chore->quest_eligible;
            $chore->save();
        }
    }

    public function adjustMinAge(int $choreId, int $delta): void
    {
        $chore = $this->ownedChore($choreId);

        if (! $chore) {
            return;
        }

        $current = $chore->min_age ?? 0;

        // Turning the restriction on from "Any age" starts at a sensible floor.
        $new = ($current === 0 && $delta > 0) ? 6 : max(0, $current + $delta);

        $chore->min_age = $new > 0 ? $new : null;
        $chore->save();
    }

    public function remove(int $choreId): void
    {
        $this->ownedChore($choreId)?->delete();
    }

    public function addChore(): void
    {
        $name = trim($this->newChoreName);

        if ($name === '') {
            return;
        }

        Chore::create([
            'household_id' => $this->profile->household_id,
            'name' => $name,
            'points' => max(0, (int) preg_replace('/\D/', '', $this->newChorePoints) ?: 100),
            'cadence' => ChoreCadence::tryFrom($this->newChoreCadence) ?? ChoreCadence::Daily,
        ]);

        $this->newChoreName = '';
        $this->newChorePoints = '100';
        $this->newChoreCadence = 'daily';
    }

    public function with(): array
    {
        return [
            'chores' => Chore::where('household_id', $this->profile->household_id)->orderBy('id')->get(),
        ];
    }
}; ?>

<x-parent.shell :profile="$profile" active="chores">
    <div class="grid grid-cols-[repeat(auto-fit,minmax(300px,1fr))] gap-[14px]">
        <div class="flex flex-col gap-3">
            @foreach ($chores as $chore)
                <div wire:key="chore-{{ $chore->id }}" class="flex flex-wrap items-center gap-3 rounded-[18px] border border-fq-line bg-fq-panel p-[14px]">
                    <div class="min-w-[140px] flex-1">
                        <p class="text-[15px] font-semibold">{{ $chore->name }}</p>
                        <p class="font-mono-fq text-[10px] text-fq-text-4 uppercase">
                            @if ($chore->cadence->value === 'weekly')
                                Weekly · Cooldown 7d
                            @elseif ($chore->cadence->value === 'unlimited')
                                Unlimited · No cooldown
                            @else
                                Daily · Cooldown 24h
                            @endif
                            · {{ $chore->min_age ? "Age {$chore->min_age}+" : 'Any age' }}
                            @unless ($chore->quest_eligible)
                                · <span class="text-fq-coral">Excluded from quest</span>
                            @endunless
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="adjustPoints({{ $chore->id }}, -25)" class="h-8 w-8 rounded-[10px] border border-fq-line-3 bg-fq-sunk text-lg">&minus;</button>
                        <span class="w-12 text-center font-baloo text-[17px] font-extrabold text-fq-lime">{{ $chore->points }}</span>
                        <button type="button" wire:click="adjustPoints({{ $chore->id }}, 25)" class="h-8 w-8 rounded-[10px] border border-fq-line-3 bg-fq-sunk text-lg">+</button>
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="adjustMinAge({{ $chore->id }}, -1)" class="h-8 w-8 rounded-[10px] border border-fq-line-3 bg-fq-sunk text-lg">&minus;</button>
                        <span class="w-14 text-center font-baloo text-[15px] font-extrabold text-fq-violet">{{ $chore->min_age ? "{$chore->min_age}+" : 'Any' }}</span>
                        <button type="button" wire:click="adjustMinAge({{ $chore->id }}, 1)" class="h-8 w-8 rounded-[10px] border border-fq-line-3 bg-fq-sunk text-lg">+</button>
                    </div>

                    <div class="flex items-center gap-1 rounded-[12px] border border-fq-line-3 bg-fq-sunk p-1">
                        @foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'unlimited' => 'Unlimited'] as $value => $label)
                            <button
                                type="button"
                                wire:click="setCadence({{ $chore->id }}, '{{ $value }}')"
                                class="rounded-[9px] px-[10px] py-1 text-xs font-semibold {{ $chore->cadence->value === $value ? 'bg-fq-lime text-fq-bg' : 'text-fq-text-4' }}"
                            >{{ $label }}</button>
                        @endforeach
                    </div>

                    <button
                        type="button"
                        wire:click="toggleQuestEligible({{ $chore->id }})"
                        class="rounded-[12px] border px-3 py-2 text-xs {{ $chore->quest_eligible ? 'border-fq-line-3 bg-fq-sunk text-fq-text-3' : 'border-fq-coral bg-fq-sunk text-fq-coral' }}"
                    >
                        {{ $chore->quest_eligible ? 'Exclude from quest' : 'Allow as quest' }}
                    </button>

                    <button
                        type="button"
                        wire:click="remove({{ $chore->id }})"
                        wire:confirm="Remove '{{ $chore->name }}' from the board?"
                        class="rounded-[12px] border border-fq-danger-border bg-transparent px-3 py-2 text-xs text-fq-danger hover:bg-fq-danger-bg"
                    >Remove</button>

                    <div class="w-full">
                        <input
                            type="text"
                            value="{{ $chore->hint }}"
                            wire:blur="setHint({{ $chore->id }}, $event.target.value)"
                            placeholder="Mystery hint — a clue, not the answer"
                            maxlength="255"
                            class="w-full rounded-[12px] border border-dashed px-3 py-2 text-sm outline-none focus:border-fq-magenta"
                            style="border-color: {{ $chore->hint ? 'oklch(0.65 0.19 320 / .5)' : 'var(--fq-line-2)' }}; background: var(--fq-sunk)"
                        >
                    </div>
                </div>
            @endforeach
        </div>

        <div class="flex flex-col gap-3 rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
            <h3 class="font-baloo text-lg font-bold">Add a chore</h3>

            <input
                type="text" wire:model="newChoreName" placeholder="Chore name"
                class="rounded-[14px] border border-fq-line-2 bg-fq-sunk px-[14px] py-[13px] text-[15px] outline-none"
            >
            <input
                type="text" inputmode="numeric" wire:model="newChorePoints" placeholder="Points"
                class="rounded-[14px] border border-fq-line-2 bg-fq-sunk px-[14px] py-[13px] text-[15px] outline-none"
            >

            @php
                $cadenceLabels = ['daily' => 'Daily', 'weekly' => 'Weekly', 'unlimited' => 'Unlimited'];
                $nextCadence = ['daily' => 'weekly', 'weekly' => 'unlimited', 'unlimited' => 'daily'][$newChoreCadence] ?? 'daily';
            @endphp
            <button
                type="button"
                wire:click="$set('newChoreCadence', '{{ $nextCadence }}')"
                class="rounded-[14px] border border-fq-line-2 bg-fq-sunk px-[14px] py-[13px] text-left text-[15px] text-fq-text-3"
            >{{ $cadenceLabels[$newChoreCadence] ?? 'Daily' }}</button>

            <button
                type="button"
                wire:click="addChore"
                class="rounded-[15px] py-[13px] font-baloo text-[17px] font-extrabold text-fq-bg"
                style="background:var(--fq-cyan)"
            >Add to board</button>

            <p class="text-xs text-fq-text-5">
                100 points = $1. Daily chores unlock again the next morning; weekly ones after 7 days; unlimited ones never lock — good for stuff like laundry that can happen more than once a day.
            </p>
            <p class="text-xs text-fq-text-5">
                Each day, one any-age chore is automatically picked as a hidden bonus — first kid to finish it wins extra points. No setup needed here.
            </p>
            <p class="text-xs text-fq-text-5">
                The hint on each chore is the clue a kid gets if they spend tickets on a mystery hint. Write it like a riddle — "the hungry ones can't ask for it themselves" — so it narrows the field without giving the answer away.
            </p>
        </div>
    </div>
</x-parent.shell>
