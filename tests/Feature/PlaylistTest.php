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
