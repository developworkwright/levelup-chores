{{-- The three cards the daily quest is chosen from.

     Sits inside <x-chest>'s revealed slot: the chest bursts, the cards flip
     over, the kid takes one and the other two burn. The chest used to reveal
     the quest outright, which made the best-animated moment on the page an
     announcement of something decided for them — a card they pick is a card
     they own, and picking is the only part of a chore board a kid actually
     gets to control.

     The suspense runs *before* the server call, the same way the chest does
     it: the burn plays for 1.4s locally and `chooseAction` fires at the end of
     it. Anything else and the hero card lands on top of two cards still
     alight. .fq-card-burn in app.css carries the matching 1.15s.

     The reveal card is dispatched by the server rather than from here, which
     is the difference between celebrating a tap and celebrating a pick that
     actually landed: a sibling can claim a chore in the second between the
     deal and the tap, and a card announcing a quest the kid didn't get would
     be immediately contradicted by the message underneath.

     Cards arrive cheapest-first and the last one is the bold card, which is
     the whole shape of the decision: it is always the most work and always
     pays a bonus for it, so the row reads left to right as "cheap and quick"
     through to "worth it if you're feeling brave". --}}
@props([
    'cards',
    'chooseAction',
    'charm' => null,
    'message' => null,
])

@php
    // The bold card is last by construction (the hand is sorted by points), so
    // the palette warms up along the row rather than being assigned per card.
    $accents = ['var(--fq-cyan)', 'var(--fq-lime)', 'var(--fq-gold)'];
@endphp

<div
    wire:key="quest-cards"
    x-data="{
        {{-- Null until a card is taken. Every card reads it to decide whether
             it is the one lifting clear or one of the two going up. --}}
        taken: null,
        async choose(id) {
            if (this.taken !== null) return;
            this.taken = id;
            await new Promise(resolve => setTimeout(resolve, 1400));
            await $wire.{{ $chooseAction }}(id);
        },
    }"
    class="rounded-[24px] border p-5"
    style="animation: fq-pop .3s ease both; background: var(--fq-wash-gold); border-color: rgba(255,225,77,0.65)"
>
    <p class="font-mono-fq text-[10px] tracking-[0.24em] text-fq-lime uppercase">Today's Main Quest</p>
    <h2 class="mt-2 font-baloo text-[24px] leading-[1.1] font-extrabold sm:text-[28px]">Pick your quest</h2>
    <p class="mt-2 max-w-[420px] text-sm text-fq-text-2">
        @if ($cards->count() > 1)
            Take one. The other {{ $cards->count() === 2 ? 'one burns' : 'two burn' }} — but they stay on the board below as side quests.
        @else
            Only one chore is going spare today, so this one's yours.
        @endif
    </p>

    {{-- What the charm did, said plainly over the hand it did it to. The
         Unchanged case says so rather than staying quiet: a kid who spent
         tickets and sees an ordinary hand needs telling the charm is still
         live, or the perk reads as broken. --}}
    @if ($charm)
        <p class="mt-2 inline-flex items-center gap-2 rounded-full px-[10px] py-[3px] font-mono-fq text-[10px] tracking-[0.16em] uppercase"
           style="background: color-mix(in srgb, var(--fq-violet) 18%, transparent); color: var(--fq-violet)">
            <span class="font-baloo text-[12px]">✧</span>{{ $charm->announcement() }}
        </p>
    @endif

    {{-- The perspective lives here rather than on the cards: a rotateY needs a
         parent with depth or it flattens into a horizontal squash. --}}
    <div class="mt-4 grid grid-cols-3 gap-2 sm:gap-3" style="perspective: 900px">
        @foreach ($cards as $i => $card)
            @php
                $chore = $card['chore'];
                $accent = $accents[$i % count($accents)];
                $blocked = $card['takenBy'] !== null || $card['expired'];
            @endphp

            <button
                type="button"
                wire:key="quest-card-{{ $chore->id }}"
                @if (! $blocked) @click="choose({{ $chore->id }})" @endif
                @disabled($blocked)
                class="fq-quest-card relative flex min-h-[150px] flex-col items-center overflow-hidden rounded-[18px] border-2 p-[10px] text-center transition sm:min-h-[168px] sm:p-3 {{ $blocked ? 'cursor-default opacity-45' : 'cursor-pointer hover:brightness-110' }}"
                style="
                    --fq-card-index: {{ $i }};
                    --fq-burn-tilt: {{ $i % 2 === 0 ? '-6deg' : '7deg' }};
                    background: var(--fq-panel);
                    border-color: {{ $blocked ? 'var(--fq-line)' : $accent }};
                    {{ $card['bold'] && ! $blocked ? "box-shadow: var(--fq-shadow-glow-sm) {$accent}" : '' }}
                "
                x-bind:class="taken === null ? '' : (taken === {{ $chore->id }} ? 'fq-card-chosen' : 'fq-card-burn')"
            >
                @if ($card['bold'])
                    @php
                        // Read off the card rather than passed in: a charm can
                        // double the rate, and a chip saying 50% over a card
                        // paying 100% is the one thing worse than no chip.
                        $bonusPercent = (int) round($card['bonus'] / max(1, $card['points']) * 100);
                    @endphp
                    <span
                        class="mb-[6px] rounded-full px-[7px] py-[2px] font-mono-fq text-[8px] leading-none tracking-[0.16em] uppercase"
                        style="background: {{ $accent }}; color: var(--fq-bg)"
                    >Bold +{{ $bonusPercent }}%</span>
                @else
                    {{-- Holds the same vertical space, so the three names start
                         on one line and the row reads as a set. --}}
                    <span class="mb-[6px] h-[13px]"></span>
                @endif

                <span class="line-clamp-3 text-[12.5px] leading-[1.25] font-semibold text-fq-text sm:text-[13.5px] {{ $card['takenBy'] ? 'line-through' : '' }}">
                    {{ $chore->name }}
                </span>

                <span class="mt-auto pt-2 font-baloo text-[19px] leading-none font-extrabold sm:text-[22px]" style="color: {{ $blocked ? 'var(--fq-text-4)' : $accent }}">
                    {{ number_format($card['points'] + $card['bonus']) }}
                </span>
                <span class="font-mono-fq text-[8.5px] tracking-[0.16em] text-fq-text-4 uppercase">
                    @if ($card['bonus'])
                        {{ number_format($card['points']) }} + {{ number_format($card['bonus']) }} pts
                    @else
                        pts
                    @endif
                </span>

                @if ($card['takenBy'])
                    <span class="mt-[6px] font-mono-fq text-[8.5px] leading-tight text-fq-text-4">Taken by {{ $card['takenBy']->name }}</span>
                @elseif ($card['expired'])
                    <span class="mt-[6px] font-mono-fq text-[8.5px] leading-tight" style="color: var(--fq-danger)">Time's up</span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- Why a tap didn't take. Same reasoning as the board's message: a
         sibling can claim a card between the deal and the tap, and a button
         that silently does nothing explains none of it. --}}
    @if ($message)
        <p class="mt-3 font-mono-fq text-[11px]" style="color: var(--fq-danger)">{{ $message }}</p>
    @endif
</div>
