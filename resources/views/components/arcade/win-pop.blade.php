{{-- The run's payout, popped over the game screen at game over.

     The itemised card (x-arcade.payout) is under the canvas, which on a phone
     is below the fold — so the moment tokens land has to happen where the kid
     is already looking: on the machine, next to their score. This is the
     headline only; the card keeps the line-by-line.

     No pointer events, so a tap meant for "play again" reaches the game. --}}
@props(['payout'])

@php
    // Rungs only: the pet's Coin Sniffer line is its own thing, said after.
    $rungs = count(array_filter($payout['lines'], fn (array $line) => $line['paid'] && ! ($line['pet'] ?? false))) - 1;
    $sniffed = collect($payout['lines'])->first(fn (array $line) => ($line['pet'] ?? false) && $line['paid'])['tokens'] ?? 0;
@endphp

<div {{ $attributes->class('fq-win-pop pointer-events-none absolute inset-x-0 top-[64px] z-10 flex justify-center px-[12px]') }} role="status">
    <div
        class="flex items-center gap-[10px] rounded-[18px] border-2 border-fq-gold px-[16px] py-[10px]"
        style="background: linear-gradient(170deg, #2a2405, var(--fq-bg) 82%); box-shadow: 0 0 22px rgba(255, 201, 61, .45), 0 10px 30px rgba(0, 0, 0, .55)"
    >
        <fq-prize kind="token" class="h-[40px] w-[40px] shrink-0"></fq-prize>

        <div class="min-w-0">
            <p class="font-baloo text-[28px] leading-none font-extrabold text-fq-lime">
                {{ $payout['paid'] > 0 ? '+'.$payout['paid'].' '.Str::plural('token', $payout['paid']) : 'No tokens' }}
            </p>
            <p class="mt-[3px] text-[12px] leading-tight text-fq-notice-text">
                @if ($payout['paid'] === 0)
                    Machine&rsquo;s empty &mdash; a chore refills it
                @elseif ($payout['lost'] > 0)
                    Machine&rsquo;s full now &mdash; a chore makes room
                @elseif ($rungs > 0)
                    1 for playing &middot; {{ $rungs }} new {{ Str::plural('rung', $rungs) }}@if ($sniffed) &middot; 🐾 +{{ $sniffed }}@endif
                @else
                    1 for playing &middot; bank {{ $payout['to'] }}
                @endif
            </p>
        </div>
    </div>
</div>
