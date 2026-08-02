@props(['class' => 'h-[17px] w-[17px]'])

{{--
    The IEC power symbol as geometry rather than the U+23FB character it used
    to be: that codepoint lives in Miscellaneous Technical, which neither
    Baloo 2 nor the iOS/Android system fonts cover, so on a phone the Exit
    button rendered as a missing-glyph box. Drawn with currentColor so the
    button's existing text/hover colours still drive it.
--}}
<svg
    viewBox="0 0 24 24"
    xmlns="http://www.w3.org/2000/svg"
    class="{{ $class }}"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    aria-hidden="true"
>
    <path d="M7.41 5.45 A 8 8 0 1 0 16.59 5.45" />
    <path d="M12 3 V 11.5" />
</svg>
