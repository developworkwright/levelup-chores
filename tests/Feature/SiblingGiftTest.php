<?php

namespace Tests\Feature;

use App\Enums\FeedMessageKind;
use App\Enums\FeedRoomKind;
use App\Enums\TicketKind;
use App\Models\BonusTicketEntry;
use App\Models\FeedMessage;
use App\Models\Household;
use App\Models\Profile;
use App\Models\SiblingGift;
use App\Notifications\GiftReceived;
use App\Services\FeedService;
use App\Services\GiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The daily gift: once a household day, a kid picks a sibling and the house
 * hands that sibling a ticket. Nothing leaves the giver's pocket, and nothing
 * banks — an ungiven gift is gone at the rollover.
 */
class SiblingGiftTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $ava;

    private Profile $rowan;

    private Profile $colton;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->household = Household::factory()->create();

        // Midday, clear of the 4am household rollover.
        $this->travelTo(Carbon::parse('2026-05-01 12:00', $this->household->timezone));

        $this->ava = Profile::factory()->for($this->household)->create(['name' => 'Ava', 'bonus_tickets' => 4]);
        $this->rowan = Profile::factory()->for($this->household)->create(['name' => 'Rowan', 'bonus_tickets' => 0]);
        $this->colton = Profile::factory()->for($this->household)->create(['name' => 'Colton', 'bonus_tickets' => 0]);
    }

    private function gifts(): GiftService
    {
        return app(GiftService::class);
    }

    public function test_the_sibling_gets_a_ticket_and_the_giver_keeps_theirs(): void
    {
        $gift = $this->gifts()->give($this->ava, $this->rowan->id);

        $this->assertNotNull($gift);
        $this->assertSame(GiftService::TICKETS, $this->rowan->refresh()->bonus_tickets);
        $this->assertSame(4, $this->ava->refresh()->bonus_tickets);

        $entry = BonusTicketEntry::where('profile_id', $this->rowan->id)->sole();
        $this->assertSame(TicketKind::Gift, $entry->kind);
        $this->assertSame('Gift from Ava', $entry->description);
        $this->assertTrue($entry->related->is($gift));
    }

    public function test_the_recipient_is_pushed_with_the_givers_name(): void
    {
        $this->gifts()->give($this->ava, $this->rowan->id);

        Notification::assertSentTo($this->rowan, GiftReceived::class);
        Notification::assertNotSentTo([$this->ava, $this->colton], GiftReceived::class);
    }

    public function test_only_one_gift_a_day(): void
    {
        $this->assertNotNull($this->gifts()->give($this->ava, $this->rowan->id));
        $this->assertNull($this->gifts()->give($this->ava, $this->colton->id));
        $this->assertNull($this->gifts()->give($this->ava, $this->rowan->id));

        $this->assertSame(0, $this->colton->refresh()->bonus_tickets);
        $this->assertSame(1, $this->rowan->refresh()->bonus_tickets);
        $this->assertDatabaseCount('sibling_gifts', 1);
    }

    public function test_a_new_household_day_brings_a_new_gift_and_nothing_banks(): void
    {
        $this->gifts()->give($this->ava, $this->rowan->id);

        // Rowan never gave theirs yesterday, and gets exactly one today.
        $this->travel(1)->day();

        $this->assertNull($this->gifts()->givenToday($this->ava));
        $this->assertNotNull($this->gifts()->give($this->ava, $this->rowan->id));
        $this->assertNotNull($this->gifts()->give($this->rowan, $this->ava->id));
        $this->assertNull($this->gifts()->give($this->rowan, $this->colton->id));
    }

    public function test_two_siblings_can_trade_gifts_every_day(): void
    {
        $this->assertNotNull($this->gifts()->give($this->ava, $this->rowan->id));
        $this->assertNotNull($this->gifts()->give($this->rowan, $this->ava->id));

        $this->assertSame(5, $this->ava->refresh()->bonus_tickets);
        $this->assertSame(1, $this->rowan->refresh()->bonus_tickets);
    }

    public function test_a_kid_cannot_give_to_themselves_a_parent_or_another_household(): void
    {
        $parent = Profile::factory()->parent()->for($this->household)->create();
        $stranger = Profile::factory()->for(Household::factory())->create();

        $this->assertNull($this->gifts()->give($this->ava, $this->ava->id));
        $this->assertNull($this->gifts()->give($this->ava, $parent->id));
        $this->assertNull($this->gifts()->give($this->ava, $stranger->id));
        $this->assertNull($this->gifts()->give($this->ava, 999999));

        $this->assertDatabaseCount('sibling_gifts', 0);
        $this->assertNull($this->gifts()->givenToday($this->ava));
    }

    public function test_a_parent_has_no_gift_to_give(): void
    {
        $parent = Profile::factory()->parent()->for($this->household)->create();

        $this->assertTrue($this->gifts()->siblingsOf($parent)->isEmpty());
        $this->assertNull($this->gifts()->give($parent, $this->ava->id));
    }

    public function test_received_lists_every_gift_today_in_the_order_they_came(): void
    {
        $this->gifts()->give($this->colton, $this->ava->id);
        $this->gifts()->give($this->rowan, $this->ava->id);

        $this->assertSame(
            ['Colton', 'Rowan'],
            $this->gifts()->receivedToday($this->ava)->map(fn (SiblingGift $gift) => $gift->giver->name)->all(),
        );
    }

    public function test_home_gives_the_gift_in_place(): void
    {
        Auth::guard('profile')->login($this->ava);

        Volt::test('kid.home')
            ->assertSet('openRow', null)
            ->assertSee('Daily Gift')
            ->assertSee('1 TO GIVE')
            ->call('toggleRow', 'gift')
            ->assertSee('Who gets your ticket today?')
            ->assertSee('Rowan')
            ->assertSee('Colton')
            ->call('giveGift', $this->rowan->id)
            ->assertDispatched('celebrate')
            ->assertSee('You gave Rowan a ticket today.')
            ->assertSee('GIVEN')
            ->assertDontSee('Who gets your ticket today?');

        $this->assertSame(1, $this->rowan->refresh()->bonus_tickets);
    }

    public function test_a_new_gift_flags_the_row_until_it_is_opened(): void
    {
        $this->gifts()->give($this->rowan, $this->ava->id);

        Auth::guard('profile')->login($this->ava);

        Volt::test('kid.home')
            ->assertSee('Rowan gave you a ticket!')
            ->assertSee('NEW GIFT')
            ->assertSee('data-attention', false)
            ->call('toggleRow', 'gift')
            ->assertSee('gave you a ticket')
            ->call('toggleRow', 'gift')
            ->assertDontSee('data-attention', false)
            ->assertDontSee('NEW GIFT')
            ->assertSee('Rowan gave you one — give one too');

        $this->assertNotNull(SiblingGift::sole()->seen_at);
    }

    /** The alert survives giving your own — it is about theirs, not yours. */
    public function test_giving_back_does_not_clear_an_unseen_gift(): void
    {
        $this->gifts()->give($this->rowan, $this->ava->id);
        $this->gifts()->give($this->ava, $this->rowan->id);

        Auth::guard('profile')->login($this->ava);

        Volt::test('kid.home')
            ->assertSee('NEW GIFT')
            ->assertSee('data-attention', false);
    }

    public function test_the_gift_push_opens_the_gift_row(): void
    {
        $this->gifts()->give($this->rowan, $this->ava->id);

        $this->actingAs($this->ava, 'profile')
            ->get(route('kid.home', ['row' => 'gift']))
            ->assertOk()
            ->assertSee('gave you a ticket');

        $this->assertNotNull(SiblingGift::sole()->seen_at);
    }

    /** The house hears about it as one quiet line in Everyone, which never pushes. */
    public function test_the_gift_is_announced_in_the_family_feed(): void
    {
        $feed = app(FeedService::class);
        $feed->ensureRooms($this->household);

        $gift = $this->gifts()->give($this->ava, $this->rowan->id);

        $event = FeedMessage::where('kind', FeedMessageKind::Event)->sole();
        $this->assertSame(FeedRoomKind::Everyone, $event->room->kind);
        $this->assertSame($this->ava->id, $event->profile_id);
        $this->assertSame('🎟 Ava gave «Rowan» a ticket', $event->body);
        $this->assertTrue($event->source->is($gift));

        // The recipient's buzz is the gift push alone — the event adds none.
        Notification::assertSentToTimes($this->rowan, GiftReceived::class, 1);
        Notification::assertNothingSentTo($this->colton);
    }

    public function test_a_third_sibling_sees_the_gift_in_the_feed_but_not_as_theirs(): void
    {
        app(FeedService::class)->ensureRooms($this->household);
        $this->gifts()->give($this->ava, $this->rowan->id);

        Auth::guard('profile')->login($this->colton);

        Volt::test('kid.home')
            ->assertDontSee('NEW GIFT')
            ->call('toggleRow', 'gift')
            ->assertSee('Who gets your ticket today?')
            ->assertDontSee('gave you a ticket');

        Volt::test('family-feed', ['embedded' => true])
            ->assertSee('Ava gave', false);
    }

    public function test_an_only_child_gets_no_gift_row(): void
    {
        $household = Household::factory()->create();
        $only = Profile::factory()->for($household)->create();

        Auth::guard('profile')->login($only);

        Volt::test('kid.home')
            ->assertDontSee('Daily Gift')
            ->assertDontSee("toggleRow('gift')", false);
    }
}
