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
 * The bonus chest rolls on a better table once the day's quest is cleared, and
 * nothing used to say so — so it was opened first thing every morning and the
 * boost went permanently unclaimed.
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
            'quest_eligible' => true,
        ]);

        Auth::guard('profile')->login($this->kid);
    }

    private function clearQuest(): void
    {
        $chores = app(ChoreService::class);
        $chores->revealQuest($this->kid);
        $chores->claimQuest($this->kid);
    }

    public function test_an_unearned_chest_stops_to_explain_what_waiting_is_worth(): void
    {
        Volt::test('kid.home')
            ->assertSee('Ready to open')
            // The prompt is on the page, hidden until the chest is tapped.
            ->assertSee('Hold on', escape: false)
            ->assertSee('Do my quest first')
            // "Open now anyway", not "Open it now anyway" — the sleep chest's
            // own CTA is "Open it", and SleepCardPagesTest asserts that string
            // is gone once it has been opened.
            ->assertSee('Open now anyway');
    }

    public function test_the_chest_asks_before_opening_only_while_the_quest_is_open(): void
    {
        // The stop is <x-chest>'s own 'confirming' phase now that the chest is
        // a card on Home rather than a tile in the Quests tray — a tile was too
        // small to hold a two-button question and had to raise an event for it.
        //
        // Asserted on the attribute rather than on the branch in x-data, which
        // is now always present: the decision has to be re-readable by a
        // component Alpine already initialised. See the test below.
        $html = Volt::test('kid.home')->html();
        $this->assertStringContainsString('data-fq-confirm="1"', $html);

        $this->clearQuest();

        // Nothing left to ask: the chest is already on the good table.
        $cleared = Volt::test('kid.home')->html();
        $this->assertStringNotContainsString('data-fq-confirm="1"', $cleared);
    }

    public function test_the_chest_survives_the_quest_being_cleared_underneath_it(): void
    {
        // The dead end: tap the chest, get told to do the quest first, then go
        // and do exactly that on the card directly above it — and come back to
        // a chest with no button on it. The panel that answers the question is
        // rendered by the page and disappears along with the question, which
        // left the chest stuck in a 'confirming' phase it could not leave.
        //
        // Nothing here is a page load, so the client cannot re-read the markup
        // it was built from; the question stopping has to reach it as an
        // attribute change on the same element.
        $page = Volt::test('kid.home')
            ->assertSee('data-fq-confirm="1"', escape: false)
            ->assertSee('Hold on')
            ->call('dealHand');

        $page->call('chooseQuest', app(ChoreService::class)->offeredChoresFor($this->kid)->first()->id)
            ->call('claimQuest')
            ->assertOk()
            ->assertSee('Your chest is OP today')
            ->assertDontSee('Hold on')
            ->assertDontSee('data-fq-confirm="1"', escape: false);
    }

    public function test_a_cleared_quest_flags_the_chest_as_op_on_the_shut_tile(): void
    {
        $this->clearQuest();

        // Has to be visible *before* it is opened — a boost discovered
        // afterwards changes nobody's behaviour tomorrow.
        Volt::test('kid.home')
            ->assertSee('Ready · OP', escape: false)
            ->assertSee('Bonus Chest · OP', escape: false)
            ->assertSee('Your chest is OP today');
    }

    public function test_the_op_flag_is_absent_while_the_quest_is_still_open(): void
    {
        Volt::test('kid.home')
            ->assertSee('Ready to open')
            ->assertDontSee('Ready · OP', escape: false);
    }

    public function test_the_chest_still_opens_for_a_kid_who_chooses_not_to_wait(): void
    {
        // The prompt is a stop, not a lock. Opening early is a real choice and
        // has to keep working.
        Volt::test('kid.home')->call('openDailyChest');

        $this->assertNotNull(
            app(\App\Services\ChestService::class)->openedToday($this->kid),
            'The chest must still be openable before the quest is cleared.',
        );
    }

    public function test_a_chest_opened_after_the_quest_records_that_it_was_boosted(): void
    {
        $this->clearQuest();

        Volt::test('kid.home')->call('openDailyChest');

        $chest = app(\App\Services\ChestService::class)->openedToday($this->kid);

        $this->assertNotNull($chest);
        $this->assertTrue((bool) $chest->quest_was_done);
    }

    public function test_a_chest_opened_before_the_quest_records_that_it_was_not(): void
    {
        Volt::test('kid.home')->call('openDailyChest');

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
