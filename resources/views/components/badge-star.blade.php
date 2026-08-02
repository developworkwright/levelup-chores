@props(['badge', 'earned', 'secret' => false, 'size' => 'sm'])

@php
    /**
     * The metal a badge is struck from reads straight off its XP reward, so a
     * harder achievement always looks more valuable without anyone having to
     * hand-assign a tier.
     */
    $tier = match (true) {
        $badge->xp_reward >= 250 => 'gold',
        $badge->xp_reward >= 150 => 'silver',
        default => 'amethyst',
    };

    [$box, $glyphSize, $nudge, $glow] = $size === 'lg'
        ? ['50px', '17px', '4px', '0 0 20px -6px']
        : ['38px', '13px', '3px', '0 0 16px -4px'];
@endphp

<div
    {{ $attributes->merge(['class' => 'grid flex-shrink-0 place-items-center font-baloo font-extrabold']) }}
    style="
        width: {{ $box }};
        height: {{ $box }};
        font-size: {{ $glyphSize }};
        clip-path: var(--fq-star);
        background: {{ $earned ? "var(--fq-tier-{$tier})" : 'var(--fq-badge-empty)' }};
        color: {{ $earned ? 'var(--fq-ink)' : 'var(--fq-badge-empty-fg)' }};
        filter: {{ $earned ? "drop-shadow({$glow} var(--fq-tier-{$tier}-glow))" : 'none' }};
    "
>
    {{-- The star's optical centre sits below its geometric one, so the glyph
         needs nudging down to look centred inside the points. --}}
    <span style="margin-top: {{ $nudge }}">{{ $secret ? '?' : $badge->glyph }}</span>
</div>
