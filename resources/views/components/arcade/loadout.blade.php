{{-- What your pet has out — 2d in handoff/design_handoff_arcade_tokens.

     One row per slot, only what you own, tap to swap: the Locker's own
     pattern, so a kid who can dress an avatar can already work it. Above the
     rows, the floor at true size — the bed, the pet as it stands, its toy and
     its snack — so a swap is seen, not just ticked.

     The pet is its own idle pose off its sheet. Egg or no pet at all, the spot
     says so instead of drawing a stand-in. --}}
@props([
    'owned',
    'gear',
    'petSprite' => null,
])

@php
    $hasPet = $petSprite !== null && ! isset($petSprite['egg']) && $petSprite['src'];
@endphp

<div class="flex flex-col gap-[12px] rounded-[24px] border border-fq-line bg-fq-bg p-[13px]">
    <span class="font-baloo text-[18px] font-extrabold text-fq-text">What your pet has out</span>

    <div
        class="relative flex h-[150px] items-end gap-[10px] rounded-[18px] border border-fq-line px-[14px] py-[12px]"
        style="background: linear-gradient(0deg, var(--fq-panel-alt), var(--fq-bg) 72%)"
    >
        <span class="absolute top-[10px] left-[14px] font-mono-fq text-[8.5px] tracking-[0.16em] text-fq-text-5">YOUR FLOOR &middot; TRUE SIZE</span>

        @if ($gear['bed'])
            <fq-prize kind="bed" key="{{ $gear['bed'] }}" class="h-[96px] w-[96px] shrink-0"></fq-prize>
        @endif

        @if ($hasPet)
            <span
                class="block h-[80px] w-[80px] shrink-0"
                style="background-image: url('{{ $petSprite['src'] }}'); background-size: 400% 300%; background-position: 0 0; background-repeat: no-repeat"
                aria-label="Your pet"
            ></span>
        @else
            <span class="grid h-[80px] w-[70px] shrink-0 place-items-center rounded-[14px_14px_6px_6px] border border-dashed border-fq-line-3 text-center font-mono-fq text-[8px] tracking-[0.08em] text-fq-text-4">
                {{ isset($petSprite['egg']) ? 'STILL AN EGG' : 'NO PET YET' }}
            </span>
        @endif

        @if ($gear['toy'])
            <fq-prize kind="toy" key="{{ $gear['toy'] }}" class="fq-bob h-[34px] w-[34px] shrink-0"></fq-prize>
        @endif

        <fq-prize kind="snack" key="{{ $gear['snack'] }}" class="h-[26px] w-[26px] shrink-0"></fq-prize>
    </div>

    @foreach (\App\Enums\PrizeSlot::cases() as $slot)
        @php
            $mine = array_values(array_filter($slot->items(), fn ($item) => in_array($item['key'], $owned[$slot->value] ?? [], true)));
        @endphp

        <div class="flex flex-col gap-[7px]">
            <span class="font-mono-fq text-[9px] tracking-[0.16em] text-fq-text-4 uppercase">{{ $slot->label() }}</span>

            @if ($mine === [])
                <p class="rounded-[13px] border border-dashed border-fq-line-2 px-[12px] py-[11px] text-[12px] text-fq-text-5">
                    Nothing bought yet &mdash; the counter has {{ count($slot->items()) }}.
                </p>
            @else
                <div class="flex flex-wrap gap-[7px]">
                    @foreach ($mine as $item)
                        @php($on = $gear[$slot->value] === $item['key'])
                        <button
                            type="button"
                            wire:key="gear-{{ $slot->value }}-{{ $item['key'] }}"
                            wire:click="{{ $on && $slot !== \App\Enums\PrizeSlot::Snack ? "putAwayPrize('{$slot->value}')" : "putOutPrize('{$slot->value}', '{$item['key']}')" }}"
                            @class([
                                'flex w-[82px] flex-col items-center gap-[6px] rounded-[14px] border p-[8px]',
                                'border-fq-green' => $on,
                                'border-fq-line bg-fq-panel-alt' => ! $on,
                            ])
                            @if ($on) style="background: var(--fq-green-deep)" @endif
                            aria-pressed="{{ $on ? 'true' : 'false' }}"
                        >
                            <fq-prize kind="{{ $slot->value }}" key="{{ $item['key'] }}" class="h-[44px] w-[44px]"></fq-prize>
                            <span @class(['block text-center text-[10.5px] leading-[1.15]', 'text-fq-green' => $on, 'text-fq-text-3' => ! $on])>{{ $item['name'] }}</span>
                        </button>
                    @endforeach
                </div>

                @if ($slot !== \App\Enums\PrizeSlot::Snack && $gear[$slot->value])
                    <p class="text-[11px] text-fq-text-5">Tap the one that&rsquo;s out to put it away.</p>
                @endif
            @endif
        </div>
    @endforeach
</div>
