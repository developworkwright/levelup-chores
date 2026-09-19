{{-- The prize counter — 3a in handoff/design_handoff_arcade_tokens, which
     replaced the tabbed grid of 2c.

     A place rather than a shop: somebody behind the glass, prizes standing on
     lit shelves with a token tag under each, so a six-year-old reads the whole
     case without reading a word. Green tags are yours, yellow you can afford,
     dim ones you can't yet. Tap one and the keeper names the price and how far
     off you are, and the buy is a second tap on the tray — never a hidden form.
     Tickets are the little window at the counter's end; sweets are the top
     shelf's opposite, the one a grown-up gets down.

     The keeper is the design's stand-in, drawn in shapes and shipped as drawn.

     Every prize hands the tray its whole self in the tap that picks it — see
     resources/js/arcade-counter.js for why it is never a table in x-data. --}}
@props([
    'meter',
    'owned',
    'gear',
    'candies',
    'waiting' => 0,
    'ticketPrice' => 10,
    'note' => null,
])

@php
    $bank = $meter['balance'];

    $petItem = function (\App\Enums\PrizeSlot $slot, array $item) use ($owned, $gear, $bank): array {
        return [
            'id' => $slot->value.':'.$item['key'],
            'kind' => $slot->value,
            'key' => $item['key'],
            'name' => $item['name'],
            'cost' => $item['cost'],
            'blurb' => $slot->blurb(),
            'owned' => in_array($item['key'], $owned[$slot->value] ?? [], true),
            'out' => ($gear[$slot->value] ?? null) === $item['key'],
            'soldOut' => false,
            'bank' => $bank,
            'size' => $slot->trueSize(),
        ];
    };

    $candyItem = fn (\App\Models\Candy $candy): array => [
        'id' => 'candy:'.$candy->id,
        'kind' => 'candy',
        'key' => (string) $candy->hue,
        'candyId' => $candy->id,
        'name' => $candy->name,
        'cost' => $candy->tokens,
        'blurb' => 'Real sweets. Buy it here and a grown-up brings it to you — it sits in their queue until they do.',
        'owned' => false,
        'out' => false,
        'soldOut' => ! $candy->isInStock(),
        'bank' => $bank,
        'size' => 0,
    ];

    $ticket = [
        'id' => 'ticket',
        'kind' => 'ticket',
        'key' => 'token',
        'name' => 'One ticket',
        'cost' => $ticketPrice,
        'blurb' => 'Tickets buy frames, avatars, pets and surprise eggs in the Locker. Always '.$ticketPrice.' for 1 — the machine never haggles.',
        'owned' => false,
        'out' => false,
        'soldOut' => false,
        'bank' => $bank,
        'size' => 0,
    ];

    $shelves = collect(\App\Enums\PrizeSlot::cases())
        ->map(fn ($slot) => ['label' => strtoupper($slot->shelf()), 'items' => array_map(fn ($item) => $petItem($slot, $item), $slot->items())])
        ->push(['label' => 'SWEETS · A GROWN-UP BRINGS THESE', 'items' => $candies->map($candyItem)->all()]);
@endphp

