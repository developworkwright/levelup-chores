{{-- Gnash: the toy-box plush with a zipper for a mouth. Two big eyes, noodle
     arms, and a zip that opens wider the more damage it takes. --}}
@props(['skin', 'stage'])

@php
    $wear = $stage->wear();
    $open = $stage->openness();
@endphp

<x-boss.frame :skin="$skin" :stage="$stage">
    {{-- Arms first, so they sit behind the body. --}}
    <path
        d="M 58 104 Q 18 118 24 154 Q 27 172 44 174"
        fill="none" stroke="var(--boss-shade)" stroke-width="13" stroke-linecap="round"
    />
    <path
        d="M 142 104 Q 182 118 176 154 Q 173 172 156 174"
        fill="none" stroke="var(--boss-shade)" stroke-width="13" stroke-linecap="round"
    />

    {{-- Tufts. --}}
    <path d="M 78 48 L 84 22 L 96 46 Z" fill="var(--boss-shade)" />
    <path d="M 122 48 L 116 22 L 104 46 Z" fill="var(--boss-shade)" />

    <path
        d="M 55 176 Q 40 122 52 82 Q 62 42 100 40 Q 138 42 148 82 Q 160 122 145 176 Z"
        fill="var(--boss-body)"
    />

    {{-- Belly patch, the bit of plush softness that makes the teeth worse. --}}
    <ellipse cx="100" cy="150" rx="34" ry="26" fill="var(--boss-shade)" opacity="0.45" />

    <x-boss.eye cx="79" cy="94" :r="15" :stage="$stage" />
    <x-boss.eye cx="126" cy="94" :r="15" :stage="$stage" />

    <x-boss.grin :cx="100" :cy="141" :width="76" :teeth="8" :stage="$stage" />

    {{-- The zip runs along the top lip; its pull hangs lower the wider the
         mouth gapes, which is the one detail that sells the mouth as a zip
         rather than a hole. --}}
    <g opacity="0.85">
        <line
            x1="60" y1="{{ 141 - (6 + 12 * $open) / 2 }}"
            x2="140" y2="{{ 141 - (6 + 12 * $open) / 2 }}"
            stroke="var(--boss-teeth)" stroke-width="2" opacity="0.5"
        />
        <circle cx="{{ 60 + 80 * $open }}" cy="{{ 141 - (6 + 12 * $open) / 2 }}" r="4" fill="var(--boss-teeth)" />
        <line
            x1="{{ 60 + 80 * $open }}" y1="{{ 141 - (6 + 12 * $open) / 2 }}"
            x2="{{ 60 + 80 * $open }}" y2="{{ 152 - (6 + 12 * $open) / 2 }}"
            stroke="var(--boss-teeth)" stroke-width="2.5" stroke-linecap="round"
        />
    </g>

    {{-- Stuffing coming out. Every skin carries its damage in its own material:
         Gnash leaks fluff. --}}
    <g opacity="{{ $wear }}">
        <path d="M 62 70 Q 52 60 58 52" fill="none" stroke="var(--boss-teeth)" stroke-width="3" stroke-linecap="round" />
        <circle cx="55" cy="49" r="6" fill="var(--boss-teeth)" opacity="0.9" />
        <circle cx="47" cy="55" r="4" fill="var(--boss-teeth)" opacity="0.7" />
        <path
            d="M 132 118 L 138 126 L 130 130 L 137 138"
            fill="none" stroke="var(--boss-shade)" stroke-width="3" stroke-linecap="round"
        />
    </g>
</x-boss.frame>
