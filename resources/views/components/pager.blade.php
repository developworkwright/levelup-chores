{{-- `pageName` has to match the one the component paginated with, so a page
     carrying two feeds can move one without dragging the other along.

     Deliberately not Laravel's `links()` view: these consoles are dark all the
     way through, and the framework's default pagination markup would land a
     white bar in the middle of one. --}}
@props(['paginator', 'pageName' => 'page', 'newer' => 'Newer', 'older' => 'Older'])

@if ($paginator->hasPages())
    <div class="mt-3 flex items-center justify-between gap-2 border-t border-fq-divider pt-3">
        <button
            type="button"
            wire:click="previousPage('{{ $pageName }}')"
            @disabled($paginator->onFirstPage())
            class="rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[13px] py-[8px] text-[13px] text-fq-text-2-b transition hover:border-fq-line-4 hover:text-fq-text disabled:cursor-default disabled:opacity-35 disabled:hover:border-fq-line-2"
        >&larr; {{ $newer }}</button>

        <span class="text-center font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
            Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}
            <span class="text-fq-text-6">· {{ number_format($paginator->total()) }} total</span>
        </span>

        <button
            type="button"
            wire:click="nextPage('{{ $pageName }}')"
            @disabled(! $paginator->hasMorePages())
            class="rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[13px] py-[8px] text-[13px] text-fq-text-2-b transition hover:border-fq-line-4 hover:text-fq-text disabled:cursor-default disabled:opacity-35 disabled:hover:border-fq-line-2"
        >{{ $older }} &rarr;</button>
    </div>
@endif
