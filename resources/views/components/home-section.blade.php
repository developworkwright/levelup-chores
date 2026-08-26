{{-- The header on one of Home's cards.

     Home reads top to bottom in the order the day usually goes — quest, chests,
     spin, standings — but that is a habit rather than a rule, and nothing on the
     page is gated on anything above it. So these are headers, not steps: no
     numbers to imply a sequence a kid has to obey, just a coloured mark, the
     name of the thing, and the one-line answer to "where am I on this" out on
     the right where the four of them line up as a column. --}}
@props([
    'title',
    'accent' => 'var(--fq-lime)',
    'done' => false,
    'status' => null,
    'statusColor' => null,
])

<div class="flex flex-wrap items-center gap-3">
    <span class="h-[22px] w-[5px] shrink-0 rounded-full" style="background: {{ $accent }}" aria-hidden="true"></span>

    <h2 class="font-baloo text-[21px] leading-none font-extrabold sm:text-[24px]">{{ $title }}</h2>

    @if ($status)
        <span
            class="ml-auto rounded-full px-[11px] py-[5px] font-mono-fq text-[10px] font-semibold tracking-[0.14em] whitespace-nowrap uppercase"
            style="background: var(--fq-panel-alt); color: {{ $statusColor ?? 'var(--fq-text-4)' }}"
        >{{ $done ? '✓ ' : '' }}{{ $status }}</span>
    @endif
</div>
