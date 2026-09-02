{{-- The pill that hangs off a nav row when something is waiting on this kid.

     Coral rather than one of the six accents, and dark ink on top of it: a kid
     whose own colour is violet must never look like a waiting trade.

     Geometry is inline rather than in arbitrary-value utilities: a count badge
     that silently degrades to a bare superscript numeral when the CSS is a
     build behind is worse than no badge at all. --}}
@props(['count', 'title' => null, 'small' => false, 'word' => null])

@if ($count > 0)
    <span
        class="inline-flex shrink-0 items-center justify-center font-mono-fq font-bold whitespace-nowrap"
        style="background: var(--fq-count); color: var(--fq-count-ink); border-radius: 999px; padding: {{ $small ? '2px 6px' : '3px 7px' }}; font-size: {{ $small ? 8 : 9 }}px; line-height: 1.3"
        @if ($title) title="{{ $title }}" @endif
    >{{ $count }}{{ $word ? ' '.$word : '' }}</span>
@endif
