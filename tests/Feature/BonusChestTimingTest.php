<?php

namespace Tests\Feature;

use App\Enums\LedgerKind;
use App\Models\Chore;
use App\Models\Household;
use App\Models\LedgerEntry;
use App\Models\Profile;
use App\Services\ChoreService;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The bonus chest rolls on a better table once a chore is done, and nothing
 * used to say so — so it was opened first thing every morning and the boost
 * went permanently unclaimed.
 *
 * Any chore earns it. The column recording it is still called
 * `quest_was_done`, from when the daily quest was the only thing that counted;
 * the rule it stores is {@see ChestService::isBoosted()}.
 *
 * @see ChestService::BOOSTED_TABLE
 */
class BonusChestTimingTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->travelTo(Carbon::parse('2026-05-04 12:00', $this->household->timezone));

        $this->kid = Profile::factory()->for($this->household)->create(['age' => 10]);

        Chore::factory()->for($this->household)->count(4)->create([
            'min_age' => null,
        ]);

        Auth::guard('profile')->login($this->kid);
    }

    /** Puts one chore in for the day, which is what powers the chest up. */
    private function doAChore(): void
    {
        app(ChoreService::class)->claim(
            $this->kid,
            Chore::where('household_id', $this->household->id)->first(),
        );
    }

    public function test_an_unearned_chest_stops_to_explain_what_waiting_is_worth(): void
    {
        Volt::test('kid.home')->call('toggleRow', 'chest')
            ->assertSee("Open today's bonus chest")
            // The prompt is on the page, hidden until the chest is tapped.
            ->assertSee('Hold on', escape: false)
            ->assertSee('Do a chore first')
            // "Open now anyway", not "Open it now anyway" — the sleep chest's
            // own CTA is "Open it", and SleepCardPagesTest asserts that string
            // is gone once it has been opened.
            ->assertSee('Open now anyway');
    }

    public function test_the_chest_asks_before_opening_only_while_nothing_is_in(): void
    {
        // The stop is <x-chest>'s own 'confirming' phase now that the chest is
        // a card on Home rather than a tile in the Quests tray — a tile was too
        // small to hold a two-button question and had to raise an event for it.
        //
        // Asserted on the attribute rather than on the branch in x-data, which
        // is now always present: the decision has to be re-readable by a
        // component Alpine already initialised. See the test below.
        $html = Volt::test('kid.home')->call('toggleRow', 'chest')->html();
        $this->assertStringContainsString('data-fq-confirm="1"', $html);

        $this->doAChore();

        // Nothing left to ask: the chest is already on the good table.
        $cleared = Volt::test('kid.home')->call('toggleRow', 'chest')->html();
        $this->assertStringNotContainsString('data-fq-confirm="1"', $cleared);
    }

    public function test_the_chest_survives_a_chore_going_in_underneath_it(): void
    {
        // The dead end: tap the chest, get told to do a chore first, then go
        // and do exactly that — and come back to a chest with no button on it.
        // The panel that answers the question is rendered by the page and
        // disappears along with the question, which left the chest stuck in a
        // 'confirming' phase it could not leave.
        //
        // The work is done on the Quests page now rather than on a card
        // directly above this one, but the re-render is the same and so is the
        // trap: the question stopping has to reach the client as an attribute
        // change on the same element.
        Volt::test('kid.home')->call('toggleRow', 'chest')
            ->assertSee('data-fq-confirm="1"', escape: false)
            ->assertSee('Hold on');

        $this->doAChore();

        Volt::test('kid.home')->call('toggleRow', 'chest')
            ->assertOk()
            ->assertSee('Your chest is OP today')
            ->assertDontSee('Hold on')
            ->assertDontSee('data-fq-confirm="1"', escape: false);
    }

    public function test_a_chore_flags_the_chest_as_op_on_the_shut_row(): void
    {
        $this->doAChore();

        // Has to be visible *before* it is opened — a boost discovered
        // afterwards changes nobody's behaviour tomorrow.
        Volt::test('kid.home')->call('toggleRow', 'chest')
            // The row's own line, so it is readable without opening anything.
            ->assertSee('Rolling on the good table')
            ->assertSee('Your chest is OP today');
    }

    public function test_a_claim_still_waiting_on_a_parent_flags_the_chest_as_op(): void
    {
        // Claimed, not approved. The kid did the work, so the chest says so and
        // the stop that asks them to go and do some first has nothing left to
        // ask — a chest that rolled worse because a parent hadn't got to the
        // queue would be blaming the kid for somebody else's inbox.
        $this->doAChore();

        $page = Volt::test('kid.home')->call('toggleRow', 'chest')
            ->assertSee('Rolling on the good table')
            ->assertSee('Your chest is OP today');

        $this->assertStringNotContainsString('data-fq-confirm="1"', $page->html());
    }

    public function test_the_op_flag_is_absent_while_nothing_has_been_done(): void
    {
        Volt::test('kid.home')->call('toggleRow', 'chest')
            ->assertSee('Better after a chore')
            ->assertDontSee('Rolling on the good table');
    }

    public function test_the_chest_still_opens_for_a_kid_who_chooses_not_to_wait(): void
    {
        // The prompt is a stop, not a lock. Opening early is a real choice and
        // has to keep working.
        Volt::test('kid.home')->call('toggleRow', 'chest')->call('openDailyChest');

        $this->assertNotNull(
            app(\App\Services\ChestService::class)->openedToday($this->kid),
            'The chest must still be openable before any chore is done.',
        );
    }

    public function test_a_chest_opened_after_a_chore_records_that_it_was_boosted(): void
    {
        $this->doAChore();

        Volt::test('kid.home')->call('toggleRow', 'chest')->call('openDailyChest');

        $chest = app(\App\Services\ChestService::class)->openedToday($this->kid);

        $this->assertNotNull($chest);
        $this->assertTrue((bool) $chest->quest_was_done);
    }

    public function test_a_chest_opened_before_any_chore_records_that_it_was_not(): void
    {
        Volt::test('kid.home')->call('toggleRow', 'chest')->call('openDailyChest');

        $this->assertFalse((bool) app(\App\Services\ChestService::class)->openedToday($this->kid)->quest_was_done);
    }

    public function test_the_activity_log_dates_every_entry(): void
    {
        $parent = Profile::factory()->parent()->for($this->household)->create();

        app(LedgerService::class)->record(
            $this->household,
            $this->kid,
            LedgerKind::Earn,
            100,
            'Something happened',
        );

        // Stored in UTC, like every real write: Eloquent formats whatever
        // Carbon it is handed without converting it, so a household-zoned
        // instance here would write its wall clock and then be read back as
        // if it were UTC — five hours out, and nothing to do with the code
        // under test.
        LedgerEntry::latest('id')->first()->forceFill([
            'created_at' => Carbon::parse('2026-04-02 15:04', $this->household->timezone)->utc(),
        ])->save();

        Auth::guard('profile')->login($parent);

        // Absolute, not "a month ago" — the log is read to answer *when*, and
        // a relative stamp is the one thing that cannot answer it.
        Volt::test('parent.activity')
            ->assertSee('Something happened')
            ->assertSee('2 Apr, 3:04pm');
    }

    public function test_todays_entries_are_named_rather_than_dated(): void
    {
        $parent = Profile::factory()->parent()->for($this->household)->create();

        app(LedgerService::class)->record(
            $this->household,
            $this->kid,
            LedgerKind::Earn,
            100,
            'Happened today',
        );

        Auth::guard('profile')->login($parent);

        // On today and yesterday the date is the part a reader has to
        // translate; the time is the part they want.
        Volt::test('parent.activity')->assertSee('Today 12:00pm');
    }
}
