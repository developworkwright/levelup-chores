@props(['poweredUp', 'handSize', 'bonusCards'])

{{--
    What one chore today is worth, said out loud on both sides of it.

    The daily chest has boosted itself on this exact rule for months and nobody
    ever noticed, because a better roll you cannot see is not a reward — it is
    a number the app changed quietly. So this strip exists to be the visible
    half: it names the extras whether or not they are switched on, which is the
    only way a kid who has done nothing today finds out what they are missing.

    Never a gate, and the wording has to keep being careful about that. Nothing
    on this strip is taken away when `poweredUp` is false — it has not been
    earned yet. "Do one chore to switch these on", never "locked".
--}}
<div
    @class([
        'flex flex-col gap-[9px] rounded-[16px] border p-[13px]',
        'border-fq-lime bg-fq-sunk' => $poweredUp,
        'border-fq-line bg-fq-panel' => ! $poweredUp,
    ])
>
    <div class="flex items-center gap-[8px]">
        <i @class([
            'fa-solid text-[15px]',
            'fa-bolt text-fq-lime' => $poweredUp,
            'fa-bolt text-fq-text-5' => ! $poweredUp,
        ])></i>

        <h3 class="font-baloo text-[16px] leading-none font-extrabold">
            {{ $poweredUp ? 'Powered up' : 'Not powered up yet' }}
        </h3>

        @if ($poweredUp)
            <span class="ml-auto font-mono-fq text-[9px] tracking-[0.14em] text-fq-lime uppercase">Today</span>
        @endif
    </div>

    <p class="text-[13px] leading-snug text-fq-text-3">
        @if ($poweredUp)
            You did a chore today, so today's extras are on.
        @else
            Do one chore today and these switch on. Any chore counts, and it happens
            the moment you put it in.
        @endif
    </p>

    <ul class="flex flex-col gap-[5px]">
        <li @class(['flex items-baseline gap-[7px] text-[12.5px]', 'text-fq-text-3' => $poweredUp, 'text-fq-text-5' => ! $poweredUp])>
            <i @class(['fa-solid text-[10px]', 'fa-circle-check text-fq-lime' => $poweredUp, 'fa-circle text-fq-text-6' => ! $poweredUp])></i>
            <span>The chest rolls on the good table</span>
        </li>

        {{-- Tomorrow's, not today's — the hand is dealt in the morning, before
             today's work exists. Saying "tomorrow" is the only honest way to
             put it, and it is also the only line here that pays a kid for
             coming back. --}}
        <li @class(['flex items-baseline gap-[7px] text-[12.5px]', 'text-fq-text-3' => $poweredUp, 'text-fq-text-5' => ! $poweredUp])>
            <i @class(['fa-solid text-[10px]', 'fa-circle-check text-fq-lime' => $poweredUp, 'fa-circle text-fq-text-6' => ! $poweredUp])></i>
            <span>{{ $bonusCards }} extra quest cards tomorrow</span>
        </li>
    </ul>

    @if ($handSize > \App\Services\ChoreService::HAND_SIZE)
        <p class="font-mono-fq text-[9.5px] tracking-[0.12em] text-fq-lime uppercase">
            Yesterday's work paid — {{ $handSize }} cards in today's hand
        </p>
    @endif
</div>
