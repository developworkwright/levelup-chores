<?php

namespace Tests\Feature;

use App\Enums\FeedRoomKind;
use App\Models\FeedRoom;
use App\Models\Household;
use App\Models\Profile;
use App\Notifications\FeedMessagePosted;
use App\Services\FeedService;
use App\Services\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Pushes for the family feed.
 *
 * The kids have no phones. Without a push, a message sits until the other kid
 * happens to open the app — which is the exact problem the feed exists to fix —
 * so this is the most important thing the feed does after storing the message.
 */
class FamilyFeedNotificationTest extends TestCase
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

        Notification::fake();

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

    /** @return array<string, mixed> */
    private function push(Profile $to, FeedMessagePosted $notification): array
    {
        return $notification->toWebPush($to, $notification)->toArray();
    }

    public function test_everyone_else_in_the_room_is_told_and_the_author_is_not(): void
    {
        $this->feed()->say($this->raylan, $this->room(FeedRoomKind::Everyone), 'dinner is ready');

        foreach ([$this->westin, $this->mom, $this->dad] as $member) {
            Notification::assertSentTo($member, FeedMessagePosted::class);
        }

        Notification::assertNotSentTo($this->raylan, FeedMessagePosted::class);
    }

    public function test_the_push_names_who_said_it_where_and_carries_the_message(): void
    {
        $this->feed()->say($this->raylan, $this->room(FeedRoomKind::Everyone), 'dinner is ready');

        Notification::assertSentTo($this->westin, FeedMessagePosted::class, function (FeedMessagePosted $n) {
            $push = $this->push($this->westin, $n);

            return $push['title'] === 'Raylan in Everyone'
                && $push['body'] === 'dinner is ready'
                // Kids land on Home, which is where the feed lives for them.
                && $push['data']['url'] === '/kid/home';
        });

        Notification::assertSentTo($this->mom, FeedMessagePosted::class, function (FeedMessagePosted $n) {
            // Parent Home carries the feed; there is no parent Family page.
            return $this->push($this->mom, $n)['data']['url'] === '/parent/home';
        });
    }

    /** One notification per room that updates, rather than a stack of them. */
    public function test_messages_in_one_room_share_a_tag_so_they_replace_each_other(): void
    {
        $room = $this->room(FeedRoomKind::Everyone);

        $this->feed()->say($this->raylan, $room, 'one');
        $this->feed()->say($this->raylan, $room, 'two');

        Notification::assertSentTo($this->westin, FeedMessagePosted::class, function (FeedMessagePosted $n) use ($room) {
            $push = $this->push($this->westin, $n);

            return $push['tag'] === 'feed-room-'.$room->id && $push['renotify'] === true;
        });
    }

    /** The privacy rule, again, at the push: a DM between kids reaches nobody else. */
    public function test_a_direct_message_only_reaches_the_other_person(): void
    {
        $room = $this->feed()->directRoomWith($this->raylan, $this->westin);

        $this->feed()->say($this->raylan, $room, 'just us');

        Notification::assertSentTo($this->westin, FeedMessagePosted::class, function (FeedMessagePosted $n) {
            // A one-to-one is titled by the sender alone — the room *is* them.
            return $this->push($this->westin, $n)['title'] === 'Raylan';
        });

        Notification::assertNotSentTo($this->mom, FeedMessagePosted::class);
        Notification::assertNotSentTo($this->dad, FeedMessagePosted::class);
    }

    public function test_a_kids_line_to_the_grown_ups_reaches_them_and_not_a_sibling(): void
    {
        $this->feed()->say($this->raylan, $this->room(FeedRoomKind::Parents, $this->raylan), 'can I stay up');

        Notification::assertSentTo($this->mom, FeedMessagePosted::class, function (FeedMessagePosted $n) {
            // Named from her side of it.
            return $this->push($this->mom, $n)['title'] === 'Raylan in Raylan & grown-ups';
        });

        Notification::assertSentTo($this->dad, FeedMessagePosted::class);
        Notification::assertNotSentTo($this->westin, FeedMessagePosted::class);
    }

    /**
     * Parents can read the Kids room, but their phones do not buzz with it —
     * that would turn a quieter room into one being listened to.
     */
    public function test_the_kids_room_pushes_to_the_kids_only(): void
    {
        $this->feed()->say($this->raylan, $this->room(FeedRoomKind::Kids), 'lego after school');

        Notification::assertSentTo($this->westin, FeedMessagePosted::class);
        Notification::assertNotSentTo($this->mom, FeedMessagePosted::class);
        Notification::assertNotSentTo($this->dad, FeedMessagePosted::class);
    }

    public function test_a_stamp_and_a_drawing_say_what_they_are(): void
    {
        $this->feed()->stamp($this->raylan, $this->room(FeedRoomKind::Everyone), 'hug');

        Notification::assertSentTo($this->westin, FeedMessagePosted::class, function (FeedMessagePosted $n) {
            return $this->push($this->westin, $n)['body'] === '🤗 Hug';
        });
    }

    public function test_a_shout_out_tells_its_subject_it_is_about_them(): void
    {
        $this->feed()->shoutOut($this->mom, $this->room(FeedRoomKind::Everyone), $this->westin->id, 'put every toy away');

        Notification::assertSentTo($this->westin, FeedMessagePosted::class, function (FeedMessagePosted $n) {
            return $this->push($this->westin, $n)['title'] === 'Mom gave you a shout-out';
        });

        Notification::assertSentTo($this->raylan, FeedMessagePosted::class, function (FeedMessagePosted $n) {
            return $this->push($this->raylan, $n)['title'] === 'Mom gave Westin a shout-out';
        });
    }

    /** The app talking is the quietest thing in the room, and stays silent. */
    public function test_an_event_pushes_nothing(): void
    {
        $this->feed()->event($this->raylan, 'Raylan did a thing');

        Notification::assertNothingSent();
    }

    /** Quotes lost their push when they moved into the feed, at the user's request. */
    public function test_a_quote_pushes_nothing(): void
    {
        app(QuoteService::class)->record($this->mom, 'Cheese is just angry milk.', $this->raylan);

        Notification::assertNothingSent();
    }

    public function test_a_long_message_is_trimmed_for_the_lock_screen(): void
    {
        $this->feed()->say($this->raylan, $this->room(FeedRoomKind::Everyone), str_repeat('a', 400));

        Notification::assertSentTo($this->westin, FeedMessagePosted::class, function (FeedMessagePosted $n) {
            return mb_strlen($this->push($this->westin, $n)['body']) <= 140;
        });
    }
}
