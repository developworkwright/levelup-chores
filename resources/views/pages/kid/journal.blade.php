<?php

use App\Models\Profile;
use App\Services\GratitudeService;
use App\Services\QuoteService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * The Journal — the two things this app keeps that aren't worth points.
 *
 * Gratitude is what a kid wrote about their own day; the Quote Wall is what a
 * grown-up wrote down about theirs. Neither is spendable and neither expires,
 * which is exactly why they belong on one page rather than scattered next to
 * the things that are: this is the page you open to read, not to do.
 *
 * Segments rather than two routes. They are the same shape of thing — a dated
 * list nothing ever prunes — and a second nav entry would have cost a rail slot
 * to say so.
 */
new class extends Component
{
    use WithPagination;

    /** A fortnight or so a page — enough to read a stretch in one go. */
    private const PER_PAGE = 15;

    /** Quotes are one line each, so a page of them holds more. */
    private const QUOTES_PER_PAGE = 25;

    public Profile $profile;

    /** 'gratitude' or 'quotes'. */
    public string $tab = 'gratitude';

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();

        abort_unless($this->profile->isKid(), 403);

        // Read off the query string the same way the shell reads ?world=, so
        // Home's "Every quote ever" link and the quote push both land on the
        // right half of the page. Anything unrecognised falls through to
        // gratitude rather than rendering an empty panel.
        if (request()->query('tab') === 'quotes') {
            $this->tab = 'quotes';
        }
    }

    public function showTab(string $tab): void
    {
        $this->tab = in_array($tab, ['gratitude', 'quotes'], true) ? $tab : 'gratitude';
    }

    /** Same call as kid Home's — the scope check lives in the service. */
    public function react(int $quoteId, string $reaction): void
    {
        app(QuoteService::class)->react($this->profile, $quoteId, $reaction);
    }

    public function with(): array
    {
        $gratitude = app(GratitudeService::class);
        $quotes = app(QuoteService::class);
        $household = $this->profile->household;

        $entries = $gratitude->journalFor($this->profile, self::PER_PAGE);

        return [
            'entries' => $entries,
            // Off the paginator rather than a second count query.
            'total' => $entries->total(),
            'ticketsBanked' => $entries->total() * GratitudeService::TICKETS,
            // The nudge only makes sense while today's is still open, and the
            // writing itself stays on Quests — this page is for reading back.
            'writtenToday' => ! $gratitude->isAvailable($this->profile),
            'quotes' => $quotes->archive($household, self::QUOTES_PER_PAGE, 'quotes'),
            'quoteTotal' => $quotes->countFor($household),
        ];
    }
}; ?>

