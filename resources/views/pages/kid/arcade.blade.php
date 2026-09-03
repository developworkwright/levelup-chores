<?php

use App\Models\Profile;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * The arcade, inside the console.
 *
 * It has always existed on the login page, where it is a door before it is a
 * game. This is the same `<livewire:arcade>` component with the kid's shell
 * around it — a page rather than a copy, so the board is one board and a score
 * posted from either side of the login sits in the same table.
 *
 * The eleventh page, and the reason the sheet exists: it belonged to none of
 * the five worlds the old rail was built out of, and there was nowhere to put
 * it. Under the sheet, shipping a page costs one row.
 */
new class extends Component
{
    public Profile $profile;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isKid(), 403);
    }
}; ?>

<x-kid.shell :profile="$profile" active="arcade">
    <livewire:arcade />
</x-kid.shell>
