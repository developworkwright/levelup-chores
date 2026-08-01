@props(['entry'])

@php
    $defaults = $entry['effect']->defaults();
    $blocked = $entry['blocked'];
@endphp

<button
    type="button"
    wire:click="usePerk('{{ $entry['effect']->value }}')"
    @disabled($blocked !== null)
    title="{{ $blocked ?? $defaults['description'] }}"
    class="inline-flex items-center gap-2 rounded-[12px] border px-3 py-2 text-xs font-semibold disabled:opacity-40"
    style="border-color: oklch(0.65 0.19 320 / .5); color: var(--fq-magenta); background: var(--fq-sunk)"
>
    <span class="font-baloo text-sm">{{ $defaults['glyph'] }}</span>
    <span>Use {{ $defaults['name'] }}</span>
    @if ($entry['count'] > 1)
        <span class="font-mono-fq text-[10px]">×{{ $entry['count'] }}</span>
    @endif
</button>
