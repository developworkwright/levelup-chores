{{-- One eye. The pupil swells with panic and shrinks to a pinprick once the
     monster is beaten, which is `BossStage::eyeScale()` doing the work — the
     shape never changes, only how much of it is pupil. --}}
@props([
    'cx',
    'cy',
    'r' => 13,
    'stage',
    'lidless' => false,
])

@php
    $scale = $stage->eyeScale();
    $pupil = max(1.2, $r * 0.42 * $scale);
    $iris = max($pupil + 1.5, $r * 0.62 * $scale);
@endphp

<g class="fq-boss-eye">
    <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $r }}" fill="var(--boss-teeth)" />
    <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $iris }}" fill="var(--boss-eye)" />
    <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $pupil }}" fill="#0a0512" />

    {{-- The glint is what stops a black dot reading as a hole. It goes out
         when the monster does. --}}
    @unless ($stage->isDefeated())
        <circle
            cx="{{ $cx - $r * 0.3 }}" cy="{{ $cy - $r * 0.32 }}"
            r="{{ max(1.4, $r * 0.16) }}" fill="#fff" opacity="0.9"
        />
    @endunless

    {{-- A heavy lid pulled low is most of what makes a face read as angry
         rather than surprised, so it tracks the stage too. --}}
    @unless ($lidless || $stage->isDefeated())
        <path
            d="M {{ $cx - $r - 1 }} {{ $cy - $r * 0.45 }}
               Q {{ $cx }} {{ $cy - $r * (1.35 - 0.5 * $stage->openness()) }} {{ $cx + $r + 1 }} {{ $cy - $r * 0.45 }}
               L {{ $cx + $r + 1 }} {{ $cy - $r - 2 }}
               L {{ $cx - $r - 1 }} {{ $cy - $r - 2 }} Z"
            fill="var(--boss-shade)"
        />
    @endunless

    {{-- Beaten: the eye crosses out. Nothing else in the set says "done" as
         immediately to a kid who can't read the label yet. --}}
    @if ($stage->isDefeated())
        <path
            d="M {{ $cx - $r * 0.6 }} {{ $cy - $r * 0.6 }} L {{ $cx + $r * 0.6 }} {{ $cy + $r * 0.6 }}
               M {{ $cx + $r * 0.6 }} {{ $cy - $r * 0.6 }} L {{ $cx - $r * 0.6 }} {{ $cy + $r * 0.6 }}"
            stroke="#0a0512" stroke-width="{{ max(2, $r * 0.22) }}" stroke-linecap="round"
        />
    @endif
</g>
