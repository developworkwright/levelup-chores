<?php

use App\Models\Profile;
use App\Services\GratitudeService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    /** A fortnight or so a page — enough to read a stretch in one go. */
    private const PER_PAGE = 15;

    public Profile $profile;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();

        abort_unless($this->profile->isKid(), 403);
    }

    public function with(): array
    {
        $gratitude = app(GratitudeService::class);

        $entries = $gratitude->journalFor($this->profile, self::PER_PAGE);

        return [
            'entries' => $entries,
            // Off the paginator rather than a second count query.
            'total' => $entries->total(),
            'ticketsBanked' => $entries->total() * GratitudeService::TICKETS,
            // The nudge only makes sense while today's is still open, and the
            // writing itself stays on Quests — this page is for reading back.
            'writtenToday' => ! $gratitude->isAvailable($this->profile),
        ];
    }
}; ?>

<x-kid.shell :profile="$profile" active="journal">
    <div class="flex flex-col gap-[14px]">
        <div class="rounded-[24px] border p-6" style="background: var(--fq-wash-blue); border-color: var(--fq-line-cool)">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: var(--fq-cyan)">Gratitude</p>
                    <h2 class="mt-1 font-baloo text-2xl font-extrabold">Journal</h2>
                </div>
                @if ($total > 0)
                    <span class="font-mono-fq text-[11px] text-fq-text-4">
                        {{ number_format($total) }} {{ Str::plural('DAY', $total) }} · {{ number_format($ticketsBanked) }} {{ Str::plural('TICKET', $ticketsBanked) }} BANKED
                    </span>
                @endif
            </div>

            <p class="mt-2 max-w-[520px] text-sm text-fq-text-2">
                Every three things you've been grateful for, kept for good. Nothing here ever
                gets deleted — scroll back as far as you like.
            </p>

            @unless ($writtenToday)
                <a
                    href="{{ route('kid.quests') }}"
                    wire:navigate
                    class="mt-4 inline-flex items-center gap-2 rounded-[14px] px-[18px] py-[11px] font-baloo text-[15px] font-bold transition hover:brightness-110"
                    style="background: var(--fq-cyan); color: var(--fq-ink)"
                >Write today's &rarr;</a>
            @endunless
        </div>

        <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
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
        </div>
    </div>
</x-kid.shell>
