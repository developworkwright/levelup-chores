{{-- One bonus item, beside the thing it acts on.

     The Bonus Shop is a separate tab, so a kid looking at the board has to
     already know the charm exists, already know they own one, and leave the
     page to find out what another costs. This says all three in place: how many
     are held, a button to spend one, and the ticket price of the next — the
     price stays on screen whether or not they are holding any, since "you have
     two" and "another is three tickets" are both things worth knowing at the
     moment the item is useful.

     `entry` is what the page's bonusItem() builds:
     ['effect' => PerkEffect, 'count' => int, 'blocked' => ?string,
      'perk' => ?BonusPerk, 'shortfall' => int]

     `perk` is the catalogue row and is null when a parent has switched the item
     off — which takes the price and the buy button with it, but leaves a held
     one spendable. The slot is the one-line "what it does", written by the page
     because only the page knows what the item is about to act on. --}}
@props(['entry', 'buyAction' => 'buyBonusItem'])

@php
    $effect = $entry['effect'];
    $defaults = $effect->defaults();
    $perk = $entry['perk'] ?? null;
    $count = (int) $entry['count'];
    $shortfall = (int) ($entry['shortfall'] ?? 0);

    // The parent-editable name where there is one — a household that renamed
    // the charm must not be told to buy something by its factory name.
    $name = $perk?->name ?? $defaults['name'];
    $article = str_contains('aeiou', mb_strtolower(mb_substr($name, 0, 1))) ? 'an' : 'a';

    // One line under the buttons, never two. A blocked Use outranks the price,
    // because it is the answer to the button the kid is looking at.
    $line = match (true) {
        $count > 0 && $entry['blocked'] !== null => $entry['blocked'],
        $perk !== null && $shortfall > 0 => $shortfall.' more '.Str::plural('ticket', $shortfall).' to buy one',
        default => null,
    };

    // Nothing held and nothing to sell — a parent has switched this item off.
    // The whole control goes rather than leaving its note stranded over an
    // empty row.
    $nothingToOffer = $count === 0 && $perk === null;
@endphp

@unless ($nothingToOffer)
    <div class="flex flex-col items-start gap-1">
        <div class="flex flex-wrap items-center gap-2">
            {{-- Held first, and said in words rather than as a bare multiplier
                 on the button: this is the half a kid is most often looking
                 for, and an item they own is worth nothing to them if they
                 don't know they own it. --}}
            @if ($count > 0)
                <span
                    class="inline-flex h-[42px] items-center rounded-[12px] border px-[12px] font-mono-fq text-[10px] tracking-[0.12em] whitespace-nowrap uppercase"
                    style="border-color: var(--fq-steel-edge); color: var(--fq-steel-text)"
                >{{ $count }} held</span>

                <x-perk-button :entry="$entry" />
            @endif

            @if ($perk)
                <button
                    type="button"
                    wire:click="{{ $buyAction }}('{{ $effect->value }}')"
                    @disabled($shortfall > 0)
                    title="{{ $perk->description }}"
                    class="inline-flex h-[42px] items-center gap-2 rounded-[12px] border px-[14px] text-xs font-semibold whitespace-nowrap transition hover:brightness-125 disabled:opacity-40"
                    style="border-color: var(--fq-steel-edge); color: var(--fq-steel-text); background: var(--fq-steel-panel)"
                >
                    <span class="font-baloo text-sm">{{ $perk->glyph }}</span>
                    <span>Buy {{ $count > 0 ? 'another' : $article.' '.$name }}</span>
                    <span class="font-mono-fq text-[10px]" style="color: {{ $shortfall > 0 ? 'var(--fq-text-5)' : 'var(--fq-lime)' }}">
                        {{ $perk->cost }}&#127903;
                    </span>
                </button>
            @endif
        </div>

        {{-- A disabled button with no reason on it is the thing the board
             messages exist to stop. --}}
        @if ($line)
            <span class="font-mono-fq text-[10px] text-fq-text-5">{{ $line }}</span>
        @elseif ($slot->isNotEmpty())
            <span class="font-mono-fq text-[10px] text-fq-text-5">{{ $slot }}</span>
        @endif
    </div>
@endunless
