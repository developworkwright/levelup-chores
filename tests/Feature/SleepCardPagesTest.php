<?php

namespace Tests\Feature;

use App\Enums\Constellation;
use App\Enums\SleepCardType;
use App\Enums\SleepOutcome;
use App\Models\Chore;
use App\Models\Household;
use App\Models\LedgerEntry;
use App\Models\Profile;
use App\Models\SleepNight;
use App\Services\NightWindow;
use App\Services\SleepService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SleepCardPagesTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create(['sleep_card_enabled' => true]);
        $this->kid = Profile::factory()->for($this->household)->create([
            'name' => 'Ziggy',
            'sleep_card_enabled' => true,
        ]);
        Chore::factory()->for($this->household)->create();

        $this->travelTo(Carbon::parse('2026-05-01 09:00', $this->household->timezone));
    }

    private function loginKid(): void
    {
        Auth::guard('profile')->login($this->kid);
    }

    public function test_the_card_is_absent_for_a_kid_it_is_not_switched_on_for(): void
    {
        $this->kid->update(['sleep_card_enabled' => false]);
        $this->loginKid();

        // Every other kid's page must look exactly as it did before this
        // feature existed.
        Volt::test('kid.quests')
            ->assertOk()
            ->assertDontSee('How did last night go?')
            ->assertDontSee('Last Night');
    }

    public function test_the_card_is_absent_when_the_household_switch_is_off(): void
    {
        $this->household->update(['sleep_card_enabled' => false]);
        $this->loginKid();

        Volt::test('kid.quests')
            ->assertOk()
            ->assertDontSee('How did last night go?');
    }

    public function test_the_card_asks_and_a_star_lands(): void
    {
        $this->loginKid();

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('How did last night go?')
            ->assertSee(Constellation::LittleBear->label())
            ->call('answerSleep', SleepOutcome::OwnBed->value)
            ->assertSee('That is a star.');

        $this->assertSame(1, $this->kid->fresh()->sleep_nights);
    }

    public function test_a_hard_night_is_answered_warmly_and_costs_nothing(): void
    {
        app(SleepService::class)->record($this->kid, SleepOutcome::OwnBed);
        $this->travel(1)->days();

        $this->loginKid();

        Volt::test('kid.quests')
            ->call('answerSleep', SleepOutcome::Rough->value)
            ->assertSee('Nothing lost.');

        $fresh = $this->kid->fresh();

        $this->assertSame(1, $fresh->sleep_nights);
        $this->assertSame(0, $fresh->sleep_run);
        $this->assertSame(1, $fresh->sleep_best_run);
    }

    public function test_answering_twice_does_not_light_two_stars(): void
    {
        $this->loginKid();

        // The card is on the page a kid lands on, and a double tap is the most
        // likely thing a six-year-old will do to it.
        Volt::test('kid.quests')
            ->call('answerSleep', SleepOutcome::OwnBed->value)
            ->call('answerSleep', SleepOutcome::OwnBed->value)
            ->assertOk();

        $this->assertSame(1, $this->kid->fresh()->sleep_nights);
        $this->assertSame(1, SleepNight::where('profile_id', $this->kid->id)->count());
    }

    public function test_a_nonsense_answer_is_ignored_rather_than_thrown(): void
    {
        $this->loginKid();

        Volt::test('kid.quests')
            ->call('answerSleep', 'slept-on-the-roof')
            ->assertOk();

        $this->assertSame(0, SleepNight::where('profile_id', $this->kid->id)->count());
    }

    public function test_the_night_chest_shows_and_opens(): void
    {
        $service = app(SleepService::class);

        for ($i = 0; $i < 3; $i++) {
            $service->record($this->kid->refresh(), SleepOutcome::OwnBed);
            $this->travel(1)->days();
        }

        $this->loginKid();

        Volt::test('kid.quests')
            ->assertOk()
            // The chest lives in the card's own rail now, not in a flat panel
            // below it, and the ready state is the loudest thing on the card.
            // Asserted without the separators, which are HTML entities in the
            // markup rather than the characters they render as.
            ->assertSee('Night chest', false)
            ->assertSee('Open it', false)
            ->assertSee('3 nights in a row', false)
            ->call('openSleepChest')
            ->assertDontSee('Open it', false);

        $this->assertNull($this->kid->fresh()->pending_sleep_chest);
    }

    public function test_no_chest_is_drawn_until_the_first_one_has_been_opened(): void
    {
        $this->loginKid();

        // A chest a kid has never opened reads as a prize being withheld. The
        // first leg promises it in words instead.
        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('First chest at 3 in a row', false)
            ->assertDontSee('Night chest', false);
    }

    public function test_no_chest_is_drawn_between_legs_either(): void
    {
        $service = app(SleepService::class);

        for ($i = 0; $i < 3; $i++) {
            $service->record($this->kid->refresh(), SleepOutcome::OwnBed);
            $this->travel(1)->days();
        }

        $service->openChest($this->kid->refresh());

        $this->loginKid();

        // A chest on this card always means "open me". Between legs the strip
        // takes over, now reading "Next" rather than "First", and it measures
        // the 3 → 7 leg rather than starting again from zero.
        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('Next chest at 7 in a row', false)
            ->assertDontSee('Night chest', false)
            ->assertDontSee('First chest at', false);
    }

    // --- Parent console ----------------------------------------------------

    private function loginParent(): Profile
    {
        $parent = Profile::factory()->for($this->household)->parent()->create();
        Auth::guard('profile')->login($parent);

        return $parent;
    }

    public function test_a_parent_can_switch_the_card_on_for_one_kid(): void
    {
        $this->kid->update(['sleep_card_enabled' => false]);
        $this->loginParent();

        Volt::test('parent.kids')
            ->assertOk()
            ->assertSee('Own Bed Card')
            ->call('toggleSleepCard', $this->kid->id);

        $this->assertTrue($this->kid->fresh()->sleep_card_enabled);
    }

    public function test_the_per_kid_switch_is_hidden_until_the_household_one_is_on(): void
    {
        $this->household->update(['sleep_card_enabled' => false]);
        $this->loginParent();

        // The household block still explains the feature; what disappears is
        // the per-kid row, which would be meaningless with the family off.
        Volt::test('parent.kids')
            ->assertOk()
            ->assertDontSee('Now switch it on for whoever needs it');
    }

    public function test_the_ledger_line_says_what_it_is_and_which_night(): void
    {
        // 2026-05-01 is a Friday, so the night that ended that morning is
        // Thursday night.
        app(SleepService::class)->record($this->kid, SleepOutcome::Visited);

        $entry = LedgerEntry::where('profile_id', $this->kid->id)->latest('id')->first();

        // "Ziggy — Came in" said neither what it was about nor when: a parent
        // reading a feed of chores and loot has to guess it concerns sleep,
        // and the row's timestamp is the morning they answered — the day
        // *after* the night being described.
        $this->assertSame('Ziggy — Own bed card: came in (Thu night)', $entry->description);
    }

    public function test_a_parent_can_taper_the_payout_from_the_console(): void
    {
        $this->loginParent();

        Volt::test('parent.kids')
            ->assertOk()
            // One dial per answer, plus the picture.
            ->assertSee('Own bed')
            ->assertSee('Came in')
            ->assertSee('Rough night')
            ->assertSee('Constellation')
            ->call('adjustConstellationPayout', -SleepService::PAYOUT_STEP);

        $this->assertSame(450, $this->household->fresh()->sleep_constellation_points);
    }

    public function test_a_parent_can_taper_one_answer_without_touching_the_others(): void
    {
        $this->loginParent();

        Volt::test('parent.kids')
            ->assertOk()
            // The button's own markup, not just the method behind it: the call
            // is built as a string with quoted arguments, and Blade escaping
            // could mangle it into something Livewire won't parse.
            ->assertSee('adjustNightPayout(&#039;visited&#039;, -'.SleepService::NIGHT_STEP.')', false)
            ->call('adjustNightPayout', SleepOutcome::Visited->value, -SleepService::NIGHT_STEP);

        $sleep = app(SleepService::class);
        $household = $this->household->fresh();

        $this->assertSame(75, $sleep->pointsFor($household, SleepOutcome::Visited));
        $this->assertSame(200, $sleep->pointsFor($household, SleepOutcome::OwnBed));
    }

    public function test_a_nonsense_outcome_moves_no_dial(): void
    {
        $this->loginParent();

        Volt::test('parent.kids')
            ->call('adjustNightPayout', 'slept-on-the-roof', -SleepService::NIGHT_STEP)
            ->assertOk();

        $this->assertSame(200, app(SleepService::class)->pointsFor($this->household->fresh(), SleepOutcome::OwnBed));
    }

    public function test_the_payout_cannot_be_tapered_below_nothing(): void
    {
        app(SleepService::class)->setConstellationPoints($this->household, 0);
        $this->loginParent();

        Volt::test('parent.kids')
            // The dial reads "nothing" rather than 0 — the end of a taper is a
            // state, not a broken number.
            ->assertSee('nothing')
            ->call('adjustConstellationPayout', -SleepService::PAYOUT_STEP);

        $this->assertSame(0, $this->household->fresh()->sleep_constellation_points);
    }

    public function test_a_parent_can_correct_the_numbers_from_the_console(): void
    {
        app(SleepService::class)->adjust($this->kid, nights: 5, run: 5);
        $this->loginParent();

        Volt::test('parent.kids')
            ->call('adjustSleep', $this->kid->id, -1, 0)
            ->call('adjustSleep', $this->kid->id, 0, -1);

        $fresh = $this->kid->fresh();

        $this->assertSame(4, $fresh->sleep_nights);
        $this->assertSame(4, $fresh->sleep_run);
    }

    public function test_a_parent_cannot_reach_another_households_kid(): void
    {
        $stranger = Profile::factory()->for(Household::factory()->create())->create([
            'sleep_card_enabled' => false,
        ]);

        $this->loginParent();

        Volt::test('parent.kids')->call('toggleSleepCard', $stranger->id);

        $this->assertFalse($stranger->fresh()->sleep_card_enabled);
    }

    /**
     * The counters are the score; a parent also needs the answer behind them.
     * Without the times, "0 full nights" is a verdict with no evidence, and a
     * kid sleeping 2am to 10am reads exactly like one sleeping four hours.
     */
    public function test_a_parent_sees_the_hours_and_times_a_kid_logged(): void
    {
        $this->kid->update(['sleep_card_type' => SleepCardType::Hours]);

        // Two nights: a full one, then one long enough but at the wrong end of
        // the clock.
        app(SleepService::class)->recordHours($this->kid, NightWindow::DEFAULT_ASLEEP, NightWindow::DEFAULT_AWAKE);
        $this->travel(1)->days();
        app(SleepService::class)->recordHours($this->kid->refresh(), 900, 1380);

        $this->loginParent();

        Volt::test('parent.kids')
            ->assertOk()
            ->assertSee('What they logged')
            // Last night, said as a length and as two times.
            ->assertSee('8h')
            ->assertSee('3:00 am')
            ->assertSee('11:00 am')
            // Why it wasn't a full one, in the same words the kid was given.
            ->assertSee('only 3h between 12 and 6')
            // And the week behind it, which is what the minutes were kept for.
            ->assertSee('2 of the last 7 nights answered')
            ->assertSee('average 8h')
            ->assertSee('1 full');
    }

    /** A kid who has stopped answering reads as that, not as an empty panel. */
    public function test_a_parent_sees_when_nothing_has_been_logged(): void
    {
        $this->kid->update(['sleep_card_type' => SleepCardType::Hours]);
        $this->loginParent();

        Volt::test('parent.kids')
            ->assertOk()
            ->assertSee('Nothing answered in the last 7 nights');
    }

    /** An own-bed answer has no length to show, so the panel stays away. */
    public function test_the_hours_readout_is_absent_for_an_own_bed_kid(): void
    {
        app(SleepService::class)->record($this->kid, SleepOutcome::OwnBed);

        $this->loginParent();

        Volt::test('parent.kids')
            ->assertOk()
            ->assertDontSee('What they logged');
    }
}
