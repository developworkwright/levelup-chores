<?php

use App\Enums\ProfileRole;
use App\Models\Profile;
use App\Services\GratitudeService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Gratitude — every list the kids have written, for the grown-ups.
 *
 * Activity shows the last few, which is the right amount for "what did they say
 * this week". This is the rest of it: paged rather than capped, and filterable
 * to one kid, so a parent can read a single child's year back.
 *
 * Read-only. The lists belong to the kids who wrote them, and the grown-ups
 * read everything here the way they always could on Activity — including a
 * list kept from the siblings, which is labelled so, since that choice was
 * about the house and not about the adults.
 */
new class extends Component
{
    use WithPagination;

    private const PER_PAGE = 20;

    public Profile $profile;

    /** One kid's lists only, or null for the whole house. */
    #[Url(as: 'kid')]
    public ?int $kidId = null;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isParent(), 403);
    }

    public function showKid(?int $kidId): void
    {
        // Only a kid in this house; anything else falls back to everyone.
        $this->kidId = $kidId && $this->profile->household->profiles()->where('role', ProfileRole::Kid)->whereKey($kidId)->exists()
            ? $kidId
            : null;

        $this->resetPage();
    }

    public function with(): array
    {
        $household = $this->profile->household;

        return [
            'kids' => $household->profiles()->where('role', ProfileRole::Kid)->orderBy('name')->get(),
            'entries' => app(GratitudeService::class)->archiveForHousehold($household, $this->kidId, self::PER_PAGE),
        ];
    }
}; ?>

<x-parent.shell :profile="$profile" active="gratitude">
    <div class="rounded-[22px] border p-[18px]" style="background: var(--fq-wash-blue); border-color: var(--fq-line-cool)">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h1 class="font-baloo text-2xl font-extrabold">Grateful For</h1>
            <span class="font-mono-fq text-[10px] text-fq-text-4">EVERY ENTRY · NEWEST FIRST</span>
        </div>

        <p class="mt-1 text-sm text-fq-text-3">
            Three things a day, from each of the kids. Nothing here is ever deleted.
        </p>

        {{-- Whose lists. Pills rather than a select: the house is a handful of
             kids, and one tap beats opening a dropdown to read three names. --}}
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ([null => 'Everyone'] + $kids->pluck('name', 'id')->all() as $id => $name)
                @php $active = ($id ?: null) === $kidId; @endphp

                <button
                    type="button"
                    wire:key="gratitude-kid-{{ $id ?: 'all' }}"
                    wire:click="showKid({{ $id ?: 'null' }})"
                    aria-pressed="{{ $active ? 'true' : 'false' }}"
                    @class([
                        'rounded-full border px-[13px] py-[6px] text-[13px] transition',
                        'border-fq-cyan text-fq-cyan' => $active,
                        'border-fq-line-2 bg-fq-sunk text-fq-text-3 hover:border-fq-line-4' => ! $active,
                    ])
                >{{ $name }}</button>
            @endforeach
        </div>

        <div class="mt-4 flex flex-col gap-2">
            @forelse ($entries as $entry)
                <div wire:key="gratitude-{{ $entry->id }}" class="rounded-[16px] border border-fq-line-2 bg-fq-sunk px-[14px] py-[11px]">
                    <p class="flex flex-wrap items-center gap-x-2 font-mono-fq text-[10px] text-fq-text-5">
                        <span>{{ $entry->profile?->name }} · {{ $entry->entry_date->toFormattedDateString() }}</span>

                        @unless ($entry->shared)
                            <span class="rounded-full border border-fq-line-3 px-[7px] py-[1px] uppercase">Kept from the house</span>
                        @endunless
                    </p>

                    <ol class="mt-[6px] flex flex-col gap-1">
                        @foreach ($entry->items as $index => $item)
                            <li class="flex items-start gap-2 text-sm">
                                <span class="font-baloo text-[12px] font-extrabold" style="color: var(--fq-cyan)">{{ $index + 1 }}</span>
                                <span class="min-w-0 flex-1 text-fq-text-2">{{ $item }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @empty
                <p class="py-2 text-sm text-fq-text-5">
                    Nothing yet. Kids bank {{ \App\Services\GratitudeService::TICKETS }} tickets for naming three
                    things they're grateful for, once a day, from Home.
                </p>
            @endforelse
        </div>

        <x-pager :paginator="$entries" />
    </div>
</x-parent.shell>
