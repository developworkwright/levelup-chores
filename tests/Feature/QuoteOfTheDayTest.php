<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Profile;
use App\Models\Quote;
use App\Notifications\QuoteAdded;
use App\Services\HouseholdClock;
use App\Services\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Quote of the Day — written down by a parent, read by everyone, never ranked.
 */
class QuoteOfTheDayTest extends TestCase
{
    use RefreshDatabase;

    private function household(): Household
    {
        return Household::factory()->create();
    }

    public function test_a_parent_writing_one_down_files_it_under_today(): void
    {
        Notification::fake();

        $household = $this->household();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Mabel']);

        $quote = app(QuoteService::class)->record(
            $parent,
            'I am not tired, my eyes are just closing.',
            $kid,
            context: 'Ten minutes before falling asleep on the stairs.',
        );

        $this->assertNotNull($quote);
        $this->assertSame($kid->id, $quote->profile_id);
        $this->assertNull($quote->said_by);
        $this->assertSame('Mabel', $quote->attribution());
        $this->assertSame($parent->id, $quote->added_by_profile_id);
        /*
         * The *household's* today, not the app's.
         *
         * `said_on` is a household day — Chicago in the factory, rolling over
         * at 4am — and `now()` is UTC. Comparing the two passed for most of the
         * day and failed every evening between 7pm and midnight Chicago time,
         * when UTC has already moved on to tomorrow. Nothing about the feature
         * was ever wrong; the assertion was reading the wrong clock.
         */
        $this->assertTrue(
            $quote->said_on->isSameDay(HouseholdClock::for($household)->today()),
            'A quote should be filed under the household day it was said on.',
        );
    }

    public function test_a_blank_quote_is_refused(): void
    {
        Notification::fake();

        $parent = Profile::factory()->parent()->for($this->household())->create();

        $this->assertNull(app(QuoteService::class)->record($parent, '   '));
        $this->assertSame(0, Quote::count());
    }

    /**
     * The point of the free-text name: the funniest thing said in a house is
     * regularly said by someone without a login.
     */
    public function test_a_quote_can_be_attributed_to_someone_without_a_profile(): void
    {
        Notification::fake();

        $parent = Profile::factory()->parent()->for($this->household())->create();

        $quote = app(QuoteService::class)->record($parent, 'Dogs have no elbows.', null, 'Granny');

        $this->assertNull($quote->profile_id);
        $this->assertSame('Granny', $quote->attribution());
    }

    /** A kid from another house must not be able to be named as the speaker. */
    public function test_a_speaker_from_another_household_is_not_attributed(): void
    {
        Notification::fake();

        $parent = Profile::factory()->parent()->for($this->household())->create();
        $stranger = Profile::factory()->for(Household::factory())->create();

        $quote = app(QuoteService::class)->record($parent, 'Not from here.', $stranger);

        $this->assertNull($quote->profile_id);
    }

    public function test_the_heading_becomes_contenders_only_past_one(): void
    {
        $this->assertSame('Quote of the Day', QuoteService::heading(0));
        $this->assertSame('Quote of the Day', QuoteService::heading(1));
        $this->assertSame('Contenders for Quote of the Day', QuoteService::heading(2));
        $this->assertSame('Contenders for Quote of the Day', QuoteService::heading(5));
    }

    /**
     * The Home card falls back rather than emptying: a card that disappears for
     * days at a time is one nobody learns is there.
     */
    public function test_the_home_card_falls_back_to_the_last_day_that_had_any(): void
    {
        $household = $this->household();
        Quote::factory()->for($household)->daysAgo(3)->create(['text' => 'Older']);

        $day = app(QuoteService::class)->latestDay($household);

        $this->assertNotNull($day);
        $this->assertSame('Older', $day['quotes']->first()->text);
        $this->assertFalse(app(QuoteService::class)->isToday($household, $day['date']));
    }

    public function test_the_home_card_prefers_today_and_keeps_every_contender(): void
    {
        $household = $this->household();
        // Said today as the *house* reckons it. The factory's default files a
        // quote under the app's UTC date, which is a different day from about
        // 7pm Chicago — see the assertion in the first test in this file.
        $today = HouseholdClock::for($household)->today()->toDateString();

        Quote::factory()->for($household)->daysAgo(3)->create(['text' => 'Older']);
        Quote::factory()->for($household)->create(['text' => 'First today', 'said_on' => $today]);
        Quote::factory()->for($household)->create(['text' => 'Second today', 'said_on' => $today]);

        $day = app(QuoteService::class)->latestDay($household);

        $this->assertTrue(app(QuoteService::class)->isToday($household, $day['date']));
        $this->assertSame(['First today', 'Second today'], $day['quotes']->pluck('text')->all());
    }

