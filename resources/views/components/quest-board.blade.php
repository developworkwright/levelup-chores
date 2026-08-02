@props(['class' => ''])

{{-- The gold rail both consoles hang their tabs from — a solid bar of the
     points colour, so the navigation reads as part of the cabinet rather than
     another dark panel. --}}
<div
    {{ $attributes->merge(['class' => 'mt-2 rounded-[18px] p-2 '.$class]) }}
    style="background: var(--fq-rail); box-shadow: var(--fq-shadow-rail)"
>
    <div class="flex flex-wrap gap-[7px]">
        {{ $slot }}
    </div>
</div>
