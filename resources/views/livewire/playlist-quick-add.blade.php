<?php

use App\Models\Playlist;
use App\Models\Profile;
use App\Services\PlaylistService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

/**
 * The server half of "put this song in a playlist" from the header's player.
 *
 * It draws nothing. The panel above it is Alpine reading the music store —
 * the rail, the track list and the sheet that asks which playlist — because
 * none of that can be Livewire: the header survives `wire:navigate` and the
 * store outlives the page. But a playlist lives in the database, so the one
 * moment that actually changes something has to reach a server, and this is
 * the smallest thing that can hear it.
 *
 * Hence a global `Livewire.dispatch` rather than a `$wire` call: the panel is
 * drawn by the *shell*, so `$wire` there is whatever page happens to be under
 * it — kid.quests, parent.loot, anything. A broadcast event is the one way in
 * that does not depend on which page a kid was standing on when they heard a
 * song they liked.
 *
 * @see PlaylistService for the rules; nothing here re-decides any of them.
 * @see resources/js/music.js for the panel state this answers.
 */
new class extends Component
{
    /**
     * Add a song to one of this profile's playlists.
     *
     * Both halves of the answer go back to the browser as events, because the
     * store — not this component — is what draws the result: `playlists-updated`
     * refreshes the ids the panel ticks its rows against, and the spoken line
     * is the only way a refusal can say anything at all, this component having
     * no markup to say it in.
     */
    #[On('quick-add-song')]
    public function addSong(?int $playlistId, ?string $trackId): void
    {
        $playlist = $this->playlist($playlistId);

        if ($playlist === null || $trackId === null) {
            return;
        }

        $service = app(PlaylistService::class);

        if (! $service->add($playlist, $trackId)) {
            $this->dispatch(
                'playlist-add-said',
                message: $playlist->tracks()->count() >= PlaylistService::MAX_TRACKS
                    ? $playlist->name.' is full at '.PlaylistService::MAX_TRACKS.' songs.'
                    : 'It is already in '.$playlist->name.'.',
                ok: false,
            );

            return;
        }

        $this->dispatch(
            'playlists-updated',
            playlists: $service->payloadFor($this->profile()),
        );

        $this->dispatch('playlist-add-said', message: 'Added to '.$playlist->name.'.', ok: true);

        /*
         * And the builder, if it happens to be on screen. A kid adding from the
         * header while standing on the music page would otherwise watch the
         * list below it not change. Its own name rather than the event above,
         * because the builder dispatches that one itself and a component that
         * listened for its own announcement would answer it forever.
         */
        $this->dispatch('playlist-touched');
    }

    /**
     * Whoever is signed in, off the guard.
     *
     * @see playlist-builder — the id comes from a browser and the lists are per
     *      profile precisely so nobody edits anybody else's.
     */
    private function profile(): Profile
    {
        $profile = Auth::guard('profile')->user();

        abort_unless($profile instanceof Profile, 403);

        return $profile;
    }

    private function playlist(?int $id): ?Playlist
    {
        if ($id === null) {
            return null;
        }

        return Playlist::where('profile_id', $this->profile()->id)->find($id);
    }
}; ?>

{{-- Nothing to draw: the panel is Alpine. Livewire needs one root element,
     and `hidden` rather than no element at all so it is findable in the DOM
     when this ever needs debugging. --}}
<div class="hidden" aria-hidden="true"></div>
