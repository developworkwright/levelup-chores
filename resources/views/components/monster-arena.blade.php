{{-- The arena: up to three monsters, standing at once.

     Each one is a family goal at its own scale, and each pays out something
     different — which is the whole point. A kid picks who to hit after every
     chore, so the three have to be readable side by side.

     `states` is keyed by tier so a household that has only named one of the
     three still renders it in the right place rather than shuffling everything
     left. --}}
@props(['states'])

@php
    $tiers = App\Enums\MonsterTier::cases();
    $anyStanding = collect($states)->isNotEmpty();
@endphp

<div {{ $attributes->merge(['class' => 'rounded-[24px] border border-fq-line-2 bg-fq-panel p-5 sm:p-6']) }}>
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <div>
            <p class="font-mono-fq text-[10px] tracking-[0.24em] text-fq-coral uppercase">The Arena</p>
            <h2 class="mt-1 font-baloo text-2xl font-extrabold">Three monsters, three rewards</h2>
        </div>

        @if ($anyStanding)
            <p class="max-w-[420px] text-[13px] text-fq-text-4">
                Every chore you finish hits the one you pick. Beat one and the family gets
                what it was guarding.
            </p>
        @endif
    </div>

    @if (! $anyStanding)
        <div class="mt-4 rounded-[18px] border border-dashed border-fq-line-4 bg-fq-sunk p-5 text-center">
            <h3 class="font-baloo text-xl font-bold">Nothing standing yet</h3>
            <p class="mx-auto mt-2 max-w-[360px] text-sm text-fq-text-2">
                A parent picks what each monster is guarding &mdash; something small,
                something bigger, and one worth saving up for.
            </p>
        </div>
    @else
        {{-- Wrapping rather than a three-column grid: on a phone these stack,
             and an empty tier must not leave a hole in the row. --}}
        <div class="mt-4 flex flex-wrap items-stretch gap-[14px]">
            @foreach ($tiers as $tier)
                @php $state = $states[$tier->value] ?? null; @endphp

                {{-- Weighted by tier, so the long game simply takes up more of
                     the row than the ice cream does. Still wraps one per line
                     on a phone, where there is no room for a ratio anyway. --}}
                <div class="flex min-w-0 {{ $tier->cardBasis() }} flex-col" wire:key="arena-tier-{{ $tier->value }}">
                    @if ($state)
                        <x-monster-card :state="$state" class="h-full" />
                    @else
                        <div class="flex h-full flex-col justify-center rounded-[22px] border border-dashed border-fq-line-4 bg-fq-sunk p-5 text-center">
                            <p class="font-mono-fq text-[10px] tracking-[0.2em] text-fq-text-4 uppercase">
                                {{ $tier->label() }}
                            </p>
                            <p class="mt-2 font-baloo text-[17px] font-bold text-fq-text-2">Empty</p>
                            <p class="mt-1 text-[12px] text-fq-text-5">{{ $tier->blurb() }}</p>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
