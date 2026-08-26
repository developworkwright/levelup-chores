{{-- The main quest, from shut chest to cleared card.

     It is a component rather than a block on the Quests page because a kid can
     now reach it from two places: Home, where it is step one of the day, and
     Quests, where it still gates the board. Copying it would have been the
     worse half of that — the charm can only be cast on a shut chest, and a
     second copy that quietly left the charm buttons out would cost a kid the
     ticket they had already spent.

     Every action name here is a method on whichever Livewire component is
     hosting it: `dealHand`, `chooseQuest`, `claimQuest`, `buyQuestCharm` and
     `usePerk`. Both hosts implement all five.

     `questDoneOnArrival` is the host's *snapshot*, not live state: arriving
     with the quest already cleared collapses this to a line, while clearing it
     during the visit keeps the full card on screen so the moment still gets its
     celebration. --}}
@props([
    'profile',
    'household',
    'quest',
    'questDoneOnArrival',
    'questRevealed',
    'handDealt',
    'questCards',
    'questCharm',
    'questCharmed',
    'questCharmPayout',
    'questBoldBonus',
    'questPoints',
    'questClosesAt',
    'questDone',
    'questApproved',
    'questPending',
    'questSentBack',
    'questCardMessage' => null,
    'boost' => null,
    'questBoosted' => false,
    'charmForSale' => null,
    'heldPerks' => [],
])

