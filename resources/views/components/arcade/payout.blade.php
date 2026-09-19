{{-- The run-end payout — 2a in handoff/design_handoff_arcade_tokens, and the
     whole point of tokens: a six-year-old sees the coins come out of the thing
     they just did.

     Under the game rather than in a dialog, so on a big screen the run is still
     there behind it (2g). Itemised: one line for having a go, one per rung
     reached for the first time today, then the bank ticking up. Lines the day's
     cap could not pay stay on the card, greyed, above the refill line — the cap
     is the lever, and a kid has to see what it cost them.

     `$payout` is TokenService::payRun()'s answer. --}}
@props(['payout'])

<div
    wire:key="payout-{{ $payout['from'] }}-{{ $payout['score'] }}"
    class="flex flex-col gap-[11px] rounded-[20px] border border-fq-gold px-[14px] py-[15px]"
    style="background: linear-gradient(170deg, #2a2405, var(--fq-bg) 72%)"
>
    <div class="flex items-start gap-[11px]">
        <div class="min-w-0 flex-1">
            <p class="font-mono-fq text-[9px] tracking-[0.18em] text-fq-ticket-label uppercase">{{ $payout['game'] }} &middot; game over</p>
            <p class="mt-[3px] font-baloo text-[34px] leading-[1.02] font-extrabold text-fq-lime">
                {{ $payout['paid'] > 0 ? '+'.$payout['paid'].' '.Str::plural('token', $payout['paid']) : 'No tokens' }}
            </p>
            <p class="text-[12.5px] text-fq-notice-text">{{ number_format($payout['score']) }} {{ $payout['unit'] }} &middot; {{ $payout['rung'] }}</p>
        </div>

        <fq-prize kind="token" class="fq-token-drop h-[46px] w-[46px] shrink-0"></fq-prize>
    </div>

    <div class="flex flex-col gap-[5px]">
        @foreach ($payout['lines'] as $line)
            <div
                @class([
                    'flex items-center gap-[9px] rounded-[11px] border bg-fq-panel px-[11px] py-[8px]',
                    'border-fq-line-2' => $line['paid'],
                    'border-fq-divider' => ! $line['paid'],
                ])
            >
                <i class="fa-solid fa-flag-checkered text-[10px] text-fq-green"></i>
                <span @class(['flex-1 text-[12.5px]', 'text-fq-lime' => $line['paid'], 'text-fq-text-4' => ! $line['paid']])>{{ $line['name'] }}</span>
                <span @class(['font-baloo text-[14px] font-extrabold whitespace-nowrap', 'text-fq-lime' => $line['paid'], 'text-fq-text-4' => ! $line['paid']])>
                    {{ $line['paid'] ? '+'.$line['tokens'] : 'no room' }}
                </span>
            </div>
        @endforeach
    </div>

    <div class="flex items-center justify-between gap-[8px] border-t border-dashed border-fq-line-3 pt-[9px]">
        <span class="font-mono-fq text-[9.5px] tracking-[0.12em] text-fq-ticket-label uppercase">In the bank</span>
        <span class="font-baloo text-[17px] font-extrabold text-fq-lime">{{ $payout['from'] }} &rarr; {{ $payout['to'] }}</span>
    </div>

    @if ($payout['lost'] > 0)
        <div class="flex gap-[9px] rounded-[13px] border border-fq-streak px-[12px] py-[10px]" style="background: #3b0c1d">
            <i class="fa-solid fa-gauge-simple-high mt-[2px] text-[13px] text-[#ff7a97]"></i>
            <span class="flex-1 text-[12px] text-pretty text-[#f3ccd6]">
                {{ $payout['lost'] }} {{ Str::plural('token', $payout['lost']) }} wouldn&rsquo;t fit &mdash; the machine is out for today.
                Claim a chore and it refills by {{ \App\Services\TokenService::CAP_PER_CHORE }}.
            </span>
        </div>
    @endif

    <button
        type="button"
        wire:click="showTab('prizes')"
        class="rounded-[14px] border border-fq-line-3 bg-fq-sunk p-[12px] text-center font-baloo text-[15px] font-bold text-fq-magenta"
    >Spend them at the prize counter</button>
</div>
