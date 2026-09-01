<?php

use App\Models\Profile;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * Music — where a kid builds the lists the header plays.
 *
 * The page is only the shell and the door: everything on it is the shared
 * playlist builder, which the parent console draws too. See that component for
 * why building a list is a page rather than something in the header's picker.
 *
 * Nothing here is worth points, tickets or XP, and that is on purpose — see the
 * shell for why this page sits under Me. It is the one screen in the app that
 * is only for the kid's own enjoyment.
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

<x-kid.shell :profile="$profile" active="music">
    <livewire:playlist-builder audience="kid" />
</x-kid.shell>
