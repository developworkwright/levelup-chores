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

    /** Answers a run of nights, one household day apart. */
    private function nights(int $count, int $minutes = 480): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->service()->recordHours($this->kid->refresh(), $minutes);
            $this->travel(1)->days();
        }
    }

    public function test_a_full_night_pays_the_full_rate_and_advances_the_run(): void
    {
        $result = $this->service()->recordHours($this->kid, 480);

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
        $result = $this->service()->recordHours($this->kid, 400);

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

        $result = $this->service()->recordHours($this->kid->refresh(), 300);

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
    }

    public function test_minutes_are_snapped_to_the_half_hour_and_clamped(): void
    {
        $result = $this->service()->recordHours($this->kid, 9999);

        $this->assertSame(SleepBand::MAX_MINUTES, $result['minutes']);

        $this->travel(1)->days();

        $result = $this->service()->recordHours($this->kid->refresh(), 487);

        // Snapped down to the half hour rather than rejected.
        $this->assertSame(480, $result['minutes']);
    }

    public function test_the_night_is_logged_with_its_minutes_and_named_in_the_ledger(): void
    {
        $this->service()->recordHours($this->kid, 450);

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
        $this->service()->recordHours($this->kid, 480);

        $this->expectException(RuntimeException::class);
        $this->service()->recordHours($this->kid->refresh(), 300);
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
        $this->service()->recordHours($this->kid->refresh(), 480);
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
        $this->service()->recordHours($this->kid->refresh(), 300);

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
        $this->assertSame(SleepBand::DEFAULT_MINUTES, $card['startMinutes']);
    }

    public function test_a_household_can_taper_what_a_band_pays(): void
    {
        $this->service()->setHoursPointsFor($this->household, SleepBand::Full, 60);

        $result = $this->service()->recordHours($this->kid, 480);

        $this->assertSame(60, $result['nightPoints']);

        // Tapered to nothing still counts the night — the run is not the money.
        $this->service()->setHoursPointsFor($this->household->refresh(), SleepBand::Full, 0);
        $this->travel(1)->days();

        $result = $this->service()->recordHours($this->kid->refresh(), 480);

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

        $this->service()->recordHours($this->kid->refresh(), 300);

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
            ->call('answerSleepHours', 450)
            ->assertOk();

        $this->kid->refresh();
        // A short night: paid, but the run stays where it was.
        $this->assertSame(50, $this->kid->points);
        $this->assertSame(0, $this->kid->sleep_hours_run);
        $this->assertSame(450, SleepNight::where('profile_id', $this->kid->id)->sole()->minutes);
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
