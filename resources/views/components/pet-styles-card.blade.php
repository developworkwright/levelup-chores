@props(['styles', 'mine' => null, 'petName' => null])

{{-- What every style is worth in the arcade, not only the one a kid happens
     to have out: the word on a pet in the shop means nothing to a kid who has
     never owned that style. Theirs is open and marked; the rest are a tap
     away, so four styles across four games stay one card.

     `styles` is [['style' => PetStyle, 'games' => [game => help]], …] — see
     the Pets page's with(). --}}
<div {{ $attributes->merge(['class' => 'flex flex-col gap-[8px] rounded-[16px] border p-[12px]']) }} style="border-color: #241539; background: #0b0616" data-all-styles data-pet-style-games>
    <span class="flex flex-wrap items-baseline justify-between gap-[6px]">
        <span class="flex items-center gap-[7px]">
            <i class="fa-solid fa-gamepad text-[13px]" style="color: #c9a0ff"></i>
            <span class="font-baloo text-[16px] leading-tight font-extrabold">What the styles do</span>
        </span>
        <span class="text-[10.5px]" style="color: #8c7bab">Every pet has one of the four.</span>
    </span>

    @foreach ($styles as $row)
        @php $isMine = $mine === $row['style']; @endphp
        <div
            wire:key="style-{{ $row['style']->value }}"
            x-data="{ open: {{ $isMine ? 'true' : 'false' }} }"
            class="flex flex-col gap-[5px] border-t pt-[8px] first:border-t-0 first:pt-0"
            style="border-color: #1b1030"
            data-style-row="{{ $row['style']->value }}"
        >
            <button type="button" x-on:click="open = ! open" class="flex items-center gap-[7px] text-left">
                <i class="fa-solid {{ $row['style']->icon() }} w-[14px] text-[12px]" style="color: {{ $isMine ? '#7dffb0' : '#c8bade' }}"></i>
                <span class="font-baloo text-[14px] font-extrabold">{{ $row['style']->label() }}</span>
                @if ($isMine)
                    <span class="rounded-full border px-[6px] py-[1px] font-mono-fq text-[7.5px] tracking-[0.1em] uppercase" style="border-color: #7dffb0; color: #7dffb0">Yours</span>
                @endif
                <span class="min-w-0 flex-1 truncate text-[11px]" style="color: #8c7bab">{{ $row['style']->blurb() }}</span>
                <i class="fa-solid fa-chevron-down text-[9px] transition-transform" style="color: #8c7bab" x-bind:class="open && 'rotate-180'"></i>
            </button>

            <div x-show="open" x-cloak class="flex flex-col gap-[4px] pl-[21px]">
                @foreach ($row['games'] as $game => $help)
                    <span class="flex flex-col gap-[1px]">
                        <span class="font-mono-fq text-[8px] tracking-[0.12em] uppercase" style="color: #8c7bab">{{ $game }}</span>
                        <span class="text-[11.5px]" style="color: #ded0f5">{{ $isMine && $petName ? $petName : 'Your pet' }} {{ $help }}</span>
                    </span>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
