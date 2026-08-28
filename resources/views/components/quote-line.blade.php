{{-- One quote, as everyone who isn't editing it sees it: the words, who said
     them, what was going on at the time, and who laughed.

     Shared by kid Home, the kid Journal's Quote Wall and the parent console's
     "today" panel, so the three can't drift into disagreeing about whether the
     context line or the attribution comes first.

     `viewer` switches the reaction row on — pass null (the parent console's
     editable archive rows) and it draws nothing. `interactive` is what makes
     them buttons: it needs a Livewire host with a `react($quoteId, $kind)`
     method, which kid Home and the Journal both have and the parent page
     deliberately does not.

     `showContext` is off by default, and that default is the joke. On Home the
     quote stands on its own: half of these are only funny *because* you don't
     know what was going on, and an explanation under the day's card is the one
     thing guaranteed to kill it.

     It is switched on for the Journal's Quote Wall and the parent console. The
     archive is somewhere you go to read back rather than to be caught by a
     punchline, and by then the story behind the line is the half worth having. --}}
@props([
    'quote',
    'accent' => 'var(--fq-gold)',
    'viewer' => null,
    'interactive' => true,
    'showContext' => false,
])

<div {{ $attributes->merge(['class' => 'rounded-[16px] border border-fq-line-2 bg-fq-sunk px-[14px] py-[12px]']) }}>
    <p class="font-baloo text-[17px] leading-snug font-bold">&ldquo;{{ $quote->text }}&rdquo;</p>

    <p class="mt-[5px] font-mono-fq text-[10px] tracking-[0.12em] uppercase" style="color: {{ $accent }}">
        &mdash; {{ $quote->attribution() }}
    </p>

    @if ($showContext && $quote->context)
        <p class="mt-[6px] text-[12.5px] text-fq-text-3">{{ $quote->context }}</p>
    @endif

    @if ($viewer)
        @php $reactions = \App\Services\QuoteService::reactionSummary($quote, $viewer); @endphp

        {{-- All four faces, always — the row is the control as well as the
             readout, and a kid who has to find a hidden "add reaction" button
             before they can laugh at their brother won't bother.

             A face nobody has tapped sits dim and countless; one that has been
             tapped lights up and names the people, because "Mabel" is the part
             worth reading and a bare 2 isn't. --}}
        <div class="mt-[10px] flex flex-wrap items-center gap-[6px]">
            @foreach ($reactions as $reaction)
                @php
                    $kind = $reaction['kind'];
                    $hit = $reaction['count'] > 0;
                    $title = $hit ? $reaction['who'] : $kind->label();
                @endphp

                @if ($interactive)
                    <button
                        type="button"
                        wire:click="react({{ $quote->id }}, '{{ $kind->value }}')"
                        title="{{ $title }}"
                        aria-label="{{ $kind->label() }}{{ $hit ? ' — '.$reaction['who'] : '' }}"
                        aria-pressed="{{ $reaction['mine'] ? 'true' : 'false' }}"
                        class="flex items-center gap-[5px] rounded-full border px-[9px] py-[4px] text-[13px] leading-none transition hover:brightness-125 {{ $hit ? '' : 'opacity-45 hover:opacity-100' }}"
                        style="border-color: {{ $reaction['mine'] ? $accent : 'var(--fq-line-2)' }};
                               background: {{ $reaction['mine'] ? 'var(--fq-tab-active)' : 'transparent' }}"
                    >
                        <span aria-hidden="true">{{ $kind->emoji() }}</span>
                        @if ($hit)
                            <span class="font-mono-fq text-[10px] text-fq-text-3">{{ $reaction['count'] }}</span>
                        @endif
                    </button>
                @elseif ($hit)
                    <span
                        title="{{ $title }}"
                        class="flex items-center gap-[5px] rounded-full border border-fq-line-2 px-[9px] py-[4px] text-[13px] leading-none"
                    >
                        <span aria-hidden="true">{{ $kind->emoji() }}</span>
                        <span class="font-mono-fq text-[10px] text-fq-text-3">{{ $reaction['count'] }}</span>
                    </span>
                @endif
            @endforeach
        </div>
    @endif
</div>
