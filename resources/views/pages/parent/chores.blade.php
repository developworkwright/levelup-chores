<?php

use App\Enums\ChoreCadence;
use App\Enums\ChoreCategory;
use App\Enums\ChoreEffort;
use App\Enums\ChoreIcon;
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

    /** Narrows the list to the chores nobody can claim right now. */
    public bool $onlyUnavailable = false;

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

    /**
     * Puts a chore back up for grabs ahead of its cadence — the vacuuming is
     * weekly right up until someone tips over a bag of chips.
     */
    public function reopen(int $choreId): void
    {
        $chore = $this->ownedChore($choreId);

        if ($chore) {
            app(ChoreService::class)->reopen($chore);
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

    /**
     * How hard the job is, cycled "not said" → easy going → hard work → back.
     *
     * Three states rather than a switch, because "nobody has said" is a real
     * answer and a parent has to be able to get back to it — the same shape as
     * the min-age stepper's "Any age". Only Hard work is load-bearing: it's
     * what the kid board's Muscle chip collects, and it's the one thing no
     * keyword pass could ever guess (see {@see ChoreEffort}).
     */
    public function cycleEffort(int $choreId): void
    {
        $chore = $this->ownedChore($choreId);

        if ($chore) {
            $chore->effort = ChoreEffort::next($chore->effort);
            $chore->save();
        }
    }

    /**
     * Which chip a chore browses under on the kids' board.
     *
     * A dropdown rather than a cycle button like the two beside it: eleven
     * options is nine taps too many, and unlike cadence or effort there is no
     * natural order to walk through. An empty value clears it back to "not
     * said", which collects under Other — never a silent disappearance.
     */
    public function setCategory(int $choreId, string $category): void
    {
        $chore = $this->ownedChore($choreId);

        if ($chore) {
            $chore->category = ChoreCategory::tryFrom($category);
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

    /**
     * Which chore's icon picker is open. One at a time — sixteen icons under
     * every row at once is a wall, and this is a control most parents will
     * touch once per chore and never again.
     */
    public ?int $pickingIconFor = null;

    /**
     * What's been typed into the custom-class box, keyed by chore.
     *
     * Per chore rather than one shared string: the picker is one-at-a-time
     * today, but a half-typed class leaking onto the next chore a parent opens
     * is the kind of thing that only shows up once it's live.
     *
     * @var array<int, string>
     */
    public array $customIcon = [];

    /** Why a typed class didn't take, keyed by chore. @var array<int, string> */
    public array $customIconMessage = [];

    public function togglePicker(int $choreId): void
    {
        $this->pickingIconFor = $this->pickingIconFor === $choreId ? null : $choreId;
        unset($this->customIconMessage[$choreId]);

        // Seeded with whatever the chore is already wearing, so a parent
        // tweaking a class starts from it rather than retyping it.
        if ($this->pickingIconFor === $choreId) {
            $this->customIcon[$choreId] = (string) ($this->ownedChore($choreId)?->icon ?? '');
        }
    }

    /** Sets a chore's face, or clears it when the same icon is tapped again. */
    public function setIcon(int $choreId, string $icon): void
    {
        $chore = $this->ownedChore($choreId);
        $case = ChoreIcon::tryFrom($icon);

        if (! $chore || ! $case) {
            return;
        }

        // Tapping the current one clears it, which is the only way back to the
        // typographic face once a parent has chosen — and the guessed default
        // means some chores start out with a face nobody picked.
        $chore->icon = $chore->icon === $case->faClass() ? null : $case->faClass();
        $chore->save();

        $this->pickingIconFor = null;
        unset($this->customIconMessage[$choreId]);
    }

    /**
     * Sets a chore's face from a Font Awesome class a parent typed.
     *
     * The presets above are a shortlist; this is the whole of Font Awesome.
     * Blank clears the face, which is the same escape hatch tapping the lit
     * preset gives — and anything that survives {@see ChoreIcon::normalizeClass}
     * is a `fa-` token, since this string ends up in a `class` attribute.
     *
     * The picker deliberately stays open on success: a typed class is chosen
     * blind, so the parent needs to see the glyph it landed on to know whether
     * they got the one they meant.
     */
    public function setCustomIcon(int $choreId): void
    {
        $chore = $this->ownedChore($choreId);

        if (! $chore) {
            return;
        }

        $typed = trim($this->customIcon[$choreId] ?? '');

        if ($typed === '') {
            $chore->icon = null;
            $chore->save();
            $this->customIcon[$choreId] = '';
            unset($this->customIconMessage[$choreId]);

            return;
        }

        $class = ChoreIcon::normalizeClass($typed);

        if ($class === null) {
            // Named rather than silently ignored: the box takes free text, and
            // a control that eats what you type reads as broken.
            $this->customIconMessage[$choreId] = 'That doesn\'t look like a Font Awesome class — try something like fa-solid fa-rocket.';

            return;
        }

        $chore->icon = $class;
        $chore->save();

        // Echoed back normalised, so a parent can see what was actually stored
        // when they typed a bare name or pasted a whole tag.
        $this->customIcon[$choreId] = $class;
        unset($this->customIconMessage[$choreId]);
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
            // Guessed from the name so a board arrives with faces on it
            // without a parent picking sixteen times. Null when nothing fits,
            // which the card reads as "use the typographic face" — a wrong
            // picture is worse than none, because the kid this is for chooses
            // by the picture and has nothing to check it against.
            'icon' => ChoreIcon::classForName($name),
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

    public function toggleOnlyUnavailable(): void
    {
        $this->onlyUnavailable = ! $this->onlyUnavailable;
    }

    public function with(): array
    {
        $scoped = Chore::where('household_id', $this->profile->household_id);
        $chores = (clone $scoped)->matching($this->search)->orderBy('id')->get();

        $service = app(ChoreService::class);
        $timezone = $this->profile->household->timezone;

        $availability = $chores->mapWithKeys(fn (Chore $chore) => [
            $chore->id => $service->availabilityFor($chore),
        ]);

        $lockedCount = $availability->reject(fn (array $row) => $row['available'])->count();

        if ($this->onlyUnavailable) {
            $chores = $chores->reject(fn (Chore $chore) => $availability[$chore->id]['available'])->values();
        }

        return [
            'chores' => $chores,
            'totalChores' => $scoped->count(),
            // Counted before the filter narrows the list, so the toggle can say
            // how many chores it would show without having to run twice.
            'lockedCount' => $lockedCount,
            // Keyed by chore so a row can show who's holding it, and until
            // when, without each one working the household clock out for
            // itself. Times are pre-localised because the stamps are UTC.
            'availability' => $availability->map(fn (array $row) => [
                ...$row,
                'freesAt' => $row['freesAt']?->copy()->setTimezone($timezone),
                'lastDoneAt' => $row['lastDone']?->submitted_at?->copy()->setTimezone($timezone),
            ]),
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

                {{-- The quickest way to answer "why can't anyone do anything
                     tonight" — and to spot a cooldown that's holding a chore
                     longer than the household actually wants it held. --}}
                <button
                    type="button"
                    wire:click="toggleOnlyUnavailable"
                    class="w-full rounded-[12px] border px-3 py-2 text-xs font-semibold {{ $onlyUnavailable ? 'text-fq-bg' : 'border-fq-line-3 bg-fq-sunk text-fq-text-3' }}"
                    style="{{ $onlyUnavailable ? 'background: var(--fq-gold); border-color: var(--fq-gold)' : '' }}"
                >
                    {{ $onlyUnavailable ? 'Showing unavailable only' : 'Show unavailable only' }}
                    · {{ $lockedCount }} locked
                </button>
            </div>

            @if ($chores->isEmpty())
                <div class="rounded-[18px] border border-dashed border-fq-line-3 bg-fq-panel p-6 text-center text-sm text-fq-text-5">
                    @if ($onlyUnavailable && $lockedCount === 0)
                        Every chore is up for grabs right now.
                    @elseif (trim($search) !== '')
                        No chores match "{{ $search }}".
                    @else
                        No chores yet — add one on the right to get started.
                    @endif
                </div>
            @endif

            @foreach ($chores as $chore)
                @php
                    $deadline = $deadlines[$chore->id];
                    $status = $availability[$chore->id];
                    $holder = $status['claimant']?->profile->name;
                    $freesAt = $status['freesAt'];

                    // Relative to now, so the stored UTC stamp reads correctly
                    // without needing the household's timezone.
                    $claimedAgo = $status['claimant']?->submitted_at->diffForHumans();

                    // 'expired' is deliberately absent: the deadline block
                    // below already says which time closed it, to the minute,
                    // which this line can't.
                    $statusLine = match ($status['reason']) {
                        'ready' => 'Up for grabs',
                        'pending' => "Claimed by {$holder} {$claimedAgo} · waiting on your approval",
                        'used_up' => "Taken by {$holder} {$claimedAgo} · off the board until you put it back",
                        'claimed' => "Done by {$holder} {$claimedAgo} · back "
                            .($freesAt ? $freesAt->calendar() : 'once you reopen it'),
                        default => null,
                    };

                    $statusColor = match ($status['reason']) {
                        'ready' => 'var(--fq-lime)',
                        'pending' => 'var(--fq-gold)',
                        default => 'var(--fq-coral)',
                    };
                @endphp
                <div wire:key="chore-{{ $chore->id }}" class="flex flex-wrap items-center gap-3 rounded-[18px] border border-fq-line bg-fq-panel p-[14px]">
                    {{-- The face this chore wears on the kids' quest cards.
                         Tapping it opens the picker; the swatch itself is the
                         control, so the row doesn't grow a labelled button for
                         something most parents set once. --}}
                    <button
                        type="button"
                        wire:click="togglePicker({{ $chore->id }})"
                        title="{{ $chore->icon ? 'Card face: '.(ChoreIcon::tryFromClass($chore->icon)?->label() ?? $chore->icon) : 'No card face — pick one' }}"
                        class="grid h-[46px] w-[46px] shrink-0 place-items-center rounded-[14px] border transition hover:border-fq-lime"
                        style="border-color: {{ $chore->icon ? 'var(--fq-gold)' : 'var(--fq-line-3)' }};
                               background: var(--fq-sunk);
                               color: {{ $chore->icon ? 'var(--fq-gold)' : 'var(--fq-text-5)' }}"
                    >
                        @if ($chore->icon)
                            <x-chore-icon :icon="$chore->icon" class="text-[24px]" />
                        @else
                            <span class="font-mono-fq text-[15px] leading-none">+</span>
                        @endif
                    </button>

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
                            @if ($chore->effort)
                                · {{ $chore->effort->label() }}
                            @endif
                            · {{ $chore->category?->label() ?? 'No category' }}
                        </p>
                        {{-- Whether the family can actually do this job right
                             now, and who's holding it if not. The one line on
                             the row that answers "why is nothing available?"
                             and "is this cooldown too long?" --}}
                        @if ($statusLine)
                            <p class="mt-1 font-mono-fq text-[10px] uppercase" style="color: {{ $statusColor }}">
                                {{ $statusLine }}
                            </p>
                        @endif

                        {{-- Kept for the states where the holding claim isn't
                             the last thing that happened — a chore closed by
                             deadline, or one done and then reopened. --}}
                        @if ($status['lastDone'] && in_array($status['reason'], ['ready', 'expired'], true))
                            <p class="mt-1 font-mono-fq text-[10px] text-fq-text-5 uppercase">
                                Last done by {{ $status['lastDone']->profile->name }}
                                {{ $status['lastDoneAt']->diffForHumans() }}
                                · {{ $status['lastDoneAt']->format('D j M, g:i A') }}
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

                    @unless ($status['available'])
                        <button
                            type="button"
                            wire:click="reopen({{ $chore->id }})"
                            class="rounded-[12px] border px-3 py-2 text-xs font-semibold text-fq-bg"
                            style="background: var(--fq-gold); border-color: var(--fq-gold)"
                        >{{ $chore->isOneTime() ? 'Put back on the board' : 'Make available now' }}</button>
                    @endunless

                    <button
                        type="button"
                        wire:click="toggleQuestEligible({{ $chore->id }})"
                        class="rounded-[12px] border px-3 py-2 text-xs {{ $chore->quest_eligible ? 'border-fq-line-3 bg-fq-sunk text-fq-text-3' : 'border-fq-coral bg-fq-sunk text-fq-coral' }}"
                    >
                        {{ $chore->quest_eligible ? 'Exclude from quest' : 'Allow as quest' }}
                    </button>

                    {{-- Which chip this browses under on the kids' board.

                         A dropdown, not a pill row: eleven options would be a
                         second line of buttons on every chore, and unlike the
                         cadence beside it there is no order to cycle through.
                         Deliberately not read off the icon — that is a card
                         face for a kid who can't read the name, and letting it
                         pick the category made choosing a nicer picture move
                         the chore to a different chip. --}}
                    <select
                        wire:change="setCategory({{ $chore->id }}, $event.target.value)"
                        title="Which chip this chore shows under when a kid browses the board"
                        class="rounded-[12px] border px-3 py-2 text-xs {{ $chore->category ? 'border-fq-line-3 bg-fq-sunk text-fq-text-3' : 'border-fq-line-3 bg-fq-sunk text-fq-text-5' }}"
                    >
                        <option value="" @selected($chore->category === null)>No category</option>
                        @foreach (ChoreCategory::cases() as $case)
                            <option value="{{ $case->value }}" @selected($chore->category === $case)>{{ $case->label() }}</option>
                        @endforeach
                    </select>

                    {{-- The one filter on the kid's board that can't be
                         derived from anything already stored. Cycles rather
                         than toggles so "nobody has said" stays reachable. --}}
                    <button
                        type="button"
                        wire:click="cycleEffort({{ $chore->id }})"
                        title="How hard is this job? Kids can browse for the hard ones."
                        class="flex items-center gap-2 rounded-[12px] border px-3 py-2 text-xs {{ $chore->effort === \App\Enums\ChoreEffort::Heavy ? 'border-fq-cyan bg-fq-sunk text-fq-cyan' : 'border-fq-line-3 bg-fq-sunk text-fq-text-3' }}"
                    >
                        <i class="fa-solid fa-dumbbell text-[11px]" aria-hidden="true"></i>
                        {{ $chore->effort?->label() ?? 'Effort not said' }}
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

                    @if ($pickingIconFor === $chore->id)
                        <div class="w-full rounded-[14px] border border-fq-line-2 bg-fq-sunk p-3">
                            <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                                Card face &middot; what the kids pick from
                            </p>

                            <div class="mt-2 grid grid-cols-8 gap-2">
                                @foreach (ChoreIcon::cases() as $option)
                                    <button
                                        type="button"
                                        wire:click="setIcon({{ $chore->id }}, '{{ $option->value }}')"
                                        title="{{ $option->label() }}"
                                        class="grid aspect-square place-items-center rounded-[12px] border transition hover:border-fq-lime"
                                        style="border-color: {{ $chore->icon === $option->faClass() ? 'var(--fq-gold)' : 'var(--fq-line-3)' }};
                                               background: var(--fq-panel);
                                               color: {{ $chore->icon === $option->faClass() ? 'var(--fq-gold)' : 'var(--fq-text-3)' }}"
                                    >
                                        <x-chore-icon :icon="$option" class="text-[20px]" />
                                    </button>
                                @endforeach
                            </div>

                            <p class="mt-2 font-mono-fq text-[10px] text-fq-text-5">
                                Tap the gold one again to clear it &mdash; the card falls back to showing its points.
                            </p>

                            {{-- The sixteen above are a shortlist. Every Font
                                 Awesome free icon works, and typing the class
                                 is the only way to reach the other two
                                 thousand — so the box is part of the picker
                                 rather than a setting somewhere else. --}}
                            <div class="mt-3 border-t border-fq-line-2 pt-3">
                                <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                                    Or type a Font Awesome class
                                </p>

                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    {{-- A live preview of what's in the box,
                                         because a class typed from memory is
                                         chosen blind — this is the only way to
                                         see you got fa-rocket and not nothing
                                         before it lands on a kid's card. --}}
                                    @php $customPreview = ChoreIcon::normalizeClass($customIcon[$chore->id] ?? null); @endphp
                                    <span
                                        class="grid h-[42px] w-[42px] shrink-0 place-items-center rounded-[12px] border"
                                        style="border-color: {{ $customPreview ? 'var(--fq-gold)' : 'var(--fq-line-3)' }};
                                               background: var(--fq-panel);
                                               color: {{ $customPreview ? 'var(--fq-gold)' : 'var(--fq-text-5)' }}"
                                    >
                                        @if ($customPreview)
                                            <x-chore-icon :icon="$customPreview" class="text-[22px]" />
                                        @else
                                            <span class="font-mono-fq text-[13px] leading-none">?</span>
                                        @endif
                                    </span>

                                    <input
                                        type="text"
                                        wire:model.live.debounce.400ms="customIcon.{{ $chore->id }}"
                                        wire:keydown.enter="setCustomIcon({{ $chore->id }})"
                                        placeholder="fa-solid fa-rocket"
                                        maxlength="120"
                                        autocapitalize="off"
                                        autocomplete="off"
                                        spellcheck="false"
                                        class="min-w-[180px] flex-1 rounded-[12px] border border-fq-line-2 px-3 py-2 text-sm outline-none focus:border-fq-gold"
                                        style="background: var(--fq-panel)"
                                    >

                                    <button
                                        type="button"
                                        wire:click="setCustomIcon({{ $chore->id }})"
                                        class="rounded-[12px] px-4 py-2 text-xs font-semibold text-fq-bg transition hover:brightness-110"
                                        style="background: var(--fq-gold)"
                                    >Use it</button>
                                </div>

                                @if (isset($customIconMessage[$chore->id]))
                                    <p class="mt-2 font-mono-fq text-[10px]" style="color: var(--fq-danger)">
                                        {{ $customIconMessage[$chore->id] }}
                                    </p>
                                @else
                                    <p class="mt-2 font-mono-fq text-[10px] text-fq-text-5">
                                        Any free icon from fontawesome.com/search &mdash; paste the whole
                                        &lt;i&gt; tag or just the name. Empty clears the face.
                                    </p>
                                @endif
                            </div>
                        </div>
                    @endif
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
                Every chore says whether it's up for grabs, who's holding it, and when it comes
                back. "Make available now" overrides that — the vacuuming is weekly right up
                until someone tips over a bag of chips. Whoever already did it keeps their points.
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
