{{-- Too many teeth, in a mouth that opens further the worse things are going.

     The teeth are drawn deliberately oversized and then clipped to the mouth
     outline, which is what makes them read as set into the lips rather than
     floating in the hole. --}}
@props([
    'cx' => 100,
    'cy' => 140,
    'width' => 74,
    'teeth' => 7,
    'stage',
])

@php
    $open = $stage->openness();

    // Unique per render: two monsters (or a replay's stack of stages) on one
    // page would otherwise share a clip path id, and every mouth after the
    // first would be clipped to the shape of the first.
    $id = 'fq-grin-'.Str::random(10);

    $half = $width / 2;
    $x0 = $cx - $half;
    $x1 = $cx + $half;

    $up = 6 + 12 * $open;
    $down = 8 + 34 * $open;
    $toothW = $width / $teeth;
    $toothH = 5 + 9 * $open;

    $outline = "M {$x0} {$cy} Q {$cx} ".($cy - $up)." {$x1} {$cy} Q {$cx} ".($cy + $down)." {$x0} {$cy} Z";
@endphp

<g>
    <defs>
        <clipPath id="{{ $id }}">
            <path d="{{ $outline }}" />
        </clipPath>
    </defs>

    <path d="{{ $outline }}" fill="#1a0510" />

    <g clip-path="url(#{{ $id }})">
        @for ($i = 0; $i < $teeth; $i++)
            @php
                $tx = $x0 + $i * $toothW;
                $mid = $tx + $toothW / 2;
                $topBase = $cy - $up - 2;
                $bottomBase = $cy + $down + 2;
            @endphp

            <polygon
                points="{{ $tx }},{{ $topBase }} {{ $tx + $toothW }},{{ $topBase }} {{ $mid }},{{ $topBase + $toothH + 4 }}"
                fill="var(--boss-teeth)"
            />
            <polygon
                points="{{ $tx }},{{ $bottomBase }} {{ $tx + $toothW }},{{ $bottomBase }} {{ $mid }},{{ $bottomBase - $toothH - 4 }}"
                fill="var(--boss-teeth)"
            />
        @endfor
    </g>

    <path d="{{ $outline }}" fill="none" stroke="var(--boss-shade)" stroke-width="2.5" stroke-linejoin="round" />
</g>
