<?php

use App\Enums\LedgerKind;
use App\Models\LedgerEntry;
use App\Models\Profile;
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
            LedgerKind::Spend => 'oklch(0.7 0.14 25)',
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
                        <span class="font-mono-fq text-[11px] whitespace-nowrap">{{ $entry->amount > 0 ? '+' : '' }}{{ $entry->amount }}</span>
                    @endif
                </div>
            @empty
                <p class="py-4 text-sm text-fq-text-5">Nothing logged yet.</p>
            @endforelse
        </div>
    </div>
</x-parent.shell>