<x-kid.shell :profile="$profile" active="journal">
    @php
        $onQuotes = $tab === 'quotes';
        $accent = $onQuotes ? 'var(--fq-gold)' : 'var(--fq-cyan)';
    @endphp

    <div class="flex flex-col gap-[14px]">
        <div class="rounded-[24px] border p-6" style="background: var(--fq-wash-blue); border-color: var(--fq-line-cool)">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: {{ $accent }}">Kept for good</p>
                    <h2 class="mt-1 font-baloo text-2xl font-extrabold">Journal</h2>
                </div>

                @if ($onQuotes)
                    @if ($quoteTotal > 0)
                        <span class="font-mono-fq text-[11px] text-fq-text-4">
                            {{ number_format($quoteTotal) }} {{ Str::plural('QUOTE', $quoteTotal) }}
                        </span>
                    @endif
                @elseif ($total > 0)
                    <span class="font-mono-fq text-[11px] text-fq-text-4">
                        {{ number_format($total) }} {{ Str::plural('DAY', $total) }} · {{ number_format($ticketsBanked) }} {{ Str::plural('TICKET', $ticketsBanked) }} BANKED
                    </span>
                @endif
            </div>

            {{-- Two segments, not two pages. Both halves are dated lists that
                 nothing ever prunes; the only difference is who did the
                 writing. --}}
            <div class="mt-4 flex gap-2">
                @foreach ([['gratitude', 'Gratitude'], ['quotes', 'Quote Wall']] as [$key, $label])
                    <button
                        type="button"
                        wire:click="showTab('{{ $key }}')"
                        class="rounded-[13px] border px-[15px] py-[9px] font-baloo text-[14px] font-bold transition"
                        style="{{ $tab === $key
                            ? 'background: var(--fq-tab-active); border-color: '.($key === 'quotes' ? 'var(--fq-gold)' : 'var(--fq-cyan)').'; color: var(--fq-text)'
                            : 'background: transparent; border-color: var(--fq-line-3); color: var(--fq-text-4)' }}"
                    >{{ $label }}</button>
                @endforeach
            </div>

            <p class="mt-3 max-w-[520px] text-sm text-fq-text-2">
                @if ($onQuotes)
                    Every ridiculous thing anyone in this house has said, written down by a grown-up
                    before it got forgotten — and here, unlike on Home, you get to find out what was
                    actually going on. More than one on a day and they're all contenders.
                @else
                    Every three things you've been grateful for, kept for good. Nothing here ever
                    gets deleted — scroll back as far as you like.
                @endif
            </p>

            @if (! $onQuotes && ! $writtenToday)
                <a
                    href="{{ route('kid.quests') }}"
                    wire:navigate
                    class="mt-4 inline-flex items-center gap-2 rounded-[14px] px-[18px] py-[11px] font-baloo text-[15px] font-bold transition hover:brightness-110"
                    style="background: var(--fq-cyan); color: var(--fq-ink)"
                >Write today's &rarr;</a>
            @endif
        </div>

        <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
            @if ($onQuotes)
                @php $lastDay = null; @endphp

                <div class="flex flex-col gap-2">
                    @forelse ($quotes as $quote)
                        {{-- The date is a heading over the day rather than a
                             line on every card, because that grouping *is* the
                             contenders: two quotes under one date are the whole
                             idea, and repeating the date on each would hide it. --}}
                        @if ($lastDay !== $quote->said_on->toDateString())
                            @php
                                $lastDay = $quote->said_on->toDateString();
                                $dayCount = $quotes->filter(fn ($row) => $row->said_on->toDateString() === $lastDay)->count();
                            @endphp

                            <p class="mt-2 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-5 uppercase first:mt-0">
                                {{ $quote->said_on->toFormattedDateString() }}
                                @if ($dayCount > 1)
                                    <span style="color: var(--fq-gold)">· {{ $dayCount }} contenders</span>
                                @endif
                            </p>
                        @endif

                        {{-- The Quote Wall is the one kid surface that shows
                             the context. Home is the punchline — out of context
                             is funnier, and an explanation under the day's card
                             kills it — but the archive is where you come to
                             read back, and by then the story behind it is the
                             half worth having. --}}
                        <x-quote-line
                            :quote="$quote"
                            :viewer="$profile"
                            :show-context="true"
                            wire:key="quote-{{ $quote->id }}"
                        />
                    @empty
                        <div class="rounded-[18px] border border-dashed border-fq-line-3 p-8 text-center">
                            <p class="font-baloo text-lg font-bold">Nobody's said anything daft yet</p>
                            <p class="mt-1 text-sm text-fq-text-5">
                                When you say something funny, a grown-up can write it down from their
                                side of the app — and everyone gets told about it.
                            </p>
                        </div>
                    @endforelse

                    <x-pager :paginator="$quotes" page-name="quotes" newer="Newer" older="Older" />
                </div>
            @else
                <div class="flex flex-col gap-2">
                    @forelse ($entries as $entry)
                        <div wire:key="entry-{{ $entry->id }}" class="rounded-[18px] border border-fq-line-2 bg-fq-sunk px-[15px] py-[13px]">
                            <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-5 uppercase">
                                {{ $entry->entry_date->toFormattedDateString() }}
                            </p>

                            <ol class="mt-2 flex flex-col gap-[6px]">
                                @foreach ($entry->items as $index => $item)
                                    <li class="flex items-start gap-[10px]">
                                        <span class="font-baloo text-[13px] font-extrabold" style="color: var(--fq-cyan)">{{ $index + 1 }}</span>
                                        <span class="min-w-0 flex-1 text-sm text-fq-text-2">{{ $item }}</span>
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    @empty
                        <div class="rounded-[18px] border border-dashed border-fq-line-3 p-8 text-center">
                            <p class="font-baloo text-lg font-bold">Nothing written down yet</p>
                            <p class="mt-1 text-sm text-fq-text-5">
                                Name three things you're grateful for on the Quests page — it's worth
                                {{ \App\Services\GratitudeService::TICKETS }} tickets, once a day.
                            </p>
                            <a
                                href="{{ route('kid.quests') }}"
                                wire:navigate
                                class="mt-4 inline-block rounded-[12px] border border-fq-line-3 bg-fq-sunk px-[14px] py-[9px] text-[13px] text-fq-text-2-b transition hover:border-fq-line-4 hover:text-fq-text"
                            >Go to Quests &rarr;</a>
                        </div>
                    @endforelse

                    <x-pager :paginator="$entries" newer="Newer" older="Older" />
                </div>
            @endif
        </div>
    </div>
</x-kid.shell>
