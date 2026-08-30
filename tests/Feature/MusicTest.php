<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\MusicService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Volt\Volt;
use Tests\TestCase;

class MusicTest extends TestCase
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

    public function test_it_lists_the_songs_on_the_disk_by_title(): void
    {
        $this->library(['Snowglobe_Ruins.mp3', 'Mossy_Save_Point.mp3', 'notes.txt']);

        $tracks = app(MusicService::class)->tracks();

        $this->assertSame(['Mossy Save Point', 'Snowglobe Ruins'], array_column($tracks, 'title'));
        $this->assertSame(['mossy-save-point', 'snowglobe-ruins'], array_column($tracks, 'id'));
    }

    public function test_a_stored_filename_never_needs_encoding(): void
    {
        // Belt as well as braces. urlFor() encodes now, because a folder
        // dropped in by hand brings whatever names it came with — but what this
        // app writes itself still needs no encoding at all.
        $stored = app(MusicService::class)->filename('Mossy Save Point');

        $this->assertSame('Mossy_Save_Point.mp3', $stored);
        $this->assertSame($stored, rawurlencode($stored));
    }

    public function test_it_encodes_a_hand_placed_filename_that_does_need_it(): void
    {
        /*
         * A bought soundtrack arrives as a folder of files named however the
         * shop named them, spaces and all — nothing this app wrote.
         *
         * Storage::url() cannot be left to deal with that: given a base url,
         * which the local disk has and every hosted bucket has, it concatenates
         * the path on raw. The space reaches the browser unencoded and the
         * bucket never matches the key.
         */
        $this->library(['Undertale/toby fox - 02 Start Menu.mp3']);

        $url = app(MusicService::class)->tracks()[0]['url'];

        $this->assertStringNotContainsString(' ', $url);
        $this->assertStringContainsString('Undertale/toby%20fox%20-%2002%20Start%20Menu.mp3', $url);
    }

    public function test_it_groups_songs_into_the_folders_they_sit_in(): void
    {
        $this->library([
            'Pixel_Run.mp3',
            'Undertale/Ruins.mp3',
            'Undertale/Fallen_Down.mp3',
            'Celeste/Resurrections.mp3',
        ]);

        $tracks = app(MusicService::class)->tracks();

        // Loose songs first, then albums alphabetically, then songs within each.
        $this->assertSame(
            [null, 'Celeste', 'Undertale', 'Undertale'],
            array_column($tracks, 'album'),
        );
        $this->assertSame(
            ['Pixel Run', 'Resurrections', 'Fallen Down', 'Ruins'],
            array_column($tracks, 'title'),
        );
    }

    public function test_a_folder_nested_deeper_still_belongs_to_the_album_at_the_top(): void
    {
        // Otherwise the picker would need a third level, and a menu a kid has
        // to walk down three times is not a menu.
        $this->library(['Undertale/Disc One/Ruins.mp3']);

        $this->assertSame('Undertale', app(MusicService::class)->tracks()[0]['album']);
    }

    public function test_two_albums_can_each_have_a_song_of_the_same_name(): void
    {
        // The id is what a kid's browser remembers their choice by, so a
        // collision here would have one album's song select the other's.
        $this->library(['Undertale/Ruins.mp3', 'Celeste/Ruins.mp3']);

        $ids = array_column(app(MusicService::class)->tracks(), 'id');

        $this->assertSame($ids, array_unique($ids));
    }

    public function test_a_song_at_the_top_keeps_the_id_it_had_before_folders_existed(): void
    {
        // Kids have a chosen song in localStorage keyed by this. Changing the
        // shape of it would silently forget every one of them.
        $this->library(['Pixel_Run.mp3']);

        $this->assertSame('pixel-run', app(MusicService::class)->tracks()[0]['id']);
    }

    public function test_a_parent_can_upload_into_an_album(): void
    {
        $this->loginParent();

        Volt::test('parent.music')
            ->set('upload', UploadedFile::fake()->create('track01.mp3', 200, 'audio/mpeg'))
            ->set('newTitle', 'Fallen Down')
            ->set('newAlbum', 'Undertale')
            ->call('addSong')
            ->assertHasNoErrors();

        Storage::disk('music')->assertExists('Undertale/Fallen_Down.mp3');
    }

    public function test_renaming_a_song_leaves_it_in_its_album(): void
    {
        $this->library(['Undertale/Ruins.mp3']);
        $this->loginParent();

        Volt::test('parent.music')->call('renameSong', 'Undertale/Ruins.mp3', 'Home');

        Storage::disk('music')->assertExists('Undertale/Home.mp3');
        Storage::disk('music')->assertMissing('Home.mp3');
    }

    public function test_the_kid_picker_offers_the_album_as_a_folder(): void
    {
        $this->library(['Undertale/Ruins.mp3', 'Pixel_Run.mp3']);
        $this->loginKid();

        Volt::test('kid.quests')
            ->assertSee('Undertale')
            ->assertSee('Ruins')
            // The folder is a real control, not a heading.
            ->assertSee('toggleAlbum(album)', false);
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

    /**
     * Point the library at a bucket that cannot be reached at all.
     *
     * Nothing is faked here — a real s3 disk aimed at an endpoint with nothing
     * behind it, which is the shape of every way this goes wrong in production:
     * a bucket that does not exist, credentials that are blank because the
     * platform never set the variables they were read from, a wrong region.
     */
    private function useUnreachableBucket(): void
    {
        config([
            'filesystems.music_disk' => 'music_cloud',
            'filesystems.disks.music_cloud.key' => 'nobody',
            'filesystems.disks.music_cloud.secret' => 'nothing',
            'filesystems.disks.music_cloud.region' => 'us-east-1',
            'filesystems.disks.music_cloud.bucket' => 'no-such-bucket',
            'filesystems.disks.music_cloud.endpoint' => 'http://127.0.0.1:1',
            'filesystems.disks.music_cloud.use_path_style_endpoint' => true,
            // Straight to the failure. The SDK's default is three retries with
            // backoff, which turned these three tests into half a minute of
            // waiting for a connection that was refused on the first attempt.
            'filesystems.disks.music_cloud.retries' => 0,
            'filesystems.disks.music_cloud.http' => ['connect_timeout' => 1, 'timeout' => 2],
        ]);
    }

    public function test_a_library_that_cannot_be_read_comes_back_empty_rather_than_throwing(): void
    {
        $this->useUnreachableBucket();

        $service = app(MusicService::class);

        $this->assertSame([], $service->tracks());
        $this->assertNotNull($service->failure());
    }

    public function test_unreachable_storage_does_not_take_the_kid_console_down(): void
    {
        // The whole kid console draws the header, so a throwing library took
        // every quest, chore and balance down with it — over the one feature
        // nobody needs in order to get their jobs done.
        $this->useUnreachableBucket();
        $this->loginKid();

        Volt::test('kid.quests')
            ->assertOk()
            ->assertDontSee('Choose a song');
    }

    public function test_the_music_screen_says_why_the_playlist_is_empty(): void
    {
        // Now that a broken library is silent, an empty playlist is ambiguous:
        // no songs, or no bucket. This screen is where that gets answered.
        $this->useUnreachableBucket();
        $this->loginParent();

        Volt::test('parent.music')
            ->assertOk()
            ->assertSee('The music storage cannot be read.')
            ->assertDontSee('Nothing here yet');
    }

    public function test_it_stores_an_upload_that_has_no_readable_path_on_this_machine(): void
    {
        /*
         * Livewire holds an upload on a temporary disk between the browser
         * sending it and the action using it, and anywhere running more than
         * one application container has to make that disk a shared, remote one.
         * A temporary file sitting in a bucket has no local path: getRealPath()
         * returns a bucket key, and anything that tries to fopen() it dies on a
         * missing file whose name it can print.
         *
         * The suite cannot reproduce that directly — Livewire pins the
         * temporary disk to a local fake whenever tests are running, which is
         * precisely why every test here passed while production could not store
         * a single song. So the condition is reproduced instead: a real
         * temporary upload whose real path leads nowhere.
         */
        // First: it is this call that registers the temporary disk, and
        // constructing the upload below resolves that disk in its constructor.
        FileUploadConfiguration::storage()->put('livewire-tmp/song.mp3', 'not really an mp3');

        // Bare filename: the constructor prefixes the temporary directory for
        // you, so passing the full path lands it under livewire-tmp twice.
        $temporary = new class('song.mp3', 'tmp-for-tests') extends TemporaryUploadedFile
        {
            public function getRealPath(): string
            {
                return 'livewire-tmp/there-is-no-such-file.mp3';
            }
        };

        app(MusicService::class)->store($temporary, 'Pixel Run');

        Storage::disk('music')->assertExists('Pixel_Run.mp3');
    }

    public function test_adding_a_song_notifies_absolutely_nobody(): void
    {
        /*
         * The guard on the thing that started all this. An album is a hundred
         * separate uploads, so anything that pushes per song pushes a hundred
         * times — and a hundred buzzes in a row is how a kid turns
         * notifications off for good, taking the chore approvals and the trade
         * offers with them.
         *
         * Kids find out from the marker on the header music button instead,
         * which costs them nothing and cannot arrive twice.
         */
        Notification::fake();

        $parent = $this->loginParent();
        Profile::factory()->for($parent->household)->create();

        Volt::test('parent.music')
            ->set('upload', UploadedFile::fake()->create('track01.mp3', 200, 'audio/mpeg'))
            ->set('newTitle', 'Pixel Run')
            ->call('addSong')
            ->assertHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_the_library_reports_when_it_last_changed(): void
    {
        // The one number the header needs: a browser keeps the last value it
        // saw, and anything higher means songs arrived since it looked.
        $this->assertSame(0, app(MusicService::class)->latestChangeAt(), 'An empty library has no age.');

        $this->library(['Pixel_Run.mp3']);

        $this->assertGreaterThan(0, app(MusicService::class)->latestChangeAt());
    }

    public function test_the_kid_header_carries_the_marker_and_what_it_compares_against(): void
    {
        $this->library(['Pixel_Run.mp3']);
        $this->loginKid();

        $latest = app(MusicService::class)->latestChangeAt();

        Volt::test('kid.quests')
            // The high-water mark rides along as the picker's second argument.
            // Without it every browser compares against zero and reads as
            // permanently caught up, so the marker would never appear at all.
            ->assertSee(', '.$latest.')', false)
            ->assertSee('music.hasNew', false);
    }

    public function test_the_upload_field_never_asks_for_more_than_one_file(): void
    {
        /*
         * Livewire's S3 temporary-upload driver refuses a `multiple` input
         * outright — S3DoesntSupportMultipleFileUploads, thrown from
         * _startUpload the moment a file is chosen, before any of this app's
         * own code runs. It goes off the attribute rather than the number of
         * files, so one song fails exactly as hard as a hundred.
         *
         * That temporary disk is local here and the bucket in production, which
         * is the same local-versus-remote blind spot that has now caused three
         * production failures in this feature. The condition cannot be
         * reproduced in this suite, so the attribute itself is the assertion.
         */
        $this->loginParent();

        Volt::test('parent.music')
            ->assertSee('wire:model="upload"', false)
            ->assertDontSee('multiple', false);
    }

    public function test_a_failed_upload_says_why_and_not_just_that(): void
    {
        // "The storage turned it down" is not something anybody can act on, and
        // the person reading it is the only person who can fix it.
        $this->useUnreachableBucket();
        $this->loginParent();

        Volt::test('parent.music')
            ->set('upload', UploadedFile::fake()->create('track01.mp3', 200, 'audio/mpeg'))
            ->call('addSong')
            ->assertSee('That did not save')
            ->assertSee('no-such-bucket');
    }

    public function test_the_music_screen_lists_the_disks_it_could_have_been_pointed_at(): void
    {
        // The disk MUSIC_DISK is meant to name is registered at boot by the
        // host, so it cannot be written down in advance — but it is in this
        // list at runtime, which is the whole point of printing it.
        $this->useUnreachableBucket();
        $this->loginParent();

        // Not the exact list — that is config order and would break the first
        // time a disk is added. What matters is that the names are printed
        // after the prompt telling you to go and read them.
        Volt::test('parent.music')
            ->assertSeeInOrder([
                'MUSIC_DISK is set to',
                'The disks this app actually has are:',
                'music_cloud',
            ]);
    }

    public function test_the_music_library_is_closed_to_kids(): void
    {
        $this->loginKid();

        $this->get(route('parent.music'))->assertForbidden();
    }
}
