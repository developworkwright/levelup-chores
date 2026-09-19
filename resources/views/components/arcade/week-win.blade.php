{{-- Sunday: the board pays — 2e in handoff/design_handoff_arcade_tokens.

     Shown to the kid who was paid, once, the next time they open the arcade,
     until they tap it away. It says exactly what the tokens buy, or a kid
     can't tell whether they won anything.

     When a grown-up topped the week the tokens came down to the best kid below
     them, and the card says so rather than pretending the kid took the board. --}}
@props(['win'])

@php
    $winnerName = $win->profile?->name;
    $handedDown = $win->profile_id !== $win->paid_profile_id;
@endphp

<div
    class="flex flex-col gap-[12px] rounded-[20px] border border-fq-gold px-[15px] py-[16px]"
    style="background: linear-gradient(165deg, #2a2405, var(--fq-sunk) 70%)"
>
    <div class="flex items-center gap-[12px]">
        <i class="fa-solid fa-trophy text-[30px] text-fq-gold"></i>
        <div class="min-w-0 flex-1">
            <p class="font-mono-fq text-[9px] tracking-[0.18em] text-fq-ticket-label uppercase">{{ $win->game->label() }} &middot; week closed</p>
            <p class="font-baloo text-[25px] leading-[1.05] font-extrabold text-fq-lime">
                @if ($handedDown)
                    {{ $winnerName }} topped it &mdash; you&rsquo;re paid
                @else
                    You took the board
                @endif
            </p>
            @if ($win->score !== null)
                <p class="text-[13px] text-fq-notice-text">{{ number_format($win->score) }} {{ $win->game->unit() }} &middot; {{ app(\App\Services\ArcadeService::class)->altitude($win->game, $win->score) }}</p>
            @endif
        </div>
    </div>

    <div class="flex items-center gap-[12px] rounded-[15px] border border-fq-ticket-line bg-fq-panel p-[12px]">
        <fq-prize kind="token" class="fq-token-drop h-[42px] w-[42px] shrink-0"></fq-prize>
        <div class="flex-1">
            <p class="font-baloo text-[20px] font-extrabold text-fq-lime">+{{ $win->tokens }} tokens</p>
            <p class="text-[12px] text-fq-text-3">
                Straight in, over today&rsquo;s cap. That&rsquo;s a frisbee, or {{ intdiv($win->tokens, \App\Services\TokenService::TICKET_PRICE) }} tickets.
            </p>
        </div>
    </div>

    <div class="flex gap-[8px]">
        <button
            type="button"
            wire:click="dismissWin(true)"
            class="flex-1 rounded-[13px] border border-fq-gold p-[11px] text-center font-baloo text-[14px] font-extrabold text-fq-ink"
            style="background: var(--fq-fill-gold-soft)"
        >Spend it</button>
        <button
            type="button"
            wire:click="dismissWin"
            class="rounded-[13px] border border-fq-line px-[13px] py-[11px] text-center font-baloo text-[14px] font-bold text-fq-text-4"
        >New week&rsquo;s board</button>
    </div>
</div>
