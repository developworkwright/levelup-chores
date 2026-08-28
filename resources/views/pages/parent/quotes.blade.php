<?php

use App\Enums\ProfileRole;
use App\Models\Profile;
use App\Models\Quote;
use App\Services\QuoteService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * The grown-up side of Quote of the Day — where the funny things get written
 * down, and the only place they can be.
 *
 * Entry is parent-only because the feature depends on somebody being in the
 * room when the line lands; a kid submitting their own quotes is a different
 * feature with a different failure mode. Everything written here is immediately
 * readable by everyone: the day's quotes land on kid Home, the whole archive
 * sits on the kid Journal, and every kid gets a push the moment one is added.
 *
 * Nothing on this screen crowns a winner, and nothing should grow one. Several
 * quotes on a day are contenders and stay contenders — see the migration.
 */
new class extends Component
{
    use WithPagination;

    /** Roughly a screenful of the archive. */
    private const PER_PAGE = 20;

    /** How far back the "when was this said" picker goes. */
    private const BACKDATE_DAYS = 7;

    public Profile $profile;

    public string $newQuote = '';

    /** A kid's profile id as a string, or 'other' for someone without a login. */
    public string $newSpeaker = '';

    public string $newSpeakerName = '';

    public string $newContext = '';

    /** Days back from today. 0 is today, which is nearly always right. */
    public int $newDaysAgo = 0;

    public ?string $flashMessage = null;

    public ?string $savedMessage = null;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();

        abort_unless($this->profile->isParent(), 403);
    }

    /** @return \Illuminate\Support\Collection<int, Profile> */
    private function kids(): \Illuminate\Support\Collection
    {
        return Profile::where('household_id', $this->profile->household_id)
            ->where('role', ProfileRole::Kid)
            ->orderByDesc('age')
            ->get()
            ->collect();
    }

    private function ownedQuote(int $quoteId): ?Quote
    {
        return Quote::where('household_id', $this->profile->household_id)->find($quoteId);
    }

    public function addQuote(): void
    {
        $service = app(QuoteService::class);

        $said = $this->newSpeaker === '' || $this->newSpeaker === 'other'
            ? null
            : $this->kids()->firstWhere('id', (int) $this->newSpeaker);

        $quote = $service->record(
            $this->profile,
            $this->newQuote,
            $said,
            $this->newSpeaker === 'other' ? $this->newSpeakerName : null,
            $this->newContext,
            Carbon::now()->subDays($this->newDaysAgo),
        );

        if (! $quote) {
            $this->flashMessage = 'Type what they said first.';
            $this->savedMessage = null;

            return;
        }

        // Counted back out of the database rather than tracked here, so the
        // confirmation says the same thing the kids are about to read on Home.
        $sameDay = $service->forDay($this->profile->household, $quote->said_on)->count();

        // The day is named whenever it isn't today, and that is the whole point
        // of it: a quote filed under the wrong date vanishes from the kids'
        // Home page without any signal that it went anywhere, and the parent
        // finds out days later. Saying "Saved to Thu 27 Aug" makes a stuck
        // backdate visible in the second it happens.
        $filedToday = $service->isToday($this->profile->household, $quote->said_on);
        $when = $filedToday ? 'today' : $quote->said_on->format('D j M');

        $this->savedMessage = match (true) {
            $sameDay > 1 && $filedToday => "Saved — that's {$sameDay} contenders for today.",
            $sameDay > 1 => "Saved to {$when} — that's {$sameDay} contenders that day.",
            $filedToday => "Saved, and everyone's been told.",
            default => "Saved to {$when}, and everyone's been told.",
        };

        $this->newQuote = '';
        $this->newContext = '';
        $this->newSpeakerName = '';
        // Back to today. Backdating is the exception — a picker left on "3 days
        // ago" after one catch-up quietly misfiles every quote typed after it,
        // which is exactly how a contender ended up on the wrong day.
        $this->newDaysAgo = 0;
        $this->flashMessage = null;
    }

    public function updateQuoteText(int $quoteId, string $value): void
    {
        $value = mb_substr(trim($value), 0, QuoteService::MAX_LENGTH);

        if ($value !== '' && ($quote = $this->ownedQuote($quoteId))) {
            $quote->text = $value;
            $quote->save();
        }
    }

    public function updateQuoteContext(int $quoteId, string $value): void
    {
        if ($quote = $this->ownedQuote($quoteId)) {
            $quote->context = mb_substr(trim($value), 0, QuoteService::MAX_CONTEXT) ?: null;
            $quote->save();
        }
    }

    /**
     * Moves a quote to the day it was actually said on.
     *
     * The archive could edit the words, the context and the speaker but not the
     * date, which made a misfiled quote unfixable without going into the
     * database by hand — and a wrong date is the one mistake here that hides
     * the quote from the kids entirely rather than just reading oddly.
     *
     * Anything that isn't a real Y-m-d is ignored rather than thrown, and a
     * future date is pulled back to today: nothing was said tomorrow.
     */
    public function updateQuoteDate(int $quoteId, string $value): void
    {
        $value = trim($value);

        if (! Carbon::hasFormat($value, 'Y-m-d') || ! $quote = $this->ownedQuote($quoteId)) {
            return;
        }

        $today = app(QuoteService::class)->today($this->profile->household);
        $said = Carbon::createFromFormat('Y-m-d', $value)->startOfDay();

        $quote->said_on = $said->greaterThan($today) ? $today : $said;
        $quote->save();
    }

    /** Fixes a misattributed quote. Null hands it back to the typed-in name. */
    public function setQuoteSpeaker(int $quoteId, ?int $kidId): void
    {
        if (! $quote = $this->ownedQuote($quoteId)) {
            return;
        }

        $quote->profile_id = $kidId === null ? null : $this->kids()->firstWhere('id', $kidId)?->id;
        $quote->save();
    }

    /**
     * The only destructive control here, and it has to exist: a quote is
     * written down in a hurry, and occasionally what gets written down is a
     * typo, the wrong kid, or something that turned out to be less funny than
     * it was mean.
     */
    public function removeQuote(int $quoteId): void
    {
        $this->ownedQuote($quoteId)?->delete();
    }

    public function with(): array
    {
        $service = app(QuoteService::class);
        $household = $this->profile->household;

        $today = $service->today($household);
        $todays = $service->forDay($household, $today);

        return [
            'kids' => $this->kids(),
            'todays' => $todays,
            'todaysHeading' => QuoteService::heading($todays->count()),
            'archive' => $service->archive($household, self::PER_PAGE),
            'total' => $service->countFor($household),
            // "Today", "Yesterday", then plain dates — enough to catch up after
            // a week away without this becoming a calendar widget.
            'days' => collect(range(0, self::BACKDATE_DAYS))->map(fn (int $back) => [
                'back' => $back,
                'label' => match ($back) {
                    0 => 'Today',
                    1 => 'Yesterday',
                    default => $today->copy()->subDays($back)->format('D j M'),
                },
            ]),
            'maxLength' => QuoteService::MAX_LENGTH,
            'maxContext' => QuoteService::MAX_CONTEXT,
            // Caps the archive's date pickers — nothing was said tomorrow.
            'todayDate' => $today->toDateString(),
        ];
    }
}; ?>

