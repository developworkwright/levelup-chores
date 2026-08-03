<?php

use App\Enums\ChoreCadence;
use App\Models\Chore;
use App\Models\Profile;
use App\Services\ChoreService;
use App\Services\HouseholdClock;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public Profile $profile;

    public string $newChoreName = '';

    public string $newChorePoints = '100';

    public string $newChoreCadence = 'daily';

    public string $search = '';

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
            // Moving off one-time drops the spent stamp with it, so a chore
            // parked on Daily for a while doesn't come back as already-used
            // the moment someone sets it to One-time again.
            if ($case !== ChoreCadence::Once) {
                $chore->used_at = null;
            }
            $chore->save();
        }
    }

    /** Puts a spent one-time chore back up for grabs. */
    public function reactivate(int $choreId): void
    {
        $chore = $this->ownedChore($choreId);

        if ($chore?->isUsedUp()) {
            app(ChoreService::class)->reactivate($chore);
        }
    }

    /**
     * Starts the clock on a chore: the kids get until this time to beat the
     * parent to it, after which it closes for the rest of the day.
     *
     * A blank or unparseable time lifts the deadline instead, so clearing the
     * field is the same gesture as pressing Clear.
     */
    public function setDeadline(int $choreId, string $time): void
    {
        $chore = $this->ownedChore($choreId);

        if (! $chore) {
            return;
        }

        $at = HouseholdClock::for($this->profile->household)->atTime($time);

        if (! $at) {
            app(ChoreService::class)->clearDeadline($chore);

            return;
        }

        app(ChoreService::class)->setDeadline($chore, $at);
    }

    public function clearDeadline(int $choreId): void
    {
        $chore = $this->ownedChore($choreId);

        if ($chore) {
            app(ChoreService::class)->clearDeadline($chore);
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

    /**
     * Independent of the quest toggle: a chore can be a perfectly good assigned
     * quest and still be a bad bet on the wheel, which is the case for anything
     * opportunistic — the groceries only need putting away on shopping day.
     */
    public function toggleWheelEligible(int $choreId): void
    {
        $chore = $this->ownedChore($choreId);

        if ($chore) {
            $chore->wheel_eligible = ! $chore->wheel_eligible;
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

        // Otherwise a chore added while a search is active appears to vanish.
        $this->search = '';
    }

    public function clearSearch(): void
    {
        $this->search = '';
    }

    public function with(): array
    {
        $scoped = Chore::where('household_id', $this->profile->household_id);
        $chores = (clone $scoped)->matching($this->search)->orderBy('id')->get();

        $service = app(ChoreService::class);
        $timezone = $this->profile->household->timezone;

        return [
            'chores' => $chores,
            'totalChores' => $scoped->count(),
            // Keyed by chore so a row can show its deadline without each one
            // working out where the household day starts for itself. The time
            // is pre-localised because the input below is a wall clock and the
            // stamp is stored in UTC.
            'deadlines' => $chores->mapWithKeys(fn (Chore $chore) => [$chore->id => [
                'closesAt' => $service->deadlineFor($chore),
                'expired' => $service->isExpired($chore),
                'time' => $chore->expires_at?->copy()->setTimezone($timezone),
            ]]),
        ];
    }
}; ?>

<x-parent.shell :profile="$profile" active="chores">
    <div class="grid grid-cols-[repeat(auto-fit,minmax(300px,1fr))] gap-[14px]">
        <div class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center gap-2 rounded-[18px] border border-fq-line bg-fq-panel p-[12px_14px]">
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search chores by name"
                    class="min-w-[160px] flex-1 rounded-[12px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-sm outline-none focus:border-fq-cyan"
                >

                @if (trim($search) !== '')
                    <span class="font-mono-fq text-[10px] whitespace-nowrap text-fq-text-4">
                        {{ $chores->count() }} / {{ $totalChores }}
                    </span>
                    <button
                        type="button"
                        wire:click="clearSearch"
                        class="rounded-[12px] border border-fq-line-3 bg-fq-sunk px-3 py-2 text-xs text-fq-text-3"
                    >Clear</button>
                @else
                    <span class="font-mono-fq text-[10px] whitespace-nowrap text-fq-text-4">
                        {{ $totalChores }} {{ Str::plural('CHORE', $totalChores) }}
                    </span>
                @endif
            </div>

            @if ($chores->isEmpty())
                <div class="rounded-[18px] border border-dashed border-fq-line-3 bg-fq-panel p-6 text-center text-sm text-fq-text-5">
                    @if (trim($search) !== '')
                        No chores match "{{ $search }}".
                    @else
                        No chores yet — add one on the right to get started.
                    @endif
                </div>
            @endif

            @foreach ($chores as $chore)
                @php $deadline = $deadlines[$chore->id]; @endphp
                <div wire:key="chore-{{ $chore->id }}" class="flex flex-wrap items-center gap-3 rounded-[18px] border border-fq-line bg-fq-panel p-[14px]">
                    <div class="min-w-[140px] flex-1">
                        <p class="text-[15px] font-semibold {{ $chore->isUsedUp() ? 'text-fq-text-4 line-through decoration-2' : '' }}">{{ $chore->name }}</p>
                        <p class="font-mono-fq text-[10px] text-fq-text-4 uppercase">
                            {{ $chore->cadence->summary() }}
                            · {{ $chore->min_age ? "Age {$chore->min_age}+" : 'Any age' }}
                            @unless ($chore->quest_eligible)
                                · <span class="text-fq-coral">Excluded from quest</span>
                            @endunless
                            @unless ($chore->wheel_eligible)
                                · <span class="text-fq-coral">Excluded from wheel</span>
                            @endunless
                        </p>
                        @if ($chore->isUsedUp())
                            {{-- The one state a parent has to act on: nothing
                                 puts a spent one-time chore back but them. --}}
                            <p class="mt-1 font-mono-fq text-[10px] uppercase" style="color: var(--fq-gold)">
                                Taken {{ $chore->used_at->diffForHumans() }} · off the board until reactivated
                            </p>
                        @endif

                        @if ($deadline['expired'])
                            <p class="mt-1 font-mono-fq text-[10px] uppercase" style="color: var(--fq-danger)">
                                Closed {{ $deadline['time']->format('g:i A') }} · all yours until tomorrow
                            </p>
                        @elseif ($deadline['closesAt'])
                            <p class="mt-1 font-mono-fq text-[10px] uppercase" style="color: var(--fq-cyan)">
                                Closes {{ $deadline['time']->format('g:i A') }} · {{ $deadline['closesAt']->diffForHumans() }}
                            </p>
                        @endif
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

                    <div class="flex flex-wrap items-center gap-1 rounded-[12px] border border-fq-line-3 bg-fq-sunk p-1">
                        @foreach (\App\Enums\ChoreCadence::cases() as $case)
                            <button
                                type="button"
                                wire:click="setCadence({{ $chore->id }}, '{{ $case->value }}')"
                                class="rounded-[9px] px-[10px] py-1 text-xs font-semibold {{ $chore->cadence === $case ? 'bg-fq-lime text-fq-bg' : 'text-fq-text-4' }}"
                            >{{ $case->label() }}</button>
                        @endforeach
                    </div>

                    {{-- The "beat me to it" clock. Set a time and the kids get
                         until then to claim it; after that it closes and the
                         job is the parent's, which is the point. --}}
                    {{-- Same shell as the cadence pills beside it — p-1 outer,
                         px-[10px] py-1 on each child — so the two read as one
                         bank of controls rather than two near-misses. --}}
                    <div class="flex items-center gap-1 rounded-[12px] border border-fq-line-3 bg-fq-sunk p-1">
                        <span class="px-[10px] font-mono-fq text-[10px] whitespace-nowrap uppercase" style="color: {{ $chore->expires_at ? 'var(--fq-cyan)' : 'var(--fq-text-4)' }}">Closes</span>
                        {{-- color-scheme is what makes the browser draw the
                             clock icon and the picker popup for a dark surface.
                             Without it the indicator is a near-black glyph on a
                             near-black field — the control looks broken. --}}
                        <input
                            type="time"
                            value="{{ $deadline['time']?->format('H:i') }}"
                            wire:change="setDeadline({{ $chore->id }}, $event.target.value)"
                            class="w-[116px] rounded-[9px] border border-fq-line-2 px-[10px] py-1 text-xs font-semibold outline-none focus:border-fq-cyan"
                            style="color-scheme: dark; background: var(--fq-panel); color: {{ $chore->expires_at ? 'var(--fq-cyan)' : 'var(--fq-text-3)' }}"
                        >
                        @if ($chore->expires_at)
                            <button
                                type="button"
                                wire:click="clearDeadline({{ $chore->id }})"
                                class="rounded-[9px] px-[10px] py-1 text-xs font-semibold text-fq-text-4"
                            >Clear</button>
                        @endif
                    </div>

                    @if ($chore->isUsedUp())
                        <button
                            type="button"
                            wire:click="reactivate({{ $chore->id }})"
                            class="rounded-[12px] border px-3 py-2 text-xs font-semibold text-fq-bg"
                            style="background: var(--fq-gold); border-color: var(--fq-gold)"
                        >Put back on the board</button>
                    @endif

                    <button
                        type="button"
                        wire:click="toggleQuestEligible({{ $chore->id }})"
                        class="rounded-[12px] border px-3 py-2 text-xs {{ $chore->quest_eligible ? 'border-fq-line-3 bg-fq-sunk text-fq-text-3' : 'border-fq-coral bg-fq-sunk text-fq-coral' }}"
                    >
                        {{ $chore->quest_eligible ? 'Exclude from quest' : 'Allow as quest' }}
                    </button>

                    <button
                        type="button"
                        wire:click="toggleWheelEligible({{ $chore->id }})"
                        class="rounded-[12px] border px-3 py-2 text-xs {{ $chore->wheel_eligible ? 'border-fq-line-3 bg-fq-sunk text-fq-text-3' : 'border-fq-coral bg-fq-sunk text-fq-coral' }}"
                    >
                        {{ $chore->wheel_eligible ? 'Exclude from wheel' : 'Allow on wheel' }}
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
                            style="border-color: {{ $chore->hint ? 'color-mix(in srgb, var(--fq-magenta) 50%, transparent)' : 'var(--fq-line-2)' }}; background: var(--fq-sunk)"
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
                $cadence = \App\Enums\ChoreCadence::tryFrom($newChoreCadence) ?? \App\Enums\ChoreCadence::Daily;
            @endphp
            <button
                type="button"
                wire:click="$set('newChoreCadence', '{{ $cadence->next()->value }}')"
                class="rounded-[14px] border border-fq-line-2 bg-fq-sunk px-[14px] py-[13px] text-left text-[15px] text-fq-text-3"
            >{{ $cadence->label() }}</button>

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
                One-time chores are up for grabs once — first kid to claim it takes it, and it comes off everyone's board for good until you put it back. They sit at the top of the kids' side quests. Good for one-offs like "rake the leaves". Sending a claim back reopens it automatically.
            </p>
            <p class="text-xs text-fq-text-5">
                "Closes" puts a deadline on a chore — the kids get a countdown on their board and one last shot at it, and once the time passes it's off their board for the rest of the day and the job is yours. Set it when you're going to do something this evening anyway. It lifts on its own overnight.
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
