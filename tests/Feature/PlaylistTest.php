<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\Profile;
use App\Services\MusicService;
use App\Services\PlaylistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Playlists — one profile's own running order through the music library.
 *
 * The library is a folder of mp3s with no table behind it, so most of what is
 * worth testing here is the seam between the two: what happens to a list when
 * the song it names is renamed, deleted, or was never there.
 *
 * The builder is one component drawn in both consoles, so it is driven here by
 * its own name rather than through whichever page happens to host it.
 */
class PlaylistTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<int, string>  $filenames */
    private function library(array $filenames): void
    {
        foreach ($filenames as $filename) {
            Storage::disk('music')->put($filename, 'not really an mp3');
        }
    }

    private function loginKid(): Profile
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->count(2)->create();

        Auth::guard('profile')->login($kid);

        return $kid;
    }

    private function loginParent(): Profile
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->for($household)->parent()->create();

        Auth::guard('profile')->login($parent);

        return $parent;
    }

    private function service(): PlaylistService
    {
        return app(PlaylistService::class);
    }

    public function test_a_kid_can_make_a_playlist_and_put_songs_in_it(): void
    {
        $this->library(['Mossy_Save_Point.mp3', 'Snowglobe_Ruins.mp3']);
        $kid = $this->loginKid();

        Volt::test('playlist-builder')
            ->set('newName', 'Bangers')
            ->call('createPlaylist')
            ->call('addSong', 'snowglobe-ruins')
            ->call('addSong', 'mossy-save-point');

        $playlist = Playlist::where('profile_id', $kid->id)->firstOrFail();

        $this->assertSame('Bangers', $playlist->name);
        // In the order they were added, not the order the library sorts them.
        $this->assertSame(
            ['Snowglobe Ruins', 'Mossy Save Point'],
            $playlist->tracks->pluck('title')->all(),
        );
    }

    public function test_a_song_can_only_be_in_a_playlist_once(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $kid = $this->loginKid();
        $playlist = Playlist::factory()->create(['profile_id' => $kid->id]);

        $this->assertTrue($this->service()->add($playlist, 'mossy-save-point'));
        $this->assertFalse($this->service()->add($playlist, 'mossy-save-point'));

        $this->assertSame(1, $playlist->tracks()->count());
    }

    public function test_a_song_that_is_not_in_the_library_never_gets_in(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $kid = $this->loginKid();
        $playlist = Playlist::factory()->create(['profile_id' => $kid->id]);

        // The id arrives from a browser. An entry naming a file that never
        // existed is one no rename or delete will ever come looking for.
        $this->assertFalse($this->service()->add($playlist, 'a-song-nobody-has'));

        $this->assertSame(0, $playlist->tracks()->count());
    }

    public function test_songs_can_be_moved_up_and_down(): void
    {
        $this->library(['One.mp3', 'Two.mp3', 'Three.mp3']);
        $kid = $this->loginKid();
        $playlist = Playlist::factory()->create(['profile_id' => $kid->id]);

        foreach (['one', 'two', 'three'] as $id) {
            $this->service()->add($playlist, $id);
        }

        Volt::test('playlist-builder')->call('moveSong', $playlist->id, 'three', -1);

        $this->assertSame(['One', 'Three', 'Two'], $playlist->fresh()->tracks->pluck('title')->all());

        Volt::test('playlist-builder')->call('moveSong', $playlist->id, 'one', 1);

        $this->assertSame(['Three', 'One', 'Two'], $playlist->fresh()->tracks->pluck('title')->all());
    }

    public function test_the_ends_of_a_playlist_do_not_wrap_around(): void
    {
        $this->library(['One.mp3', 'Two.mp3']);
        $kid = $this->loginKid();
        $playlist = Playlist::factory()->create(['profile_id' => $kid->id]);

        $this->service()->add($playlist, 'one');
        $this->service()->add($playlist, 'two');

        $this->service()->move($playlist->fresh(), 'one', -1);

        $this->assertSame(['One', 'Two'], $playlist->fresh()->tracks->pluck('title')->all());
    }

    public function test_removing_a_song_closes_the_gap_it_left(): void
    {
        $this->library(['One.mp3', 'Two.mp3', 'Three.mp3']);
        $kid = $this->loginKid();
        $playlist = Playlist::factory()->create(['profile_id' => $kid->id]);

        foreach (['one', 'two', 'three'] as $id) {
            $this->service()->add($playlist, $id);
        }

        Volt::test('playlist-builder')->call('removeSong', $playlist->id, 'two');

        // Positions stay 1..n, so nothing is ordered by a number that drifts
        // further from its row every time something is taken out.
        $this->assertSame([1, 2], $playlist->fresh()->tracks->pluck('position')->all());
        $this->assertSame(['One', 'Three'], $playlist->fresh()->tracks->pluck('title')->all());
    }

    public function test_a_song_can_be_taken_out_from_the_library_row_that_put_it_in(): void
    {
        // The two halves of editing a list used to be at opposite ends of the
        // page: in from the library at the bottom, out from the playlist at the
        // top. Undoing the tap you just made meant scrolling past everything
        // you had already added.
        $this->library(['Mossy_Save_Point.mp3', 'Snowglobe_Ruins.mp3']);
        $kid = $this->loginKid();
        $playlist = Playlist::factory()->create(['profile_id' => $kid->id]);

        Volt::test('playlist-builder')
            ->call('addSong', 'mossy-save-point')
            ->call('addSong', 'snowglobe-ruins')
            // The row that says a song is in the list is the button that takes
            // it out again — there is no second control for it.
            ->assertSee('Take Mossy Save Point out of this playlist')
            ->call('dropSong', 'mossy-save-point')
            ->assertDontSee('Take Mossy Save Point out of this playlist')
            ->assertSee('Add Mossy Save Point');

        $this->assertSame(['Snowglobe Ruins'], $playlist->fresh()->tracks->pluck('title')->all());
        // Positions still start at one, the same as taking it out from the top.
        $this->assertSame([1], $playlist->fresh()->tracks->pluck('position')->all());
    }

    public function test_the_library_only_takes_songs_out_of_a_list_its_owner_has_open(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $kid = $this->loginKid();
        $sibling = Profile::factory()->for($kid->household)->create();

        $theirs = Playlist::factory()->create(['profile_id' => $sibling->id]);
        $this->service()->add($theirs, 'mossy-save-point');

        // Nothing of this kid's is open — they have no lists at all — so the
        // row has no list to act on and the sibling's is not reachable from
        // here by any id, because dropSong() takes none.
        Volt::test('playlist-builder')->call('dropSong', 'mossy-save-point');

        $this->assertSame(1, $theirs->fresh()->tracks->count());
    }

    public function test_a_song_can_be_heard_before_it_goes_in_the_playlist(): void
    {
        // The library is a hundred files named after the game they came out of,
        // so choosing between two of them is guesswork until one is playing.
        $this->library(['Mossy_Save_Point.mp3']);
        $kid = $this->loginKid();

        // A list has to be open for the library to be drawn at all — mount()
        // opens the only one there is.
        Playlist::factory()->create(['profile_id' => $kid->id]);

        Volt::test('playlist-builder')
            // The row's own play button, reaching into the same store the
            // header plays from, so testing a song is never a second thing
            // making noise.
            ->assertSee('$store.music.preview($el.dataset.track)', false)
            ->assertSee('data-track="mossy-save-point"', false)
            // And the bar above the library that says where in the song it is.
            ->assertSee('Seek through the song you are testing')
            ->assertSee('$store.music.scrubTo($event.target.value)', false)
            ->assertSee('$store.music.seek($event.target.value)', false);
    }

    public function test_the_preview_bar_is_only_drawn_where_there_is_a_library_under_it(): void
    {
        // No playlist open, no library, and so nothing for a play button to
        // start — a transport bar with nothing to play is a control that has
        // to explain itself.
        $this->library(['Mossy_Save_Point.mp3']);
        $this->loginKid();

        Volt::test('playlist-builder')->assertDontSee('Seek through the song you are testing');
    }

    public function test_a_kid_cannot_touch_another_kids_playlist(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $kid = $this->loginKid();
        $sibling = Profile::factory()->for($kid->household)->create();

        $theirs = Playlist::factory()->create(['profile_id' => $sibling->id, 'name' => 'Not Yours']);

        Volt::test('playlist-builder')
            ->call('renamePlaylist', $theirs->id, 'Mine Now')
            ->call('deletePlaylist', $theirs->id);

        $this->assertSame('Not Yours', $theirs->fresh()->name);
    }

    public function test_a_kid_only_ever_sees_their_own_playlists(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $kid = $this->loginKid();
        $sibling = Profile::factory()->for($kid->household)->create();

        Playlist::factory()->create(['profile_id' => $kid->id, 'name' => 'Mine']);
        Playlist::factory()->create(['profile_id' => $sibling->id, 'name' => 'Theirs']);

        Volt::test('playlist-builder')
            ->assertSee('Mine')
            ->assertDontSee('Theirs');
    }

    public function test_deleting_a_playlist_takes_its_songs_with_it(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $kid = $this->loginKid();
        $playlist = Playlist::factory()->create(['profile_id' => $kid->id]);

        $this->service()->add($playlist, 'mossy-save-point');

        Volt::test('playlist-builder')->call('deletePlaylist', $playlist->id);

        // There are no foreign keys on these tables, so nothing cascades — the
        // rows are cleaned up in code or not at all.
        $this->assertSame(0, PlaylistTrack::where('playlist_id', $playlist->id)->count());
        $this->assertNull($playlist->fresh());
    }

    public function test_two_playlists_cannot_share_a_name(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $kid = $this->loginKid();

        Playlist::factory()->create(['profile_id' => $kid->id, 'name' => 'Bangers']);

        Volt::test('playlist-builder')
            // Case-insensitively: "bangers" and "Bangers" are the same list to
            // everybody except the database.
            ->set('newName', 'bangers')
            ->call('createPlaylist')
            ->assertSee('You already have a playlist called that.');

        $this->assertSame(1, Playlist::where('profile_id', $kid->id)->count());
    }

    public function test_a_kid_is_told_when_they_have_hit_the_playlist_limit(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $kid = $this->loginKid();

        Playlist::factory()->count(PlaylistService::MAX_PER_PROFILE)->create(['profile_id' => $kid->id]);

        Volt::test('playlist-builder')
            ->set('newName', 'One More')
            ->call('createPlaylist')
            ->assertSee('delete one to make room');

        $this->assertSame(PlaylistService::MAX_PER_PROFILE, Playlist::where('profile_id', $kid->id)->count());
    }

    public function test_renaming_a_song_takes_every_playlist_with_it(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $kid = $this->loginKid();
        $playlist = Playlist::factory()->create(['profile_id' => $kid->id]);

        $this->service()->add($playlist, 'mossy-save-point');

        $this->loginParent();

        Volt::test('parent.music')->call('renameSong', 'Mossy_Save_Point.mp3', 'Mossy Checkpoint');

        // The id is a slug of the path, so a rename moves the song out from
        // under every list that named it unless something follows it across.
        $entry = $playlist->fresh()->tracks->first();

        $this->assertSame('mossy-checkpoint', $entry->track_id);
        $this->assertSame('Mossy Checkpoint', $entry->title);
    }

    public function test_a_rename_onto_a_song_already_in_the_list_does_not_duplicate_it(): void
    {
        $this->library(['Ruins.mp3', 'Old_Ruins.mp3']);
        $kid = $this->loginKid();
        $playlist = Playlist::factory()->create(['profile_id' => $kid->id]);

        $this->service()->add($playlist, 'ruins');
        $this->service()->add($playlist, 'old-ruins');

        $this->loginParent();

        // Both songs now want the same id. The unique index would take a bulk
        // update down, so the loser is dropped instead.
        Volt::test('parent.music')->call('renameSong', 'Old_Ruins.mp3', 'Ruins');

        $this->assertSame(['ruins'], $playlist->fresh()->tracks->pluck('track_id')->all());
    }

    public function test_deleting_a_song_takes_it_out_of_every_playlist(): void
    {
        $this->library(['Mossy_Save_Point.mp3', 'Snowglobe_Ruins.mp3']);
        $kid = $this->loginKid();
        $playlist = Playlist::factory()->create(['profile_id' => $kid->id]);

        $this->service()->add($playlist, 'mossy-save-point');
        $this->service()->add($playlist, 'snowglobe-ruins');

        $this->loginParent();

        Volt::test('parent.music')->call('removeSong', 'Mossy_Save_Point.mp3');

        $this->assertSame(['snowglobe-ruins'], $playlist->fresh()->tracks->pluck('track_id')->all());
    }

    public function test_a_song_that_vanished_is_shown_rather_than_quietly_dropped(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $kid = $this->loginKid();
        $playlist = Playlist::factory()->create(['profile_id' => $kid->id]);

        $this->service()->add($playlist, 'mossy-save-point');

        // Moved in the bucket by hand — the one case no screen in the app knows
        // about, and the reason entries carry the title they were added under.
        Storage::disk('music')->delete('Mossy_Save_Point.mp3');
        app(MusicService::class)->forget();

        // No showPlaylist call: a kid's only list is open from mount, and
        // toggling it here would close it.
        Volt::test('playlist-builder')
            ->assertSee('Mossy Save Point')
            ->assertSee('GONE');
    }

    public function test_the_player_is_never_handed_a_song_that_is_not_there(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $kid = $this->loginKid();
        $playlist = Playlist::factory()->create(['profile_id' => $kid->id]);

        $this->service()->add($playlist, 'mossy-save-point');

        Storage::disk('music')->delete('Mossy_Save_Point.mp3');
        app(MusicService::class)->forget();

        // The page shows it; the browser is not sent it. Nothing in the player
        // can explain a missing song to a kid, so it should never meet one.
        $this->assertSame([], app(PlaylistService::class)->payloadFor($kid)[0]['trackIds']);
    }

    public function test_the_kid_header_carries_the_kids_own_playlists(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $kid = $this->loginKid();
        $sibling = Profile::factory()->for($kid->household)->create();

        $mine = Playlist::factory()->create(['profile_id' => $kid->id, 'name' => 'Chore Power']);
        Playlist::factory()->create(['profile_id' => $sibling->id, 'name' => 'Sibling Sounds']);

        $this->service()->add($mine, 'mossy-save-point');

        Volt::test('kid.quests')
            ->assertSee('Chore Power')
            ->assertDontSee('Sibling Sounds');
    }

    public function test_the_panel_is_a_player_with_a_rail_of_sources(): void
    {
        // The panel stopped being a picker: a dozen playlists above two hundred
        // songs in one 288px scroller, where the only way into a list was to
        // start it. It is a rail of sources and the songs of whichever you are
        // looking at — and looking is not choosing, so the rail only calls
        // look().
        $this->library(['Mossy_Save_Point.mp3']);
        $kid = $this->loginKid();

        $mine = Playlist::factory()->create(['profile_id' => $kid->id, 'name' => 'Chore Power']);
        $this->service()->add($mine, 'mossy-save-point');

        Volt::test('kid.quests')
            ->assertSee('Chore Power')
            ->assertSee('Your playlists')
            ->assertSee('The house')
            ->assertSee('All songs')
            // The rail looks; only Play all, a track row and the transport
            // reach the store.
            ->assertSee("look('playlist', list.id)", false)
            ->assertSee("look('album', album)", false)
            ->assertSee('music.playFrom(viewing)', false)
            ->assertSee('music.preview(track.id)', false);
    }

    public function test_the_panel_carries_the_whole_transport(): void
    {
        // Everything here reads the store the header bar plays from, so the two
        // agree by construction rather than by being kept in step.
        $this->library(['Mossy_Save_Point.mp3']);
        $this->loginKid();

        Volt::test('kid.quests')
            ->assertSee('music.back()', false)
            ->assertSee('music.advance()', false)
            ->assertSee('music.toggleShuffle()', false)
            ->assertSee('music.toggleRepeat()', false)
            ->assertSee('music.setVolume(', false)
            ->assertSee('music.repeatFrom(track.id)', false)
            ->assertSee('Seek through the song')
            // Where it is coming from and how far through — the two questions
            // the old picker could not answer about the list it was playing.
            ->assertSee('music.scopeLabel', false)
            ->assertSee('music.queueAt', false)
            // Back is cut on a phone: skip is in two places, and going
            // backwards is rare enough to be the row itself.
            ->assertSee('hidden h-[44px] w-[44px] place-items-center', false);
    }

    public function test_the_panel_never_carries_another_kids_playlist(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $kid = $this->loginKid();
        $sibling = Profile::factory()->for($kid->household)->create();

        Playlist::factory()->create(['profile_id' => $kid->id, 'name' => 'Chore Power']);
        Playlist::factory()->create(['profile_id' => $sibling->id, 'name' => 'Sibling Sounds']);

        Volt::test('kid.quests')
            ->assertSee('Chore Power')
            ->assertDontSee('Sibling Sounds');
    }

    public function test_both_consoles_draw_the_same_builder(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);

        $this->loginKid();
        $this->get(route('kid.music'))->assertSeeLivewire('playlist-builder');

        $this->loginParent();
        $this->get(route('parent.music'))->assertSeeLivewire('playlist-builder');
    }

    public function test_the_kid_music_page_is_closed_to_parents(): void
    {
        // Parents build their lists in the same component, on their own music
        // screen. The kid page is still the kid console's.
        $this->loginParent();

        $this->get('/kid/music')->assertForbidden();
    }

    public function test_a_parent_has_playlists_of_their_own(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $parent = $this->loginParent();

        Volt::test('playlist-builder', ['audience' => 'parent'])
            ->set('newName', 'Dishes At Nine')
            ->call('createPlaylist')
            ->call('addSong', 'mossy-save-point');

        $playlist = Playlist::where('profile_id', $parent->id)->firstOrFail();

        $this->assertSame('Dishes At Nine', $playlist->name);
        $this->assertSame(['Mossy Save Point'], $playlist->tracks->pluck('title')->all());
    }

    public function test_a_parents_music_screen_shows_only_their_own_lists(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $parent = $this->loginParent();
        $kid = Profile::factory()->for($parent->household)->create();

        Playlist::factory()->create(['profile_id' => $parent->id, 'name' => 'Dishes At Nine']);
        Playlist::factory()->create(['profile_id' => $kid->id, 'name' => 'Kid Bangers']);

        // The builder and the header picker are both on this page, and neither
        // is allowed to be a way of reading a kid's list.
        Volt::test('parent.music')
            ->assertSee('Dishes At Nine')
            ->assertDontSee('Kid Bangers');
    }

    public function test_a_parent_cannot_touch_a_kids_playlist(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $parent = $this->loginParent();
        $kid = Profile::factory()->for($parent->household)->create();

        // A parent administers the library, not the lists made out of it. The
        // ids arrive from the browser, so being on the parent console buys
        // nothing here.
        $theirs = Playlist::factory()->create(['profile_id' => $kid->id, 'name' => 'Not Yours']);

        Volt::test('playlist-builder', ['audience' => 'parent'])
            ->call('renamePlaylist', $theirs->id, 'Mine Now')
            ->call('deletePlaylist', $theirs->id);

        $this->assertSame('Not Yours', $theirs->fresh()->name);
    }

    public function test_changing_the_library_tells_the_builder_below_it(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $this->loginParent();

        // The library admin and the builder are separate components on one
        // screen: without this, a song deleted up top is still offered down
        // below until the page is loaded again.
        Volt::test('parent.music')
            ->call('removeSong', 'Mossy_Save_Point.mp3')
            ->assertDispatched('music-library-changed');
    }

    public function test_no_control_on_the_playlist_builder_is_named_after_one_of_its_properties(): void
    {
        /*
         * The same trap the parent music screen fell into: a public method and
         * a public property sharing a name resolve to the *property* through
         * $wire, so `wire:click="openPlaylist(3)"` calls null and the row never
         * opens. Nothing that goes through ->call() can see it — ->call() never
         * touches $wire — which is why this is reflection rather than a click.
         */
        $this->loginKid();

        $component = Volt::test('playlist-builder')->instance();
        $class = new ReflectionClass($component);

        $declaredHere = fn (array $members): array => array_map(
            fn ($member) => $member->getName(),
            array_filter($members, fn ($member) => $member->getDeclaringClass()->getName() === $class->getName()),
        );

        $collisions = array_intersect(
            $declaredHere($class->getProperties(ReflectionProperty::IS_PUBLIC)),
            $declaredHere($class->getMethods(ReflectionMethod::IS_PUBLIC)),
        );

        $this->assertSame(
            [],
            array_values($collisions),
            'These are both a property and a method, so wire:click on them silently does nothing.',
        );
    }

    public function test_a_song_can_be_put_in_a_playlist_from_the_player(): void
    {
        // Browsing is when a kid decides they like a song. The panel is Alpine
        // over a store, so the one moment that touches the database is this
        // component, reached by a broadcast rather than by $wire — see its
        // docblock for why the page underneath cannot be asked.
        $this->library(['Mossy_Save_Point.mp3']);
        $kid = $this->loginKid();

        $playlist = Playlist::factory()->create(['profile_id' => $kid->id, 'name' => 'Chore Power']);

        Volt::test('playlist-quick-add')
            ->call('addSong', $playlist->id, 'mossy-save-point')
            ->assertDispatched('playlists-updated')
            ->assertDispatched('playlist-add-said', message: 'Added to Chore Power.', ok: true);

        $this->assertSame(['mossy-save-point'], $playlist->tracks()->pluck('track_id')->all());
    }

    public function test_the_player_adds_to_the_end_of_the_list(): void
    {
        $this->library(['One.mp3', 'Two.mp3']);
        $kid = $this->loginKid();

        $playlist = Playlist::factory()->create(['profile_id' => $kid->id]);
        $this->service()->add($playlist, 'one');

        Volt::test('playlist-quick-add')->call('addSong', $playlist->id, 'two');

        $this->assertSame(['one', 'two'], $playlist->tracks()->pluck('track_id')->all());
        $this->assertSame([1, 2], $playlist->tracks()->pluck('position')->all());
    }

    public function test_the_player_says_so_when_the_song_is_already_in_the_list(): void
    {
        // The sheet ticks a list it cannot add to, so this only happens to a
        // panel whose store is a moment behind — and it still gets an answer
        // rather than a tap that does nothing.
        $this->library(['Mossy_Save_Point.mp3']);
        $kid = $this->loginKid();

        $playlist = Playlist::factory()->create(['profile_id' => $kid->id, 'name' => 'Chore Power']);
        $this->service()->add($playlist, 'mossy-save-point');

        Volt::test('playlist-quick-add')
            ->call('addSong', $playlist->id, 'mossy-save-point')
            ->assertDispatched('playlist-add-said', message: 'It is already in Chore Power.', ok: false)
            ->assertNotDispatched('playlists-updated');

        $this->assertSame(1, $playlist->tracks()->count());
    }

    public function test_the_player_says_so_when_the_list_is_full(): void
    {
        $this->library(['Mossy_Save_Point.mp3', 'Old_Ruins.mp3']);
        $kid = $this->loginKid();

        $playlist = Playlist::factory()->create(['profile_id' => $kid->id, 'name' => 'Chore Power']);

        // Straight to the limit rather than through a hundred real songs: what
        // is being tested is the refusal, not PlaylistService's counting.
        for ($position = 1; $position <= PlaylistService::MAX_TRACKS; $position++) {
            PlaylistTrack::create([
                'playlist_id' => $playlist->id,
                'track_id' => 'filler-'.$position,
                'title' => 'Filler '.$position,
                'position' => $position,
            ]);
        }

        Volt::test('playlist-quick-add')
            ->call('addSong', $playlist->id, 'mossy-save-point')
            ->assertDispatched(
                'playlist-add-said',
                message: 'Chore Power is full at '.PlaylistService::MAX_TRACKS.' songs.',
                ok: false,
            );

        $this->assertSame(PlaylistService::MAX_TRACKS, $playlist->tracks()->count());
    }

    public function test_the_player_never_adds_to_somebody_elses_playlist(): void
    {
        // The id comes off a broadcast event, which anything in the browser can
        // send. Same rule as the builder: the list is found through the profile
        // signed in or not at all.
        $this->library(['Mossy_Save_Point.mp3']);
        $kid = $this->loginKid();
        $sibling = Profile::factory()->for($kid->household)->create();

        $theirs = Playlist::factory()->create(['profile_id' => $sibling->id]);

        Volt::test('playlist-quick-add')
            ->call('addSong', $theirs->id, 'mossy-save-point')
            ->assertNotDispatched('playlists-updated');

        $this->assertSame(0, $theirs->tracks()->count());
    }

    public function test_the_player_never_adds_a_song_that_is_not_in_the_library(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $kid = $this->loginKid();

        $playlist = Playlist::factory()->create(['profile_id' => $kid->id]);

        Volt::test('playlist-quick-add')
            ->call('addSong', $playlist->id, 'never-existed')
            ->assertNotDispatched('playlists-updated');

        $this->assertSame(0, $playlist->tracks()->count());
    }

    public function test_an_empty_sheet_asks_for_nothing(): void
    {
        // Alpine holds an unset selection as null, and a typed parameter that
        // will not take one throws on the most ordinary path there is.
        $this->library(['Mossy_Save_Point.mp3']);
        $this->loginKid();

        Volt::test('playlist-quick-add')
            ->call('addSong', null, null)
            ->assertNotDispatched('playlists-updated');
    }

    public function test_adding_from_the_player_redraws_the_builder_under_it(): void
    {
        // Both are on the music page. Its own event rather than the one the
        // builder sends: a component listening for its own announcement would
        // answer it forever.
        $this->library(['Mossy_Save_Point.mp3']);
        $kid = $this->loginKid();

        $playlist = Playlist::factory()->create(['profile_id' => $kid->id]);

        // Drawn first, so its count is a lie by the time the song goes in —
        // which is the whole situation: both are on the music page at once.
        $builder = Volt::test('playlist-builder')->assertSee('0 SONGS');

        Volt::test('playlist-quick-add')
            ->call('addSong', $playlist->id, 'mossy-save-point')
            ->assertDispatched('playlist-touched');

        // And it must not answer with `playlists-updated`, the event it sends
        // itself: a component listening for its own announcement would answer
        // it forever.
        $builder
            ->dispatch('playlist-touched')
            ->assertDontSee('0 SONGS')
            ->assertNotDispatched('playlists-updated');
    }

    public function test_every_song_in_the_player_carries_the_button_that_keeps_it(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $this->loginKid();

        Volt::test('kid.quests')
            ->assertSee('openAdd(track)', false)
            // Lit when it is already in a list of theirs — the cheapest answer
            // to "have I got this one?" over a hundred songs.
            ->assertSee('kept(track.id)', false)
            ->assertSee('Add to playlist')
            ->assertSee('addTo(list)', false)
            // `x-show`, never `<template x-if>`: the header is morphed by every
            // render of the page under it, and x-if clones its contents in as a
            // sibling the morph cannot see, so the handlers come back dead.
            ->assertSee('x-show="adding"', false)
            // Blade leaves an unknown `@thing` alone, but the sheet is mute if
            // it ever stops doing so — this is the only way a refusal is heard.
            ->assertSee('@playlist-add-said.window', false)
            ->assertSeeLivewire('playlist-quick-add');
    }

    public function test_the_parent_player_can_keep_a_song_too(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $parent = $this->loginParent();

        $playlist = Playlist::factory()->create(['profile_id' => $parent->id, 'name' => 'Kitchen']);

        Volt::test('playlist-quick-add')
            ->call('addSong', $playlist->id, 'mossy-save-point')
            ->assertDispatched('playlists-updated');

        $this->assertSame(1, $playlist->tracks()->count());
    }

    public function test_an_edit_tells_the_header_about_it(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $this->loginKid();

        /*
         * The picker and this page are the same screen, and Livewire morphs
         * around the picker's Alpine component without re-evaluating its
         * x-data — so a new playlist reaches it by event or not until the next
         * full page load.
         */
        Volt::test('playlist-builder')
            ->set('newName', 'Bangers')
            ->call('createPlaylist')
            ->assertDispatched('playlists-updated');
    }
}
