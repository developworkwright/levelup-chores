{{-- The 16 chore faces.

     Line icons, not characters: a glyph is no more readable to a six-year-old
     than the chore's name, and the face exists precisely for the kids who
     can't read the name. See App\Enums\ChoreIcon for the keys and the keyword
     pass that assigns them.

     Every path is stroke-only on `currentColor`, so one icon inherits gold on
     a bold quest card and lilac in the parent's picker without this file
     knowing either colour exists. Nothing here may take a `fill` — a filled
     shape would stop tracking the text colour and go black on the dark panel.

     Sized by the caller through `class`, never by a width attribute, so the
     ring it sits in stays the thing that decides how big it is. --}}
@props(['icon', 'class' => 'h-12 w-12'])

@php
    $key = $icon instanceof \App\Enums\ChoreIcon ? $icon->value : (string) $icon;

    // Paths only, so the wrapper below owns every shared attribute and no icon
    // can quietly drift on stroke width or cap style.
    $paths = [
        'lawn' => '<path d="M3 20h18"/><path d="M6 20c0-3 .6-5.4 2-7"/><path d="M10 20c0-4 .8-7 2.4-9.4"/><path d="M14.5 20c0-3.2.7-5.8 2-7.6"/><path d="M18.5 20c0-2.2.5-4 1.5-5.4"/>',
        'dishes' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3.4"/><path d="M17.6 6.4 15.4 8.6"/>',
        'laundry' => '<path d="M8.5 3.5 12 6l3.5-2.5L20 6l-1.6 3.4-2-.8V20H7.6V8.6l-2 .8L4 6z"/>',
        'bed' => '<path d="M3 19v-9"/><path d="M3 13h18v6"/><path d="M21 19v-4"/><path d="M6.5 13V9.5h5V13"/><path d="M21 15V9.5a2 2 0 0 0-2-2h-5.5"/>',
        'sweep' => '<path d="M15.5 3.5 9 10"/><path d="M6.5 12.5 11.5 7.5l4 4-5 5z"/><path d="M10.5 16.5 6 21"/><path d="M13 19l-2.5 2"/><path d="M15.5 20.5 14 21.5"/>',
        'pet' => '<circle cx="7" cy="10" r="1.9"/><circle cx="11.5" cy="6.6" r="1.9"/><circle cx="16.5" cy="7.6" r="1.9"/><circle cx="19" cy="12" r="1.7"/><path d="M13 12.5c3 0 5 2.2 5 4.4S15.6 20.5 13 20.5 8 19 8 16.9s2-4.4 5-4.4z"/>',
        'trash' => '<path d="M4 7h16"/><path d="M9.5 7V4.5h5V7"/><path d="M6 7l1 13h10l1-13"/><path d="M10 11v5.5"/><path d="M14 11v5.5"/>',
        'vacuum' => '<circle cx="15.5" cy="15" r="5"/><path d="M15.5 12.6v4.8"/><path d="M15.5 10V6.5a2.5 2.5 0 0 0-2.5-2.5H10"/><path d="M3.5 19.5h6"/><path d="M4.5 19.5l1.5-4h4l-1 4"/>',
        'water' => '<path d="M4 10h9v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><path d="M13 12.5 20 9v8"/><path d="M6.5 10V8a2.5 2.5 0 0 1 5 0v2"/><path d="M20 9l1.5-2.5"/>',
        'table' => '<path d="M3 9.5h18"/><path d="M5.5 9.5V20"/><path d="M18.5 9.5V20"/><path d="M8 5.5h8"/><path d="M12 5.5v4"/>',
        'recycle' => '<path d="M8.4 4.8 12 3l3.6 1.8"/><path d="M12 3.6 15.6 10l3.2-.6"/><path d="M18.4 9.2 21 12l-1.6 3.4"/><path d="M19.6 15.2 12 15.4"/><path d="M12.4 15.4 8.6 21"/><path d="M8.8 21 5 20.4 3.6 17"/><path d="M3.8 17.4 8 10.6"/>',
        'teeth' => '<path d="M6.5 3.5 9 6"/><path d="M4.5 5.5 7 8"/><path d="M4.5 5.5 6.5 3.5"/><path d="M8 7 18 17"/><path d="M17 15.6l2.6 2.6a2 2 0 0 1-2.8 2.8L14.2 18.4z"/>',
        'window' => '<rect x="4" y="4" width="16" height="16" rx="1.5"/><path d="M12 4v16"/><path d="M4 12h16"/><path d="M6.5 7 9 9.5"/>',
        'toys' => '<circle cx="9" cy="9" r="5.5"/><path d="M4 7.5c3.6 1.4 7 1.4 9.8-.4"/><path d="M9 3.5c1.8 3.2 2 6.6.6 9.6"/><rect x="12" y="13" width="8" height="8" rx="1.5"/><path d="M14.5 17h3"/>',
        'car' => '<path d="M3 15.5h18"/><path d="M5 15.5 6.8 9.6A2 2 0 0 1 8.7 8.2h6.6a2 2 0 0 1 1.9 1.4l1.8 5.9"/><path d="M4 15.5V19h2.5v-3.5"/><path d="M20 15.5V19h-2.5v-3.5"/><circle cx="7.8" cy="15.5" r="1.4"/><circle cx="16.2" cy="15.5" r="1.4"/>',
        'mail' => '<rect x="3" y="5.5" width="18" height="13" rx="1.8"/><path d="M3.6 6.6 12 13l8.4-6.4"/>',
    ];
@endphp

@if (isset($paths[$key]))
    <svg
        viewBox="0 0 24 24"
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        stroke="currentColor"
        stroke-width="1.7"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        {{ $attributes->merge(['class' => $class]) }}
    >{!! $paths[$key] !!}</svg>
@endif
