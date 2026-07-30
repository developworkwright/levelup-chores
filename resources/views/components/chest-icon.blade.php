@props(['class' => 'h-24 w-24', 'accent' => 'var(--fq-gold)', 'style' => ''])

<svg viewBox="0 0 120 100" xmlns="http://www.w3.org/2000/svg" class="{{ $class }}" style="{{ $style }}">
    <path d="M10 45 Q10 15 60 15 Q110 15 110 45 Z" fill="{{ $accent }}" />
    <rect x="10" y="45" width="100" height="45" rx="10" fill="{{ $accent }}" />
    <rect x="10" y="42" width="100" height="6" fill="#0d1020" opacity="0.25" />
    <rect x="48" y="38" width="24" height="26" rx="5" fill="#171c38" />
    <circle cx="60" cy="48" r="4.5" fill="{{ $accent }}" />
    <rect x="58" y="48" width="4" height="9" fill="{{ $accent }}" />
</svg>
