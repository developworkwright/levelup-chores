<?php

use App\Enums\LedgerKind;
use App\Models\BonusTicketEntry;
use App\Models\LedgerEntry;
use App\Models\Profile;
use App\Services\HouseholdClock;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public Profile $profile;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isParent(), 403);
    }

    private function dotColor(LedgerKind $kind): string
    {
        return match ($kind) {
            LedgerKind::Earn => 'var(--fq-lime)',
            LedgerKind::Spend => 'var(--fq-negative)',
            LedgerKind::CashIn, LedgerKind::CashOut => 'var(--fq-cyan)',
            LedgerKind::Adjustment => 'var(--fq-violet)',
        };
    }

    public function with(): array
    {
        return [
            'entries' => LedgerEntry::where('household_id', $this->profile->household_id)
                ->latest('created_at')
                ->limit(24)
                ->get(),
            // Its own card rather than mixed into the ledger above — tickets
            // are a separate currency, and summing the two columns together
            // would be meaningless.
            'ticketEntries' => BonusTicketEntry::where('household_id', $this->profile->household_id)
                ->with('profile')
                ->latest('created_at')
                ->latest('id')
                ->limit(16)
                ->get(),
        ];
    }
}; ?>

<x-parent.shell :profile="$profile" active="activity">
    <div class="rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
        <div class="flex items-center justify-between">
            <h2 class="font-baloo text-xl font-bold">Activity Log</h2>
            <span class="font-mono-fq text-[10px] text-fq-text-4">NEWEST FIRST</span>
        </div>

        <div class="mt-2">
            @forelse ($entries as $entry)
                <div wire:key="entry-{{ $entry->id }}" class="flex items-center gap-3 border-b border-fq-divider py-[12px_2px]">
                    <span class="h-2 w-2 shrink-0 rounded-full" style="background:{{ $this->dotColor($entry->kind) }}"></span>
                    <span class="flex-1 text-sm">{{ $entry->description }}</span>
                    @if ($entry->amount !== 0)
                        <span
                            class="font-mono-fq text-[11px] whitespace-nowrap"
                            style="color: {{ $entry->amount < 0 ? 'var(--fq-negative-2)' : 'var(--fq-text)' }}"
                        >{{ $entry->amount > 0 ? '+' : '' }}{{ $entry->amount }}</span>
                    @endif
                </div>
            @empty
                <p class="py-4 text-sm text-fq-text-5">Nothing logged yet.</p>
            @endforelse
        </div>
    </div>

    <div class="mt-[14px] rounded-[22px] border border-fq-ticket-line p-[18px]" style="background: var(--fq-panel)">
        <div class="flex items-center justify-between">
            <h2 class="font-baloo text-xl font-bold">Ticket Activity</h2>
            <span class="font-mono-fq text-[10px] text-fq-text-4">NEWEST FIRST</span>
        </div>

        <div class="mt-2">
            @forelse ($ticketEntries as $entry)
                <div wire:key="ticket-{{ $entry->id }}" class="flex items-center gap-3 border-b border-fq-divider py-[12px_2px]">
                    <span class="h-2 w-2 shrink-0 rounded-full" style="background: {{ $entry->amount > 0 ? 'var(--fq-lime)' : 'var(--fq-negative-2)' }}"></span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm">{{ $entry->description }}</p>
                        <p class="font-mono-fq text-[10px] text-fq-text-5">
                            {{ $entry->profile->name }} · {{ $entry->kind->label() }} · {{ $entry->created_at->diffForHumans() }}
                        </p>
                    </div>
                    <span
                        class="font-mono-fq text-[11px] whitespace-nowrap"
                        style="color: {{ $entry->amount < 0 ? 'var(--fq-negative-2)' : 'var(--fq-text)' }}"
                    >{{ $entry->amount > 0 ? '+' : '' }}{{ $entry->amount }}</span>
                </div>
            @empty
                <p class="py-4 text-sm text-fq-text-5">No tickets yet. They arrive on level-ups, badges and daily chests.</p>
            @endforelse
        </div>
    </div>
</x-parent.shell>
