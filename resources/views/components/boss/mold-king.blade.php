{{-- The Mold King: crowned himself at the back of the fridge. Three eyes, a
     fuzzy scalloped body, and drips that grow as he goes. --}}
@props(['skin', 'stage'])

@php
    $wear = $stage->wear();
    $drip = 6 + 20 * $wear;
@endphp

<x-boss.frame :skin="$skin" :stage="$stage">
    {{-- The crown, which is the joke: he is king of nothing anyone wants. --}}
    <path
        d="M 68 52 L 74 24 L 90 42 L 100 16 L 110 42 L 126 24 L 132 52 Z"
        fill="var(--boss-eye)"
    />
    <rect x="66" y="50" width="68" height="9" rx="4" fill="var(--boss-eye)" />
    <circle cx="100" cy="34" r="4" fill="var(--boss-shade)" opacity="0.5" />

    {{-- A scalloped edge all the way round: fuzz, in silhouette. --}}
    <path
        d="M 100 58
           C 138 58 166 84 166 116
           C 166 152 136 176 100 176
           C 64 176 34 152 34 116
           C 34 84 62 58 100 58 Z"
        fill="var(--boss-body)"
    />
    <g fill="var(--boss-body)">
        <circle cx="42" cy="90" r="11" />
        <circle cx="34" cy="120" r="12" />
        <circle cx="44" cy="150" r="11" />
        <circle cx="158" cy="90" r="11" />
        <circle cx="166" cy="120" r="12" />
        <circle cx="156" cy="150" r="11" />
        <circle cx="70" cy="66" r="10" />
        <circle cx="130" cy="66" r="10" />
        <circle cx="100" cy="172" r="12" />
    </g>

    {{-- Spore blooms, the flat rings mold makes on a wall. --}}
    <g fill="var(--boss-shade)" opacity="0.55">
        <circle cx="66" cy="140" r="9" />
        <circle cx="136" cy="132" r="7" />
        <circle cx="118" cy="158" r="5" />
    </g>

    {{-- Three eyes in a triangle: the third one is what stops him reading as
         a friendly blob. --}}
    <x-boss.eye cx="80" cy="104" :r="13" :stage="$stage" />
    <x-boss.eye cx="124" cy="104" :r="13" :stage="$stage" />
    <x-boss.eye cx="102" cy="80" :r="9" :stage="$stage" lidless />

    <x-boss.grin :cx="100" :cy="140" :width="62" :teeth="6" :stage="$stage" />

    {{-- Drips off the bottom edge, longer the further gone he is. --}}
    <g fill="var(--boss-body)">
        <path d="M 72 172 Q 68 {{ 172 + $drip }} 76 {{ 174 + $drip }} Q 82 {{ 172 + $drip }} 80 172 Z" />
        <path d="M 118 174 Q 114 {{ 174 + $drip * 1.3 }} 122 {{ 176 + $drip * 1.3 }} Q 128 {{ 174 + $drip * 1.3 }} 126 174 Z" />
    </g>
    <circle cx="76" cy="{{ 178 + $drip }}" r="{{ 2 + 3 * $wear }}" fill="var(--boss-body)" opacity="{{ $wear }}" />

    {{-- Bleached patches where the cleaning actually landed. --}}
    <g opacity="{{ $wear }}" fill="var(--boss-teeth)">
        <ellipse cx="58" cy="112" rx="10" ry="7" opacity="0.5" />
        <ellipse cx="146" cy="146" rx="8" ry="6" opacity="0.45" />
        <ellipse cx="104" cy="166" rx="9" ry="5" opacity="0.4" />
    </g>
</x-boss.frame>