    public function test_a_household_with_no_quotes_shows_no_card(): void
    {
        $this->assertNull(app(QuoteService::class)->latestDay($this->household()));
    }

    public function test_the_whole_household_is_told_except_the_parent_who_typed_it(): void
    {
        Notification::fake();

        $household = $this->household();
        $author = Profile::factory()->parent()->for($household)->create();
        $otherParent = Profile::factory()->parent()->for($household)->create();
        $speaker = Profile::factory()->for($household)->create(['name' => 'Otto']);
        $sibling = Profile::factory()->for($household)->create();
        $elsewhere = Profile::factory()->for(Household::factory())->create();

        app(QuoteService::class)->record($author, 'Cheese is just angry milk.', $speaker);

        // Including the kid who said it — being told your line got written down
        // is most of the reward.
        foreach ([$speaker, $sibling] as $kid) {
            Notification::assertSentTo($kid, QuoteAdded::class, function (QuoteAdded $notification) use ($kid) {
                $message = $notification->toWebPush($kid, $notification)->toArray();

                return $message['title'] === 'Otto said…'
                    && str_contains($message['body'], 'Cheese is just angry milk.')
                    && $message['data']['url'] === '/kid/home#quote-of-the-day';
            });
        }

        // The grown-up who wasn't in the room still wants to hear it — but the
        // kid page is behind role:kid, so their link has to go somewhere else.
        Notification::assertSentTo($otherParent, QuoteAdded::class, function (QuoteAdded $notification) use ($otherParent) {
            return $notification->toWebPush($otherParent, $notification)->toArray()['data']['url'] === '/parent/quotes';
        });

        Notification::assertNotSentTo($author, QuoteAdded::class);
        Notification::assertNotSentTo($elsewhere, QuoteAdded::class);
    }

    public function test_the_parent_page_saves_a_quote_and_says_how_many_contenders_there_are(): void
    {
        Notification::fake();

        $household = $this->household();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Mabel']);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.quotes')
            ->set('newQuote', 'The moon is following our car.')
            ->set('newSpeaker', (string) $kid->id)
            ->call('addQuote')
            ->assertSet('newQuote', '')
            ->assertSet('savedMessage', "Saved, and everyone's been told.")
            ->set('newQuote', 'It stopped when we stopped.')
            ->call('addQuote')
            ->assertSet('savedMessage', "Saved — that's 2 contenders for today.")
            ->assertSee('Contenders for Quote of the Day');

