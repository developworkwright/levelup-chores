{{-- One reward on the shelf.

     Pulled out of the Loot Shop page because the catalogue is now drawn three
     times over — starred, new, and the shelves themselves — and a card copied
     three ways is three cards that drift.

     **Led by a picture, not a paragraph.** This was a colour bar, a name, a
     description, a button and a price: five lines of text per card, times
     thirty cards, which is why the kids stopped shopping and never found
     anything new. The icon carries the identity now and the description is
     demoted to a single clamped line — it is the detail you read once you have
     already spotted the thing, not the thing itself.

     Locked rewards are dimmed, never hidden. A locked reward a kid can see is
     the reason to climb; one filtered off the shelf is nothing at all. --}}
@props([
    'item',
    'profile',
    'saving' => null,
    'isNew' => false,
    'favorite' => false,
    'boughtCount' => 0,
])

@php
    $locked = $item->isLockedFor($profile);
    $affordable = $profile->points >= $item->cost;
    $isGoal = $saving && $saving->id === $item->id;
    $lockRank = $locked ? App\Enums\Rank::fromLevel($item->min_level) : null;

    // The icon takes the lock's colour when locked, so a card a kid can't have
    // yet reads as one thing rather than a gold picture over a grey badge.
    $faceColor = $locked
        ? $lockRank->ringVar()
        : ($item->category?->colorVar() ?? $item->color_tag->cssVar());
@endphp

<div
    @class([
        'relative flex flex-col gap-3 rounded-[20px] border bg-fq-panel p-4',
        'opacity-70' => $locked,
    ])
    style="border-color: {{ $locked ? $lockRank->ringVar() : ($affordable ? 'var(--fq-line-focus)' : 'var(--fq-line)') }}"
>
    {{-- Top-right, out of the way of the face. NEW outranks the star: a kid
         who has starred something already knows about it. --}}
    @if ($isNew)
        <span
            class="absolute top-[10px] right-[10px] rounded-full px-[7px] py-[2px] font-mono-fq text-[8px] leading-none font-semibold tracking-[0.16em] uppercase"
            style="background: var(--fq-lime); color: var(--fq-bg)"
        >New</span>
    @elseif ($boughtCount > 1)
        <span
            class="absolute top-[10px] right-[10px] rounded-full px-[7px] py-[2px] font-mono-fq text-[8px] leading-none tracking-[0.12em] uppercase"
            style="background: var(--fq-panel-alt); color: var(--fq-text-4)"
        >&times;{{ $boughtCount }}</span>
    @endif

    <div class="flex items-start gap-3">
        {{-- The face. Falls back to the colour tag as a plain disc when a
             reward has no icon — a blank square would read as a broken
             picture, which is worse than an honest blob of colour. --}}
        <span
            class="grid h-[52px] w-[52px] flex-none place-items-center rounded-[16px] border"
            style="border-color: var(--fq-line-2); background: var(--fq-sunk); color: {{ $faceColor }}"
        >
            @if ($item->icon)
                <x-chore-icon :icon="$item->icon" class="text-[24px]" />
            @else
                <span class="h-[14px] w-[14px] rounded-full" style="background: {{ $faceColor }}"></span>
            @endif
        </span>

        <div class="min-w-0 flex-1">
            <p class="text-[16px] leading-tight font-semibold">{{ $item->name }}</p>
            <p class="mt-1 line-clamp-2 text-[12.5px] leading-[1.35] text-fq-text-4">{{ $item->description }}</p>

            {{-- Only when there is something to point at. A card that grows an
                 empty "have a look" button is worse than one with no link.

                 New tab with noopener: this leaves the app for a page nobody
                 here controls, and the tab that opened it must not be
                 reachable from the other side. --}}
            @if ($item->url)
                <a
                    href="{{ $item->url }}"
                    target="_blank"
                    rel="noopener noreferrer nofollow"
                    class="mt-[6px] inline-flex items-center gap-[6px] font-mono-fq text-[10px] tracking-[0.1em] uppercase transition hover:brightness-125"
                    style="color: var(--fq-cyan)"
                >
                    <i aria-hidden="true" class="fa-fw fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                    Have a look
                </a>
            @endif
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        @if ($locked)
            <span
                class="rounded-full border bg-fq-sunk px-[11px] py-[5px] font-mono-fq text-[10px] tracking-[0.1em] uppercase"
                style="border-color: {{ $lockRank->ringVar() }}; color: {{ $lockRank->ringVar() }}"
            >Unlocks at LVL {{ $item->min_level }}</span>
        @else
            <button
                type="button"
                wire:click="saveFor({{ $item->id }})"
                class="rounded-full border border-fq-line bg-fq-sunk px-[11px] py-[5px] font-mono-fq text-[10px] tracking-[0.1em] uppercase transition hover:border-fq-line-4"
                style="color: {{ $isGoal ? 'var(--fq-gold)' : 'var(--fq-text-4)' }}"
            >{{ $isGoal ? 'Saving for this' : 'Save for this' }}</button>
        @endif

        {{-- Starring works on a locked reward on purpose: wanting the thing you
             can't have yet is the entire point of showing it. --}}
        <button
            type="button"
            wire:click="toggleFavorite({{ $item->id }})"
            aria-label="{{ $favorite ? 'Remove '.$item->name.' from favorites' : 'Add '.$item->name.' to favorites' }}"
            class="ml-auto grid h-[30px] w-[30px] place-items-center rounded-full border transition hover:brightness-125"
            style="border-color: {{ $favorite ? 'var(--fq-gold)' : 'var(--fq-line-3)' }};
                   background: var(--fq-sunk);
                   color: {{ $favorite ? 'var(--fq-gold)' : 'var(--fq-text-5)' }}"
        >
            <i aria-hidden="true" class="fa-fw {{ $favorite ? 'fa-solid' : 'fa-regular' }} fa-star text-[13px]"></i>
        </button>
    </div>

    <div class="mt-auto flex items-center justify-between gap-2">
        <span class="font-baloo text-[19px] font-extrabold text-fq-gold">{{ $item->cost }} pts</span>

        @if ($locked)
            <button type="button" disabled class="cursor-default rounded-[13px] bg-fq-panel-alt px-4 py-[10px] text-[13px] font-semibold text-fq-text-4">
                {{ $lockRank->label() }}
            </button>
        @elseif ($affordable)
            <button
                type="button"
                wire:click="redeem({{ $item->id }})"
                class="rounded-[13px] px-4 py-[10px] text-[13px] font-semibold text-fq-bg transition hover:brightness-110"
                style="background:var(--fq-cyan)"
            >Cash out</button>
        @else
            <button type="button" disabled class="cursor-default rounded-[13px] bg-fq-panel-alt px-4 py-[10px] text-[13px] font-semibold text-fq-text-4">
                Need {{ $item->cost - $profile->points }}
            </button>
        @endif
    </div>
</div>