<div x-data="fqCounter" class="relative flex flex-col overflow-hidden rounded-[24px] border border-fq-line bg-fq-bg">
    {{-- The scene: the sign, the keeper, the ticket window and the counter top. --}}
    <div class="relative h-[236px] overflow-hidden" style="background: radial-gradient(ellipse at 50% 100%, var(--fq-line), var(--fq-bg) 70%)">
        <div class="fq-flicker absolute inset-x-0 top-[10px] text-center font-baloo text-[30px] font-extrabold tracking-[0.12em] text-fq-coral" style="text-shadow: 0 0 8px #ff8ac7, 0 0 26px #e0365b, 0 0 2px #fff">PRIZES</div>
        <div class="absolute inset-x-0 top-[54px] h-px" style="background: linear-gradient(90deg, transparent, var(--fq-line-4), transparent)"></div>

        {{-- The keeper. Shapes, as drawn. --}}
        <div aria-hidden="true" class="absolute bottom-[30px] left-1/2 h-[150px] w-[160px] -translate-x-1/2">
            <div class="absolute right-[14px] bottom-0 left-[14px] h-[62px]" style="border-radius: 44px 44px 6px 6px; background: linear-gradient(180deg, #4a2f7a, #1b0f30); border: 2px solid #6a3fb0; border-bottom: none"></div>
            <div class="absolute bottom-0 left-1/2 h-[58px] w-[34px] -translate-x-1/2" style="background: linear-gradient(180deg, #f7f0ff, #c9bfe0); clip-path: polygon(0 0, 100% 0, 72% 100%, 28% 100%)"></div>
            <div class="absolute bottom-[40px] left-1/2 h-[12px] w-[12px] -translate-x-1/2 rotate-45" style="background: #e0365b; border: 2px solid #1b0f30"></div>
            <div class="absolute bottom-[6px] left-1/2 h-[10px] w-[10px] -translate-x-1/2 rounded-full" style="background: #ffe14d; border: 2px solid #1b0f30"></div>
            <div class="absolute bottom-[22px] left-1/2 h-[10px] w-[10px] -translate-x-1/2 rounded-full" style="background: #ffe14d; border: 2px solid #1b0f30"></div>
            {{-- Head and face, then the top hat over them — turn 4 of the design
                 redrew both so the brim sits down on the head, wider than the
                 crown, drawn last so it reads in front. --}}
            <div class="absolute left-1/2 h-[66px] w-[68px] -translate-x-1/2" style="top: 36px; border-radius: 46% 46% 48% 48%; background: linear-gradient(180deg, #e8d4c4, #c9a58c); border: 3px solid #1b0f30"></div>
            <div class="absolute h-[10px] w-[9px] rounded-full" style="top: 62px; left: calc(50% - 16px); background: #f7f0ff; border: 2px solid #1b0f30"></div>
            <div class="absolute h-[10px] w-[9px] rounded-full" style="top: 62px; left: calc(50% + 7px); background: #f7f0ff; border: 2px solid #1b0f30"></div>
            <div class="fq-blink absolute h-[5px] w-[5px] rounded-full" style="top: 65px; left: calc(50% - 13px); background: #1b0f30"></div>
            <div class="fq-blink absolute h-[5px] w-[5px] rounded-full" style="top: 65px; left: calc(50% + 10px); background: #1b0f30"></div>
            <div class="absolute h-[4px] w-[14px] rounded-[2px]" style="top: 56px; left: calc(50% - 20px); background: #1b0f30; transform: rotate(-14deg)"></div>
            <div class="absolute h-[4px] w-[14px] rounded-[2px]" style="top: 56px; left: calc(50% + 6px); background: #1b0f30; transform: rotate(14deg)"></div>
            <div class="absolute h-[12px] w-[26px]" style="top: 80px; left: calc(50% - 26px); border-radius: 12px 12px 2px 12px; background: #1b0f30; transform: rotate(-10deg)"></div>
            <div class="absolute left-1/2 h-[12px] w-[26px]" style="top: 80px; border-radius: 12px 12px 12px 2px; background: #1b0f30; transform: rotate(10deg)"></div>
            <div class="absolute h-[8px] w-[8px] rounded-full" style="top: 84px; left: calc(50% - 4px); background: #1b0f30"></div>
            <div class="absolute h-[6px] w-[22px]" style="top: 95px; left: calc(50% - 11px); border-radius: 0 0 11px 11px; background: #1b0f30"></div>
            <div class="absolute top-0 left-1/2 h-[46px] w-[72px] -translate-x-1/2" style="border-radius: 8px 8px 3px 3px; background: linear-gradient(180deg, #1b0f30, #0a0512); border: 3px solid #1b0f30"></div>
            <div class="absolute left-1/2 h-[9px] w-[66px] -translate-x-1/2" style="top: 32px; background: #e0365b"></div>
            <div class="absolute h-[7px] w-[7px] rounded-full" style="top: 33px; left: calc(50% + 16px); background: #ffe14d; box-shadow: 0 0 6px #ffe14d"></div>
            <div class="absolute left-1/2 h-[13px] w-[100px] -translate-x-1/2 rounded-[7px]" style="top: 41px; background: linear-gradient(180deg, #2e1b4d, #0a0512); border: 3px solid #1b0f30"></div>
            <div class="absolute -bottom-[8px] -left-[6px] h-[20px] w-[56px] rounded-[10px]" style="background: linear-gradient(180deg, #4a2f7a, #2e1b4d); border: 2px solid #6a3fb0"></div>
            <div class="absolute -right-[6px] -bottom-[8px] h-[20px] w-[56px] rounded-[10px]" style="background: linear-gradient(180deg, #4a2f7a, #2e1b4d); border: 2px solid #6a3fb0"></div>
        </div>
        <div aria-hidden="true" class="absolute bottom-[36px] z-[2] h-[18px] w-[26px] rounded-[9px]" style="left: calc(50% - 88px); background: #e8d4c4; border: 2px solid #1b0f30"></div>
        <div aria-hidden="true" class="absolute bottom-[36px] z-[2] h-[18px] w-[26px] rounded-[9px]" style="left: calc(50% + 62px); background: #e8d4c4; border: 2px solid #1b0f30"></div>

        {{-- What the keeper says. The idle line is server-rendered so it always
             carries the balance as it is now; the rest is the tray's. --}}
        <div class="absolute top-[100px] left-[14px] max-w-[42%] rounded-[16px_16px_16px_4px] bg-fq-text px-[12px] py-[9px] font-baloo text-[15px] leading-[1.15] font-extrabold text-pretty text-[#1b0f30] shadow-[0_6px_20px_rgba(0,0,0,.4)]">
            <span x-show="! sel">{{ $note ?? $bank.' tokens. What\'ll it be?' }}</span>
            <span x-show="sel" x-cloak x-text="line"></span>
        </div>

        <button
            type="button"
            x-on:click="pick(@js($ticket))"
            class="absolute top-[104px] right-[14px] flex w-[96px] flex-col items-center gap-[4px] rounded-[12px] border-2 bg-fq-panel px-[9px] py-[8px]"
            :class="sel && sel.id === 'ticket' ? 'border-fq-lime' : 'border-fq-line-3'"
        >
            <i class="fa-solid fa-ticket text-[18px] text-fq-magenta"></i>
            <span class="font-mono-fq text-[8px] tracking-[0.14em] text-fq-text-4">TICKETS</span>
            <span class="rounded-[7px] bg-fq-lime px-[9px] py-[2px] font-baloo text-[13px] font-extrabold text-fq-ink">{{ $ticketPrice }} each</span>
        </button>

        {{-- The counter top, in wood, with the brass rail. --}}
        <div class="absolute inset-x-0 bottom-0 h-[44px]" style="background: repeating-linear-gradient(90deg, #3b1d1a 0 26px, #4a2620 26px 52px), #3b1d1a; border-top: 5px solid #ffc93d; box-shadow: 0 -6px 18px rgba(0,0,0,.55), inset 0 2px 0 #7a4a3c"></div>
        <div class="absolute inset-x-0 bottom-[44px] h-[3px]" style="background: linear-gradient(90deg, #7a6a12, #fff6b0 50%, #7a6a12)"></div>
        <div class="absolute inset-x-0 bottom-[40px] h-px bg-white/20"></div>
        <div class="absolute bottom-[9px] left-[12px] flex items-center gap-[6px] rounded-[8px] border border-fq-ticket-line bg-fq-panel-alt py-[3px] pr-[9px] pl-[5px] font-mono-fq text-[8.5px] leading-none tracking-[0.1em] whitespace-nowrap text-[#e8ddbd]">
            <fq-prize kind="token" class="h-[15px] w-[15px] shrink-0"></fq-prize>
            YOU HAVE <span class="font-baloo text-[14px] font-extrabold tracking-normal text-fq-lime">{{ $bank }}</span>
        </div>
        <div class="absolute right-[12px] bottom-[9px] rounded-[8px] border border-fq-line-3 bg-fq-panel-alt px-[9px] py-[6px] font-mono-fq text-[8.5px] leading-none tracking-[0.1em] whitespace-nowrap text-fq-text-3">
            {{ $waiting }} {{ Str::plural('SWEET', $waiting) }} WAITING
        </div>
    </div>

    {{-- The glass shelves. --}}
    <div class="flex flex-col gap-[14px] px-[13px] pt-[14px] pb-[18px]" style="background: linear-gradient(180deg, var(--fq-bg), var(--fq-panel))">
        @foreach ($shelves as $shelf)
            <div class="flex flex-col">
                <span class="mb-[6px] font-mono-fq text-[8.5px] tracking-[0.16em] text-fq-text-5">{{ $shelf['label'] }}</span>

                @if ($shelf['items'] === [])
                    <p class="pb-[8px] text-[12px] text-fq-text-5">Nothing on this shelf yet &mdash; ask a grown-up to stock it.</p>
                @else
                    {{-- Wraps, in fixed slots: with the spooky half added a shelf holds up to
                         twelve, and squeezing twelve into one row made every prize a speck. --}}
                    <div class="flex flex-wrap items-end gap-x-0 gap-y-[2px] px-[2px] pt-[6px]" style="background: linear-gradient(180deg, transparent 60%, rgba(125, 255, 176, .05))">
                        @foreach ($shelf['items'] as $item)
                            @php
                                $afford = $bank >= $item['cost'];
                                $mine = $item['owned'] || $item['out'];
                            @endphp
                            <button
                                type="button"
                                wire:key="shelf-{{ $item['id'] }}"
                                x-on:click="pick(@js($item))"
                                aria-label="{{ $item['name'] }}, {{ $item['cost'] }} tokens"
                                class="flex w-[54px] min-w-0 shrink-0 grow-0 flex-col items-center gap-[3px]"
                                style="opacity: {{ $afford || $mine ? 1 : 0.62 }}"
                            >
                                <span
                                    class="relative block h-[46px] w-[46px] rounded-[10px] drop-shadow-[0_4px_6px_rgba(0,0,0,.5)]"
                                    :style="sel && sel.id === @js($item['id']) ? 'box-shadow: 0 0 0 2px var(--fq-lime)' : ''"
                                >
                                    <fq-prize kind="{{ $item['kind'] }}" key="{{ $item['key'] }}" class="absolute inset-0"></fq-prize>
                                </span>
                                <span
                                    @class([
                                        'rounded-[6px] px-[7px] py-[3px] font-baloo text-[12px] leading-none font-extrabold whitespace-nowrap',
                                        'bg-fq-green text-[#05170c]' => $mine,
                                        'bg-fq-lime text-fq-ink' => ! $mine && $afford && ! $item['soldOut'],
                                        'bg-fq-line text-fq-text-3' => ! $mine && (! $afford || $item['soldOut']),
                                    ])
                                >{{ $item['out'] ? 'OUT' : ($item['owned'] ? 'YOURS' : ($item['soldOut'] ? 'GONE' : $item['cost'])) }}</span>
                            </button>
                        @endforeach
                    </div>
                    <div class="h-[5px] rounded-[2px] opacity-80" style="background: linear-gradient(90deg, var(--fq-line-2), var(--fq-green) 50%, var(--fq-line-2)); box-shadow: 0 3px 12px rgba(125, 255, 176, .25)"></div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- The tray: slides up over the page with whatever was picked. Fixed to
         the window rather than the counter, because on a phone the counter is
         taller than the screen and a tray at its foot would be off the bottom. --}}
    <div
        x-show="sel"
        x-cloak
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="translate-y-6 opacity-0"
        x-transition:enter-end="translate-y-0 opacity-100"
        x-on:keydown.escape.window="close()"
        class="fixed inset-x-0 bottom-0 z-50 mx-auto flex max-w-[460px] flex-col gap-[12px] rounded-t-[22px] border-t border-[#7a5ab8] px-[14px] pt-[14px] pb-[max(16px,env(safe-area-inset-bottom))] shadow-[0_-14px_40px_rgba(0,0,0,.6)]"
        style="background: linear-gradient(180deg, var(--fq-panel-alt), var(--fq-bg) 80%)"
        data-fq-no-feed
    >
        <div class="flex items-center gap-[13px]">
            <span class="relative block h-[72px] w-[72px] shrink-0 rounded-[16px]" style="background: radial-gradient(circle at 50% 30%, var(--fq-line), var(--fq-bg) 74%)">
                <fq-prize class="absolute inset-[8px]" :prize="sel ? sel.kind + ':' + sel.key : 'token:'"></fq-prize>
            </span>
            <div class="min-w-0 flex-1">
                <p class="font-baloo text-[21px] leading-[1.05] font-extrabold text-fq-text" x-text="sel && sel.name"></p>
                <p class="font-baloo text-[16px] font-extrabold text-fq-lime" x-text="sel && sel.cost + ' ✦'"></p>
                <p class="mt-[3px] text-[12px] text-pretty text-fq-text-3" x-text="sel && sel.blurb"></p>
            </div>
            <button
                type="button"
                x-on:click="close()"
                aria-label="Close"
                class="grid h-[32px] w-[32px] shrink-0 place-items-center self-start rounded-full border border-fq-line-2"
            ><i class="fa-solid fa-xmark text-[13px] text-fq-text-4"></i></button>
        </div>

        <div x-show="sel && sel.kind === 'candy'" class="flex items-center gap-[9px] rounded-[12px] border border-fq-streak px-[11px] py-[8px]" style="background: #3b0c1d">
            <i class="fa-solid fa-hand-holding-heart text-[12px] text-[#ff7a97]"></i>
            <span class="flex-1 text-[12px] text-[#f3ccd6]">A real thing &mdash; a grown-up hands it to you.</span>
        </div>

        {{-- Trying it on: the prize goes on the pet's own floor, for real, and
             this shows it at the size it will be there. --}}
        <div x-show="trying" class="flex items-end gap-[10px] rounded-[14px] border border-fq-line-3 bg-fq-bg px-[12px] py-[10px]">
            <span class="relative block shrink-0" :style="sel ? 'width:' + sel.size + 'px;height:' + sel.size + 'px' : ''">
                <fq-prize class="absolute inset-0" :prize="sel ? sel.kind + ':' + sel.key : 'token:'"></fq-prize>
            </span>
            <span class="flex-1 pb-[4px] text-[11.5px] text-fq-text-3">True size. Look down &mdash; it&rsquo;s on your floor now.</span>
        </div>

        <div class="flex gap-[8px]">
            <button
                type="button"
                x-on:click="act()"
                :disabled="! can"
                class="flex-[2_1_0] rounded-[14px] border p-[13px] text-center font-baloo text-[16px] font-extrabold"
                :class="can
                    ? (sel && sel.owned ? 'border-transparent bg-fq-green text-[#05170c]' : 'border-transparent text-fq-ink')
                    : 'border-fq-line-2 bg-fq-sunk text-fq-text-4'"
                :style="can && sel && ! sel.owned ? 'background: var(--fq-fill-gold-soft)' : ''"
                x-text="cta"
            ></button>
            <button
                type="button"
                x-show="canTry"
                x-on:click="tryIt()"
                class="flex-[1_1_0] rounded-[14px] border border-fq-line-3 bg-fq-sunk p-[13px] text-center font-baloo text-[15px] font-bold text-fq-magenta"
                x-text="trying ? 'Take it off' : 'Try it'"
            ></button>
        </div>

        <p class="text-center text-[12px] text-fq-text-4" x-text="note"></p>
    </div>
</div>
