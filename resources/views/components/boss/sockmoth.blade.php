{{-- The Sockmoth: six eyes, ragged wings, and a body made of odd socks. Sheds
     dust as it is worn down. --}}
@props(['skin', 'stage'])

@php
    $wear = $stage->wear();
    // Wings droop and close as the moth tires.
    $spread = 1 - 0.35 * $wear;
@endphp

<x-boss.frame :skin="$skin" :stage="$stage">
    <g opacity="0.92">
        <path
            d="M 96 78 Q {{ 30 - 10 * $spread }} {{ 34 + 16 * (1 - $spread) }} {{ 18 - 6 * $spread }} 96
               Q {{ 14 - 4 * $spread }} 152 96 128 Z"
            fill="var(--boss-body)"
        />
        <path
            d="M 104 78 Q {{ 170 + 10 * $spread }} {{ 34 + 16 * (1 - $spread) }} {{ 182 + 6 * $spread }} 96
               Q {{ 186 + 4 * $spread }} 152 104 128 Z"
            fill="var(--boss-body)"
        />

        {{-- Wing markings: two false eyes, the oldest trick a moth has. --}}
        <ellipse cx="52" cy="96" rx="13" ry="17" fill="var(--boss-shade)" opacity="0.75" />
        <ellipse cx="148" cy="96" rx="13" ry="17" fill="var(--boss-shade)" opacity="0.75" />
        <circle cx="52" cy="96" r="5" fill="var(--boss-eye)" opacity="0.8" />
        <circle cx="148" cy="96" r="5" fill="var(--boss-eye)" opacity="0.8" />
    </g>

    {{-- Antennae. --}}
    <path d="M 90 54 Q 74 32 62 28" fill="none" stroke="var(--boss-shade)" stroke-width="4" stroke-linecap="round" />
    <path d="M 110 54 Q 126 32 138 28" fill="none" stroke="var(--boss-shade)" stroke-width="4" stroke-linecap="round" />
    <circle cx="61" cy="27" r="5" fill="var(--boss-shade)" />
    <circle cx="139" cy="27" r="5" fill="var(--boss-shade)" />

    {{-- The sock body: banded, because every one of them came off a foot. --}}
    <path d="M 82 56 Q 100 44 118 56 L 124 150 Q 100 172 76 150 Z" fill="var(--boss-shade)" />
    <path d="M 80 92 L 121 92 M 79 112 L 122 112 M 78 132 L 123 132"
          stroke="var(--boss-body)" stroke-width="5" opacity="0.55" stroke-linecap="round" />

    {{-- Six eyes, two rows. The middle pair sits lower so the cluster reads as
         a face rather than a pattern. --}}
    <x-boss.eye cx="88" cy="74" :r="8" :stage="$stage" lidless />
    <x-boss.eye cx="112" cy="74" :r="8" :stage="$stage" lidless />
    <x-boss.eye cx="80" cy="92" :r="6" :stage="$stage" lidless />
    <x-boss.eye cx="100" cy="96" :r="7" :stage="$stage" lidless />
    <x-boss.eye cx="120" cy="92" :r="6" :stage="$stage" lidless />
    <x-boss.eye cx="100" cy="60" :r="5" :stage="$stage" lidless />

    {{-- A small round mouth, which is all a moth needs to look wrong. --}}
    <ellipse cx="100" cy="126" rx="{{ 6 + 8 * $stage->openness() }}" ry="{{ 5 + 10 * $stage->openness() }}" fill="#12101c" />
    <ellipse cx="100" cy="126" rx="{{ 3 + 4 * $stage->openness() }}" ry="{{ 2 + 5 * $stage->openness() }}" fill="var(--boss-eye)" opacity="0.35" />

    {{-- Wing dust, falling harder the more of the moth is gone. --}}
    <g opacity="{{ $wear }}" fill="var(--boss-glow)">
        <circle cx="40" cy="140" r="3" opacity="0.8" />
        <circle cx="58" cy="158" r="2.4" opacity="0.6" />
        <circle cx="150" cy="146" r="3" opacity="0.75" />
        <circle cx="166" cy="164" r="2" opacity="0.5" />
        <circle cx="120" cy="168" r="2.6" opacity="0.6" />
    </g>

    {{-- Tears in the wing edges. --}}
    <g opacity="{{ $wear }}">
        <path d="M 18 108 L 34 102 L 20 118 L 36 116" fill="none" stroke="var(--boss-shade)" stroke-width="4" />
        <path d="M 182 108 L 166 102 L 180 118 L 164 116" fill="none" stroke="var(--boss-shade)" stroke-width="4" />
    </g>
</x-boss.frame>
