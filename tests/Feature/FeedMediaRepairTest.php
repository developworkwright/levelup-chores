<?php

namespace Tests\Feature;

use App\Models\FeedMessage;
use App\Models\Household;
use App\Models\Profile;
use App\Services\FeedDrawings;
use App\Services\FeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pictures whose row outlived the shape of its own path.
 *
 * Drawings posted before the `drawings/` folder moved into the path are filed
 * on the disk exactly where they always were — the row just describes them the
 * old way, so the controller streams a key that is not there and everybody in
 * the room gets a 404. The repair is a rename of the *row*, never of the file.
 *
 * The rule that makes it safe to run against a live bucket: a path is only
 * rewritten when the file is found at the new one. Anything else is reported
 * and left alone.
 */
class FeedMediaRepairTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $raylan;

    private Profile $westin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('drawings');

        $this->household = Household::factory()->create();
        $this->raylan = Profile::factory()->for($this->household)->create(['name' => 'Raylan']);
        $this->westin = Profile::factory()->for($this->household)->create(['name' => 'Westin']);

        app(FeedService::class)->ensureRooms($this->household);
    }

    /** A drawing posted the way the app posts one today. */
    private function drawing(): FeedMessage
    {
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        return app(FeedService::class)->draw(
            $this->raylan,
            app(FeedService::class)->roomFor($this->raylan),
            $png,
        );
    }

    /**
     * The bug itself: the file is under `drawings/`, the row says it is not,
     * and the fix is to agree with the disk.
     */
    public function test_a_path_written_before_the_folder_moved_is_re_pointed(): void
    {
        $message = $this->drawing();
        $filed = $message->drawing_path;

        $this->assertStringStartsWith('drawings/', $filed);

        // Wound back to how the row would have been written before the folder
        // lived in the path. The file does not move; only the row is wrong.
        $message->forceFill(['drawing_path' => substr($filed, strlen('drawings/'))])->save();

        $this->artisan('feed:repair-media', ['--apply' => true])->assertSuccessful();

        $this->assertSame($filed, $message->refresh()->drawing_path);
        $this->assertTrue(app(FeedDrawings::class)->disk()->exists($filed));
    }

    /** And the picture comes back for the room it was posted in. */
    public function test_the_repaired_drawing_is_served_again(): void
    {
        $message = $this->drawing();
        $filed = $message->drawing_path;

        $message->forceFill(['drawing_path' => substr($filed, strlen('drawings/'))])->save();

        $this->actingAs($this->westin, 'profile')
            ->get(route('feed.drawing', $message))
            ->assertNotFound();

        $this->artisan('feed:repair-media', ['--apply' => true]);

        $this->actingAs($this->westin, 'profile')
            ->get(route('feed.drawing', $message))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    /** Without `--apply` it is a report and nothing else. */
    public function test_a_dry_run_writes_nothing(): void
    {
        $message = $this->drawing();
        $broken = substr($message->drawing_path, strlen('drawings/'));

        $message->forceFill(['drawing_path' => $broken])->save();

        $this->artisan('feed:repair-media')->assertSuccessful();

        $this->assertSame($broken, $message->refresh()->drawing_path);
    }

    /**
     * A picture that is on no disk at all keeps its row.
     *
     * It is the only record left that the drawing ever happened, and throwing
     * it away is a decision for whoever is looking at the report — not
     * something a repair run should do on the way past.
     */
    public function test_a_picture_with_no_file_anywhere_is_left_alone(): void
    {
        $message = $this->drawing();

        app(FeedDrawings::class)->disk()->delete($message->drawing_path);
        $message->forceFill(['drawing_path' => 'gone/forever.png'])->save();

        $this->artisan('feed:repair-media', ['--apply' => true])->assertSuccessful();

        $this->assertSame('gone/forever.png', $message->refresh()->drawing_path);
        $this->assertDatabaseHas('feed_messages', ['id' => $message->id]);
    }

    /** A healthy row is never touched. */
    public function test_a_picture_that_already_resolves_is_untouched(): void
    {
        $message = $this->drawing();
        $filed = $message->drawing_path;

        $this->artisan('feed:repair-media', ['--apply' => true])->assertSuccessful();

        $this->assertSame($filed, $message->refresh()->drawing_path);
    }
}
