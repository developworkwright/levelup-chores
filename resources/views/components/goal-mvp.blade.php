{{-- Who's put the most into the family goal. Shared by the Quests sidebar and
     the Goal Planner so the two can't drift into telling different stories
     about the same numbers.

     `contributors` is what ChoreService::goalContributors() returns. --}}
@props(['contributors', 'label' => "Who's pulling hardest"])

@php
    // Bars are scaled against the leader rather than the total, so a two-kid
    // household doesn't render both at half width on a goal they're nailing.
    $best = max(1, (int) collect($contributors)->max('points'));
@endphp

<div {{ $attributes }}>
    <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">{{ $label }}</p>

    <div class="mt-[10px] flex flex-col gap-[10px]">
        @foreach ($contributors as $row)
            @php $kid = $row['profile']; @endphp

            <div wire:key="mvp-{{ $kid->id }}">
                <div class="flex items-center gap-2">
                    <span
                        class="grid h-[22px] w-[22px] shrink-0 place-items-center rounded-[6px] font-baloo text-[11px] font-extrabold text-fq-bg"
                        style="background: {{ $kid->color->cssVar() }}"
                    >{{ mb_substr($kid->name, 0, 1) }}</span>

                    <span class="min-w-0 flex-1 truncate text-[13px] font-semibold">
                        {{ $kid->name }}
                        {{-- The crown is the whole point of the card, so it
                             sits next to the name rather than in a column of
                             its own where it'd read as decoration. --}}
                        @if ($row['isLeader'])
                            <span title="Most points into the goal so far">&#128081;</span>
                        @endif
                    </span>

                    <span class="font-mono-fq text-[11px] whitespace-nowrap text-fq-text-4">
                        {{ number_format($row['points']) }} · {{ $row['percent'] }}%
                    </span>
                </div>

                <div class="mt-[6px] h-[10px] overflow-hidden rounded-full bg-fq-track">
                    <div
                        class="h-full rounded-full transition-[width] duration-500"
                        style="width: {{ round($row['points'] / $best * 100) }}%; background: {{ $row['isLeader'] ? 'linear-gradient(90deg, var(--fq-lime), var(--fq-gold))' : $kid->color->cssVar() }}"
                    ></div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- A board of zeroes with a "nobody yet" line under it is clearer than an
         empty card that looks like it failed to load. --}}
    @if (collect($contributors)->sum('points') === 0)
        <p class="mt-[10px] text-[13px] text-fq-text-5">
            Nobody's on the board yet. First approved chore takes the crown.
        </p>
    @endif
</div>
