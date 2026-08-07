{{-- Crumbler: everything that ever went down the back of the sofa, holding
     hands. Loses chunks of itself as it takes damage. --}}
@props(['skin', 'stage'])

@php
    $wear = $stage->wear();
@endphp

<x-boss.frame :skin="$skin" :stage="$stage">
    {{-- Stumpy legs. --}}
    <rect x="66" y="156" width="22" height="26" rx="9" fill="var(--boss-shade)" />
    <rect x="112" y="156" width="22" height="26" rx="9" fill="var(--boss-shade)" />

    {{-- A deliberately lumpy silhouette — no two arcs the same radius, so it
         reads as swept together rather than moulded. --}}
    <path
        d="M 46 158
           Q 30 130 44 106
           Q 34 78 60 66
           Q 68 42 96 46
           Q 118 32 134 56
           Q 164 60 158 92
           Q 172 118 154 140
           Q 158 166 128 164
           Q 100 178 74 166
           Q 52 172 46 158 Z"
        fill="var(--boss-body)"
    />

    {{-- Crumbs pressed into the surface. --}}
    <g fill="var(--boss-shade)" opacity="0.6">
        <circle cx="62" cy="120" r="4" />
        <circle cx="72" cy="146" r="3" />
        <circle cx="140" cy="112" r="4.5" />
        <circle cx="128" cy="146" r="3.5" />
        <circle cx="96" cy="64" r="3" />
        <rect x="146" y="128" width="7" height="4" rx="1.5" transform="rotate(24 146 128)" />
        <rect x="54" y="92" width="8" height="4" rx="1.5" transform="rotate(-18 54 92)" />
    </g>

    <x-boss.eye cx="82" cy="98" :r="14" :stage="$stage" />
    <x-boss.eye cx="126" cy="94" :r="12" :stage="$stage" />

    <x-boss.grin :cx="102" :cy="136" :width="66" :teeth="6" :stage="$stage" />

    {{-- Bites taken out of the outline, painted in the page background so they
         read as absence rather than as another crumb. --}}
    <g opacity="{{ $wear }}" fill="var(--fq-panel)">
        <circle cx="46" cy="118" r="13" />
        <circle cx="158" cy="104" r="11" />
        <circle cx="118" cy="168" r="10" />
    </g>

    {{-- The pieces that fell off, on the floor beside it. --}}
    <g opacity="{{ $wear }}" fill="var(--boss-body)">
        <circle cx="34" cy="180" r="5" />
        <circle cx="46" cy="186" r="3.5" />
        <circle cx="170" cy="182" r="4.5" />
    </g>
</x-boss.frame>
