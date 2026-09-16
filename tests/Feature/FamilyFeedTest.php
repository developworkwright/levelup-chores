<?php

namespace Tests\Feature;

use App\Enums\FeedMessageKind;
use App\Enums\FeedRoomKind;
use App\Models\FeedMessage;
use App\Models\FeedReaction;
use App\Models\FeedRoom;
use App\Models\Household;
use App\Models\Profile;
use App\Services\FeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The family feed — somewhere for the kids to talk.
 *
 * Most of this file is about who can read what. That is not defensiveness: the
 * house chose full trust, so there is no moderation queue and no approval step,
 * and the *only* thing standing in their place is FeedRoom::readableBy() plus a
 * who-can-read line drawn under every room name. Getting one of these backwards
 * is the one bug in this feature that would actually matter, so there is a test
 * per branch rather than one test that walks the happy path.
 */
class FamilyFeedTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $raylan;

    private Profile $westin;

    private Profile $mom;

    private Profile $dad;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->raylan = Profile::factory()->for($this->household)->create(['name' => 'Raylan']);
        $this->westin = Profile::factory()->for($this->household)->create(['name' => 'Westin']);
        $this->mom = Profile::factory()->parent()->for($this->household)->create(['name' => 'Mom']);
        $this->dad = Profile::factory()->parent()->for($this->household)->create(['name' => 'Dad']);

        $this->feed()->ensureRooms($this->household);
    }

    private function feed(): FeedService
    {
        return app(FeedService::class);
    }

    private function room(FeedRoomKind $kind, ?Profile $for = null): FeedRoom
    {
        return FeedRoom::where('household_id', $this->household->id)
            ->where('kind', $kind)
            ->when($for, fn ($q) => $q->where('for_profile_id', $for->id))
            ->firstOrFail();
    }

    /*
     * ------------------------------------------------------------------
     * Rooms
     * ------------------------------------------------------------------
     */

    public function test_a_household_gets_everyone_kids_and_one_line_to_the_grown_ups_per_kid(): void
    {
        $this->assertSame(1, FeedRoom::where('kind', FeedRoomKind::Everyone)->count());
        $this->assertSame(1, FeedRoom::where('kind', FeedRoomKind::Kids)->count());

        // One per kid, and none for the grown-ups: a parents-only room was
        // considered and rejected. Mom and Dad have phones.
        $this->assertSame(2, FeedRoom::where('kind', FeedRoomKind::Parents)->count());
        $this->assertNotNull($this->room(FeedRoomKind::Parents, $this->raylan));
        $this->assertNotNull($this->room(FeedRoomKind::Parents, $this->westin));
    }

    public function test_seeding_twice_makes_nothing_twice(): void
    {
        $this->feed()->ensureRooms($this->household);
        $this->feed()->ensureRooms($this->household);

        $this->assertSame(4, FeedRoom::where('household_id', $this->household->id)->count());
    }

    public function test_a_kid_added_later_gets_their_own_line_to_the_grown_ups(): void
    {
        $colton = Profile::factory()->for($this->household)->create(['name' => 'Colton']);

        $this->feed()->ensureRooms($this->household);

        $this->assertNotNull($this->room(FeedRoomKind::Parents, $colton));
    }

    /**
     * Everyone and Kids derive their membership from role rather than storing
     * it, so a kid who arrives after the room did is in it immediately.
     */
    public function test_group_membership_is_derived_rather_than_stored(): void
    {
        $colton = Profile::factory()->for($this->household)->create(['name' => 'Colton']);
        $roster = $this->feed()->roster($this->household->refresh());

        $kids = $this->room(FeedRoomKind::Kids)->membersFrom($roster);

        $this->assertTrue($kids->contains(fn (Profile $p) => $p->is($colton)));
        $this->assertFalse($kids->contains(fn (Profile $p) => $p->is($this->mom)));
    }

    /*
     * ------------------------------------------------------------------
     * Who can read what — the whole policy, one test per branch
     * ------------------------------------------------------------------
     */

    public function test_everyone_is_readable_by_everyone(): void
    {
        $room = $this->room(FeedRoomKind::Everyone);

        foreach ([$this->raylan, $this->westin, $this->mom, $this->dad] as $member) {
            $this->assertTrue($room->readableBy($member));
        }
    }

    /**
     * A decision, not an oversight: the kids' room is quieter, not secret. The
     * header says so on every screen that opens it, which is the half of this
     * that makes it honest — see the audience-line test below.
     */
    public function test_the_kids_room_is_readable_by_the_grown_ups_too(): void
    {
        $room = $this->room(FeedRoomKind::Kids);

        $this->assertTrue($room->readableBy($this->raylan));
        $this->assertTrue($room->readableBy($this->mom));
    }

    public function test_a_kids_line_to_the_grown_ups_is_not_readable_by_a_sibling(): void
    {
        $room = $this->room(FeedRoomKind::Parents, $this->raylan);

        $this->assertTrue($room->readableBy($this->raylan));
        $this->assertTrue($room->readableBy($this->mom));
        $this->assertTrue($room->readableBy($this->dad));
        $this->assertFalse($room->readableBy($this->westin));
    }

    /**
     * The one rule here that cannot be quietly changed later without breaking a
     * promise the room header has already made to those two.
     */
    public function test_a_direct_room_between_two_kids_is_not_readable_by_a_parent(): void
    {
        $room = $this->feed()->directRoomWith($this->raylan, $this->westin);

        $this->assertTrue($room->readableBy($this->raylan));
        $this->assertTrue($room->readableBy($this->westin));
        $this->assertFalse($room->readableBy($this->mom));
        $this->assertFalse($room->readableBy($this->dad));
    }

    public function test_no_room_is_readable_from_another_household(): void
    {
        $stranger = Profile::factory()->for(Household::factory())->create();

        foreach ([FeedRoomKind::Everyone, FeedRoomKind::Kids] as $kind) {
            $this->assertFalse($this->room($kind)->readableBy($stranger));
        }

        $this->assertFalse($this->room(FeedRoomKind::Parents, $this->raylan)->readableBy($stranger));
    }

    public function test_the_room_list_leaves_out_what_the_reader_cannot_open(): void
    {
        $this->feed()->directRoomWith($this->raylan, $this->westin);

        $momsRooms = collect($this->feed()->roomsFor($this->mom))->pluck('room.id');
        $direct = FeedRoom::where('kind', FeedRoomKind::Direct)->firstOrFail();

        $this->assertFalse($momsRooms->contains($direct->id));
        $this->assertTrue(collect($this->feed()->roomsFor($this->raylan))->pluck('room.id')->contains($direct->id));
    }

    /*
     * ------------------------------------------------------------------
     * The copy that makes the policy honest
     * ------------------------------------------------------------------
     */

    public function test_every_room_says_who_can_read_it(): void
    {
        $roster = $this->feed()->roster($this->household);

        $this->assertSame(
            'All four of you can read this',
            $this->room(FeedRoomKind::Everyone)->audienceLineFor($this->raylan, $roster),
        );

        // The asymmetry, stated on the kids' own screen rather than hidden.
        $this->assertSame(
            'The two of you can read this — Dad and Mom can open it too',
            $this->room(FeedRoomKind::Kids)->audienceLineFor($this->raylan, $roster),
        );

        $this->assertSame(
            'Just you, Dad and Mom',
            $this->room(FeedRoomKind::Parents, $this->raylan)->audienceLineFor($this->raylan, $roster),
        );

        $this->assertSame(
            'Just you and Westin',
            $this->feed()->directRoomWith($this->raylan, $this->westin)->audienceLineFor($this->raylan, $roster),
        );
    }

    /**
     * A kid's line to the grown-ups is "Dad and Mom" to him and
     * "Raylan & grown-ups" to them — from their side there are two of these and
     * whose it is is the only thing telling them apart.
     */
    public function test_a_line_to_the_grown_ups_is_named_from_both_sides(): void
    {
        $roster = $this->feed()->roster($this->household);
        $room = $this->room(FeedRoomKind::Parents, $this->raylan);

        $this->assertSame('Dad and Mom', $room->nameFor($this->raylan, $roster));
        $this->assertSame('Raylan & grown-ups', $room->nameFor($this->mom, $roster));
    }

    public function test_the_composer_placeholder_names_the_room(): void
    {
        $roster = $this->feed()->roster($this->household);

        $this->assertSame(
            'Message everyone…',
            $this->room(FeedRoomKind::Everyone)->composerPlaceholderFor($this->raylan, $roster),
        );
        $this->assertSame(
            'Message the kids…',
            $this->room(FeedRoomKind::Kids)->composerPlaceholderFor($this->raylan, $roster),
        );
        $this->assertSame(
            'Message Westin…',
            $this->feed()->directRoomWith($this->raylan, $this->westin)->composerPlaceholderFor($this->raylan, $roster),
        );
    }

    /*
     * ------------------------------------------------------------------
     * Saying things
     * ------------------------------------------------------------------
     */

    public function test_saying_something_posts_it_and_moves_the_room_up_the_list(): void
    {
        $room = $this->room(FeedRoomKind::Everyone);

        $message = $this->feed()->say($this->raylan, $room, '  who wants to build   the castle ');

        $this->assertNotNull($message);
        $this->assertSame('who wants to build the castle', $message->body);
        $this->assertNotNull($room->refresh()->last_message_at);
    }

    public function test_a_blank_message_goes_nowhere(): void
    {
        $this->assertNull($this->feed()->say($this->raylan, $this->room(FeedRoomKind::Everyone), '   '));
        $this->assertSame(0, FeedMessage::count());
    }

    public function test_nothing_can_be_posted_into_a_room_the_author_cannot_read(): void
    {
        $room = $this->room(FeedRoomKind::Parents, $this->raylan);

        $this->expectException(HttpException::class);

        $this->feed()->say($this->westin, $room, 'let me in');
    }

    /** Colton is six and cannot type a sentence. A stamp is a whole message. */
    public function test_a_stamp_is_a_message_in_its_own_right(): void
    {
        $message = $this->feed()->stamp($this->westin, $this->room(FeedRoomKind::Everyone), 'hug');

        $this->assertNotNull($message);
        $this->assertSame(FeedMessageKind::Stamp, $message->kind);
        $this->assertSame('🤗', $message->stamp->glyph());
    }

    public function test_a_stamp_nobody_drew_is_refused(): void
    {
        $this->assertNull($this->feed()->stamp($this->westin, $this->room(FeedRoomKind::Everyone), 'not-a-stamp'));
    }

    /**
     * A shout-out about somebody who cannot read the room is a thing said
     * behind their back with their name on it.
     */
    public function test_a_shout_out_must_be_about_somebody_who_can_read_the_room(): void
    {
        $raylansLine = $this->room(FeedRoomKind::Parents, $this->raylan);

        $this->assertNull($this->feed()->shoutOut($this->mom, $raylansLine, $this->westin->id, 'Westin was great'));

        $ok = $this->feed()->shoutOut($this->mom, $raylansLine, $this->raylan->id, 'Raylan was great');

        $this->assertNotNull($ok);
        $this->assertSame(FeedMessageKind::Shoutout, $ok->kind);
        $this->assertSame($this->raylan->id, $ok->subject_id);
    }

    /*
     * ------------------------------------------------------------------
     * Reactions
     * ------------------------------------------------------------------
     */

    public function test_a_reaction_toggles_and_only_counts_once_per_person(): void
    {
        $message = $this->feed()->say($this->raylan, $this->room(FeedRoomKind::Everyone), 'lego castle');

        $this->feed()->react($this->westin, $message->id, '👍');
        $this->feed()->react($this->westin, $message->id, '👍');
        $this->feed()->react($this->westin, $message->id, '👍');

        $this->assertSame(1, FeedReaction::where('message_id', $message->id)->count());

        // The third tap took it back off again, so a fourth puts it on.
        $this->feed()->react($this->westin, $message->id, '👍');
        $this->assertSame(0, FeedReaction::where('message_id', $message->id)->count());
    }

    public function test_an_emoji_off_the_sheet_is_refused(): void
    {
        $message = $this->feed()->say($this->raylan, $this->room(FeedRoomKind::Everyone), 'lego castle');

        $this->feed()->react($this->westin, $message->id, '🤮');

        $this->assertSame(0, FeedReaction::count());
    }

    public function test_nobody_can_react_in_a_room_they_cannot_read(): void
    {
        $room = $this->feed()->directRoomWith($this->raylan, $this->westin);
        $message = $this->feed()->say($this->raylan, $room, 'just us');

        $this->feed()->react($this->mom, $message->id, '👍');

        $this->assertSame(0, FeedReaction::count());
    }

    /** An event has no reaction affordance drawn, so nothing may put one there. */
    public function test_an_event_cannot_be_reacted_to(): void
    {
        $event = $this->feed()->event($this->raylan, 'Raylan did a thing');

        $this->feed()->react($this->westin, $event->id, '👍');

        $this->assertSame(0, FeedReaction::count());
    }

    /*
     * ------------------------------------------------------------------
     * Unread
     * ------------------------------------------------------------------
     */

    public function test_your_own_messages_are_never_unread_to_you(): void
    {
        $room = $this->room(FeedRoomKind::Everyone);

        $this->feed()->say($this->raylan, $room, 'one');
        $this->feed()->say($this->raylan, $room, 'two');

        $this->assertSame(0, $this->feed()->unreadTotal($this->raylan));
        $this->assertSame(2, $this->feed()->unreadTotal($this->westin));
    }

    /**
     * No read marker means nothing read, which is the truthful reading and the
     * safe one — the rooms are empty on the day they are created, so no first
     * visit is met with a pile of unread nobody was ever shown.
     */
    public function test_a_reader_who_has_never_opened_a_room_has_read_none_of_it(): void
    {
        $this->feed()->say($this->raylan, $this->room(FeedRoomKind::Everyone), 'hello');

        $this->assertSame(1, $this->feed()->unreadTotal($this->westin));
    }

    public function test_opening_a_room_clears_it(): void
    {
        $room = $this->room(FeedRoomKind::Everyone);
        $this->feed()->say($this->raylan, $room, 'hello');

        $this->feed()->markRead($this->westin, $room);

        $this->assertSame(0, $this->feed()->unreadTotal($this->westin));

        $this->feed()->say($this->raylan, $room, 'again');

        $this->assertSame(1, $this->feed()->unreadTotal($this->westin));
    }

    public function test_unread_never_counts_a_room_the_reader_cannot_open(): void
    {
        $room = $this->feed()->directRoomWith($this->raylan, $this->westin);
        $this->feed()->say($this->raylan, $room, 'just us');

        $this->assertSame(0, $this->feed()->unreadTotal($this->mom));
    }

    /*
     * ------------------------------------------------------------------
     * Events — the app talking, quietly
     * ------------------------------------------------------------------
     */

    public function test_an_event_lands_in_everyone(): void
    {
        $event = $this->feed()->event($this->raylan, '🏁 Raylan slid «412 m» — new record');

        $this->assertNotNull($event);
        $this->assertSame(FeedMessageKind::Event, $event->kind);
        $this->assertSame($this->room(FeedRoomKind::Everyone)->id, $event->room_id);
    }

    /** One per kid per source per household-day, so a hot streak isn't a flood. */
    public function test_events_from_one_source_are_throttled_to_one_a_day(): void
    {
        // Any model will do as a source — the throttle keys on its *type*, so
        // that one kid's good afternoon at the arcade is one line and not ten.
        $source = $this->household;

        $this->assertNotNull($this->feed()->event($this->raylan, 'first', $source));
        $this->assertNull($this->feed()->event($this->raylan, 'second', $source));

        // A different kid is a different line, and so is a different source.
        $this->assertNotNull($this->feed()->event($this->westin, 'theirs', $source));
        $this->assertNotNull($this->feed()->event($this->raylan, 'other kind'));
    }

    /*
     * ------------------------------------------------------------------
     * The page
     * ------------------------------------------------------------------
     */

    public function test_the_kid_page_draws_the_rooms_and_the_who_can_read_line(): void
    {
        Auth::guard('profile')->login($this->raylan);

        Volt::test('family-feed')
            ->assertSee('Everyone')
            ->assertSee('Kids')
            ->assertSee('All four of you can read this');
    }

    /**
     * Every tray renders. They are the only parts of the composer no other test
     * draws, and each one reaches for something the page has to hand it — the
     * stamp sheet the enum, the shout-out tray the room's members, the pad the
     * canvas size.
     */
    public function test_each_composer_tray_renders(): void
    {
        Auth::guard('profile')->login($this->raylan);

        $page = Volt::test('family-feed');

        $page->call('showTray', 'stamps')->assertSee('Give somebody a shout-out');

        // Only people who can read this room, and never yourself.
        $page->call('showTray', 'shout')->assertSee('Westin')->assertDontSee('Raylan');

        $page->call('showTray', 'draw')->assertSee('fqDrawPad', escape: false);
    }

    public function test_a_shout_out_needs_somebody_to_be_about(): void
    {
        Auth::guard('profile')->login($this->raylan);

        Volt::test('family-feed')
            ->set('shoutDraft', 'they were great')
            ->call('sendShoutOut')
            // Escaped: the notice is a rendered value, not template text.
            ->assertSee("Pick who it's about first.");

        $this->assertSame(0, FeedMessage::count());
    }

    /**
     * The box leads, then the messages newest first. A kid glancing at the room
     * should see that they can say something before they see what has been
     * said, and the latest line belongs right under the box, not at the far
     * end of a scroll.
     */
    public function test_the_message_box_sits_above_the_messages_newest_first(): void
    {
        $room = $this->room(FeedRoomKind::Everyone);
        $this->feed()->say($this->westin, $room, 'the older line');
        $this->feed()->say($this->westin, $room, 'the newest line');

        Auth::guard('profile')->login($this->raylan);

        Volt::test('family-feed')
            ->assertSeeInOrder(['Message everyone', 'the newest line', 'the older line'], false);
    }

    /**
     * The feed opens in Everyone, on every screen.
     *
     * A phone used to land on the room list and make you pick before it would
     * show you a word. The answer was Everyone almost every time, so the app was
     * charging a screen and a tap for a question it could answer itself.
     */
    public function test_the_feed_opens_in_everyone_without_being_asked(): void
    {
        $this->feed()->say($this->westin, $this->room(FeedRoomKind::Everyone), 'already here');

        Auth::guard('profile')->login($this->raylan);

        Volt::test('family-feed')
            ->assertSet('roomId', $this->room(FeedRoomKind::Everyone)->id)
            ->assertSee('already here');
    }

    /** Landing in a room reads it, so the count doesn't outlive the reading. */
    public function test_landing_in_everyone_clears_its_unread_count(): void
    {
        $this->feed()->say($this->westin, $this->room(FeedRoomKind::Everyone), 'morning');

        Auth::guard('profile')->login($this->raylan);

        Volt::test('family-feed')->html();

        $this->assertSame(0, $this->feed()->unreadTotal($this->raylan));
    }

    /**
     * What the room list was doing that the room cannot do for itself: saying
     * that something is waiting somewhere else. Folding the list behind the
     * picker without this would have quietly cost the visibility the feed is on
     * Home for.
     */
    public function test_the_picker_carries_the_unread_count_from_the_other_rooms(): void
    {
        $this->feed()->say($this->westin, $this->room(FeedRoomKind::Kids), 'in here instead');

        Auth::guard('profile')->login($this->raylan);

        // Everyone is open, so the one waiting message is elsewhere.
        $picker = $this->picker(Volt::test('family-feed')->html());

        $this->assertStringContainsString('Switch rooms', $picker);
        $this->assertStringContainsString('1 unread elsewhere', $picker);

        // Read it, and the count on the picker goes with it.
        $open = Volt::test('family-feed')->call('open', $this->room(FeedRoomKind::Kids)->id);

        $this->assertStringNotContainsString('unread elsewhere', $this->picker($open->html()));
    }

    /**
     * The picker is the room's header on a phone, so it carries the same
     * who-can-read line the laptop header does. Nobody learns their audience
     * after posting — on any screen.
     */
    public function test_the_picker_draws_the_who_can_read_line(): void
    {
        Auth::guard('profile')->login($this->raylan);

        $everyone = $this->room(FeedRoomKind::Everyone);

        $this->assertStringContainsString(
            $everyone->audienceLineFor($this->raylan, $this->feed()->roster($this->household)),
            $this->picker(Volt::test('family-feed')->html()),
        );
    }

    /**
     * The room list is drawn twice — the laptop's rail and the phone's picker —
     * and a wire:key used twice in one component is one element as far as
     * morphing is concerned, which leaves one of the two copies looking right
     * and doing nothing when tapped.
     */
    public function test_the_two_copies_of_the_room_list_do_not_share_wire_keys(): void
    {
        Auth::guard('profile')->login($this->raylan);

        preg_match_all('/wire:key="([^"]+)"/', Volt::test('family-feed')->html(), $keys);

        $this->assertNotEmpty($keys[1]);
        $this->assertSame(
            $keys[1],
            array_unique($keys[1]),
            'A wire:key is used twice on the page; one of the rows carrying it will stop responding.',
        );
    }

    /** The picker's markup, which is the only part of the page a phone sees. */
    private function picker(string $html): string
    {
        $this->assertSame(1, preg_match('/<div\s+class="relative lg:hidden".*?<\/button>/s', $html, $found));

        return $found[0];
    }

    /**
     * On Home the messages scroll inside a fixed height, so a long conversation
     * can't push the rest of the day down the page — but only on a laptop. The
     * full page has no cap on any screen; there the room is the page.
     */
    public function test_the_messages_scroll_inside_a_capped_box_only_on_home(): void
    {
        Auth::guard('profile')->login($this->raylan);

        $embedded = Volt::test('family-feed', ['embedded' => true])->html();
        $page = Volt::test('family-feed')->html();

        preg_match('/<div[^>]*data-feed-messages[^>]*>/s', $embedded, $home);
        preg_match('/<div[^>]*data-feed-messages[^>]*>/s', $page, $full);

        $this->assertStringContainsString('lg:overflow-y-auto', $home[0]);
        $this->assertStringContainsString('lg:max-h-[520px]', $home[0]);
        $this->assertStringNotContainsString('overflow-y-auto', $full[0]);
    }

    /**
     * The kid's Home gives the room a column of its own with nothing under it,
     * so there the box comes off and the messages simply run down the page.
     * Parent Home keeps it: the approval queues are below.
     */
    public function test_the_kid_home_feed_is_not_capped(): void
    {
        Auth::guard('profile')->login($this->raylan);

        $uncapped = Volt::test('family-feed', ['embedded' => true, 'capped' => false])->html();
        preg_match('/<div[^>]*data-feed-messages[^>]*>/s', $uncapped, $messages);
        $this->assertStringNotContainsString('overflow-y-auto', $messages[0]);

        preg_match('/<div[^>]*data-feed-messages[^>]*>/s', Volt::test('kid.home')->html(), $home);
        $this->assertStringNotContainsString('overflow-y-auto', $home[0]);
    }

    /**
     * And on a phone there is no inner scroller at all.
     *
     * The cap was sized when a message was a line of text. A portrait photo is
     * taller than the box was, which turned it into a letterbox you dragged a
     * picture past — inside a page that is itself scrolling. Two nested
     * scrollers on a touch screen means the page takes over at the boundary and
     * the bottom of the photo cannot be reached, however correct the
     * scrollHeight is. Unprefixed scroll classes here would put that back.
     */
    public function test_home_has_no_nested_scroller_on_a_phone(): void
    {
        Auth::guard('profile')->login($this->raylan);

        preg_match(
            '/<div[^>]*data-feed-messages[^>]*>/s',
            Volt::test('family-feed', ['embedded' => true])->html(),
            $home,
        );

        // Every scrolling class has to carry the lg: prefix.
        foreach (['overflow-y-auto', 'max-h-', 'overscroll-contain'] as $class) {
            $this->assertDoesNotMatchRegularExpression(
                '/(?<!lg:)'.preg_quote($class, '/').'/',
                $home[0],
                "`{$class}` is unprefixed, so it applies on phones and brings back the nested scroller.",
            );
        }
    }

    public function test_sending_from_the_page_posts_into_the_open_room(): void
    {
        Auth::guard('profile')->login($this->raylan);

        Volt::test('family-feed')
            ->set('draft', 'anyone want to play')
            ->call('send')
            ->assertSet('draft', '');

        $this->assertSame('anyone want to play', FeedMessage::firstOrFail()->body);
    }

    public function test_the_page_will_not_open_a_room_the_reader_cannot_read(): void
    {
        $room = $this->feed()->directRoomWith($this->raylan, $this->westin);

        Auth::guard('profile')->login($this->mom);

        // Stays where it landed rather than throwing: a stale link in a PWA
        // should leave you somewhere, not on an error page.
        Volt::test('family-feed')
            ->call('open', $room->id)
            ->assertSet('roomId', $this->room(FeedRoomKind::Everyone)->id);
    }

    /**
     * A family of five does not go looking for who it can talk to. Everybody
     * else is already listed under "Just you two", in a fixed order, with no
     * picker — and listing them creates nothing.
     */
    public function test_just_you_two_lists_everyone_else_without_a_picker(): void
    {
        Auth::guard('profile')->login($this->raylan);

        Volt::test('family-feed')
            ->assertDontSee('Start one')
            ->assertSeeInOrder(['Just you two', 'Dad', 'Mom', 'Westin']);

        $this->assertSame(0, FeedRoom::where('kind', FeedRoomKind::Direct)->count(), 'Listing people makes no rooms.');
    }

    public function test_a_person_with_a_conversation_carries_that_room_and_its_unread(): void
    {
        $room = $this->feed()->directRoomWith($this->raylan, $this->westin);
        $this->feed()->say($this->westin, $room, 'hi raylan');

        $people = collect($this->feed()->peopleFor($this->raylan, $this->feed()->roomsFor($this->raylan)));
        $westin = $people->first(fn (array $p) => $p['profile']->is($this->westin));
        $mom = $people->first(fn (array $p) => $p['profile']->is($this->mom));

        $this->assertCount(3, $people, 'Everyone but yourself.');
        $this->assertTrue($westin['started']);
        $this->assertSame($room->id, $westin['entry']['room']->id);
        $this->assertSame(1, $westin['entry']['unread']);
        $this->assertFalse($mom['started']);
        $this->assertNull($mom['entry']['room']);
    }

    public function test_starting_a_conversation_makes_the_room_once(): void
    {
        Auth::guard('profile')->login($this->raylan);

        Volt::test('family-feed')->call('startWith', $this->westin->id);
        Volt::test('family-feed')->call('startWith', $this->westin->id);

        $this->assertSame(1, FeedRoom::where('kind', FeedRoomKind::Direct)->count());
    }

    public function test_a_parent_can_open_the_feed_and_a_kid_cannot_open_the_parent_page(): void
    {
        // The feed is on parent Home now, not a page of its own.
        $this->feed()->say($this->raylan, $this->room(FeedRoomKind::Everyone), 'can we have pizza');

        Auth::guard('profile')->login($this->mom);
        Volt::test('parent.home')
            ->assertSee('can we have pizza')
            ->assertSee('Message everyone', escape: false);
        Auth::guard('profile')->logout();

        $this->actingAs($this->mom, 'profile')->get('/parent/family')->assertNotFound();
        $this->actingAs($this->raylan, 'profile')->get('/kid/family')->assertOk();
    }
}
