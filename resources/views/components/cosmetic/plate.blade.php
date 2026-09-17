{{-- A name on the plate a kid bought. The name is the element's own text, so it
     reads before any script runs; the plate is painted by <fq-plate>. --}}
@props(['item'])

<fq-plate
    {{ $attributes }}
    @if ($item->isUpload())
        src="{{ $item->artUrl() }}"
    @else
        recipe="{{ $item->recipe }}"
    @endif
>{{ $slot }}</fq-plate>
