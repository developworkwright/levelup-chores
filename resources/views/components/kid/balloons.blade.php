{{-- Balloons, drifting up behind every page, on a celebration day and no other.

     The same idea as <x-kid.sky> and it obeys the same rules: fixed, behind the
     content, faint, and no help to anybody who is trying to read the page. The
     job is that the app looks different the moment it opens — a kid should know
     something is going on before they have read a word of it.

     Positions and timings are derived from the index rather than random, so the
     balloons don't rearrange themselves on every Livewire round trip. A page
     that reshuffles its own background every time a button is pressed reads as
     a glitch.

     `colors` lets a future celebration pick its own; the default is the app's
     own palette. --}}
@props([
    'colors' => ['var(--fq-magenta)', 'var(--fq-gold)', 'var(--fq-cyan)', 'var(--fq-lime)', 'var(--fq-coral)', 'var(--fq-violet)'],
    'count' => 12,
])

@php
    $balloons = collect(range(0, $count - 1))->map(function (int $index) use ($colors) {
        $seed = crc32('fq-balloon-'.$index);

        return [
            'color' => $colors[$index % count($colors)],
            'left' => 3 + ($seed % 93),
            // Staggered so they aren't a wave: some are already halfway up the
            // screen when the page loads.
            'delay' => -1 * ($seed % 24),
            'duration' => 20 + ($seed % 14),
            'size' => 34 + ($seed % 26),
            'sway' => 6 + ($seed % 10),
            // Where it hangs when the OS has asked for less movement, since
            // nothing is carrying it up the page in that case.
            'still' => 6 + (intdiv($seed, 31) % 76),
        ];
    });
@endphp

<div class="fq-balloons" aria-hidden="true">
    @foreach ($balloons as $balloon)
        <div
            class="fq-balloon"
            style="
                left: {{ $balloon['left'] }}%;
                width: {{ $balloon['size'] }}px;
                animation-duration: {{ $balloon['duration'] }}s;
                animation-delay: {{ $balloon['delay'] }}s;
                --fq-balloon-still: {{ $balloon['still'] }};
            "
        >
            <div class="fq-balloon-sway" style="--fq-balloon-sway: {{ $balloon['sway'] }}px; animation-duration: {{ $balloon['sway'] / 2 + 3 }}s">
                <svg viewBox="0 0 40 62" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:100%; height:auto; display:block">
                    {{-- The string first, so the balloon sits over the knot. --}}
                    <path d="M20 44 C 16 50, 24 54, 20 61" stroke="{{ $balloon['color'] }}" stroke-width="1.2" stroke-linecap="round" opacity="0.55" />
                    <path d="M20 2 C 30 2, 36 11, 36 21 C 36 32, 27 42, 20 44 C 13 42, 4 32, 4 21 C 4 11, 10 2, 20 2 Z" fill="{{ $balloon['color'] }}" />
                    {{-- The knot. --}}
                    <path d="M17 43.5 L 23 43.5 L 20 47 Z" fill="{{ $balloon['color'] }}" />
                    {{-- The highlight, which is most of what makes it read as a
                         balloon rather than a coloured blob. --}}
                    <ellipse cx="14" cy="15" rx="4" ry="6" fill="#fff" opacity="0.32" transform="rotate(-20 14 15)" />
                </svg>
            </div>
        </div>
    @endforeach
</div>
