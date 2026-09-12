<?php

use App\Models\Profile;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * Family, on the grown-ups' side.
 *
 * The same `<livewire:family-feed>` the kids get, around the parent shell. Not
 * an administration screen: there is no moderation queue here, nothing to
 * approve and no report button, because the house was asked and chose full
 * trust. What a parent gets is exactly what a kid gets — the rooms they are in.
 *
 * Which is not all of them. A direct message between two kids is not readable
 * here, and that is deliberate and was confirmed before it shipped: the room
 * header promised those two it was just them, and a promise a header made is
 * not something a later screen gets to quietly take back. See
 * FeedRoom::readableBy().
 *
 * The Kids room *is* open over here, and says so on its own header every time
 * anybody opens it — a quieter room, not a secret one.
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

<x-parent.shell :profile="$profile" active="family">
    <livewire:family-feed />
</x-parent.shell>
