{{-- One bonus item, beside the thing it acts on — drawn as the thing it is
     bought with.

     Replaces the flex-wrap row of three equal steel pills. What changed and
     why:

     1. The item has a body. One stub, so the control can sit next to a spin
        button or over the board without floating against it.
     2. One full-weight action, never two. Nothing held -> the whole stub buys
        one and its price end is the target. Holding one -> the plate's button
        is Use, and buying another is a quieter gold strip beneath it.
     3. `N held` stops being a button. It is a `×N in your pocket` note beside
        the name, which is where a kid reads it.
     4. What it does always shows. The state line (blocked, or the shortfall)
        is added under the description, not swapped in for it — "1 more ticket
        to buy one" is no reason to want the thing.
     5. The price is a number, not a 10px glyph pair: Baloo 24px in --fq-lime
        with fa-ticket beside it.

     `entry` is what the page's bonusItem() builds:
     ['effect' => PerkEffect, 'count' => int, 'blocked' => ?string,
      'perk' => ?BonusPerk, 'shortfall' => int]

     `perk` is the catalogue row and is null when a parent has switched the
     item off — which takes the price end and the buy strip with it, but leaves
     a held one spendable.

     The slot is the one-line "what it does", written by the page because only
     the page knows what the item is about to act on.

     `notch` is the colour behind the stub, punched into the perforation. The
     default is the page background; pass `notch="var(--fq-panel)"` inside the
     wheel's panel. --}}
@props(['entry', 'buyAction' => 'buyBonusItem', 'notch' => 'var(--fq-bg)'])

@php
    $effect = $entry['effect'];
    $defaults = $effect->defaults();
    $perk = $entry['perk'] ?? null;
    $count = (int) $entry['count'];
    $shortfall = (int) ($entry['shortfall'] ?? 0);
    $blocked = $count > 0 ? $entry['blocked'] : null;

    // Tickets in the pocket, where the caller knows them. Optional so the
    // control still renders for a caller that doesn't pass it — it only costs
    // the confirm its second line.
    $tickets = $entry['tickets'] ?? null;

    // The parent-editable name where there is one — a household that renamed
    // the charm must not be told to buy something by its factory name.
    $name = $perk?->name ?? $defaults['name'];
    $article = str_contains('aeiou', mb_strtolower(mb_substr($name, 0, 1))) ? 'an' : 'a';

    // The amber line under the description. A blocked Use outranks the price:
    // it is the answer to the button the kid is looking at.
    $note = match (true) {
        $blocked !== null => $blocked,
        $perk !== null && $shortfall > 0 => $shortfall.' more '.Str::plural('ticket', $shortfall).' to buy one',
        default => null,
    };

    // Live = anything the kid can act on right now. A dead stub drops out of
    // the metal entirely rather than sitting at opacity .4.
    $live = $count > 0 ? $blocked === null : ($perk !== null && $shortfall === 0);

    // Nothing held and nothing to sell — a parent has switched this item off.
    $nothingToOffer = $count === 0 && $perk === null;

    $plate = $live
        ? 'border-color: var(--fq-steel-edge); background: linear-gradient(180deg,#1b1e25,#101318)'
        : 'border-color: #23262d; background: var(--fq-steel-card-dim)';
@endphp

{{-- `asking` is the buy confirm. The whole stub is the buy target, which is
     the design — and the thing that turned out to be wrong about it: sitting
     above the price bands it reads like another band, so a kid tapping to
     filter spent a ticket instead. Nothing about that is obvious enough to fix
     with wording, so the tap asks now.

     Only buying asks. Using one is a labelled button doing what it says, and
     the tickets went days ago.

     x-show rather than <template x-if>: Livewire morphs this control on every
     board render, and x-if clones its contents in as a sibling of the template
     the server still thinks they are inside — so the two disagree about the
     DOM and a button added by a round trip repaints without its handler. The
     feelings card cost an afternoon to that. --}}
@unless ($nothingToOffer)
    <div class="flex w-full flex-col" x-data="{ asking: false }">
        {{-- The plate. Its right-hand end is the price when nothing is held,
             and the Use button when something is. --}}
        <div
            x-show="! asking"
            @class(['flex items-stretch overflow-hidden rounded-[15px] border', 'rounded-b-none border-b-0' => $count > 0 && $perk])
            style="{{ $plate }}"
        >
            {{-- Buying is the whole stub when nothing is held: a price with a
                 button beside it is two targets for one intention. --}}
            @if ($count === 0)
                <button
                    type="button"
                    x-on:click="asking = true"
                    @disabled($shortfall > 0)
                    title="{{ $perk->description }}"
                    class="flex min-w-0 flex-1 items-center gap-[11px] px-[13px] py-[12px] text-left transition enabled:hover:brightness-110"
                >
                    <span
                        class="grid h-[38px] w-[38px] shrink-0 place-items-center rounded-[12px] font-baloo text-base font-extrabold"
                        style="background: {{ $live ? 'var(--fq-steel-dim)' : '#16191f' }}; color: {{ $live ? 'var(--fq-steel-text)' : 'var(--fq-steel-dim-link)' }}"
                    >{{ $defaults['glyph'] }}</span>

                    <span class="flex min-w-0 flex-col gap-[3px]">
                        <span class="text-[15px] font-bold" style="color: {{ $live ? 'var(--fq-steel-name)' : 'var(--fq-steel-dim-label)' }}">{{ $name }}</span>

                        @if ($slot->isNotEmpty())
                            <span class="text-[12.5px] text-pretty" style="color: var(--fq-steel-label)">{{ $slot }}</span>
                        @endif

                        @if ($note)
                            <span class="font-mono-fq text-[11px]" style="color: var(--fq-gold)">{{ $note }}</span>
                        @endif
                    </span>
                </button>
            @else
                <span class="flex min-w-0 flex-1 items-center gap-[11px] px-[13px] py-[12px]">
                    <span
                        class="grid h-[38px] w-[38px] shrink-0 place-items-center rounded-[12px] font-baloo text-base font-extrabold"
                        style="
                            background: {{ $live ? 'var(--fq-chrome)' : '#16191f' }};
                            color: {{ $live ? 'var(--fq-ink-steel)' : 'var(--fq-steel-dim-link)' }};
                        "
                    >{{ $defaults['glyph'] }}</span>

                    <span class="flex min-w-0 flex-col gap-[3px]">
                        <span class="flex flex-wrap items-baseline gap-x-[6px]">
                            <span class="text-[15px] font-bold" style="color: {{ $live ? 'var(--fq-steel-name)' : 'var(--fq-steel-dim-label)' }}">{{ $name }}</span>
                            {{-- Held, in words and where a kid reads it: beside
                                 the name, not on a pill shaped like a button. --}}
                            <span class="font-mono-fq text-[11px]" style="color: {{ $live ? 'var(--fq-lime)' : 'var(--fq-steel-label)' }}">
                                &times;{{ $count }} in your pocket
                            </span>
                        </span>

                        @if ($slot->isNotEmpty())
                            <span class="text-[12.5px] text-pretty" style="color: var(--fq-steel-label)">{{ $slot }}</span>
                        @endif

                        @if ($note)
                            <span class="font-mono-fq text-[11px]" style="color: var(--fq-gold)">{{ $note }}</span>
                        @endif
                    </span>
                </span>

                <span class="grid shrink-0 place-items-center pr-[6px]">
                    @if ($blocked)
                        <span
                            class="rounded-[12px] border px-[16px] py-[10px] font-baloo text-sm font-extrabold"
                            style="border-color: var(--fq-steel-line); color: var(--fq-steel-dim-link)"
                        >Use one</span>
                    @else
                        <button
                            type="button"
                            wire:click="usePerk('{{ $effect->value }}')"
                            title="{{ $defaults['description'] }}"
                            class="rounded-[12px] px-[16px] py-[11px] font-baloo text-sm font-extrabold transition hover:brightness-110"
                            style="background: var(--fq-fill-steel); color: var(--fq-ink-steel)"
                        >Use one</button>
                    @endif
                </span>
            @endif

            {{-- The perforation, and the two holes punched through it. Only
                 drawn on the buy stub — the Use plate has no torn-off end. --}}
            @if ($count === 0)
                <span class="relative w-px shrink-0" style="background: repeating-linear-gradient({{ $live ? 'var(--fq-steel-line)' : '#23262d' }} 0 5px, transparent 5px 10px)">
                    <span class="absolute -top-[7px] -left-[6px] h-[13px] w-[13px] rounded-full" style="background: {{ $notch }}"></span>
                    <span class="absolute -bottom-[7px] -left-[6px] h-[13px] w-[13px] rounded-full" style="background: {{ $notch }}"></span>
                </span>

                {{-- The price end. On a stub they cannot afford yet it shows
                     what they have instead, so the gap is arithmetic a kid can
                     do rather than a number they have to go and look up. --}}
                <span
                    class="flex w-[92px] shrink-0 flex-col items-center justify-center gap-[1px]"
                    style="background: {{ $live ? 'var(--fq-ticket-bg)' : '#16191f' }}"
                >
                    <span class="flex items-baseline gap-[4px]" style="color: {{ $live ? 'var(--fq-lime)' : 'var(--fq-text-5)' }}">
                        <span class="font-baloo text-[24px] leading-none font-extrabold">{{ $perk->cost }}</span>
                        <i class="fa-solid fa-ticket text-[12px]"></i>
                    </span>
                    <span class="font-mono-fq text-[9px] tracking-[0.12em] uppercase" style="color: {{ $live ? 'var(--fq-ticket-label)' : 'var(--fq-steel-dim-link)' }}">
                        {{ $live ? 'Buy one' : ($perk->cost - $shortfall).' in hand' }}
                    </span>
                </span>
            @endif
        </div>

        {{-- Stocking up, quieter than using: a kid holding one should be able
             to buy a second without leaving the page, but that is never the
             tap the screen is asking for. --}}
        @if ($count > 0 && $perk)
            <button
                type="button"
                x-show="! asking"
                x-on:click="asking = true"
                @disabled($shortfall > 0)
                title="{{ $perk->description }}"
                class="flex items-center justify-between gap-2 rounded-[15px] rounded-t-none border px-[13px] py-2 text-left transition enabled:hover:brightness-115 disabled:opacity-60"
                style="border-color: {{ $live ? 'var(--fq-steel-edge)' : '#23262d' }}; background: var(--fq-ticket-bg)"
            >
                <span class="font-mono-fq text-[10.5px] tracking-[0.1em] uppercase" style="color: var(--fq-ticket-label)">
                    {{ $shortfall > 0 ? $shortfall.' more '.Str::plural('ticket', $shortfall).' for another' : 'Stock up — buy another' }}
                </span>
                <span class="flex items-baseline gap-[4px]" style="color: {{ $shortfall > 0 ? 'var(--fq-text-5)' : 'var(--fq-lime)' }}">
                    <span class="font-baloo text-base leading-none font-extrabold">{{ $perk->cost }}</span>
                    <i class="fa-solid fa-ticket text-[11px]"></i>
                </span>
            </button>
        @endif

        {{-- The question. Drawn in the stub's own metal so it reads as the
             same control having second thoughts, rather than as something new
             arriving over the top of it.

             Rendered whether or not there is anything to sell — a parent can
             switch the item off between paints, and an x-show that points at
             a branch the server dropped is the morph bug this page has been
             bitten by twice. When $perk is null nothing can open it: both
             triggers live inside branches that need a price. --}}
        @if ($perk)
            <div
                x-show="asking"
                x-cloak
                class="flex flex-col gap-[10px] rounded-[15px] border px-[14px] py-[12px]"
                style="border-color: var(--fq-steel-edge); background: linear-gradient(180deg,#1b1e25,#101318); animation: fq-pop .2s ease both"
                data-perk-confirm="{{ $effect->value }}"
            >
                <p class="font-baloo text-[16px] leading-tight font-extrabold" style="color: var(--fq-steel-name)">
                    Spend
                    <span style="color: var(--fq-lime)">{{ $perk->cost }} <i class="fa-solid fa-ticket text-[13px]"></i></span>
                    on {{ $count > 0 ? 'another' : $article }} {{ $name }}?
                </p>

                {{-- What the spend leaves behind. A price on its own is a
                     number to be talked out of; this is the one a kid can
                     weigh, and it is why `tickets` is passed at all.

                     Built in PHP rather than with directives inline: a `@if`
                     glued to the end of a word ("left@if") is not a directive
                     at all, and Blade prints it. --}}
                @if ($tickets !== null)
                    @php
                        $leftAfter = max(0, $tickets - $perk->cost);
                        $after = 'You’ll have '.$leftAfter.' '.Str::plural('ticket', $leftAfter).' left'
                            .($count > 0 ? ', and '.($count + 1).' in your pocket' : '').'.';
                    @endphp

                    <p class="text-[12.5px]" style="color: var(--fq-steel-label)">{{ $after }}</p>
                @endif

                <div class="flex flex-wrap gap-[8px]">
                    <button
                        type="button"
                        wire:click="{{ $buyAction }}('{{ $effect->value }}')"
                        {{-- Closed here as well as by the render that answers:
                             Alpine state survives a morph, so a confirm left
                             open would be sitting over the stub it just
                             bought from. --}}
                        x-on:click="asking = false"
                        class="rounded-[12px] px-[16px] py-[10px] font-baloo text-sm font-extrabold transition hover:brightness-110"
                        style="background: var(--fq-fill-steel); color: var(--fq-ink-steel)"
                    >Yes, buy it</button>

                    <button
                        type="button"
                        x-on:click="asking = false"
                        class="rounded-[12px] border px-[14px] py-[10px] font-baloo text-sm font-extrabold transition hover:brightness-125"
                        style="border-color: var(--fq-steel-line); color: var(--fq-steel-dim-label)"
                    >Not now</button>
                </div>
            </div>
        @endif
    </div>
@endunless
