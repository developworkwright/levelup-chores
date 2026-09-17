{{-- A person, as a letter.

     There are no photographs in this app and there is not going to be a way to
     add one, so a member is identified by three things at once: a letter, a
     colour and a position. Colton is six and cannot read "Colton" at a glance,
     but he knows which one is pink.

     A kid who has bought a face and a frame in the locker wears them here too,
     held still: a feed of twelve blinking avatars is a worse page than a still
     one. The letter goes only when a face replaces it. --}}
@props([
    'profile' => null,
    'letter' => null,
    'accent' => null,
    'size' => 36,
    'radius' => 11,
    'text' => 14,
])

@php
    $initial = $letter ?? mb_substr($profile?->name ?? '?', 0, 1);
    $color = $accent ?? $profile?->color?->cssVar() ?? 'var(--fq-text-3)';
    $cosmetics = $profile?->household_id ? app(App\Services\CosmeticService::class) : null;
    $face = $cosmetics?->wornIn($profile, App\Enums\CosmeticSlot::Avatar);
    $ring = $cosmetics?->wornIn($profile, App\Enums\CosmeticSlot::Frame);
@endphp

{{-- Merged rather than written out, so a caller can overlap these into a stack
     with its own `style` without silently producing a second style attribute
     the browser then ignores. --}}
<span
    {{ $attributes
        ->class(['relative grid shrink-0 place-items-center border border-fq-line-3 font-baloo font-extrabold'])
        ->style([
            'width: '.$size.'px',
            'height: '.$size.'px',
            'border-radius: '.$radius.'px',
            'font-size: '.$text.'px',
            'background: var(--fq-line)',
            'color: '.$color,
        ]) }}
    aria-hidden="true"
>@unless ($face){{ $initial }}@endunless<x-cosmetic.face :avatar="$face" :frame="$ring" avatar-inset="8%" frame-inset="-3px" motion="none" /></span>
