{{-- One destination in the sheet. ~56px tall at the full width, which is what
     makes the list usable without reading any of it.

     Three states worth naming. The page you are on is gold and says so, so the
     sheet answers "where am I?" as well as "where can I go?". A page that is
     new to the app is green-rimmed until the flag comes off its entry in the
     shell. Everything else is a plain row. --}}
@props(['page', 'current' => false, 'count' => 0, 'tickets' => 0, 'compact' => false])

@php
    $new = ($page['new'] ?? false) && ! $current;

    $style = match (true) {
        $current => 'background: var(--fq-gold-fill); border-color: var(--fq-ticket-line); color: var(--fq-lime)',
        $new => 'background: var(--fq-green-deep); border-color: var(--fq-green); color: var(--fq-green-ink)',
        default => 'border-color: var(--fq-line)',
    };
@endphp

<a
    href="{{ route($page['route']) }}"
    wire:navigate
    @if ($current) aria-current="page" @endif
    class="flex items-center rounded-[13px] border transition hover:border-fq-line-focus {{ $compact ? 'gap-[9px] px-[12px] py-[14px] text-[13.5px]' : 'gap-[11px] px-[13px] py-[14px] text-[14px]' }} {{ $current ? 'font-bold' : '' }}"
    style="{{ $style }}"
>
    <i
        class="fa-solid {{ $page['icon'] }} shrink-0 text-center {{ $compact ? 'text-[14px]' : 'w-[19px] text-[15px]' }}"
        style="color: {{ $current ? 'var(--fq-lime)' : ($new ? 'var(--fq-green)' : $page['accent']) }}"
    ></i>

    <span class="flex-1 truncate">{{ $page['label'] }}</span>

    @if ($current)
        <span class="shrink-0 font-mono-fq text-[8px] tracking-[0.12em] text-fq-ticket-label">YOU'RE HERE</span>
    @elseif ($new)
        <span
            class="shrink-0 rounded-full px-[7px] py-[2px] font-mono-fq text-[8.5px] leading-none font-bold"
            style="background: var(--fq-green); color: var(--fq-ink-green)"
        >NEW</span>
    @elseif ($tickets > 0)
        {{-- Outlined rather than filled: the journal pays you, it isn't a
             backlog. A solid count would read as three more things to do. --}}
        <span
            class="shrink-0 rounded-full border px-[7px] py-[3px] font-mono-fq text-[9px] leading-none"
            style="border-color: var(--fq-green); color: var(--fq-green)"
        >{{ $tickets }} {{ Str::plural('ticket', $tickets) }}</span>
    @else
        <x-count-badge
            :count="$count"
            :word="$page['countWord'] ?? null"
            :title="$count.' '.Str::plural('thing', $count).' waiting on you'"
        />
    @endif
</a>
