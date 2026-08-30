<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\MusicService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class MusicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every test here runs against a faked music disk rather than whatever
        // songs happen to be shipped or uploaded today.
        Storage::fake('music');
        config(['filesystems.music_disk' => 'music']);
    }

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

    public function test_it_lists_the_songs_on_the_disk_by_title(): void
    {
        $this->library(['Snowglobe_Ruins.mp3', 'Mossy_Save_Point.mp3', 'notes.txt']);

        $tracks = app(MusicService::class)->tracks();

        $this->assertSame(['Mossy Save Point', 'Snowglobe Ruins'], array_column($tracks, 'title'));
        $this->assertSame(['mossy-save-point', 'snowglobe-ruins'], array_column($tracks, 'id'));
    }

    public function test_a_stored_filename_never_needs_encoding(): void
    {
        // A URL is built by concatenating a configured base onto the path, and
        // neither the local disk nor the S3 disk encodes what it is handed. A
        // space in a filename survived that only on browser leniency.
        $stored = app(MusicService::class)->filename('Mossy Save Point');

        $this->assertSame('Mossy_Save_Point.mp3', $stored);
        $this->assertSame($stored, rawurlencode($stored));
    }

    public function test_a_title_keeps_its_own_capitalisation_through_the_round_trip(): void
    {
        // The reason filenames carry underscores rather than a slug: "of" and
        // "the" come back lowercase, which a slug cannot promise.
        $service = app(MusicService::class);

        $this->assertSame(
            'Echoes of the Underground',
            $service->title($service->filename('Echoes of the Underground')),
        );
    }

    public function test_it_strips_path_characters_out_of_a_title(): void
    {
        $this->assertSame('passwd.mp3', app(MusicService::class)->filename('../../etc/passwd'));
    }

    public function test_the_kid_header_offers_the_songs(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $this->loginKid();

        Volt::test('kid.quests')
            ->assertSee('fqMusic', false)
            ->assertSee('Mossy Save Point')
            ->assertSee('Choose a song');
    }

    public function test_the_kid_header_is_told_nothing_about_where_the_songs_are_stored(): void
    {
        $this->library(['Mossy_Save_Point.mp3']);
        $this->loginKid();

        // The library carries a storage path and a size for the admin screen.
        // Neither has any business in markup a kid's browser receives.
        Volt::test('kid.quests')->assertDontSee('bytes');
    }

    public function test_the_kid_header_hides_the_control_when_there_are_no_songs(): void
    {
        $this->loginKid();

        Volt::test('kid.quests')->assertDontSee('Choose a song');
    }

    public function test_a_parent_can_add_a_song_and_name_it(): void
    {
        $this->loginParent();

        Volt::test('parent.music')
            ->set('upload', UploadedFile::fake()->create('track01.mp3', 200, 'audio/mpeg'))
            ->set('newTitle', 'Mossy Save Point')
            ->call('addSong')
            ->assertHasNoErrors()
            ->assertSee('Mossy Save Point is on the list.');

        Storage::disk('music')->assertExists('Mossy_Save_Point.mp3');
    }

    /**
     * Make the faked disk *look* remote to the service.
     *
     * Storage::fake always hands back a local disk, so nothing else in this
     * file goes anywhere near the caching branch — the listing is only cached
     * when the library is a bucket. Flipping the configured driver after the
     * fake is resolved gets the cache under test without needing MinIO: the
     * disk stays the fake, and isLocal() stops short-circuiting.
     */
    private function pretendTheDiskIsRemote(): void
    {
        config(['filesystems.disks.music.driver' => 's3']);
    }

    public function test_a_bucket_listing_is_cached_between_reads(): void
    {
        $this->pretendTheDiskIsRemote();
        $this->library(['Pixel_Run.mp3']);

        $service = app(MusicService::class);

        $this->assertCount(1, $service->tracks());

        // Straight past the service, so nothing clears the cache.
        Storage::disk('music')->put('Suspense.mp3', 'not really an mp3');

        $this->assertCount(1, $service->tracks(), 'The listing should still be the cached one.');

        $service->forget();

        $this->assertCount(2, $service->tracks());
    }

    public function test_a_song_added_by_a_parent_reaches_the_kid_header_immediately(): void
    {
        // The bucket listing is cached, so an upload that does not clear it is
        // an upload nobody hears for an hour.
        $this->pretendTheDiskIsRemote();
        $this->loginParent();

        Volt::test('parent.music')
            ->set('upload', UploadedFile::fake()->create('track01.mp3', 200, 'audio/mpeg'))
            ->set('newTitle', 'Pixel Run')
            ->call('addSong')
            ->assertHasNoErrors();

        $this->loginKid();

        Volt::test('kid.quests')->assertSee('Pixel Run');
    }

    public function test_only_mp3s_are_accepted(): void
    {
        $this->loginParent();

        Volt::test('parent.music')
            ->set('upload', UploadedFile::fake()->create('sneaky.wav', 200, 'audio/wav'))
            ->call('addSong')
            ->assertHasErrors('upload');

        $this->assertSame([], Storage::disk('music')->files());
    }

    public function test_a_parent_can_rename_a_song(): void
    {
        $this->library(['Snowy_Save_Point.mp3']);
        $this->loginParent();

        Volt::test('parent.music')->call('renameSong', 'Snowy_Save_Point.mp3', 'Snowglobe Ruins');

        Storage::disk('music')->assertExists('Snowglobe_Ruins.mp3');
        Storage::disk('music')->assertMissing('Snowy_Save_Point.mp3');
    }

    public function test_a_parent_can_delete_a_song(): void
    {
        $this->library(['Suspense.mp3']);
        $this->loginParent();

        Volt::test('parent.music')
            ->call('removeSong', 'Suspense.mp3')
            ->assertSee('Suspense is gone.');

        Storage::disk('music')->assertMissing('Suspense.mp3');
    }

    public function test_the_music_library_is_closed_to_kids(): void
    {
        $this->loginKid();

        $this->get(route('parent.music'))->assertForbidden();
    }
}
