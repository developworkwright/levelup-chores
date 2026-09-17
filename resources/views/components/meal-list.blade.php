{{-- Every night a grown-up has filled in, tonight first. Behind the Meals row
     on both Homes.

     Unset nights are skipped rather than drawn as gaps: a column of "not set
     yet" is the app nagging the grown-ups on a screen that is mostly read by
     the kids. See MealService::upcoming(). --}}
@props(['meals', 'today'])

<div
    wire:key="home-meals"
    class="flex flex-col gap-[7px] rounded-[20px] border p-[13px]"
    style="border-color: var(--fq-line-2); background: linear-gradient(120deg, var(--fq-wash-violet), var(--fq-panel) 66%)"
>
    @forelse ($meals as $meal)
        @php
            // Calendar dates, not instants: `today` is midnight in the house's
            // zone and `served_on` is midnight in the app's, so the raw gap to
            // tomorrow is under a day and truncated to "Tonight".
            $days = (int) \Illuminate\Support\Carbon::parse($today->toDateString())
                ->diffInDays(\Illuminate\Support\Carbon::parse($meal->served_on->toDateString()));
            $when = match ($days) {
                0 => 'Tonight',
                1 => 'Tomorrow',
                default => $meal->served_on->format($days < 7 ? 'l' : 'D j M'),
            };
        @endphp

        <div
            wire:key="meal-{{ $meal->id }}"
            class="flex flex-col gap-[2px] rounded-[13px] bg-fq-sunk px-[11px] py-[9px]"
        >
            <span
                class="font-mono-fq text-[9px] tracking-[0.16em] uppercase"
                style="color: {{ $days === 0 ? 'var(--fq-gold)' : 'var(--fq-text-5)' }}"
            >{{ $when }}</span>
            <span @class([
                'font-baloo font-bold',
                'text-[16px]' => $days === 0,
                'text-[14.5px] text-fq-text-2' => $days !== 0,
            ])>{{ $meal->name }}</span>

            @if ($meal->note)
                <span class="text-[12.5px] text-fq-text-4">{{ $meal->note }}</span>
            @endif
        </div>
    @empty
        <p class="px-1 py-2 text-[13.5px] text-fq-text-5">Nobody has said what's for dinner yet.</p>
    @endforelse
</div>
