{{-- Today's meter, and the machine run dry — 2b in
     handoff/design_handoff_arcade_tokens.

     A coin hopper rather than a rule: the bar is what the kid has taken today,
     the blocks are the machine's capacity, one lit per chore claimed. When it
     empties it says "go get more", never "you've had enough" — the cap is the
     lever that sends a kid to a chore, and a lever that reads as a telling-off
     is one they walk away from instead.

     Three states. Filling: the quiet meter, phone only, because on a desktop the
     chip in the header already says it (2g). Empty with a job left: loud, with
     the job and a CLAIM button that refills it in place. Empty with nothing left
     to claim: the most there is in a day, said as a win. The empty ones show on
     every screen size — a kid needs to know why the last run paid nothing. --}}
@props([
    'meter',
    'refill' => null,
    'claimable' => 0,
    'perChore' => 15,
    'note' => null,
])

@php
    $fill = $meter['cap'] > 0 ? min(100, (int) round($meter['today'] / $meter['cap'] * 100)) : 100;
    // One block per chore claimed, then one per job still on the board, capped
    // so a big board doesn't wrap the row. At least four, as drawn.
    $blocks = max(4, min(6, $meter['chores'] + $claimable));
@endphp

@if (! $meter['empty'])
    <div
        class="flex flex-col gap-[10px] rounded-[18px] border border-fq-line-3 px-[14px] py-[13px] lg:hidden"
        style="background: linear-gradient(160deg, var(--fq-panel-alt), var(--fq-bg) 78%)"
    >
        <div class="flex items-center gap-[10px]">
            <fq-prize kind="token" class="h-[26px] w-[26px] shrink-0"></fq-prize>
            <span class="flex-1 font-baloo text-[18px] font-extrabold text-fq-text">{{ $meter['room'] }} more today</span>
            <span class="font-mono-fq text-[12px] text-fq-lime">{{ $meter['today'] }}<span class="text-fq-text-5">/{{ $meter['cap'] }}</span></span>
        </div>

        <div class="h-[14px] overflow-hidden rounded-full border border-fq-line-2 bg-fq-panel">
            <div class="h-full" style="width: {{ $fill }}%; background: linear-gradient(90deg, var(--fq-gold), var(--fq-lime))"></div>
        </div>

        <div class="flex gap-[6px]">
            @for ($i = 0; $i < $blocks; $i++)
                @php($lit = $i < $meter['chores'])
                <span
                    @class([
                        'flex-1 basis-0 rounded-[8px] border px-[2px] py-[6px] text-center font-mono-fq text-[8.5px] tracking-[0.08em]',
                        'border-fq-green text-fq-green' => $lit,
                        'border-fq-line-2 text-fq-text-5' => ! $lit,
                    ])
                    @if ($lit) style="background: var(--fq-green-deep)" @endif
                >{{ $lit ? 'CHORE '.($i + 1) : '+'.$perChore }}</span>
            @endfor
        </div>

        <p class="text-[12px] text-pretty text-fq-text-3">
            @if ($claimable > 0)
                Claim a chore and the machine refills: +{{ $perChore }} tokens, straight away.
            @else
                Every job on the board is claimed &mdash; this is as big as the machine gets today.
            @endif
        </p>
    </div>
@elseif ($refill)
    <div
        class="flex flex-col gap-[10px] rounded-[18px] border border-fq-gold p-[14px]"
        style="background: linear-gradient(160deg, #2a2405, var(--fq-bg) 74%)"
    >
        <div class="flex items-center gap-[11px]">
            <i class="fa-solid fa-hand-sparkles text-[24px] text-fq-gold"></i>
            <div class="flex-1">
                <p class="font-baloo text-[22px] leading-[1.05] font-extrabold text-fq-lime">The machine&rsquo;s empty!</p>
                <p class="text-[12.5px] text-fq-notice-text">
                    You cleaned it out &mdash; {{ $meter['cap'] }} tokens today. Play all you like; it pays again when it&rsquo;s refilled.
                </p>
            </div>
        </div>

        <button
            type="button"
            wire:click="claimRefill({{ $refill->id }})"
            wire:loading.attr="disabled"
            class="flex items-center gap-[10px] rounded-[13px] border border-fq-green px-[12px] py-[11px] text-left"
            style="background: var(--fq-green-deep)"
        >
            <i class="fa-solid fa-plug-circle-bolt text-[15px] text-fq-green"></i>
            <span class="flex-1 text-[12.5px] text-fq-green-ink">
                <strong class="text-fq-text">{{ $refill->name }}</strong>
                &middot; +{{ $perChore }} tokens of room, {{ $refill->points }} pts
            </span>
            <span class="rounded-[8px] bg-fq-green px-[9px] py-[6px] font-mono-fq text-[9px] whitespace-nowrap text-[#05170c]">CLAIM</span>
        </button>

        @if ($note)
            <p class="text-[12px] text-fq-text-3">{{ $note }}</p>
        @endif
    </div>
@else
    <div
        class="flex flex-col gap-[10px] rounded-[18px] border border-fq-line-3 p-[14px]"
        style="background: linear-gradient(160deg, var(--fq-panel-alt), var(--fq-bg) 78%)"
    >
        <div class="flex items-center gap-[11px]">
            <i class="fa-solid fa-moon text-[22px] text-fq-magenta"></i>
            <div class="flex-1">
                <p class="font-baloo text-[20px] leading-[1.05] font-extrabold text-fq-text">You beat the whole board</p>
                <p class="text-[12.5px] text-pretty text-fq-text-3">
                    Every job is claimed and the machine is out &mdash; {{ $meter['cap'] }} tokens, the most there is today.
                    It fills right back up in the morning.
                </p>
            </div>
        </div>

        <div class="flex gap-[8px]">
            <button
                type="button"
                wire:click="showTab('prizes')"
                class="flex-1 rounded-[13px] border border-fq-gold p-[11px] text-center font-baloo text-[14px] font-extrabold text-fq-lime"
            >Spend {{ $meter['balance'] }} &#10022; at the counter</button>
        </div>

        <p class="text-[11.5px] text-pretty text-fq-text-5">
            Scores still count for the weekly board when the machine is empty &mdash; the cap is on tokens, never on playing.
        </p>
    </div>
@endif
