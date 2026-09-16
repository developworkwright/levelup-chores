{{-- The gratitude quest: three things, once a day, for tickets.

     Lives behind the Gratitude row of Home's "Your day". It was a card on
     Quests, which is the board, and it was the one thing there that is not
     work — nothing for a parent to approve, so the tickets land on hand-in.

     Drawn inside the page component rather than as one of its own, so the
     wire:model bindings below are the page's `gratitude` and
     `gratitudeShared` and the button calls the page's logGratitude(). --}}
@props(['today' => null, 'message' => null])

<div
    wire:key="gratitude-quest"
    id="gratitude"
    class="scroll-mt-4 rounded-[20px] border p-4"
    style="background: var(--fq-wash-cleared); border-color: color-mix(in srgb, var(--fq-magenta) 40%, transparent)"
>
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: var(--fq-magenta)">Gratitude Quest</p>
        <span class="inline-flex items-center gap-2 whitespace-nowrap">
            <span
                class="rounded-full border border-fq-ticket-line px-[10px] py-1 font-mono-fq text-[10px] text-fq-lime"
                style="background: var(--fq-ticket-bg)"
            >+{{ \App\Services\GratitudeService::TICKETS }} TICKETS</span>
            <span class="font-mono-fq text-[10px] text-fq-text-4 uppercase">{{ $today ? 'Done today' : 'Not done today' }}</span>
        </span>
    </div>

    <h2 class="mt-[6px] font-baloo text-xl font-bold">Today you were grateful for&hellip;</h2>

    @if ($today)
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ($today->items as $index => $item)
                <div class="min-w-[150px] flex-1 rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[13px] py-[11px]">
                    <span class="font-baloo text-[13px] font-extrabold" style="color: var(--fq-magenta)">{{ $index + 1 }}</span>
                    <span class="ml-2 text-sm text-fq-text-2">{{ $item }}</span>
                </div>
            @endforeach
        </div>

        <p class="mt-3 text-[13px] text-fq-text-5">
            {{ $today->shared
                ? 'The house can read this one on the Family page.'
                : 'You kept this one to yourself.' }}
            A new one opens up tomorrow. Everything you've written is kept in your
            <a href="{{ route('kid.journal') }}" wire:navigate class="font-semibold underline" style="color: var(--fq-magenta)">Journal</a>.
        </p>
    @else
        @php $slotHints = ['1 · something', '2 · someone', '3 · anything']; @endphp

        <div class="mt-3 flex flex-wrap gap-2">
            @foreach (range(0, \App\Services\GratitudeService::ITEMS - 1) as $index)
                <input
                    type="text"
                    wire:model="gratitude.{{ $index }}"
                    wire:keydown.enter="logGratitude"
                    maxlength="{{ \App\Services\GratitudeService::MAX_LENGTH }}"
                    placeholder="{{ $slotHints[$index] ?? 'Something good…' }}"
                    aria-label="Grateful for, number {{ $index + 1 }}"
                    class="min-w-[150px] flex-1 rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[13px] py-[11px] text-sm outline-none focus:border-fq-magenta"
                >
            @endforeach
        </div>

        {{-- The opt-out. In front of them at the moment they are
             deciding, because a kid who has to go and find a setting to
             be private will never be private — the same reasoning the
             FeelingVisibility docblock gives, pointed the other way:
             this one is shared unless you say otherwise, since a
             grateful line is almost always about somebody in this house
             and its whole value is that they hear it. --}}
        <label class="mt-3 flex min-h-[44px] cursor-pointer items-center gap-[10px] rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[13px] py-2">
            <input
                type="checkbox"
                wire:model="gratitudeShared"
                class="size-[18px] shrink-0 accent-fq-magenta"
            >
            <span class="text-[13px] text-fq-text-3">
                Let the house read this one
                <span class="text-fq-text-5">— it shows up on the Family page</span>
            </span>
        </label>

        <button
            type="button"
            wire:click="logGratitude"
            wire:loading.attr="disabled"
            wire:target="logGratitude"
            class="mt-3 rounded-[14px] px-5 py-[11px] font-baloo text-[15px] font-bold transition hover:brightness-110 disabled:opacity-60"
            style="background: var(--fq-magenta); color: var(--fq-ink)"
        >Hand it in</button>
    @endif

    @if ($message)
        <p class="mt-3 text-[13px]" style="color: var(--fq-gold)">{{ $message }}</p>
    @endif
</div>
