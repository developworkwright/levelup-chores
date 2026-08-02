@props(['entry'])

@php
    $defaults = $entry['effect']->defaults();
    $blocked = $entry['blocked'];
@endphp

{{-- Perks are bought with tickets, so they wear the same brushed-steel as the
     Bonus Shop rather than the purple everything else lives in. --}}
<button
    type="button"
    wire:click="usePerk('{{ $entry['effect']->value }}')"
    @disabled($blocked !== null)
    title="{{ $blocked ?? $defaults['description'] }}"
    class="inline-flex h-[42px] items-center gap-2 rounded-[12px] border px-[14px] text-xs font-semibold whitespace-nowrap transition hover:brightness-125 disabled:opacity-40"
    style="border-color: var(--fq-steel-edge); color: var(--fq-steel-text); background: var(--fq-steel-panel)"
>
    <span class="font-baloo text-sm">{{ $defaults['glyph'] }}</span>
    <span>Use {{ $defaults['name'] }}</span>
    @if ($entry['count'] > 1)
        <span class="font-mono-fq text-[10px]">×{{ $entry['count'] }}</span>
    @endif
</button>