        $this->assertSame(2, Quote::where('household_id', $household->id)->count());
    }

    public function test_the_parent_page_refuses_a_blank_quote(): void
    {
        Notification::fake();

        $parent = Profile::factory()->parent()->for($this->household())->create();
        Auth::guard('profile')->login($parent);

        Volt::test('parent.quotes')
            ->set('newQuote', '  ')
            ->call('addQuote')
            ->assertSet('flashMessage', 'Type what they said first.');

        $this->assertSame(0, Quote::count());
    }

    /**
     * The bug this guards, seen live: two quotes typed nine minutes apart, the
     * second filed under yesterday, so the kids' Home page showed one of two
     * contenders. The picker had been left on a backdate and nothing reset it.
     */
    public function test_the_day_picker_returns_to_today_after_a_backdated_quote(): void
    {
        Notification::fake();

        $household = $this->household();
        $parent = Profile::factory()->parent()->for($household)->create();
        Auth::guard('profile')->login($parent);

        $today = app(QuoteService::class)->today($household);

        Volt::test('parent.quotes')
            ->set('newQuote', 'Said on Tuesday.')
            ->set('newDaysAgo', 2)
            ->call('addQuote')
            ->assertSet('newDaysAgo', 0)
            ->set('newQuote', 'Said just now.')
            ->call('addQuote');

        $this->assertTrue(
            Quote::where('text', 'Said just now.')->firstOrFail()->said_on->isSameDay($today),
            'The second quote should have been filed under today, not dragged back by the picker.',
        );

        // Which is the whole point: both of today's contenders reach the kids.
        $this->assertCount(1, app(QuoteService::class)->forDay($household, $today));
    }

    /** A backdate is called out, so a stuck picker is visible immediately. */
    public function test_the_confirmation_names_the_day_when_it_is_not_today(): void
    {
        Notification::fake();

        $household = $this->household();
        $parent = Profile::factory()->parent()->for($household)->create();
        Auth::guard('profile')->login($parent);

        $when = app(QuoteService::class)->today($household)->subDays(3)->format('D j M');

        Volt::test('parent.quotes')
            ->set('newQuote', 'Filed backwards.')
            ->set('newDaysAgo', 3)
            ->call('addQuote')
            ->assertSet('savedMessage', "Saved to {$when}, and everyone's been told.");
    }

    /**
     * A wrong date is the one mistake that hides a quote from the kids outright,
     * so it has to be fixable in the app rather than in the database.
     */
    public function test_a_misfiled_quote_can_be_moved_to_the_right_day(): void
    {
        $household = $this->household();
        $parent = Profile::factory()->parent()->for($household)->create();
        $quote = Quote::factory()->for($household)->daysAgo(1)->create(['text' => 'Hello bro!']);

        Auth::guard('profile')->login($parent);

        $today = app(QuoteService::class)->today($household);

        Volt::test('parent.quotes')->call('updateQuoteDate', $quote->id, $today->toDateString());

        $this->assertTrue($quote->refresh()->said_on->isSameDay($today));
    }

    public function test_a_quote_cannot_be_moved_to_a_future_day_or_to_nonsense(): void
    {
        $household = $this->household();
        $parent = Profile::factory()->parent()->for($household)->create();
        $quote = Quote::factory()->for($household)->daysAgo(2)->create();
        $original = $quote->said_on->toDateString();

        Auth::guard('profile')->login($parent);

        $today = app(QuoteService::class)->today($household);

        // Nothing was said tomorrow — pulled back rather than refused, so the
        // picker never leaves the parent staring at a control that did nothing.
        Volt::test('parent.quotes')->call('updateQuoteDate', $quote->id, $today->copy()->addWeek()->toDateString());
        $this->assertTrue($quote->refresh()->said_on->isSameDay($today));

        Volt::test('parent.quotes')->call('updateQuoteDate', $quote->id, '2026-13-45');
        $this->assertTrue($quote->refresh()->said_on->isSameDay($today));

        $this->assertNotSame($original, $quote->said_on->toDateString());
    }

    /** Backdating is what makes Thursday's memory of Tuesday's line filable. */
    public function test_the_parent_page_can_backdate_a_quote(): void
    {
        Notification::fake();

        $household = $this->household();
        $parent = Profile::factory()->parent()->for($household)->create();
        Auth::guard('profile')->login($parent);

        Volt::test('parent.quotes')
            ->set('newQuote', 'Said on Tuesday.')
            ->set('newDaysAgo', 2)
            ->call('addQuote');

        $quote = Quote::firstOrFail();
        $expected = app(QuoteService::class)->today($household)->subDays(2);

        $this->assertTrue($quote->said_on->isSameDay($expected));
    }

    public function test_a_parent_can_fix_and_delete_a_quote_but_only_their_own_households(): void
    {
        Notification::fake();

        $household = $this->household();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Mabel']);
        $mine = Quote::factory()->for($household)->create(['text' => 'Typoed', 'said_by' => 'Someone']);
        $theirs = Quote::factory()->for(Household::factory())->create(['text' => 'Not yours']);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.quotes')
            ->call('updateQuoteText', $mine->id, 'Fixed')
            ->call('updateQuoteContext', $mine->id, 'At the dinner table')
            ->call('setQuoteSpeaker', $mine->id, $kid->id)
            ->call('updateQuoteText', $theirs->id, 'Tampered')
            ->call('removeQuote', $theirs->id);

        $mine->refresh();
        $this->assertSame('Fixed', $mine->text);
        $this->assertSame('At the dinner table', $mine->context);
        $this->assertSame($kid->id, $mine->profile_id);

        $this->assertSame('Not yours', $theirs->refresh()->text);
        $this->assertDatabaseHas('quotes', ['id' => $theirs->id]);

        Volt::test('parent.quotes')->call('removeQuote', $mine->id);
        $this->assertDatabaseMissing('quotes', ['id' => $mine->id]);
    }

    /**
     * Home is the punchline, so the line stands on its own there — half these
     * quotes are only funny because you don't know what was going on.
     */
    public function test_the_home_card_withholds_the_context(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        Quote::factory()->for($household)->create([
            'text' => 'I am not tired',
            'context' => 'Asleep on the stairs four minutes later',
        ]);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')
            ->assertSee('I am not tired')
            ->assertDontSee('Asleep on the stairs four minutes later');
    }

    /** The Journal is where you go to read back, so the story is there. */
    public function test_the_quote_wall_gives_the_kids_the_context(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        Quote::factory()->for($household)->create([
            'text' => 'I am not tired',
            'context' => 'Asleep on the stairs four minutes later',
        ]);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.journal')
            ->call('showTab', 'quotes')
            ->assertSee('I am not tired')
            ->assertSee('Asleep on the stairs four minutes later');
    }

    public function test_the_parent_console_shows_the_context(): void
    {
        $household = $this->household();
        $parent = Profile::factory()->parent()->for($household)->create();
        Quote::factory()->for($household)->create([
            'text' => 'I am not tired',
            'context' => 'Asleep on the stairs four minutes later',
        ]);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.quotes')->assertSee('Asleep on the stairs four minutes later');
    }

    /** The push carries the line and nothing else, for the same reason. */
    public function test_the_notification_body_carries_no_context(): void
    {
        Notification::fake();

        $household = $this->household();
        $author = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();

        app(QuoteService::class)->record($author, 'I am not tired', context: 'Asleep on the stairs');

        Notification::assertSentTo($kid, QuoteAdded::class, function (QuoteAdded $notification) use ($kid) {
            $body = $notification->toWebPush($kid, $notification)->toArray()['body'];

            return str_contains($body, 'I am not tired')
                && ! str_contains($body, 'Asleep on the stairs');
        });
    }

    public function test_a_kid_cannot_open_the_parent_quotes_page(): void
    {
        $kid = Profile::factory()->for($this->household())->create();

        $this->actingAs($kid, 'profile')->get('/parent/quotes')->assertForbidden();
    }

    public function test_the_kid_home_page_shows_the_days_contenders(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        Quote::factory()->for($household)->create(['text' => 'Angry milk', 'said_by' => 'Otto']);
        Quote::factory()->for($household)->create(['text' => 'Moon follows us', 'said_by' => 'Mabel']);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')
            ->assertSee('Contenders for Quote of the Day')
            ->assertSee('Angry milk')
            ->assertSee('Moon follows us');
    }

    public function test_the_kid_home_page_hides_the_card_when_nothing_has_been_said(): void
    {
        $kid = Profile::factory()->for($this->household())->create();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')->assertDontSee('Quote of the Day');
    }

    public function test_the_journal_holds_the_quote_wall_alongside_gratitude(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        Quote::factory()->for($household)->daysAgo(4)->create(['text' => 'Dogs have no elbows']);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.journal')
            ->assertSet('tab', 'gratitude')
            ->assertDontSee('Dogs have no elbows')
            ->call('showTab', 'quotes')
            ->assertSee('Dogs have no elbows');
    }

    /** Home's "Every quote ever" link and the push both arrive with ?tab=quotes. */
    public function test_the_journal_opens_on_the_quote_wall_when_asked_to(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        Quote::factory()->for($household)->create(['text' => 'Dogs have no elbows']);

        $this->actingAs($kid, 'profile')
            ->get('/kid/journal?tab=quotes')
            ->assertOk()
            ->assertSee('Dogs have no elbows');
    }

    /**
     * A quote written down for one household must never surface in another's
     * archive — the kid Journal is the widest-read surface in the app.
     */
    public function test_the_archive_is_scoped_to_the_household(): void
    {
        $household = $this->household();
        Quote::factory()->for($household)->create(['text' => 'Ours']);
        Quote::factory()->for(Household::factory())->create(['text' => 'Theirs']);

        $archive = app(QuoteService::class)->archive($household);

        $this->assertSame(['Ours'], $archive->pluck('text')->all());
        $this->assertSame(1, app(QuoteService::class)->countFor($household));
    }

    /** Newest day first, but the contenders inside a day read in the order said. */
    public function test_the_archive_reads_newest_day_first_and_oldest_first_within_a_day(): void
    {
        $household = $this->household();
        Quote::factory()->for($household)->daysAgo(2)->create(['text' => 'Older day']);
        Quote::factory()->for($household)->create(['text' => 'Today first']);
        Quote::factory()->for($household)->create(['text' => 'Today second']);

        $this->assertSame(
            ['Today first', 'Today second', 'Older day'],
            app(QuoteService::class)->archive($household)->pluck('text')->all(),
        );
    }

    /**
     * The household day rolls at 4am, so a line said at 1am belongs to the
     * night before — the same day the kid was awake for.
     */
    public function test_a_quote_written_in_the_small_hours_belongs_to_the_previous_day(): void
    {
        Notification::fake();

        $household = $this->household();
        $parent = Profile::factory()->parent()->for($household)->create();

        Carbon::setTestNow(
            Carbon::parse('2026-08-20 01:30', $household->timezone)->utc()
        );

        $quote = app(QuoteService::class)->record($parent, 'Still awake.');

        $this->assertSame('2026-08-19', $quote->said_on->toDateString());

        Carbon::setTestNow();
    }
}
