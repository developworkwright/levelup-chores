@props(['badge', 'earned', 'secret' => false, 'size' => 'sm'])

@php
    $tier = $badge->tier();

    [$box, $glyphSize, $nudge, $glow] = $size === 'lg'
        ? ['50px', '17px', '4px', '0 0 20px -6px']
        : ['38px', '13px', '3px', '0 0 16px -4px'];

    // Only the animated tier gets an oversized fill, since the drift works by
    // sliding a gradient three times the star's width across it.
    $animated = $earned && $tier->isAnimated();
@endphp

<div
    {{ $attributes->class([
        'grid flex-shrink-0 place-items-center font-baloo font-extrabold',
        'fq-rainbow-star' => $animated,
    ]) }}
    style="
        width: {{ $box }};
        height: {{ $box }};
        font-size: {{ $glyphSize }};
        clip-path: var(--fq-star);
        background: {{ $earned ? $tier->fillVar() : 'var(--fq-badge-empty)' }};
        @if ($animated) background-size: 300% 100%; @endif
        color: {{ $earned ? 'var(--fq-ink)' : 'var(--fq-badge-empty-fg)' }};
        filter: {{ $earned ? "drop-shadow({$glow} {$tier->glowVar()})" : 'none' }};
    "
>
    {{-- The star's optical centre sits below its geometric one, so the glyph
         needs nudging down to look centred inside the points. --}}
    <span style="margin-top: {{ $nudge }}">{{ $secret ? '?' : $badge->glyph }}</span>
</div>
