{{-- Every monster's outer shell: the canvas, the palette, and the idle
     breathing. A skin supplies only its own silhouette, so all six behave the
     same way as they take damage and none of them can drift.

     The palette and stage both arrive as CSS custom properties rather than as
     baked-in attribute values, because the parts inside (eyes, grin, wear) are
     shared across skins and would otherwise each need every colour passed
     down through them. --}}
@props(['skin', 'stage'])

@php
    $palette = $skin->palette();
@endphp

<div
    class="fq-boss"
    style="
        --boss-body: {{ $palette['body'] }};
        --boss-shade: {{ $palette['shade'] }};
        --boss-glow: {{ $palette['glow'] }};
        --boss-teeth: {{ $palette['teeth'] }};
        --boss-eye: {{ $palette['eye'] }};
        --boss-breath: {{ $stage->breathSeconds() }}s;
        --boss-tilt: {{ $stage->tilt() }}deg;
    "
    role="img"
    aria-label="{{ $skin->label() }}, {{ $stage->label() }}"
>
    <svg viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
        {{-- A pool of shadow, so the monster stands in the room rather than
             floating in front of it. Shrinks as the boss is worn down. --}}
        <ellipse
            cx="100" cy="188"
            rx="{{ 62 - 18 * $stage->wear() }}" ry="9"
            fill="#000" opacity="0.35"
        />

        <g class="fq-boss-body">
            {{ $slot }}
        </g>
    </svg>
</div>
