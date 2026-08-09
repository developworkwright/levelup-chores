{{-- The flat chest of the Loot Tray handoff — a rounded box with a band and a
     lock, no image assets.

     Its innards are proportions rather than pixels so one component covers
     every size the design asks for (54x42 in the tray, 76x60 in the quest
     hero, and the smaller mobile pair), and the caller only ever sets the box.

     `x-chest-icon` is the drawn chest, with a lid and a keyhole, for anything
     that wants one at full size; this is the graphic, sized to sit in a row. --}}
@props([
    'fill',
    'band' => 'rgba(10, 5, 18, 0.55)',
    'lock' => 'rgba(10, 5, 18, 0.7)',
    'radius' => '10px',
    'width' => null,
    'height' => null,
])

{{-- Size normally comes from a utility class on the caller. width/height are
     for the one place that can't: the streak track, where every chest is a
     different size and the sizes are computed, so there is no class to write. --}}
@php
    $box = 'border-radius: '.$radius.'; background: '.$fill
        .($width ? '; width: '.$width : '')
        .($height ? '; height: '.$height : '');
@endphp

<div
    {{ $attributes->merge(['class' => 'relative shrink-0']) }}
    style="{{ $box }}"
>
    <div class="absolute inset-x-0" style="top: 38%; height: 11%; background: {{ $band }}"></div>
    <div
        class="absolute left-1/2 -translate-x-1/2"
        style="top: 31%; height: 27%; aspect-ratio: 1; border-radius: 14%; background: {{ $lock }}"
    ></div>
</div>
