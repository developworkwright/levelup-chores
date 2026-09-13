<?php

namespace Tests\Feature;

use App\Enums\FeedMessageKind;
use App\Models\FeedMessage;
use App\Models\FeedRoom;
use App\Models\Household;
use App\Models\Profile;
use App\Services\FeedPhotos;
use App\Services\FeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Photographs in the family feed.
 *
 * This is the first untrusted binary the app accepts, so most of this file is
 * about what does *not* get stored. The rule it shares with drawings is the one
 * the whole feed turns on: a picture posted in a room a grown-up cannot read is
 * a picture a grown-up cannot read, and the answer to asking for one is a 404
 * rather than a 403 so that a forwarded link does not even confirm it exists.
 */
class FamilyFeedPhotoTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $raylan;

    private Profile $westin;

    private Profile $mom;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('drawings');

        $this->household = Household::factory()->create();
        $this->raylan = Profile::factory()->for($this->household)->create(['name' => 'Raylan']);
        $this->westin = Profile::factory()->for($this->household)->create(['name' => 'Westin']);
        $this->mom = Profile::factory()->parent()->for($this->household)->create(['name' => 'Mom']);

        app(FeedService::class)->ensureRooms($this->household);
    }

    private function everyone(): FeedRoom
    {
        return app(FeedService::class)->roomFor($this->raylan);
    }

    /** A real GD-made JPEG, so the decode-and-redraw path runs for real. */
    private function photo(int $width = 1200, int $height = 900): UploadedFile
    {
        return UploadedFile::fake()->image('fort.jpg', $width, $height);
    }

    private function postPhotoIn(FeedRoom $room, Profile $author, string $caption = ''): FeedMessage
    {
        return app(FeedService::class)->photo($author, $room, $this->photo(), $caption);
    }

    /*
     * ------------------------------------------------------------------
     * Posting
     * ------------------------------------------------------------------
     */

    public function test_a_photo_is_stored_under_its_household_and_posted(): void
    {
        $message = $this->postPhotoIn($this->everyone(), $this->raylan);

        $files = Storage::disk('drawings')->allFiles();

        $this->assertCount(1, $files);
        // Its own folder, so it never sits loose at the top of a bucket shared
        // with the music library, and never beside another family's.
        $this->assertStringStartsWith('photos/'.$this->household->id.'/', $files[0]);
        $this->assertStringEndsWith('.jpg', $files[0]);

        $this->assertDatabaseHas('feed_messages', [
            'id' => $message->id,
            'kind' => 'photo',
            'image_path' => $files[0],
        ]);
    }

    /** Whatever arrived, what is stored is a JPEG this app drew itself. */
    public function test_every_upload_is_re_encoded_to_one_format(): void
    {
        app(FeedService::class)->photo($this->raylan, $this->everyone(), UploadedFile::fake()->image('shot.png', 400, 300));

        $stored = Storage::disk('drawings')->get(Storage::disk('drawings')->allFiles()[0]);

        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($stored)[2]);
    }

    public function test_a_big_photo_is_shrunk_and_its_real_size_recorded(): void
    {
        $message = app(FeedService::class)->photo($this->raylan, $this->everyone(), $this->photo(4000, 3000));

        $this->assertSame(FeedPhotos::MAX_EDGE, $message->image_width);
        $this->assertSame((int) round(FeedPhotos::MAX_EDGE * 3 / 4), $message->image_height);

        // And the stored file really is that size, not merely recorded as it.
        [$width, $height] = getimagesizefromstring(Storage::disk('drawings')->get($message->image_path));

        $this->assertSame($message->image_width, $width);
        $this->assertSame($message->image_height, $height);
    }

    /** A small photo is left alone rather than blown up to the ceiling. */
    public function test_a_small_photo_keeps_its_own_size(): void
    {
        $message = app(FeedService::class)->photo($this->raylan, $this->everyone(), $this->photo(320, 240));

        $this->assertSame(320, $message->image_width);
        $this->assertSame(240, $message->image_height);
    }

    public function test_the_composers_line_rides_along_as_the_caption(): void
    {
        $message = $this->postPhotoIn($this->everyone(), $this->raylan, '  the fort  ');

        $this->assertSame('the fort', $message->body);
        $this->assertSame('the fort', $message->preview());
    }

    public function test_a_photo_without_a_caption_still_previews_as_something(): void
    {
        $this->assertSame('sent a photo', $this->postPhotoIn($this->everyone(), $this->raylan)->preview());
    }

    public function test_the_room_shows_the_photo_and_its_caption(): void
    {
        $message = $this->postPhotoIn($this->everyone(), $this->raylan, 'the fort');

        Auth::guard('profile')->login($this->westin);

        Volt::test('family-feed')
            ->call('open', $this->everyone()->id)
            ->assertSee(route('feed.photo', $message), false)
            ->assertSee('the fort');
    }

    public function test_a_photo_posts_from_the_composer(): void
    {
        Auth::guard('profile')->login($this->raylan);

        $page = Volt::test('family-feed')
            ->call('open', $this->everyone()->id)
            ->set('draft', 'look at this')
            ->set('photo', $this->photo());

        // Choosing one opens the preview rather than posting it: this is the
        // one thing in the composer that cannot be taken back once the room
        // has seen it, so it gets looked at first.
        $page->assertSet('tray', 'photo')->assertSee('Sending with: look at this');
        $this->assertDatabaseCount('feed_messages', 0);

        $page->call('postPhoto');

        $this->assertDatabaseHas('feed_messages', ['kind' => 'photo', 'body' => 'look at this']);
        $this->assertCount(1, Storage::disk('drawings')->allFiles());
    }

    public function test_backing_out_of_a_photo_posts_nothing(): void
    {
        Auth::guard('profile')->login($this->raylan);

        Volt::test('family-feed')
            ->call('open', $this->everyone()->id)
            ->set('photo', $this->photo())
            ->call('discardPhoto')
            ->assertSet('tray', null);

        $this->assertDatabaseCount('feed_messages', 0);
        $this->assertSame([], Storage::disk('drawings')->allFiles());
    }

    /*
     * ------------------------------------------------------------------
     * What does not get stored
     * ------------------------------------------------------------------
     */

    /**
     * The thing re-encoding is really for.
     *
     * A phone stamps the coordinates of the house into every photo taken in
     * the garden. Decoding and redrawing keeps the pixels and drops every tag,
     * so the location never reaches a disk at all.
     */
    public function test_exif_does_not_survive_the_re_encode(): void
    {
        $withExif = $this->jpegCarryingExif();

        $this->assertStringContainsString('Exif', $withExif, 'The fixture should start out carrying EXIF.');

        $message = app(FeedService::class)->photo(
            $this->raylan,
            $this->everyone(),
            UploadedFile::fake()->createWithContent('holiday.jpg', $withExif),
        );

        $stored = Storage::disk('drawings')->get($message->image_path);

        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertStringNotContainsString('GPS', $stored);
    }

    /**
     * Re-encoding is what throws the orientation tag away, so the rotation has
     * to be applied before it. Without this every portrait photo off an iPhone
     * — which writes 6 or 8 constantly — arrives in the room on its side.
     */
    public function test_a_sideways_photo_is_turned_upright(): void
    {
        // 6 and 8 are the quarter turns; 1 and 3 leave the shape alone.
        foreach ([1 => 'portrait', 3 => 'portrait', 6 => 'landscape', 8 => 'landscape'] as $orientation => $expected) {
            Storage::fake('drawings');

            $stored = app(FeedPhotos::class)->store(
                $this->raylan,
                UploadedFile::fake()->createWithContent('p.jpg', $this->jpegOriented(400, 800, $orientation)),
            );

            $this->assertSame(
                $expected,
                $stored['width'] > $stored['height'] ? 'landscape' : 'portrait',
                "A photo tagged orientation {$orientation} came out the wrong way up.",
            );
        }
    }

    /** A script that has been given a photo's name is still a script. */
    public function test_a_script_renamed_as_a_photo_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        try {
            app(FeedService::class)->photo(
                $this->raylan,
                $this->everyone(),
                UploadedFile::fake()->createWithContent('shell.jpg', '<?php echo shell_exec($_GET["c"]); ?>'),
            );
        } finally {
            $this->assertSame([], Storage::disk('drawings')->allFiles());
            $this->assertDatabaseCount('feed_messages', 0);
        }
    }

    /**
     * An SVG is an XML document that can carry a script, and one served inline
     * from this app's own origin is stored XSS against the whole family. It is
     * refused by the component's rules and again by the service.
     */
    public function test_an_svg_is_refused(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        $this->expectException(\RuntimeException::class);

        try {
            app(FeedService::class)->photo(
                $this->raylan,
                $this->everyone(),
                UploadedFile::fake()->createWithContent('drawing.svg', $svg),
            );
        } finally {
            $this->assertSame([], Storage::disk('drawings')->allFiles());
        }
    }

    public function test_the_composer_refuses_an_svg_out_loud_and_stores_nothing(): void
    {
        Auth::guard('profile')->login($this->raylan);

        Volt::test('family-feed')
            ->call('open', $this->everyone()->id)
            ->set('photo', UploadedFile::fake()->createWithContent('drawing.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'))
            ->assertSee('That has to be a photo');

        $this->assertSame([], Storage::disk('drawings')->allFiles());
        $this->assertDatabaseCount('feed_messages', 0);
    }

    /**
     * The decompression bomb, and the reason the header is read before the
     * image is: this file is a few hundred bytes and honestly declares itself
     * enormous. Decoding it first would ask for gigabytes and take the worker
     * down before any later check ran.
     */
    public function test_an_image_declaring_more_pixels_than_it_could_have_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        try {
            app(FeedService::class)->photo(
                $this->raylan,
                $this->everyone(),
                UploadedFile::fake()->createWithContent('bomb.png', $this->pngClaiming(60000, 60000)),
            );
        } finally {
            $this->assertSame([], Storage::disk('drawings')->allFiles());
            $this->assertDatabaseCount('feed_messages', 0);
        }
    }

    public function test_a_file_over_the_ceiling_is_refused(): void
    {
        $huge = UploadedFile::fake()->create('huge.jpg', FeedPhotos::MAX_UPLOAD_KB + 1024, 'image/jpeg');

        $this->expectException(\RuntimeException::class);

        try {
            app(FeedService::class)->photo($this->raylan, $this->everyone(), $huge);
        } finally {
            $this->assertSame([], Storage::disk('drawings')->allFiles());
        }
    }

    /*
     * ------------------------------------------------------------------
     * The upload ceiling
     * ------------------------------------------------------------------
     */

    /**
     * The ceiling is the smallest of what the app wants and what PHP will
     * carry, because `upload_max_filesize` and `post_max_size` are
     * PHP_INI_PERDIR — no application code can raise them, so an app that
     * merely asserts 12MB is an app that drops photos silently.
     */
    public function test_the_ceiling_never_exceeds_what_php_will_actually_carry(): void
    {
        $ceiling = FeedPhotos::uploadCeilingKb();

        $this->assertGreaterThan(0, $ceiling);
        $this->assertLessThanOrEqual(FeedPhotos::MAX_UPLOAD_KB, $ceiling);

        foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
            $limit = $this->iniKilobytes($directive);

            if ($limit !== null) {
                $this->assertLessThanOrEqual(
                    $limit,
                    $ceiling,
                    "The ceiling is above php.ini's {$directive}, so uploads at it would be discarded before Laravel saw them.",
                );
            }
        }
    }

    /** The button quotes the real ceiling, so the number on screen is true. */
    public function test_the_camera_button_carries_the_real_ceiling(): void
    {
        Auth::guard('profile')->login($this->raylan);

        Volt::test('family-feed')
            ->call('open', $this->everyone()->id)
            ->assertSee('fqPhotoPicker('.FeedPhotos::uploadCeilingKb().')', false);
    }

    /**
     * The upload must not be started by `wire:model`.
     *
     * That is what made an oversized file unrecoverable: Livewire sends it the
     * instant it is chosen, PHP throws the body away, and the 419 HTML page
     * comes back as a console error with nothing on screen. The picker has to
     * own the upload so it can refuse a file first.
     */
    public function test_the_file_input_does_not_auto_upload(): void
    {
        Auth::guard('profile')->login($this->raylan);

        $html = Volt::test('family-feed')->call('open', $this->everyone()->id)->html();

        $input = substr($html, strpos($html, 'type="file"'), 400);

        $this->assertStringNotContainsString('wire:model', $input);
        $this->assertStringContainsString('choose($event)', $input);
    }

    /**
     * The shorthand php.ini uses for these directives, in every form it takes.
     *
     * Worth pinning because getting it wrong is invisible: a parser that read
     * "12M" as 12 kilobytes would simply refuse every photo, and one that read
     * "2M" as unlimited would go back to dropping them silently.
     */
    public function test_php_ini_size_shorthand_is_read_correctly(): void
    {
        $this->assertSame(2048, FeedPhotos::kilobytesFromShorthand('2M'));
        $this->assertSame(12288, FeedPhotos::kilobytesFromShorthand('12M'));
        $this->assertSame(20480, FeedPhotos::kilobytesFromShorthand('20M'));
        $this->assertSame(8192, FeedPhotos::kilobytesFromShorthand('8192K'));
        $this->assertSame(1048576, FeedPhotos::kilobytesFromShorthand('1G'));
        // A bare number is bytes, which is what php.ini means without a suffix.
        $this->assertSame(2, FeedPhotos::kilobytesFromShorthand('2048'));
        // Case and stray whitespace both turn up in real ini files.
        $this->assertSame(12288, FeedPhotos::kilobytesFromShorthand(' 12m '));

        // "no limit" must not read as "no headroom".
        $this->assertSame(PHP_INT_MAX, FeedPhotos::kilobytesFromShorthand('0'));
        $this->assertSame(PHP_INT_MAX, FeedPhotos::kilobytesFromShorthand(''));
    }

    /** A php.ini shorthand value, in kilobytes, or null when unlimited. */
    private function iniKilobytes(string $directive): ?int
    {
        $kb = FeedPhotos::kilobytesFromShorthand((string) ini_get($directive));

        return $kb === PHP_INT_MAX ? null : $kb;
    }

    /*
     * ------------------------------------------------------------------
     * Who may see one
     * ------------------------------------------------------------------
     */

    public function test_somebody_in_the_room_can_fetch_the_photo(): void
    {
        $message = $this->postPhotoIn($this->everyone(), $this->raylan);

        $this->actingAs($this->westin, 'profile')
            ->get(route('feed.photo', $message))
            ->assertOk()
            ->assertHeader('Content-Type', FeedPhotos::MIME)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'inline');
    }

    /**
     * The reason the bucket is private. A photo in a DM between two kids is not
     * a parent's to open, and a 404 doesn't confirm it exists.
     */
    public function test_a_photo_in_a_kids_dm_is_not_served_to_a_parent(): void
    {
        $room = app(FeedService::class)->directRoomWith($this->raylan, $this->westin);
        $message = $this->postPhotoIn($room, $this->raylan);

        $this->actingAs($this->westin, 'profile')->get(route('feed.photo', $message))->assertOk();
        $this->actingAs($this->mom, 'profile')->get(route('feed.photo', $message))->assertNotFound();
    }

    public function test_a_photo_is_not_served_across_households_or_to_nobody(): void
    {
        $message = $this->postPhotoIn($this->everyone(), $this->raylan);
        $stranger = Profile::factory()->for(Household::factory())->create();

        $this->actingAs($stranger, 'profile')->get(route('feed.photo', $message))->assertNotFound();

        auth('profile')->logout();
        $this->get(route('feed.photo', $message))->assertRedirect();
    }

    public function test_a_message_that_is_not_a_photo_serves_nothing_on_the_photo_route(): void
    {
        $text = app(FeedService::class)->say($this->raylan, $this->everyone(), 'hello');

        $this->actingAs($this->westin, 'profile')->get(route('feed.photo', $text))->assertNotFound();
    }

    /**
     * The two names reach one controller, and it answers on the kind of the
     * message rather than on which name was used — so a drawing asked for down
     * the photo route is still a drawing, and a photo is still a photo.
     */
    public function test_the_two_media_routes_are_one_gate(): void
    {
        $photo = $this->postPhotoIn($this->everyone(), $this->raylan);

        $this->actingAs($this->westin, 'profile')
            ->get(route('feed.drawing', $photo))
            ->assertOk()
            ->assertHeader('Content-Type', FeedPhotos::MIME);

        $this->assertSame(route('feed.photo', $photo), $photo->mediaUrl());
    }

    public function test_a_photo_row_with_no_file_behind_it_serves_nothing(): void
    {
        $message = $this->postPhotoIn($this->everyone(), $this->raylan);

        Storage::disk('drawings')->delete($message->image_path);

        $this->actingAs($this->westin, 'profile')->get(route('feed.photo', $message))->assertNotFound();
    }

    public function test_a_photo_cannot_be_posted_into_a_room_you_cannot_read(): void
    {
        $room = app(FeedService::class)->directRoomWith($this->raylan, $this->westin);
        $outsider = Profile::factory()->for($this->household)->create(['name' => 'Nobody']);

        $this->expectException(HttpException::class);

        try {
            $this->postPhotoIn($room, $outsider);
        } finally {
            $this->assertSame([], Storage::disk('drawings')->allFiles());
        }
    }

    /*
     * ------------------------------------------------------------------
     * The throttle
     * ------------------------------------------------------------------
     */

    /**
     * Talking is deliberately unlimited; pictures are not. Every one is a write
     * to a bucket somebody pays for, and a camera button is how a six-year-old
     * discovers a camera button.
     */
    public function test_a_flood_of_pictures_is_refused_after_the_limit(): void
    {
        RateLimiter::clear('feed-media:'.$this->raylan->id);

        for ($i = 0; $i < FeedService::MEDIA_PER_WINDOW; $i++) {
            $this->postPhotoIn($this->everyone(), $this->raylan);
        }

        $this->assertCount(FeedService::MEDIA_PER_WINDOW, Storage::disk('drawings')->allFiles());

        $this->expectException(\RuntimeException::class);

        try {
            $this->postPhotoIn($this->everyone(), $this->raylan);
        } finally {
            // Nothing extra written, and the sibling is unaffected: the limit
            // is per person, not per house.
            $this->assertCount(FeedService::MEDIA_PER_WINDOW, Storage::disk('drawings')->allFiles());
        }
    }

    public function test_the_throttle_is_per_person(): void
    {
        RateLimiter::clear('feed-media:'.$this->raylan->id);
        RateLimiter::clear('feed-media:'.$this->westin->id);

        for ($i = 0; $i < FeedService::MEDIA_PER_WINDOW; $i++) {
            $this->postPhotoIn($this->everyone(), $this->raylan);
        }

        // Westin has posted nothing and is not held up by his brother.
        $message = $this->postPhotoIn($this->everyone(), $this->westin);

        $this->assertSame(FeedMessageKind::Photo, $message->kind);
    }

    /*
     * ------------------------------------------------------------------
     * Fixtures
     * ------------------------------------------------------------------
     */

    /**
     * A JPEG carrying a real, parseable EXIF orientation tag.
     *
     * Hand-built rather than faked, because the point is that exif_read_data()
     * genuinely reads it: an APP1 whose bytes do not parse would leave this
     * passing for the wrong reason, with the rotation never attempted.
     */
    private function jpegOriented(int $width, int $height, int $orientation): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 160, 90));

        ob_start();
        imagejpeg($image, null, 92);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        // Little-endian TIFF header, magic 42, IFD0 at byte 8; then one entry:
        // tag 0x0112 (Orientation), type 3 (SHORT), count 1, the value; then a
        // zero offset saying there is no IFD1.
        $tiff = 'II'.pack('v', 42).pack('V', 8)
            .pack('v', 1)
            .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation)."\x00\x00"
            .pack('V', 0);

        $payload = "Exif\x00\x00".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
    }

    /** A real JPEG carrying an APP1/Exif segment with a GPS tag in it. */
    private function jpegCarryingExif(): string
    {
        $image = imagecreatetruecolor(60, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 120, 200));

        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        // A hand-built APP1 segment. Its contents do not have to parse as a
        // real IFD — the assertion is that these bytes are gone afterwards,
        // and a decoder that keeps them would keep a real one just the same.
        $payload = "Exif\x00\x00".str_repeat("GPSLatitude\x00", 8);
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        // Straight after the SOI marker, which is where an APP1 belongs.
        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    /**
     * A tiny PNG whose header honestly claims to be enormous.
     *
     * The IHDR carries the dimensions and its CRC has to agree with them, or
     * getimagesize() refuses to read the header at all and the test would pass
     * for the wrong reason.
     */
    private function pngClaiming(int $width, int $height): string
    {
        $ihdr = 'IHDR'.pack('NN', $width, $height)."\x08\x02\x00\x00\x00";

        return "\x89PNG\x0d\x0a\x1a\x0a"
            .pack('N', 13).$ihdr.pack('N', crc32($ihdr))
            .pack('N', 0).'IEND'.pack('N', crc32('IEND'));
    }
}
