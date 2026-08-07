{{-- Dustwyrm: a long grey worm of hoover fluff with a ring of little eyes.
     Loses its tail segments as it takes damage, so it visibly shortens. --}}
@props(['skin', 'stage'])

@php
    $wear = $stage->wear();

    // The body is a run of circles down an S-curve. The tail end drops off
    // first, which is why the count falls as the wear rises.
    $segments = [
        ['cx' => 100, 'cy' => 84, 'r' => 30],
        ['cx' => 84, 'cy' => 116, 'r' => 25],
        ['cx' => 104, 'cy' => 142, 'r' => 21],
        ['cx' => 86, 'cy' => 164, 'r' => 16],
        ['cx' => 104, 'cy' => 180, 'r' => 11],
    ];

    $kept = max(2, (int) round(count($segments) - 3 * $wear));
@endphp

<x-boss.frame :skin="$skin" :stage="$stage">
    {{-- Tail first so each segment overlaps the one behind it. --}}
    @foreach (array_reverse(array_slice($segments, 0, $kept)) as $segment)
        <circle
            cx="{{ $segment['cx'] }}" cy="{{ $segment['cy'] }}" r="{{ $segment['r'] }}"
            fill="var(--boss-shade)"
        />
        <circle
            cx="{{ $segment['cx'] }}" cy="{{ $segment['cy'] }}" r="{{ $segment['r'] - 4 }}"
            fill="var(--boss-body)"
        />
    @endforeach

    {{-- Things it has swallowed, showing through the fluff. --}}
    <g fill="var(--boss-shade)" opacity="0.7">
        <rect x="76" y="110" width="12" height="4" rx="2" transform="rotate(-28 76 110)" />
        <circle cx="108" cy="140" r="4" />
        <rect x="96" y="152" width="9" height="3.5" rx="1.5" transform="rotate(40 96 152)" />
    </g>

    {{-- Fluff standing off the head, drawn as spokes. --}}
    <g stroke="var(--boss-body)" stroke-width="4" stroke-linecap="round" opacity="0.8">
        <path d="M 74 62 L 62 50" />
        <path d="M 100 54 L 100 38" />
        <path d="M 126 62 L 138 50" />
        <path d="M 70 84 L 54 82" />
        <path d="M 130 84 L 146 82" />
    </g>

    {{-- A ring of small eyes around the head, plus a larger pair at the front.
         The ring is what makes it unpleasant; the pair is what makes it a face. --}}
    <x-boss.eye cx="88" cy="78" :r="9" :stage="$stage" lidless />
    <x-boss.eye cx="112" cy="78" :r="9" :stage="$stage" lidless />
    <x-boss.eye cx="78" cy="94" :r="5" :stage="$stage" lidless />
    <x-boss.eye cx="122" cy="94" :r="5" :stage="$stage" lidless />
    <x-boss.eye cx="100" cy="64" :r="5" :stage="$stage" lidless />

    {{-- A round sucking mouth rather than a grin — it does not bite, it
         inhales. --}}
    <circle cx="100" cy="102" r="{{ 7 + 9 * $stage->openness() }}" fill="#12101c" />
    <circle cx="100" cy="102" r="{{ 4 + 6 * $stage->openness() }}" fill="var(--boss-eye)" opacity="0.3" />
    <g stroke="var(--boss-teeth)" stroke-width="2" stroke-linecap="round" opacity="0.85">
        @for ($i = 0; $i < 8; $i++)
            @php
                $angle = $i * M_PI / 4;
                $inner = 4 + 6 * $stage->openness();
                $outer = $inner + 4;
            @endphp
            <path
                d="M {{ 100 + cos($angle) * $inner }} {{ 102 + sin($angle) * $inner }}
                   L {{ 100 + cos($angle) * $outer }} {{ 102 + sin($angle) * $outer }}"
            />
        @endfor
    </g>

    {{-- Shed fluff on the floor, from the segments it has already lost. --}}
    <g opacity="{{ $wear }}" fill="var(--boss-body)">
        <circle cx="140" cy="180" r="6" />
        <circle cx="152" cy="188" r="4" />
        <circle cx="56" cy="184" r="5" />
    </g>
</x-boss.frame>
