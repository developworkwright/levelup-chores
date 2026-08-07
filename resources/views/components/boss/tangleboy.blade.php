{{-- Tangleboy: a knot of charging cables with one enormous eye. Unravels as it
     loses, and the glow goes out of it. --}}
@props(['skin', 'stage'])

@php
    $wear = $stage->wear();
    // Cables slacken and hang looser as the knot comes undone.
    $slack = 26 * $wear;
@endphp

<x-boss.frame :skin="$skin" :stage="$stage">
    {{-- The knot: several cables crossing, drawn back to front. --}}
    <g fill="none" stroke-linecap="round" stroke-width="11">
        <path
            d="M 44 {{ 150 + $slack }} Q 30 96 74 70 Q 118 46 150 84"
            stroke="var(--boss-shade)"
        />
        <path
            d="M 158 {{ 152 + $slack }} Q 178 100 138 68 Q 96 40 58 82"
            stroke="var(--boss-body)"
        />
        <path
            d="M 62 {{ 168 + $slack * 0.6 }} Q 100 130 142 {{ 166 + $slack * 0.6 }}"
            stroke="var(--boss-shade)"
        />
        <path
            d="M 52 112 Q 100 152 150 112"
            stroke="var(--boss-body)"
        />
    </g>

    {{-- Plug ends, hanging where hands would be. --}}
    <g fill="var(--boss-shade)">
        <rect x="{{ 34 }}" y="{{ 148 + $slack }}" width="20" height="16" rx="4" />
        <rect x="{{ 148 }}" y="{{ 150 + $slack }}" width="20" height="16" rx="4" />
    </g>
    <g fill="var(--boss-glow)" opacity="{{ 1 - 0.8 * $wear }}">
        <rect x="38" y="{{ 164 + $slack }}" width="4" height="7" rx="1" />
        <rect x="46" y="{{ 164 + $slack }}" width="4" height="7" rx="1" />
        <rect x="152" y="{{ 166 + $slack }}" width="4" height="7" rx="1" />
        <rect x="160" y="{{ 166 + $slack }}" width="4" height="7" rx="1" />
    </g>

    {{-- The head of the knot, a tight bundle around the eye. --}}
    <circle cx="100" cy="96" r="42" fill="var(--boss-shade)" />
    <circle cx="100" cy="96" r="34" fill="var(--boss-body)" />

    {{-- One eye, and it is far too large. The glow behind it dies with the
         monster, which is most of what makes the defeated stage land. --}}
    <circle cx="100" cy="96" r="30" fill="var(--boss-glow)" opacity="{{ 0.28 * (1 - $wear) }}" />
    <x-boss.eye cx="100" cy="96" :r="26" :stage="$stage" />

    {{-- A thin cable mouth. Sags into a frown as things go badly. --}}
    <path
        d="M 76 {{ 142 }} Q 100 {{ 142 + 22 * $stage->openness() }} 124 {{ 142 }}"
        fill="none" stroke="var(--boss-shade)" stroke-width="7" stroke-linecap="round"
    />

    {{-- Frayed copper where cables have come apart. --}}
    <g opacity="{{ $wear }}" stroke="var(--boss-glow)" stroke-width="2.5" stroke-linecap="round">
        <path d="M 44 {{ 150 + $slack }} L 32 {{ 140 + $slack }}" />
        <path d="M 44 {{ 150 + $slack }} L 30 {{ 152 + $slack }}" />
        <path d="M 158 {{ 152 + $slack }} L 172 {{ 142 + $slack }}" />
        <path d="M 158 {{ 152 + $slack }} L 174 {{ 156 + $slack }}" />
    </g>

    {{-- Sparks. --}}
    <g opacity="{{ $wear }}" fill="var(--boss-glow)">
        <circle cx="150" cy="60" r="3" />
        <circle cx="160" cy="48" r="2" />
        <circle cx="48" cy="66" r="2.5" />
    </g>
</x-boss.frame>
