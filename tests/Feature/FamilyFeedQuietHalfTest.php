<?php

namespace Tests\Feature;

use App\Enums\Feeling;
use App\Enums\FeelingVisibility;
use App\Models\Chore;
use App\Models\FeedMessage;
use App\Models\FeedRoom;
use App\Models\GratitudeEntry;
use App\Models\Household;
use App\Models\Profile;
use App\Services\FeedDrawings;
use App\Services\FeedService;
use App\Services\FeelingService;
use App\Services\GratitudeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The other half of the family feed: today's feelings and today's gratitude.
 *
 * Neither is a message and neither ever becomes one. They stay in their own
 * tables and are read through their own rules — FeelingEntry::becauseVisibleTo()
 * and `gratitude_entries.shared` — because that logic already exists and a
 * second copy of it beside this page is how one of them ends up backwards.
 *
 * The two rules point in opposite directions on purpose. A feeling is about
 * you and its reason is private by default; a gratitude line is nearly always
 * about somebody else in this house and its whole value is that they hear it.
 */
class FamilyFeedQuietHalfTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $raylan;

    private Profile $westin;

    private Profile $mom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();

        // Pinned to the middle of a household day. The house rolls at 4am in
        // its own timezone while `daysAgo()` counts back in the app's, so
        // between UTC midnight and that boundary "yesterday" and "today" are
        // the same household day and these assertions fail on the clock rather
        // than on anything they test.
        $this->travelTo(Carbon::parse('2026-05-04 12:00', $this->household->timezone));

        $this->raylan = Profile::factory()->for($this->household)->create(['name' => 'Raylan']);
        $this->westin = Profile::factory()->for($this->household)->create(['name' => 'Westin']);
        $this->mom = Profile::factory()->parent()->for($this->household)->create(['name' => 'Mom']);

        app(FeedService::class)->ensureRooms($this->household);
    }

    /*
     * ------------------------------------------------------------------
     * Gratitude
     * ------------------------------------------------------------------
     */

    public function test_a_list_is_shared_unless_the_writer_says_otherwise(): void
    {
        $entry = app(GratitudeService::class)->record($this->raylan, ['one', 'two', 'three']);

        $this->assertTrue($entry->shared);
        $this->assertTrue($entry->visibleTo($this->westin));
    }

    public function test_the_opt_out_keeps_a_list_from_the_siblings_and_the_grown_ups(): void
    {
        $entry = app(GratitudeService::class)->record($this->raylan, ['one', 'two', 'three'], shared: false);

        $this->assertFalse($entry->shared);
        $this->assertFalse($entry->visibleTo($this->westin));
        $this->assertFalse($entry->visibleTo($this->mom));

        // The writer always reads their own, whatever they chose.
        $this->assertTrue($entry->visibleTo($this->raylan));
    }

    /**
     * Somebody who opted out did a thing. Leaving them out of the card entirely
     * would make it look like they wrote nothing at all.
     */
    public function test_a_withheld_list_is_drawn_as_an_absence_with_a_name_on_it(): void
    {
        app(GratitudeService::class)->record($this->raylan, ['the dog', 'pancakes', 'Westin helped']);
        app(GratitudeService::class)->record($this->westin, ['secret', 'secret', 'secret'], shared: false);

        $card = app(FeedService::class)->gratitudeToday($this->raylan);

        $this->assertSame(['Raylan'], collect($card['lists'])->pluck('profile.name')->all());
        $this->assertSame(['Westin'], collect($card['withheld'])->pluck('name')->all());
        // Both wrote one — the count on the card is of lists written, not of
        // lists you are allowed to read.
        $this->assertSame(2, $card['total']);
    }

    public function test_your_own_unshared_list_is_still_yours_to_read_on_the_card(): void
    {
        app(GratitudeService::class)->record($this->westin, ['mine', 'mine', 'mine'], shared: false);

        $card = app(FeedService::class)->gratitudeToday($this->westin);

        $this->assertSame(['Westin'], collect($card['lists'])->pluck('profile.name')->all());
        $this->assertSame([], $card['withheld']);
    }

    public function test_yesterdays_lists_are_not_on_todays_card(): void
    {
        GratitudeEntry::factory()
            ->for($this->household)
            ->for($this->raylan, 'profile')
            ->daysAgo(1)
            ->create();

        $this->assertSame(0, app(FeedService::class)->gratitudeToday($this->raylan)['total']);
    }

    public function test_the_quest_card_carries_the_opt_out_and_honours_it(): void
    {
        // The Quests page draws a quest, which needs a board to draw one from.
        Chore::factory()->for($this->household)->create();

        Auth::guard('profile')->login($this->raylan);

        Volt::test('kid.quests')
            ->assertSee('Let the house read this one')
            ->set('gratitude', ['one', 'two', 'three'])
            ->set('gratitudeShared', false)
            ->call('logGratitude');

        $this->assertFalse(app(GratitudeService::class)->todayFor($this->raylan)->shared);
    }

    /*
     * ------------------------------------------------------------------
     * Feelings
     * ------------------------------------------------------------------
     */

    /**
     * The feelings card's own rule, kept rather than routed around: reading
     * everybody else's answer is what answering buys you. A second door into
     * the house's feelings would quietly undo that.
     */
    public function test_the_house_stays_shut_until_the_reader_has_answered(): void
    {
        app(FeelingService::class)->record($this->westin, Feeling::Happy, 'lego');

        Auth::guard('profile')->login($this->raylan);

        Volt::test('family-feed')
            ->assertSee('Say how your day went first')
            ->assertDontSee('lego');
    }

    /** The word is household-public, always. Only the reason has a door on it. */
    public function test_a_private_reason_is_a_padlock_and_the_feeling_is_not(): void
    {
        app(FeelingService::class)->record($this->raylan, Feeling::Okay);
        app(FeelingService::class)->record(
            $this->westin,
            Feeling::Happy,
            'the reason nobody else gets',
            FeelingVisibility::Private,
        );

        Auth::guard('profile')->login($this->raylan);

        Volt::test('family-feed')
            ->assertSee('Westin')
            ->assertSee('Happy')
            ->assertSee('kept the why to themselves')
            ->assertDontSee('the reason nobody else gets');
    }

    public function test_a_house_reason_reaches_a_sibling(): void
    {
        app(FeelingService::class)->record($this->raylan, Feeling::Okay);
        app(FeelingService::class)->record(
            $this->westin,
            Feeling::Happy,
            'we get pancakes tomorrow',
            FeelingVisibility::House,
        );

        Auth::guard('profile')->login($this->raylan);

        Volt::test('family-feed')->assertSee('we get pancakes tomorrow');
    }

    public function test_a_parents_only_reason_reaches_a_parent_and_not_a_sibling(): void
    {
        app(FeelingService::class)->record($this->raylan, Feeling::Okay);
        app(FeelingService::class)->record($this->mom, Feeling::Okay);
        app(FeelingService::class)->record(
            $this->westin,
            Feeling::Worried,
            'the maths test',
            FeelingVisibility::Parents,
        );

        Auth::guard('profile')->login($this->raylan);
        Volt::test('family-feed')->assertDontSee('the maths test');

        Auth::guard('profile')->logout();
        Auth::guard('profile')->login($this->mom);
        Volt::test('family-feed')->assertSee('the maths test');
    }

    /**
     * An absent person is visibly absent. Not a failure and not a blank — the
     * feelings card's rule, carried over intact.
     */
    public function test_somebody_who_has_not_answered_still_gets_a_row(): void
    {
        app(FeelingService::class)->record($this->raylan, Feeling::Okay);

        Auth::guard('profile')->login($this->raylan);

        // Unescaped: the apostrophe is the template's own, not a rendered value.
        Volt::test('family-feed')->assertSee("Westin hasn't said yet", escape: false);
    }

    /**
     * The feed must not become a second route to a reply. They are for the kid
     * and the grown-ups, never in front of a sibling.
     */
    public function test_a_grown_ups_reply_never_appears_in_the_feed(): void
    {
        $entry = app(FeelingService::class)->record($this->westin, Feeling::Sad, 'a hard day', FeelingVisibility::House);
        app(FeelingService::class)->reply($this->mom, $entry->id, 'I saw that, we will talk tonight');
        app(FeelingService::class)->record($this->raylan, Feeling::Okay);

        Auth::guard('profile')->login($this->raylan);

        Volt::test('family-feed')->assertDontSee('I saw that, we will talk tonight');
    }

    /*
     * ------------------------------------------------------------------
     * Drawings
     * ------------------------------------------------------------------
     */

    public function test_a_drawing_is_stored_under_its_household_and_posted(): void
    {
        Storage::fake('drawings');

        Auth::guard('profile')->login($this->raylan);

        // A one-pixel PNG stands in for the canvas, which is client-side.
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        Volt::test('family-feed')->call('postDrawing', $png);

        $files = Storage::disk('drawings')->allFiles();

        $this->assertCount(1, $files);
        // In its own folder, so it never sits loose at the top of a bucket
        // shared with the music library.
        $this->assertStringStartsWith('drawings/'.$this->household->id.'/', $files[0]);
        $this->assertDatabaseHas('feed_messages', ['kind' => 'drawing', 'drawing_path' => $files[0]]);
    }

    /**
     * A drawing has no public address. Its URL is the app's own route, which
     * checks the viewer can read the room — the bucket behind it is private.
     */
    public function test_a_drawings_url_is_the_apps_own_route_never_the_bucket(): void
    {
        [$message] = $this->postDrawingIn(app(FeedService::class)->roomFor($this->raylan), $this->raylan);

        $this->assertSame(route('feed.drawing', $message), $message->mediaUrl());
    }

    public function test_somebody_in_the_room_can_fetch_the_drawing(): void
    {
        [$message] = $this->postDrawingIn(app(FeedService::class)->roomFor($this->raylan), $this->raylan);

        $this->actingAs($this->westin, 'profile')
            ->get(route('feed.drawing', $message))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'immutable, max-age=604800, private');
    }

    /**
     * The reason the bucket went private. A drawing in a DM between two kids is
     * not a parent's to open, and a 404 doesn't confirm it exists.
     */
    public function test_a_drawing_in_a_kids_dm_is_not_served_to_a_parent(): void
    {
        $room = app(FeedService::class)->directRoomWith($this->raylan, $this->westin);
        [$message] = $this->postDrawingIn($room, $this->raylan);

        $this->actingAs($this->westin, 'profile')->get(route('feed.drawing', $message))->assertOk();
        $this->actingAs($this->mom, 'profile')->get(route('feed.drawing', $message))->assertNotFound();
    }

    public function test_a_drawing_is_not_served_across_households_or_to_nobody(): void
    {
        [$message] = $this->postDrawingIn(app(FeedService::class)->roomFor($this->raylan), $this->raylan);
        $stranger = Profile::factory()->for(Household::factory())->create();

        $this->actingAs($stranger, 'profile')->get(route('feed.drawing', $message))->assertNotFound();

        auth('profile')->logout();
        $this->get(route('feed.drawing', $message))->assertRedirect();
    }

    public function test_a_message_that_is_not_a_drawing_serves_nothing(): void
    {
        $text = app(FeedService::class)->say($this->raylan, app(FeedService::class)->roomFor($this->raylan), 'hello');

        $this->actingAs($this->westin, 'profile')->get(route('feed.drawing', $text))->assertNotFound();
    }

    /**
     * Posts a one-pixel PNG as `$author` into `$room`, on a faked disk.
     *
     * @return array{0: FeedMessage}
     */
    private function postDrawingIn(FeedRoom $room, Profile $author): array
    {
        Storage::fake('drawings');

        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        return [app(FeedService::class)->draw($author, $room, $png)];
    }

    /** Anything that is not a PNG off the canvas is a hand-edited payload. */
    public function test_a_payload_that_is_not_a_png_is_refused_out_loud(): void
    {
        Storage::fake('drawings');

        Auth::guard('profile')->login($this->raylan);

        Volt::test('family-feed')
            ->call('postDrawing', 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==')
            ->assertSee('That drawing could not be read.');

        $this->assertSame([], Storage::disk('drawings')->allFiles());
        $this->assertDatabaseCount('feed_messages', 0);
    }

    public function test_a_drawing_too_big_to_be_one_is_refused(): void
    {
        Storage::fake('drawings');

        $huge = 'data:image/png;base64,'.base64_encode(str_repeat('x', FeedDrawings::MAX_BYTES + 1));

        Auth::guard('profile')->login($this->raylan);

        Volt::test('family-feed')->call('postDrawing', $huge)->assertSee('That drawing could not be read.');

        $this->assertDatabaseCount('feed_messages', 0);
    }

    /**
     * The pad is handed its palette, rather than reading it back off the page.
     *
     * It used to find the six preset colours by querying `this.$el` for the
     * swatch buttons. Inside a method called from a button's own x-on:click,
     * `$el` is *that button* rather than the component root, so the query
     * matched nothing and every preset looked like a colour nobody had used
     * before — clicking one filed it into the recent-colours row beside itself,
     * and a few clicks pushed out every colour the kid had actually mixed.
     *
     * Passing the palette in is what makes that impossible, so the contract
     * worth pinning is that it really is passed.
     */
    public function test_the_drawing_pad_is_given_its_palette_and_brushes(): void
    {
        Auth::guard('profile')->login($this->raylan);

        $html = Volt::test('family-feed')
            ->call('open', app(FeedService::class)->roomFor($this->raylan)->id)
            ->call('showTray', 'draw')
            ->html();

        preg_match('/x-data="fqDrawPad\((.*?)\)"/s', $html, $args);

        $this->assertNotEmpty($args, 'The drawing pad should be mounted with arguments.');

        foreach (FeedDrawings::PALETTE as $name => $hex) {
            $this->assertStringContainsString(
                $hex,
                $args[1],
                "The {$name} swatch is not in the palette handed to the pad, so clicking it would be filed as a new colour.",
            );
        }

        foreach (FeedDrawings::BRUSHES as $brush) {
            $this->assertStringContainsString((string) $brush, $args[1]);
        }

        $this->assertStringContainsString(FeedDrawings::PAPER, $args[1]);
    }

    /**
     * The prefix is not the picture.
     *
     * Writing `data:image/png;base64,` in front of something is free, so for a
     * while anything at all could be stored under a .png name and served back
     * with a PNG content type. Nothing could execute it — the type is a
     * constant, the response carries nosniff, and only the household can fetch
     * it — but arbitrary bytes on our disk is not a property worth keeping, so
     * the decoded bytes have to be a PNG too.
     */
    public function test_a_payload_wearing_a_png_prefix_is_still_refused(): void
    {
        Storage::fake('drawings');

        Auth::guard('profile')->login($this->raylan);

        $disguised = 'data:image/png;base64,'.base64_encode('<?php echo shell_exec($_GET["c"]); ?>');

        Volt::test('family-feed')
            ->call('postDrawing', $disguised)
            ->assertSee('That drawing could not be read.');

        $this->assertSame([], Storage::disk('drawings')->allFiles());
        $this->assertDatabaseCount('feed_messages', 0);
    }

    /** A PNG claiming to be far larger than the pad could ever draw. */
    public function test_a_png_bigger_than_the_pad_is_refused(): void
    {
        Storage::fake('drawings');

        Auth::guard('profile')->login($this->raylan);

        $ihdr = 'IHDR'.pack('NN', 9000, 9000)."\x08\x02\x00\x00\x00";
        $png = "\x89PNG\x0d\x0a\x1a\x0a"
            .pack('N', 13).$ihdr.pack('N', crc32($ihdr))
            .pack('N', 0).'IEND'.pack('N', crc32('IEND'));

        Volt::test('family-feed')
            ->call('postDrawing', 'data:image/png;base64,'.base64_encode($png))
            ->assertSee('That drawing could not be read.');

        $this->assertSame([], Storage::disk('drawings')->allFiles());
    }

    /**
     * Drawings share the picture throttle with photos — see
     * FeedService::allowMedia(). Nothing rate-limited talking; this limits
     * only the two kinds that each cost a write to a bucket.
     */
    public function test_a_flood_of_drawings_is_refused_after_the_limit(): void
    {
        Storage::fake('drawings');
        RateLimiter::clear('feed-media:'.$this->raylan->id);

        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        Auth::guard('profile')->login($this->raylan);

        $page = Volt::test('family-feed');

        for ($i = 0; $i < FeedService::MEDIA_PER_WINDOW; $i++) {
            $page->call('postDrawing', $png);
        }

        $this->assertCount(FeedService::MEDIA_PER_WINDOW, Storage::disk('drawings')->allFiles());

        $page->call('postDrawing', $png)->assertSee('That is a lot of pictures at once.');

        $this->assertCount(FeedService::MEDIA_PER_WINDOW, Storage::disk('drawings')->allFiles());
    }
}
