{{-- The constellations a kid has earned, scattered across the night sky behind
     their pages.

     This is what finishing a picture is *for*. Before it existed, a completed
     constellation produced a toast and a ledger row and then nothing — a week
     of nights with no evidence afterwards that any of it had happened.

     Deliberately faint and behind everything. It should read as their sky
     having got fuller, not as decoration competing with the page.

     `constellations` is SleepService::earnedConstellations(). --}}
@props(['constellations'])

@if ($constellations !== [])
    @php
        // Scattered by a hash of the picture's own index, so a constellation
        // sits in the same place every time a kid opens the app — a sky that
        // rearranged itself on every page load would be no sky at all.
        $placed = collect($constellations)->map(function ($constellation, $index) {
            $seed = crc32($constellation->value.'-'.$index);

            return [
                'constellation' => $constellation,
                'left' => 6 + ($seed % 78),
                'top' => 8 + (intdiv($seed, 97) % 74),
                'size' => 90 + ($seed % 60),
                // Older pictures sit further back, so the newest is the one the
                // eye finds first.
                'opacity' => 0.18 + min(0.22, $index * 0.03),
            ];
        });
    @endphp

    <div class="fq-sky" aria-hidden="true">
        @foreach ($placed as $item)
            @php $shape = $item['constellation']->stars(); @endphp

            <svg
                viewBox="0 0 100 100"
                style="position:absolute; left:{{ $item['left'] }}%; top:{{ $item['top'] }}%; width:{{ $item['size'] }}px; height:{{ $item['size'] }}px; opacity:{{ $item['opacity'] }}"
                xmlns="http://www.w3.org/2000/svg"
            >
                @for ($i = 1; $i < count($shape); $i++)
                    <line
                        x1="{{ $shape[$i - 1][0] }}" y1="{{ $shape[$i - 1][1] }}"
                        x2="{{ $shape[$i][0] }}" y2="{{ $shape[$i][1] }}"
                        stroke="var(--fq-cyan)" stroke-width="0.7" opacity="0.7"
                    />
                @endfor

                @foreach ($shape as [$x, $y])
                    <circle cx="{{ $x }}" cy="{{ $y }}" r="2" fill="var(--fq-lime)" />
                @endforeach
            </svg>
        @endforeach
    </div>
@endif
