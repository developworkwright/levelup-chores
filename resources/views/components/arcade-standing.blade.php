{{-- One player's week on one game — the row the board is made of.

     Its own component because it is drawn twice: once in the top three, and
     again below the fold when the reader is not in them. A board of three that
     a fourth-placed kid is missing from tells them nothing about their own
     week, so their row gets pulled up rather than left off, and it has to look
     identical in both places.

     The name is replaced by "You" on the reader's own row rather than tinted
     alone: a six-year-old scanning four names for their own is the thing the
     tint is trying to save them from. --}}
@props([
    'rank' => 1,
    'score',
    'mine' => false,
    'posted' => false,
])

<div
    @class([
        'flex items-center gap-[6px]',
        '-mx-[4px] rounded-[7px] px-[4px] py-[3px]' => $mine,
        'ring-1 ring-fq-lime' => $posted,
    ])
    @if ($mine)
        style="background: rgba(255, 225, 77, 0.12)"
    @endif
>
    <span
        @class([
            'w-[10px] shrink-0 font-mono-fq text-[9px]',
            'text-fq-lime' => $mine,
            'text-fq-coral' => ! $mine && $rank === 1,
            'text-fq-text-5' => ! $mine && $rank !== 1,
        ])
    >{{ $rank }}</span>

    <span
        class="grid h-[19px] w-[19px] shrink-0 place-items-center rounded-full font-baloo text-[9.5px] font-extrabold text-fq-bg"
        style="background: {{ $score->profile?->color->cssVar() ?? 'var(--fq-line-3)' }}"
    >{{ mb_substr($score->displayName(), 0, 1) }}</span>

    <span
        @class([
            'min-w-0 flex-1 truncate text-[11px]',
            'font-extrabold text-fq-lime' => $mine,
            'text-fq-text' => ! $mine && $rank === 1,
            'text-fq-text-3' => ! $mine && $rank !== 1,
        ])
    >{{ $mine ? 'You' : $score->displayName() }}</span>

    <span
        @class([
            'shrink-0 font-baloo text-[12.5px] font-extrabold',
            'text-fq-lime' => $mine,
            'text-fq-coral' => ! $mine && $rank === 1,
            'text-fq-text-4' => ! $mine && $rank !== 1,
        ])
    >{{ $score->score }}</span>
</div>
