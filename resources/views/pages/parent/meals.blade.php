<?php

use App\Models\Profile;
use App\Services\HouseholdClock;
use App\Services\MealService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * The menu — seven nights, and what's for dinner on each.
 *
 * The smallest screen that answers the question the kids actually ask. It is
 * deliberately not a meal planner: there is no recipe library, no ingredient
 * list and no shopping list yet, and none of this page's shape assumes there
 * won't be — see the meals migration for where those land when they arrive.
 *
 * Today first rather than Monday first. The thing a grown-up opens this for
 * most often is "what did I say we were having tonight", and on a Thursday a
 * Monday-first week buries that four rows down.
 *
 * Typed straight into the row and saved on blur, the way the Quests admin edits
 * a chore name. There is no Save button and no form: seven boxes with a button
 * under them is a screen somebody abandons half-filled, and a half-filled week
 * that was never submitted is worse than no week at all.
 */
new class extends Component
{
    public Profile $profile;

    /**
     * The dinner on each night, keyed by Y-m-d.
     *
     * @var array<string, string>
     */
    public array $names = [];

    /** @var array<string, string> */
    public array $notes = [];

    public ?string $flashMessage = null;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();

        abort_unless($this->profile->isParent(), 403);

        foreach (app(MealService::class)->week($this->profile->household) as $day) {
            $key = $day['date']->toDateString();

            $this->names[$key] = $day['meal']->name ?? '';
            $this->notes[$key] = $day['meal']->note ?? '';
        }
    }

    /**
     * The day `$date` names, but only if it is one of the seven on screen.
     *
     * This is the tenancy guard for this page, and it does two jobs: it keeps a
     * hand-sent date from writing a row a year out, and it resolves the string
     * through the household's own clock rather than trusting it as a date.
     */
    private function editableDay(string $date): ?Carbon
    {
        $day = rescue(fn () => Carbon::parse($date)->startOfDay(), null, false);

        if (! $day) {
            return null;
        }

        $week = collect(app(MealService::class)->week($this->profile->household))
            ->map(fn (array $row) => $row['date']->toDateString());

        return $week->contains($day->toDateString()) ? $day : null;
    }

    /** Save one night. A blank name clears it — see MealService::set(). */
    public function save(string $date): void
    {
        $day = $this->editableDay($date);

        if (! $day) {
            return;
        }

        app(MealService::class)->set(
            $this->profile->household,
            $this->profile,
            $day,
            $this->names[$date] ?? '',
            $this->notes[$date] ?? '',
        );

        $this->flashMessage = null;
    }

    /** Empties both boxes on a night and saves, which deletes the row. */
    public function clearDay(string $date): void
    {
        if (! $this->editableDay($date)) {
            return;
        }

        $this->names[$date] = '';
        $this->notes[$date] = '';

        $this->save($date);
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        $today = HouseholdClock::for($this->profile->household)->today()->toDateString();

        return [
            'week' => app(MealService::class)->week($this->profile->household),
            'today' => $today,
        ];
    }
}; ?>

<x-parent.shell :profile="$profile" active="meals">
    <div class="flex max-w-[620px] flex-col gap-3">
        <div class="flex flex-col gap-[3px]">
            <h2 class="font-baloo text-xl font-bold">What's for dinner</h2>
            <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                Type it in — it saves itself. Empty the box to clear the night.
            </p>
        </div>

        @if ($flashMessage)
            <p class="text-sm font-semibold text-fq-danger">{{ $flashMessage }}</p>
        @endif

        @foreach ($week as $day)
            @php
                $key = $day['date']->toDateString();
                $isToday = $key === $today;
            @endphp

            <div
                wire:key="meal-{{ $key }}"
                @class([
                    'flex flex-col gap-[7px] rounded-[18px] border bg-fq-panel p-[13px]',
                    'border-fq-line' => ! $isToday,
                ])
                @style(['border: 1px solid var(--fq-gold)' => $isToday])
            >
                <div class="flex items-baseline justify-between gap-3">
                    <span class="font-mono-fq text-[10px] tracking-[0.16em] uppercase {{ $isToday ? '' : 'text-fq-text-4' }}" @style(['color: var(--fq-gold)' => $isToday])>
                        {{ $isToday ? 'Tonight' : $day['date']->format('D j M') }}
                    </span>

                    @if ($day['meal'])
                        <button
                            type="button"
                            wire:click="clearDay('{{ $key }}')"
                            class="font-mono-fq text-[10px] tracking-[0.12em] text-fq-text-5 uppercase"
                            aria-label="Clear {{ $day['date']->format('D j M') }}"
                        >Clear</button>
                    @endif
                </div>

                <input
                    type="text"
                    wire:model="names.{{ $key }}"
                    wire:blur="save('{{ $key }}')"
                    x-on:keydown.enter.prevent="$event.target.blur()"
                    maxlength="{{ \App\Services\MealService::MAX_NAME }}"
                    placeholder="Tacos"
                    aria-label="Dinner on {{ $day['date']->format('D j M') }}"
                    class="w-full border-0 border-b border-fq-line-2 bg-transparent py-[3px] text-[16px] font-semibold outline-none focus:border-fq-gold"
                >

                <input
                    type="text"
                    wire:model="notes.{{ $key }}"
                    wire:blur="save('{{ $key }}')"
                    x-on:keydown.enter.prevent="$event.target.blur()"
                    maxlength="{{ \App\Services\MealService::MAX_NOTE }}"
                    placeholder="Anything else — eat at 5, leftovers, takeaway night"
                    aria-label="Note for {{ $day['date']->format('D j M') }}"
                    class="w-full border-0 bg-transparent text-[13.5px] text-fq-text-3 outline-none placeholder:text-fq-text-5"
                >
            </div>
        @endforeach

        <p class="px-1 text-[12.5px] text-fq-text-5">
            Tonight's dinner shows up on the kids' family feed, under Today in the house.
            Tomorrow's shows up there too, once you've set it.
        </p>
    </div>
</x-parent.shell>
