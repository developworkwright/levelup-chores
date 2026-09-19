<?php

use App\Models\Profile;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * The arcade, on the grown-ups' side.
 *
 * The same `<livewire:arcade>` the kids get, around the parent shell — a page
 * rather than a copy, so there is one board and a score posted from either
 * console lands on it. That is the whole feature: a parent on the board is
 * somebody for the kids to beat.
 *
 * They cannot win the prize. Topping the week pays arcade tokens, and tokens
 * are the kids' — see ArcadeService::PRIZE_TOKENS. A parent who tops it takes
 * the week, and the tokens go to the best kid below them. Nor do their runs pay
 * tokens, and they have no prize counter tab; the sweets on it are stocked from
 * the Candy page.
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
        {{-- Deliberately no game name here. There are several games and the
             switcher inside owns which one is showing; a heading naming one of
             them would be wrong most of the time. --}}
        <p class="text-xs text-fq-text-3">
            One board per game, for the whole house. Your runs sit on them next to theirs — the
            kids can see exactly how you did, which is the point.
        </p>

        <livewire:arcade />
    </div>
</x-parent.shell>