@if ($questDoneOnArrival)
    {{-- Already cleared before this visit — shrink it to a line so
         hopping between tabs doesn't mean scrolling past a hero card
         for a quest that's finished. --}}
    <div
        wire:key="quest-cleared"
        class="flex items-center gap-3 rounded-[18px] border p-[14px]"
        style="background: var(--fq-wash-cleared); border-color: color-mix(in srgb, {{ $questPending ? 'var(--fq-gold)' : ($questApproved ? 'var(--fq-lime)' : 'var(--fq-danger)') }} 40%, transparent)"
    >
        @php
            // The strip has to draw the same distinctions the hero's
            // CTA does, or hopping tabs turns "waiting" into "done".
            [$stripLabel, $stripGlyph, $stripColor] = match (true) {
                $questPending => ['Waiting on parent', '⋯', 'var(--fq-gold)'],
                $questApproved => ['Cleared', '✓', 'var(--fq-lime)'],
                default => ['Sent back', '↺', 'var(--fq-danger)'],
            };
        @endphp

        <div
            class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-[12px] font-baloo text-lg font-extrabold text-fq-bg"
            style="background: {{ $stripColor }}"
        >{{ $stripGlyph }}</div>
        <div class="min-w-0 flex-1">
            <p class="font-mono-fq text-[10px] tracking-[0.2em] text-fq-text-4 uppercase">Today's Main Quest</p>
            <p class="truncate text-[15px] font-semibold">{{ $quest->chore->name }}</p>
        </div>
        <span
            class="font-mono-fq text-[11px] whitespace-nowrap"
            style="color: {{ $stripColor }}"
        >{{ $stripLabel }}</span>
    </div>
@else
    {{-- Sits above the chest and only while it is shut, because that is
         the only moment a charm can be cast — see
         PerkInventoryService::questCharmReason(). Once one is on, the
         button gives way to the mark, so a kid can't be left wondering
         whether the tickets took. --}}
    @php
        // A charm can only be cast on a shut chest, so everything in
        // this block is meaningless the instant one starts opening —
        // and the server doesn't find out until the animation ends.
        // See <x-chest>'s begin(), which announces it immediately.
        $charmable = ! $handDealt && ! $questCharmed;
        $holdsUsableCharm = isset($heldPerks['quest_charm']) && ! $heldPerks['quest_charm']['blocked'];
    @endphp

    @if ($charmable && isset($heldPerks['quest_charm']))
        <div
            x-data="{ opening: false }"
            x-on:chest-opening.window="opening = true"
            x-show="! opening"
            class="flex justify-end"
        >
            <x-perk-button :entry="$heldPerks['quest_charm']" />
        </div>
    @elseif ($charmable && $charmForSale)
        {{-- Sold from here rather than only from the shop because the
             window to use one closes the moment the chest opens — see
             buyQuestCharm(). Same brushed steel as the use button, so
             the two read as the same control at different stages. --}}
        @php
            $canAffordCharm = $profile->bonus_tickets >= $charmForSale->cost;
        @endphp

        <div
            x-data="{ opening: false }"
            x-on:chest-opening.window="opening = true"
            x-show="! opening"
            class="flex items-center justify-end gap-2"
        >
            <button
                type="button"
                wire:click="buyQuestCharm"
                @disabled(! $canAffordCharm)
                title="{{ $charmForSale->description }}"
                class="inline-flex h-[42px] items-center gap-2 rounded-[12px] border px-[14px] text-xs font-semibold whitespace-nowrap transition hover:brightness-125 disabled:opacity-40"
                style="border-color: var(--fq-steel-edge); color: var(--fq-steel-text); background: var(--fq-steel-panel)"
            >
                <span class="font-baloo text-sm">{{ $charmForSale->glyph }}</span>
                <span>Buy a {{ $charmForSale->name }}</span>
                <span class="font-mono-fq text-[10px]" style="color: {{ $canAffordCharm ? 'var(--fq-lime)' : 'var(--fq-text-5)' }}">
                    {{ $charmForSale->cost }}&#127903;
                </span>
            </button>

            {{-- A disabled button with no reason on it is the thing the
                 board messages exist to stop. --}}
            @if (! $canAffordCharm)
                <span class="font-mono-fq text-[10px] text-fq-text-5">
                    {{ $charmForSale->cost - $profile->bonus_tickets }} more
                </span>
            @endif
        </div>
    @elseif ($questCharmed && ! $questRevealed)
        <div class="flex items-center justify-end gap-2">
            <span class="font-baloo text-[15px]" style="color: var(--fq-violet)">✧</span>
            <span class="font-mono-fq text-[10px] tracking-[0.18em] uppercase" style="color: var(--fq-violet)">
                {{ $questCharm?->label() ?? 'Charmed · Open the chest to see' }}
            </span>
        </div>
    @endif

    {{-- The chest deals rather than reveals: it bursts open onto three
         cards and the kid takes one. Its own celebration is off,
         because "here are some cards" is not news — the pick is, and
         chooseQuest() fires that once it knows the pick landed.

         The id is a scroll target: the bonus chest's "do my quest
         first" button sends them here, and it has to land on something
         that exists whether the chest is shut, dealt or picked. --}}
    <div id="quest-hero">
    <x-chest
        wire-key="quest-chest"
        :revealed="$handDealt"
        open-action="dealHand"
        :celebrate-on-open="false"
        accent="var(--fq-lime)"
        kicker="Quest Chest · Open It"
        :closed-title="$questCards->count() > 1 ? 'Choose your quest' : 'Today\'s main quest is inside'"
        :closed-text="($questCards->count() > 1
            ? $questCards->count().' cards are inside. Take one, burn the rest.'
            : 'Worth +'.number_format($questPoints).' pts.')
            .($household->require_quest_first ? ' Side quests unlock the moment it\'s cleared.' : '')"
        opening-text="The chest is rattling..."
        cta="Open"
        {{-- Only when a charm is sitting unused. Buying one changes a
             button's label and nothing else, which is very easy to read
             as "bought it, so it's on" — this is the stop that catches
             that before the chest opens and the chance is gone. --}}
        :confirm="$charmable && $holdsUsableCharm"
    >
        @if ($charmable && $holdsUsableCharm)
            <x-slot:confirm-panel>
                <p class="mb-2 font-mono-fq text-[10px] tracking-[0.16em] uppercase" style="color: var(--fq-violet)">
                    ✧ You have a charm
                </p>
                <div class="flex flex-col gap-2 sm:flex-row">
                    {{-- Charms and opens in one tap. The perk call
                         finishes before begin() starts the 2.6s
                         animation, and dealHand() lands at the end of
                         it — so the effect is rolled on a chest that
                         was already charmed. --}}
                    <button
                        type="button"
                        @click="$wire.usePerk('quest_charm').then(() => begin())"
                        class="cursor-pointer rounded-[16px] px-[20px] py-[13px] font-baloo text-[16px] font-extrabold transition hover:brightness-110"
                        style="background: var(--fq-violet); color: var(--fq-bg)"
                    >Charm it first</button>

                    <button
                        type="button"
                        @click="begin()"
                        class="cursor-pointer rounded-[16px] border px-[20px] py-[13px] font-baloo text-[16px] font-bold transition hover:brightness-125"
                        style="border-color: var(--fq-line-3); color: var(--fq-text-3)"
                    >Open without it</button>
                </div>
            </x-slot:confirm-panel>
        @endif

    @if (! $questRevealed)
        <x-quest-cards
            :cards="$questCards"
            choose-action="chooseQuest"
            :charm="$questCharm"
            :message="$questCardMessage"
        />
    @else
        <div
            wire:key="hero"
            class="rounded-[24px] border p-5"
            style="animation: fq-pop .3s ease both; background: var(--fq-wash-gold); border-color: rgba(255,225,77,{{ $questDone ? '0.4' : '0.65' }})"
        >
            <p class="font-mono-fq text-[10px] tracking-[0.24em] text-fq-lime uppercase">Today's Main Quest</p>

            {{-- The face the card was picked by, carried onto the
                 quest it became. Without it the hand burns and the
                 picture the kid actually chose from is gone — which
                 for a pre-reader is the only part of the card they
                 were reading. Stays on a phone, unlike the hand's:
                 this is one full-width card, so the name isn't
                 competing with it for room. --}}
            <div class="mt-2 flex items-center gap-[14px]">
                @if ($quest->chore->icon)
                    <span
                        class="grid h-[54px] w-[54px] shrink-0 place-items-center rounded-full border"
                        style="border-color: var(--fq-line-4); background: var(--fq-panel-alt); color: var(--fq-gold)"
                    >
                        <x-chore-icon :icon="$quest->chore->icon" class="text-[27px]" />
                    </span>
                @endif
                <h2 class="font-baloo text-[26px] leading-[1.1] font-extrabold sm:text-[30px]">{{ $quest->chore->name }}</h2>
            </div>

            {{-- A deadline on the main quest is the sharpest version of
                 this: miss it and the quest rerolls, so the countdown
                 belongs right under the name where it can't be missed. --}}
            @if ($questClosesAt && ! $questDone)
                <x-chore-countdown wire:key="quest-closes" :closes-at="$questClosesAt" class="mt-2" />
            @endif

            <p class="mt-2 max-w-[420px] text-sm text-fq-text-2">
                @if ($questDone)
                    Quest cleared. Every side quest below is unlocked for today.
                @elseif ($questSentBack)
                    A parent sent this one back — finish it off and mark it done again.
                @else
                    Clear this one first — the side quests stay locked until it's done.
                @endif
            </p>

            <div class="mt-4 flex w-full flex-wrap items-center gap-3">
                @if ($questPending)
                    <button type="button" disabled class="cursor-default rounded-[16px] bg-fq-line-2 px-[22px] py-[14px] font-baloo text-[17px] font-bold text-fq-text-3">
                        Waiting on parent
                    </button>
                @elseif ($questApproved)
                    <button type="button" disabled class="cursor-default rounded-[16px] bg-fq-line-2 px-[22px] py-[14px] font-baloo text-[17px] font-bold text-fq-text-3">
                        Cleared &#10003;
                    </button>
                @else
                    {{-- Live even after a rejection: "do it again" is
                         the entire point of sending something back, so
                         the button has to work. The label carries the
                         bad news instead of a dead control. --}}
                    <button
                        type="button"
                        wire:click="claimQuest"
                        class="rounded-[16px] px-[22px] py-[14px] font-baloo text-[17px] font-bold transition hover:brightness-110"
                        style="background: var(--fq-fill-gold); color: var(--fq-ink); box-shadow: var(--fq-shadow-glow-sm) var(--fq-lime)"
                    >{{ $questSentBack ? 'Mark it done again' : 'Mark it done' }}</button>

                    @if ($questSentBack)
                        <span class="font-mono-fq text-xs" style="color: var(--fq-danger)">Sent back</span>
                    @endif
                @endif
                <span class="font-mono-fq text-xs" style="color: {{ $questBoosted ? ($boost->multiplier === 3 ? 'var(--fq-gold)' : 'var(--fq-magenta)') : 'var(--fq-lime)' }}">
                    +{{ $questPoints }} PTS
                </span>

                {{-- Says where the extra came from. Without it the
                     number on the hero doesn't match the chore's own
                     points anywhere else in the app, and the card that
                     explained it has burned. --}}
                @if ($questBoldBonus)
                    <span
                        class="rounded-full px-[7px] py-[2px] font-mono-fq text-[9px] leading-none tracking-[0.16em] uppercase"
                        style="background: var(--fq-gold); color: var(--fq-bg)"
                    >Bold +{{ number_format($questBoldBonus) }}</span>
                @endif

                {{-- Only ever present after the claim, which is what
                     rolls it. Its own chip rather than folded into the
                     bold one: they are two different bets. --}}
                @if ($questCharmPayout)
                    <span
                        class="rounded-full px-[7px] py-[2px] font-mono-fq text-[9px] leading-none tracking-[0.16em] uppercase"
                        style="background: var(--fq-violet); color: var(--fq-bg)"
                    >✧ Charm +{{ number_format($questCharmPayout) }}</span>
                @endif

                {{-- Pushed to the far edge — it's an escape hatch, not
                     the thing to reach for first. --}}
                @if (isset($heldPerks['quest_reroll']))
                    <span class="ml-auto">
                        <x-perk-button :entry="$heldPerks['quest_reroll']" />
                    </span>
                @endif
            </div>
        </div>
    @endif
    </x-chest>
    </div>
@endif

