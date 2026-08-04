{{-- The magenta pill that hangs off a nav tab when something is waiting on
     this kid. Geometry is inline rather than in arbitrary-value utilities: a
     count badge that silently degrades to a bare superscript numeral when the
     CSS is a build behind is worse than no badge at all. --}}
@props(['count', 'title' => null, 'small' => false])

@if ($count > 0)
    <span
        class="inline-flex items-center justify-center font-mono-fq font-bold"
        style="background: var(--fq-magenta); color: var(--fq-bg); min-width: {{ $small ? 19 : 20 }}px; height: {{ $small ? 19 : 20 }}px; padding: 0 6px; border-radius: 999px; font-size: {{ $small ? 10 : 11 }}px; line-height: 1"
        @if ($title) title="{{ $title }}" @endif
    >{{ $count }}</span>
@endif
