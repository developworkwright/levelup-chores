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
 * The picker itself is a whole player now — rail, track list and transport,
 * from handoff/design_handoff_music_page — and this page is what its
 * `+ New playlist` button points at. Browsing and playing happen in the panel,
 * on whatever page you are already on; building happens here, where there is
 * room to lay the library out and put songs in a list one tap at a time.
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
