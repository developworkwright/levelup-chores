{{-- The face a chore wears.

     Pictorial, not a character glyph: a glyph is no more readable to a
     six-year-old than the chore's name, and the face exists precisely for the
     kids who can't read the name. See App\Enums\ChoreIcon for the presets, the
     keyword pass that assigns them, and the normaliser every custom class goes
     through.

     Font Awesome renders through a font, so the icon inherits `color` from
     whatever it sits in — one icon comes out gold on a bold quest card and
     lilac in the parent's picker without this file knowing either colour
     exists.

     Sized by the caller through `class`, and that means a **font size**
     (`text-[26px]`), not a box: a `<i>` given h-6 w-6 keeps drawing its glyph
     at the inherited size inside a 24px box. The one thing this must never do
     is render nothing — a blank face is unpickable to the kid it exists for —
     so an unusable value draws nothing at all and lets the caller's fallback
     take over. --}}
@props(['icon', 'class' => 'text-[28px]'])

@php
    // Normalised even though everything stored has been through the same pass
    // on the way in: this is the last gate before the value lands in a `class`
    // attribute, and rows predating that pass are still in the table.
    $faClass = \App\Enums\ChoreIcon::normalizeClass(
        $icon instanceof \App\Enums\ChoreIcon ? $icon->faClass() : (string) $icon,
    );
@endphp

@if ($faClass)
    <i
        aria-hidden="true"
        {{ $attributes->merge(['class' => 'fa-fw '.$faClass.' '.$class]) }}
    ></i>
@endif
