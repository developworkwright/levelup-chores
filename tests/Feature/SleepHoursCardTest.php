<?php

namespace Tests\Feature;

use App\Enums\LedgerKind;
use App\Enums\SleepBand;
use App\Enums\SleepCardType;
use App\Enums\SleepOutcome;
use App\Enums\TicketKind;
use App\Models\BonusTicketEntry;
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
use RuntimeException;
use Tests\TestCase;

class SleepHoursCardTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create(['sleep_card_enabled' => true]);
        $this->kid = Profile::factory()->for($this->household)->create([
            'name' => 'Westin',
            'age' => 13,
            'sleep_card_enabled' => true,
            'sleep_card_type' => SleepCardType::Hours,
            'points' => 0,
        ]);

        // Clear of the 4am household boundary, so travelling a day lands on the
        // next night rather than the same one.
        $this->travelTo(Carbon::parse('2026-05-01 09:00', $this->household->timezone));
    }

    private function service(): SleepService
    {
        return app(SleepService::class);
    }

    /**
     * Answers a night of the given length, asleep at 11pm by default — early
     * enough that anything long enough to be a full night also covers 12 to 6.
     * Pass `$asleep` to answer a night that misses the window.
     *
     * @return array<string, mixed>
     */
    private function answer(int $minutes, int $asleep = NightWindow::DEFAULT_ASLEEP): array
    {
        return $this->service()->recordHours($this->kid->refresh(), $asleep, $asleep + $minutes);
    }

    /** Answers a run of nights, one household day apart. */
    private function nights(int $count, int $minutes = 480): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->answer($minutes);
            $this->travel(1)->days();
        }
    }

    public function test_a_full_night_pays_the_full_rate_and_advances_the_run(): void
    {
        $result = $this->answer(480);

        $this->assertSame(SleepBand::Full, $result['band']);
        // $1.00 at the default hundred-points-to-the-dollar rate.
        $this->assertSame(100, $result['nightPoints']);

        $this->kid->refresh();
        $this->assertSame(1, $this->kid->sleep_hours_nights);
        $this->assertSame(1, $this->kid->sleep_hours_run);
        $this->assertSame(1, $this->kid->sleep_hours_best_run);
        $this->assertSame(100, $this->kid->points);
    }

    public function test_a_short_night_pays_half_but_does_not_advance_anything(): void
    {
        $result = $this->answer(420);

        $this->assertSame(SleepBand::Short, $result['band']);
        $this->assertSame(50, $result['nightPoints']);

        $this->kid->refresh();
        $this->assertSame(0, $this->kid->sleep_hours_nights);
        $this->assertSame(0, $this->kid->sleep_hours_run);
        // Paid all the same — the night is not nothing.
        $this->assertSame(50, $this->kid->points);
    }

    public function test_a_rough_night_pays_nothing_and_takes_nothing_away(): void
    {
        $this->nights(3);
        $this->kid->refresh();

        $before = $this->kid->points;
        $this->assertSame(3, $this->kid->sleep_hours_nights);

        $result = $this->answer(300);

        $this->assertSame(SleepBand::Poor, $result['band']);
        $this->assertSame(0, $result['nightPoints']);

        $this->kid->refresh();
        // The run is the only thing a bad night costs.
        $this->assertSame(0, $this->kid->sleep_hours_run);
        $this->assertSame(3, $this->kid->sleep_hours_nights);
        $this->assertSame(3, $this->kid->sleep_hours_best_run);
        $this->assertSame($before, $this->kid->points);
    }

    public function test_the_eight_hour_line_is_where_the_run_starts_counting(): void
    {
        // One minute under is a short night; exactly eight hours is a full one.
        $this->assertSame(SleepBand::Short, SleepBand::fromMinutes(479));
        $this->assertSame(SleepBand::Full, SleepBand::fromMinutes(480));
        $this->assertSame(SleepBand::Poor, SleepBand::fromMinutes(359));
        $this->assertSame(SleepBand::Short, SleepBand::fromMinutes(360));

        // Eight hours only makes a full night if all six of the window's own
        // hours are inside it.
        $this->assertSame(SleepBand::Full, SleepBand::fromNight(480, NightWindow::CORE_LENGTH));
        $this->assertSame(SleepBand::Short, SleepBand::fromNight(480, NightWindow::CORE_LENGTH - 30));

        // Four hours inside the window is the floor for any paying band. Below
        // it, length stops mattering at all — that is the nap rule.
        $this->assertSame(SleepBand::Short, SleepBand::fromNight(600, NightWindow::PAYING_OVERLAP));
        $this->assertSame(SleepBand::Poor, SleepBand::fromNight(600, NightWindow::PAYING_OVERLAP - 30));
        $this->assertSame(SleepBand::Poor, SleepBand::fromNight(840, 0));

        // And the window can only ever demote — a short night's length is
        // still what makes it short.
        $this->assertSame(SleepBand::Short, SleepBand::fromNight(400, NightWindow::CORE_LENGTH));
        $this->assertSame(SleepBand::Poor, SleepBand::fromNight(300, NightWindow::CORE_LENGTH));
    }

    public function test_a_long_sleep_at_the_wrong_end_of_the_clock_pays_nothing(): void
    {
        $this->nights(2);
        $before = $this->kid->refresh()->points;

        // Three in the morning until eleven: eight hours, and only three of
        // them between midnight and six. The bedtime nap and the sleep-all-day
        // answer both land here, which is the point of the floor.
        $result = $this->answer(480, asleep: 900);

        $this->assertSame(SleepBand::Poor, $result['band']);
        $this->assertSame(480, $result['minutes']);
        $this->assertTrue($result['missedCoreHours']);
        $this->assertSame(0, $result['nightPoints']);

        $this->kid->refresh();
        // Pays nothing, and still takes nothing: the two nights already banked
        // are untouched and only the run stops.
        $this->assertSame($before, $this->kid->points);
        $this->assertSame(2, $this->kid->sleep_hours_nights);
        $this->assertSame(0, $this->kid->sleep_hours_run);
    }

    public function test_four_hours_inside_the_window_is_what_a_short_night_is_paid_for(): void
    {
        // Two in the morning until eleven: nine hours, exactly four of them
        // inside the window. Late to bed, but it pays.
        $this->assertSame(NightWindow::PAYING_OVERLAP, NightWindow::overlapOf(840, 1380));

        $result = $this->answer(540, asleep: 840);

        $this->assertSame(SleepBand::Short, $result['band']);
        $this->assertSame(50, $result['nightPoints']);

        $this->travel(1)->days();

        // Half an hour later to bed, same wake-up: three and a half hours
        // inside the window, and now it pays nothing. Only the hours between
        // 12 and 6 move this line — the other five and a half don't count.
        $result = $this->answer(510, asleep: 870);

        $this->assertSame(SleepBand::Poor, $result['band']);
        $this->assertSame(0, $result['nightPoints']);

        $this->travel(1)->days();

        // And it reads from the other end too: six in the evening to four in
        // the morning is ten hours with exactly four inside the window.
        $result = $this->answer(600, asleep: NightWindow::EARLIEST_ASLEEP);

        $this->assertSame(SleepBand::Short, $result['band']);
        $this->assertSame(50, $result['nightPoints']);
    }

    public function test_the_window_has_to_be_covered_at_both_ends(): void
    {
        // Half past midnight to half past nine — nine hours, and awake for the
        // first half hour of the window. Five and a half hours inside it, so it
        // still pays; it just isn't a full night.
        $this->assertSame(SleepBand::Short, $this->answer(540, asleep: 750)['band']);

        $this->travel(1)->days();

        // Ten in the evening until half five: seven and a half hours, up before
        // six. Short either way, but the run must not count it.
        $this->assertSame(SleepBand::Short, $this->answer(450, asleep: 600)['band']);

        $this->travel(1)->days();

        // Eleven until seven, which is what the card is actually asking for.
        $this->assertSame(SleepBand::Full, $this->answer(480)['band']);

        $this->assertSame(1, $this->kid->refresh()->sleep_hours_nights);
    }

    public function test_a_night_keeps_the_times_it_was_answered_with(): void
    {
        $this->answer(480);

        $night = SleepNight::where('profile_id', $this->kid->id)->sole();

        $this->assertSame(NightWindow::DEFAULT_ASLEEP, $night->asleep_minute);
        $this->assertSame(NightWindow::DEFAULT_AWAKE, $night->awake_minute);
        $this->assertSame(480, $night->minutes);
        $this->assertTrue($night->coversCoreHours());
        $this->assertFalse($night->missedCoreHours());
        $this->assertSame(SleepBand::Full, $night->band());

        // And the parent reading the ledger can see why it was a full one.
        $entry = LedgerEntry::where('profile_id', $this->kid->id)
            ->where('kind', LedgerKind::Earn)
            ->sole();

        $this->assertStringContainsString('11:00 pm to 7:00 am', $entry->description);
    }

    public function test_a_night_answered_before_the_window_rule_is_not_demoted_by_it(): void
    {
        // What every row logged by the old card looks like: a length, and no
        // times at all. The rule arrived after those nights were slept.
        $night = SleepNight::factory()->for($this->household)->for($this->kid)->create([
            'outcome' => null,
            'minutes' => 480,
            'asleep_minute' => null,
            'awake_minute' => null,
        ]);

        $this->assertNull($night->coversCoreHours());
        $this->assertFalse($night->missedCoreHours());
        $this->assertSame(SleepBand::Full, $night->band());
        $this->assertTrue($night->counted());
    }

    public function test_the_times_are_snapped_to_the_half_hour_and_held_in_range(): void
    {
        // Noon and midnight-and-a-bit: both outside what the steppers can
        // produce, so both are pulled back to the ends of their ranges rather
        // than costing the kid their answer.
        $result = $this->service()->recordHours($this->kid, 0, 9999);

        $this->assertSame(NightWindow::EARLIEST_ASLEEP, $result['asleep']);
        $this->assertSame(NightWindow::LATEST_AWAKE, $result['awake']);
        // Fourteen hours, which is the most a night can be.
        $this->assertSame(SleepBand::MAX_MINUTES, $result['minutes']);

        $this->travel(1)->days();

        $result = $this->service()->recordHours($this->kid->refresh(), 665, 1157);

        // Snapped down to the half hour rather than rejected.
        $this->assertSame(660, $result['asleep']);
        $this->assertSame(1140, $result['awake']);
        $this->assertSame(480, $result['minutes']);
    }

    public function test_a_night_cannot_end_before_it_began(): void
    {
        // Four in the morning to four in the morning: the only pair the
        // steppers can be pushed into where nothing was slept at all.
        $result = $this->service()->recordHours(
            $this->kid,
            NightWindow::LATEST_ASLEEP,
            NightWindow::EARLIEST_AWAKE,
        );

        $this->assertSame(0, $result['minutes']);
        $this->assertSame(SleepBand::Poor, $result['band']);
        $this->assertSame(0, $this->kid->refresh()->sleep_hours_run);
    }

    public function test_the_night_is_logged_with_its_minutes_and_named_in_the_ledger(): void
    {
        $this->answer(450);

        $night = SleepNight::where('profile_id', $this->kid->id)->sole();

        $this->assertSame(450, $night->minutes);
        $this->assertNull($night->outcome);
        $this->assertSame(SleepBand::Short, $night->band());
        $this->assertFalse($night->counted());

        $entry = LedgerEntry::where('profile_id', $this->kid->id)
            ->where('kind', LedgerKind::Earn)
            ->sole();

        // Names the card, the length and the night it was about — the night
        // being the evening it began, not the morning it ended.
        $this->assertStringContainsString('Hours card', $entry->description);
        $this->assertStringContainsString('7h 30m', $entry->description);
        $this->assertStringContainsString('Thu night', $entry->description);
    }

    public function test_a_night_cannot_be_answered_twice(): void
    {
        $this->answer(480);

        $this->expectException(RuntimeException::class);
        $this->answer(300);
    }

    public function test_each_card_type_refuses_the_other_type_of_answer(): void
    {
        try {
            $this->service()->record($this->kid, SleepOutcome::OwnBed);
            $this->fail('An hours kid should not be able to answer the own-bed card.');
        } catch (RuntimeException) {
            // Expected.
        }

        $this->kid->update(['sleep_card_type' => SleepCardType::OwnBed]);

        $this->expectException(RuntimeException::class);
        $this->answer(480);
    }

    public function test_a_run_of_full_nights_banks_tickets_and_queues_its_own_chest(): void
    {
        $this->nights(3);
        $this->kid->refresh();

        $this->assertSame(3, $this->kid->pending_sleep_hours_chest);
        // The own-bed card's chest is untouched by the hours card's run.
        $this->assertNull($this->kid->pending_sleep_chest);

        $entry = BonusTicketEntry::where('profile_id', $this->kid->id)
            ->where('kind', TicketKind::Sleep)
            ->sole();

        $this->assertSame(1, $entry->amount);
        $this->assertStringContainsString('3 full nights in a row', $entry->description);

        $opened = $this->service()->openChest($this->kid->refresh());

        $this->assertSame(['nights' => 3, 'tickets' => 1], $opened);
        $this->assertNull($this->kid->refresh()->pending_sleep_hours_chest);
    }

    public function test_the_night_saver_buys_back_an_hours_run(): void
    {
        $this->nights(4);
        $this->answer(300);

        $this->assertSame(0, $this->kid->refresh()->sleep_hours_run);

        $this->assertTrue($this->service()->saveNight($this->kid->refresh()));

        $this->kid->refresh();
        // Restored to what it would have been had last night counted.
        $this->assertSame(5, $this->kid->sleep_hours_run);
        $this->assertSame(5, $this->kid->sleep_hours_best_run);
        // The log stays honest about what actually happened.
        $this->assertSame(300, SleepNight::where('profile_id', $this->kid->id)
            ->orderByDesc('night_date')->first()->minutes);
    }

    public function test_graduating_freezes_the_own_bed_numbers_rather_than_clearing_them(): void
    {
        $kid = Profile::factory()->for($this->household)->create([
            'sleep_card_enabled' => true,
            'sleep_card_type' => SleepCardType::OwnBed,
            'points' => 0,
        ]);

        for ($i = 0; $i < 7; $i++) {
            $this->service()->record($kid->refresh(), SleepOutcome::OwnBed);
            $this->travel(1)->days();
        }

        $kid->refresh();
        $this->assertSame(7, $kid->sleep_nights);
        $this->assertSame(7, $kid->sleep_run);

        $kid->update(['sleep_card_type' => SleepCardType::Hours]);

        // The hours card starts clean — a seven-night own-bed run is not seven
        // full nights of sleep and must not be counted as one.
        $card = $this->service()->cardFor($kid->refresh());

        $this->assertSame(SleepCardType::Hours, $card['type']);
        $this->assertSame(0, $card['nights']);
        $this->assertSame(0, $card['run']);

        // And the sky they spent a week drawing is still theirs.
        $this->assertSame(7, $kid->sleep_nights);
        $this->assertCount(1, $this->service()->earnedConstellations($kid));
    }

    public function test_the_hours_card_pays_no_constellations(): void
    {
        $this->nights(7);
        $this->kid->refresh();

        $this->assertSame(0, $this->kid->sleep_constellations_paid);
        $this->assertSame(0, $this->kid->sleep_nights);

        // Seven full nights, and every ledger row is a night — no picture.
        $this->assertSame(0, LedgerEntry::where('profile_id', $this->kid->id)
            ->where('description', 'like', '%finished%')->count());
    }

    public function test_the_card_payload_carries_the_bands_and_no_sky(): void
    {
        $card = $this->service()->cardFor($this->kid);

        $this->assertSame(SleepCardType::Hours, $card['type']);
        $this->assertSame(
            ['full' => 100, 'short' => 50, 'poor' => 0],
            $card['bands'],
        );
        $this->assertArrayNotHasKey('drawing', $card);
        $this->assertArrayNotHasKey('earned', $card);
        // The steppers open on eleven to seven — a full night that covers the
        // window, rather than one a kid has to climb to.
        $this->assertSame(NightWindow::DEFAULT_ASLEEP, $card['startAsleep']);
        $this->assertSame(NightWindow::DEFAULT_AWAKE, $card['startAwake']);
    }

    public function test_a_household_can_taper_what_a_band_pays(): void
    {
        $this->service()->setHoursPointsFor($this->household, SleepBand::Full, 60);

        $result = $this->answer(480);

        $this->assertSame(60, $result['nightPoints']);

        // Tapered to nothing still counts the night — the run is not the money.
        $this->service()->setHoursPointsFor($this->household->refresh(), SleepBand::Full, 0);
        $this->travel(1)->days();

        $result = $this->answer(480);

        $this->assertSame(0, $result['nightPoints']);
        $this->assertSame(2, $this->kid->refresh()->sleep_hours_nights);
    }

    public function test_a_parent_correction_moves_the_active_cards_numbers_only(): void
    {
        $this->service()->adjust($this->kid, nights: 5, run: 5);

        $this->kid->refresh();
        $this->assertSame(5, $this->kid->sleep_hours_nights);
        $this->assertSame(5, $this->kid->sleep_hours_run);
        // The frozen own-bed record is left exactly where it was.
        $this->assertSame(0, $this->kid->sleep_nights);
        $this->assertSame(0, $this->kid->sleep_run);

        // And the correction settles up the chest the kid had earned.
        $this->assertSame(3, $this->kid->pending_sleep_hours_chest);
    }

    public function test_there_is_nothing_to_save_until_a_run_actually_breaks(): void
    {
        $this->nights(2);

        // A run still going has nothing standing in its way.
        $this->assertNotNull($this->service()->saveReason($this->kid->refresh()));

        $this->answer(300);

        $this->assertNull($this->service()->saveReason($this->kid->refresh()));
    }

    public function test_the_kid_page_asks_the_hours_question_rather_than_the_own_bed_one(): void
    {
        Chore::factory()->for($this->household)->create();
        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('How long did you sleep?')
            ->assertDontSee('How did last night go?');
    }

    public function test_a_kid_answers_the_hours_card_from_the_page(): void
    {
        Chore::factory()->for($this->household)->create();
        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.quests')
            ->call('answerSleepHours', NightWindow::DEFAULT_ASLEEP, NightWindow::DEFAULT_ASLEEP + 450)
            ->assertOk();

        $this->kid->refresh();
        // A short night: paid, but the run stays where it was.
        $this->assertSame(50, $this->kid->points);
        $this->assertSame(0, $this->kid->sleep_hours_run);
        $this->assertSame(450, SleepNight::where('profile_id', $this->kid->id)->sole()->minutes);
    }

    public function test_the_page_says_why_a_night_outside_the_window_did_not_pay(): void
    {
        Chore::factory()->for($this->household)->create();
        Auth::guard('profile')->login($this->kid);

        // Three in the morning until eleven: eight hours, three of them inside
        // the window, so it pays nothing — and the card has to say so in words
        // a kid who slept eight hours will accept.
        Volt::test('kid.quests')
            ->call('answerSleepHours', 900, 1380)
            ->assertOk()
            ->assertSee('3:00 am')
            ->assertSee('11:00 am')
            ->assertSee('Only 3h of that was between')
            ->assertSee('it needs four of them');

        $night = SleepNight::where('profile_id', $this->kid->id)->sole();

        $this->assertTrue($night->missedCoreHours());
        $this->assertSame(180, $night->coreOverlap());
        $this->assertSame(0, $this->kid->refresh()->points);
    }

    public function test_a_parent_graduates_a_kid_from_the_console_without_losing_anything(): void
    {
        $this->kid->update([
            'sleep_card_type' => SleepCardType::OwnBed,
            'sleep_nights' => 14,
            'sleep_run' => 6,
            'sleep_best_run' => 9,
        ]);

        Chore::factory()->for($this->household)->create();
        $parent = Profile::factory()->for($this->household)->parent()->create();
        Auth::guard('profile')->login($parent);

        Volt::test('parent.kids')
            ->assertOk()
            ->call('setSleepCardType', $this->kid->id, 'hours')
            ->assertOk();

        $this->kid->refresh();
        $this->assertSame(SleepCardType::Hours, $this->kid->sleep_card_type);

        // Everything the own-bed card built is exactly where it was left.
        $this->assertSame(14, $this->kid->sleep_nights);
        $this->assertSame(6, $this->kid->sleep_run);
        $this->assertSame(9, $this->kid->sleep_best_run);
    }

    public function test_a_parent_cannot_set_a_card_type_that_does_not_exist(): void
    {
        Chore::factory()->for($this->household)->create();
        $parent = Profile::factory()->for($this->household)->parent()->create();
        Auth::guard('profile')->login($parent);

        Volt::test('parent.kids')->call('setSleepCardType', $this->kid->id, 'nonsense');

        $this->assertSame(SleepCardType::Hours, $this->kid->fresh()->sleep_card_type);
    }
}
