<?php

use App\Models\Profile;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * Family, on the kids' side.
 *
 * The same `<livewire:family-feed>` the grown-ups get, inside the kid shell —
 * a page rather than a copy, so there is one set of rooms and a message posted
 * from either console lands in the same one. See the component for the layout
 * and FeedRoom::readableBy() for who can open what.
 *
 * The reason this page exists at all: the kids have this app and no phones, no
 * accounts and nowhere to say anything to each other.
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

<x-kid.shell :profile="$profile" active="family">
    <livewire:family-feed />
</x-kid.shell>