<x-parent.shell :profile="$profile" active="quotes">
    <div class="flex flex-col gap-3">
        {{-- The writing box comes first and stays open. Every other parent
             screen hides its editor behind an "Add" button, because those lists
             are read far more often than they are written to. This one is the
             opposite: a quote that takes two taps to start writing is a quote
             that gets forgotten. --}}
        <div class="flex flex-col gap-3 rounded-[28px] border border-fq-line bg-fq-bg p-[16px_14px]">
            <div>
                <h2 class="font-baloo text-xl font-extrabold">Write it down</h2>
                <p class="mt-[3px] text-xs text-fq-text-3">
                    Everyone gets a notification the moment you save. Nothing here is ever ranked —
                    more than one on a day just makes them contenders.
                </p>
            </div>

            <textarea
                wire:model="newQuote"
                rows="2"
                maxlength="{{ $maxLength }}"
                placeholder="What did they say?"
                class="w-full rounded-[14px] border border-fq-line-2 bg-fq-sunk px-[13px] py-[11px] text-sm text-fq-text placeholder:text-fq-text-5 focus:border-fq-line-4 focus:outline-none"
            ></textarea>

            <div class="flex flex-wrap items-center gap-2">
                {{-- Every option carries @selected. Without it the markup never
                     states which one is chosen, so after a re-render the
                     browser's own value and the component's can drift apart —
                     and on the date picker below, drift means quotes silently
                     filed under the wrong day. --}}
                <select
                    wire:model.live="newSpeaker"
                    aria-label="Who said it"
                    class="rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[11px] py-[9px] text-[13px] text-fq-text-2-b focus:border-fq-line-4 focus:outline-none"
                >
                    <option value="" @selected($newSpeaker === '')>Who said it?</option>
                    @foreach ($kids as $kid)
                        <option value="{{ $kid->id }}" @selected($newSpeaker === (string) $kid->id)>{{ $kid->name }}</option>
                    @endforeach
                    <option value="other" @selected($newSpeaker === 'other')>Someone else…</option>
                </select>

                @if ($newSpeaker === 'other')
                    <input
                        wire:model="newSpeakerName"
                        type="text"
                        maxlength="100"
                        placeholder="Their name"
                        class="w-[150px] rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[11px] py-[9px] text-[13px] text-fq-text placeholder:text-fq-text-5 focus:border-fq-line-4 focus:outline-none"
                    />
                @endif

                {{-- Live rather than deferred. This is the one field on the
                     form whose being wrong is invisible — a quote filed under
                     yesterday simply doesn't appear on the kids' Home page —
                     so it is worth a round trip to keep the server and the
                     select in step at all times. --}}
                <select
                    wire:model.live="newDaysAgo"
                    aria-label="When it was said"
                    class="rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[11px] py-[9px] text-[13px] text-fq-text-2-b focus:border-fq-line-4 focus:outline-none"
                    style="{{ $newDaysAgo === 0 ? '' : 'border-color: var(--fq-gold); color: var(--fq-gold)' }}"
                >
                    @foreach ($days as $day)
                        <option value="{{ $day['back'] }}" @selected($newDaysAgo === $day['back'])>{{ $day['label'] }}</option>
                    @endforeach
                </select>

                <button
                    type="button"
                    wire:click="addQuote"
                    class="ml-auto shrink-0 rounded-[11px] px-4 py-[9px] font-baloo text-[13px] font-extrabold transition hover:brightness-110"
                    style="background: var(--fq-fill-gold-soft); color: var(--fq-ink)"
                >Save quote</button>
            </div>

            {{-- Optional, second, and held back from the day's card — which is
                 worth saying on the form, because a parent who thinks the kids
                 read this straight away writes a different sentence than one
                 who knows it keeps until the Journal. --}}
            <input
                wire:model="newContext"
                type="text"
                maxlength="{{ $maxContext }}"
                placeholder="What was going on? (optional — kept for the Journal)"
                class="w-full rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[13px] py-[9px] text-[13px] text-fq-text placeholder:text-fq-text-5 focus:border-fq-line-4 focus:outline-none"
            />

            <p class="text-[11.5px] text-fq-text-5">
                On the kids' Home page the line stands on its own — out of context is funnier.
                The story shows up on their Journal, where they go to read back.
            </p>

            @if ($flashMessage)
                <p class="text-sm font-semibold text-fq-danger">{{ $flashMessage }}</p>
            @elseif ($savedMessage)
                <p class="text-sm font-semibold" style="color: var(--fq-lime)">{{ $savedMessage }}</p>
            @endif
        </div>

        {{-- Today's, under its own name. This is the one place the contender
             count belongs in a heading rather than a caption: it is what a
             parent is checking when they come back to this screen. --}}
        <div class="flex flex-col gap-3 rounded-[28px] border border-fq-line bg-fq-bg p-[16px_14px]">
            <div class="flex flex-wrap items-baseline gap-2">
                <h2 class="font-baloo text-xl font-extrabold">{{ $todaysHeading }}</h2>
                <span class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Today</span>
            </div>

            @if ($todays->isEmpty())
                <p class="text-[13px] text-fq-text-5">Nothing today yet. They'll say something.</p>
            @else
                <div class="flex flex-col gap-2">
                    @foreach ($todays as $quote)
                        {{-- Read-only: this page has no react() and shouldn't.
                             Seeing that both kids laughed is worth a lot to a
                             parent; joining in from the console is not what
                             the row is for. --}}
                        <x-quote-line
                            :quote="$quote"
                            :viewer="$profile"
                            :interactive="false"
                            :show-context="true"
                            wire:key="today-{{ $quote->id }}"
                            class="border-fq-line-2 bg-fq-panel"
                        />
                    @endforeach
                </div>
            @endif
        </div>

        {{-- The archive, which is also the editor. Fixing a quote is rare
             enough not to deserve a mode of its own, and every field here is
             one a parent can get wrong in a hurry: the words, the context, and
             which kid it was. --}}
        <div class="flex flex-col gap-3 rounded-[28px] border border-fq-line bg-fq-bg p-[16px_14px]">
            <div class="flex flex-wrap items-baseline gap-2">
                <h2 class="font-baloo text-xl font-extrabold">Everything ever said</h2>
                <span class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                    {{ number_format($total) }} {{ Str::plural('QUOTE', $total) }}
                </span>
            </div>

            @php $lastDay = null; @endphp

            <div class="flex flex-col gap-2">
                @forelse ($archive as $quote)
                    @if ($lastDay !== $quote->said_on->toDateString())
                        @php
                            $lastDay = $quote->said_on->toDateString();
                            $dayCount = $archive->filter(fn ($row) => $row->said_on->toDateString() === $lastDay)->count();
                        @endphp
                        <p class="mt-2 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-5 uppercase">
                            {{ $quote->said_on->toFormattedDateString() }}
                            @if ($dayCount > 1)
                                <span style="color: var(--fq-gold)">&middot; {{ $dayCount }} contenders</span>
                            @endif
                        </p>
                    @endif

                    <div
                        wire:key="quote-{{ $quote->id }}"
                        class="flex flex-col gap-[8px] rounded-[14px] border border-fq-line-2 bg-fq-panel px-3 py-[10px]"
                    >
                        <input
                            type="text"
                            value="{{ $quote->text }}"
                            maxlength="{{ $maxLength }}"
                            wire:change="updateQuoteText({{ $quote->id }}, $event.target.value)"
                            aria-label="Quote"
                            class="w-full rounded-[10px] border border-transparent bg-transparent px-[6px] py-[4px] font-baloo text-[15px] font-bold text-fq-text hover:border-fq-line-2 focus:border-fq-line-4 focus:bg-fq-sunk focus:outline-none"
                        />

                        <div class="flex flex-wrap items-center gap-2">
                            <select
                                aria-label="Who said it"
                                wire:change="setQuoteSpeaker({{ $quote->id }}, $event.target.value === '' ? null : parseInt($event.target.value))"
                                class="rounded-[10px] border border-fq-line-2 bg-fq-sunk px-[9px] py-[6px] text-[12px] text-fq-text-2-b focus:border-fq-line-4 focus:outline-none"
                            >
                                <option value="" @selected($quote->profile_id === null)>
                                    {{ $quote->said_by ?: 'Someone else' }}
                                </option>
                                @foreach ($kids as $kid)
                                    <option value="{{ $kid->id }}" @selected($quote->profile_id === $kid->id)>{{ $kid->name }}</option>
                                @endforeach
                            </select>

                            {{-- The day, editable. A quote on the wrong date
                                 disappears from the kids' Home page without a
                                 trace, so this is the one field here that has
                                 to be fixable in the app. --}}
                            <input
                                type="date"
                                value="{{ $quote->said_on->toDateString() }}"
                                max="{{ $todayDate }}"
                                wire:change="updateQuoteDate({{ $quote->id }}, $event.target.value)"
                                aria-label="Day it was said"
                                class="shrink-0 rounded-[10px] border border-fq-line-2 bg-fq-sunk px-[9px] py-[6px] font-mono-fq text-[11px] text-fq-text-3 focus:border-fq-line-4 focus:outline-none"
                            />

                            <input
                                type="text"
                                value="{{ $quote->context }}"
                                maxlength="{{ $maxContext }}"
                                placeholder="What was going on?"
                                wire:change="updateQuoteContext({{ $quote->id }}, $event.target.value)"
                                aria-label="Context"
                                class="min-w-[160px] flex-1 rounded-[10px] border border-transparent bg-transparent px-[8px] py-[6px] text-[12.5px] text-fq-text-3 placeholder:text-fq-text-6 hover:border-fq-line-2 focus:border-fq-line-4 focus:bg-fq-sunk focus:outline-none"
                            />

                            <button
                                type="button"
                                wire:click="removeQuote({{ $quote->id }})"
                                wire:confirm="Delete this quote for good?"
                                aria-label="Delete quote"
                                class="shrink-0 rounded-[9px] border border-fq-danger-border px-[10px] py-[6px] text-[11px] text-fq-danger transition hover:bg-fq-danger-bg"
                            ><i aria-hidden="true" class="fa-solid fa-trash"></i></button>
                        </div>
                    </div>
                @empty
                    <div class="rounded-[16px] border border-dashed border-fq-line-3 p-6 text-center">
                        <p class="font-baloo text-base font-bold">Nothing written down yet</p>
                        <p class="mt-1 text-[13px] text-fq-text-5">
                            The next time one of them says something ridiculous, put it in the box above.
                        </p>
                    </div>
                @endforelse

                <x-pager :paginator="$archive" newer="Newer" older="Older" />
            </div>
        </div>
    </div>
</x-parent.shell>
