<?php

use App\Models\Profile;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * The arcade cabinet, on the grown-ups' side.
 *
 * The same `<livewire:arcade>` the kids get, around the parent shell — a page
 * rather than a copy, so there is one board and a score posted from either
 * console lands on it. That is the whole feature: a parent on the board is
 * somebody for the kids to beat.
 *
 * They cannot win the prize. Topping the week pays three bonus tickets, and
 * tickets are the kids' currency — see ArcadeService::PRIZE_TICKETS. A parent
 * who tops it takes the week and nothing else.
 */
new class extends Component
{
    public Profile $profile;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();

        abort_unless($this->profile->isParent(), 403);
    }
}; ?>

<x-parent.shell :profile="$profile" active="arcade">
    <div class="flex flex-col gap-3 rounded-[28px] border border-fq-line bg-fq-bg p-[16px_14px]">
        {{-- Deliberately no game name here. There are two cabinets and the
             switcher inside owns which one is showing; a heading naming one of
             them would be wrong half the time. --}}
        <p class="text-xs text-fq-text-3">
            One board per game, for the whole house. Your runs sit on them next to theirs — the
            kids can see exactly how you did, which is the point.
        </p>

        <livewire:arcade />
    </div>
</x-parent.shell>
