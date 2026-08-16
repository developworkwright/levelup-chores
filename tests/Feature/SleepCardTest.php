<?php

namespace Tests\Feature;

use App\Enums\Constellation;
use App\Enums\LedgerKind;
use App\Enums\PerkEffect;
use App\Enums\SleepOutcome;
use App\Enums\TicketKind;
use App\Models\BonusTicketEntry;
use App\Models\Household;
use App\Models\LedgerEntry;
use App\Models\Profile;
use App\Models\SleepNight;
use App\Services\PerkInventoryService;
use App\Services\SleepService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class SleepCardTest extends TestCase
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
            'age' => 6,
            'sleep_card_enabled' => true,
            'points' => 0,
        ]);

        // Pinned well clear of the 4am household boundary, so travelling a day
        // in the helpers below lands on the next night rather than the same one.
        $this->travelTo(Carbon::parse('2026-05-01 09:00', $this->household->timezone));
    }

    private function service(): SleepService
    {
        return app(SleepService::class);
    }

    /** Answers a run of nights, one household day apart. */
    private function nights(int $count, SleepOutcome $outcome = SleepOutcome::OwnBed): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->service()->record($this->kid->refresh(), $outcome);
            $this->travel(1)->days();
        }
    }

    public function test_the_card_is_off_unless_both_switches_are_on(): void
    {
        // Two switches, and either one alone is off. A household that has never
        // heard of this feature must not start asking a kid about their bed.
        $this->assertNotNull($this->service()->cardFor($this->kid));

        $this->kid->update(['sleep_card_enabled' => false]);
        $this->assertNull($this->service()->cardFor($this->kid->refresh()));

        $this->kid->update(['sleep_card_enabled' => true]);
        $this->household->update(['sleep_card_enabled' => false]);
        $this->assertNull($this->service()->cardFor($this->kid->refresh()));
    }

    public function test_age_is_not_a_gate(): void
    {
        // A parent decides who needs this. An eleven-year-old still working on
        // it must not be locked out by a birthday.
        $older = Profile::factory()->for($this->household)->create([
            'age' => 12,
            'sleep_card_enabled' => true,
        ]);

        $this->assertNotNull($this->service()->cardFor($older));
    }

    public function test_a_night_in_their_own_bed_lights_a_star(): void
    {
        $this->service()->record($this->kid, SleepOutcome::OwnBed);

        $fresh = $this->kid->fresh();

        $this->assertSame(1, $fresh->sleep_nights);
        $this->assertSame(1, $fresh->sleep_run);
        $this->assertSame(1, $fresh->sleep_best_run);
    }

    public function test_a_bad_night_costs_nothing_but_the_run(): void
    {
        $this->nights(3);
        $this->assertSame(3, $this->kid->fresh()->sleep_run);

        $this->service()->record($this->kid->refresh(), SleepOutcome::Visited);

        $fresh = $this->kid->fresh();

        // The entire "no punishment" rule: the run stops and nothing else moves.
        $this->assertSame(0, $fresh->sleep_run);
        $this->assertSame(3, $fresh->sleep_nights, 'Total nights must never go down.');
        $this->assertSame(3, $fresh->sleep_best_run, 'The best run is kept forever.');
    }

    public function test_a_rough_night_is_treated_exactly_like_a_visit(): void
    {
        $this->nights(2);
        $this->service()->record($this->kid->refresh(), SleepOutcome::Rough);

        $this->assertSame(0, $this->kid->fresh()->sleep_run);
        $this->assertSame(2, $this->kid->fresh()->sleep_nights);
    }

    public function test_last_night_can_only_be_answered_once(): void
    {
        $this->service()->record($this->kid, SleepOutcome::OwnBed);

        // The card sits on the page a kid lands on; two taps must not be two
        // stars.
        $this->expectException(RuntimeException::class);

        $this->service()->record($this->kid->refresh(), SleepOutcome::OwnBed);
    }

    public function test_a_card_that_is_off_refuses_to_record(): void
    {
        $this->kid->update(['sleep_card_enabled' => false]);

        $this->expectException(RuntimeException::class);

        $this->service()->record($this->kid->refresh(), SleepOutcome::OwnBed);
    }

    // --- Constellations ---------------------------------------------------

    public function test_seven_nights_finishes_a_constellation_and_pays_points(): void
    {
        // Nightly payout muted so the number below is the picture's alone —
        // the two are tested together in
        // test_the_seventh_night_pays_both_the_night_and_the_picture().
        app(SleepService::class)->setNightPoints($this->household, 0);

        $this->nights(Constellation::NIGHTS);

        $fresh = $this->kid->fresh();

        $this->assertSame(1, Constellation::completedFrom($fresh->sleep_nights));
        $this->assertSame(500, $fresh->points, '$5 at 100 points to the dollar.');

        $entry = LedgerEntry::where('kind', LedgerKind::Earn)->latest('id')->first();
        $this->assertStringContainsString(Constellation::LittleBear->label(), $entry->description);
    }

    public function test_constellations_are_paid_on_total_nights_not_on_a_run(): void
    {
        app(SleepService::class)->setNightPoints($this->household, 0);

        // Six good nights, a bad one, then a seventh good one. The run broke,
        // but the picture still finishes — that is the whole point of paying on
        // the total.
        $this->nights(6);
        $this->nights(1, SleepOutcome::Visited);
        $this->nights(1);

        $this->assertSame(7, $this->kid->fresh()->sleep_nights);
        $this->assertSame(500, $this->kid->fresh()->points);
    }

    public function test_a_parent_nudging_nights_cannot_re_pay_a_constellation(): void
    {
        app(SleepService::class)->setNightPoints($this->household, 0);

        $this->nights(Constellation::NIGHTS);
        $this->assertSame(500, $this->kid->fresh()->points);

        // Down and back up. Without the paid high-water mark this is a way to
        // print money, exactly as it was for streak milestones.
        $this->service()->adjust($this->kid->fresh(), nights: 0);
        $this->service()->adjust($this->kid->fresh(), nights: Constellation::NIGHTS);
        $this->nights(1);

        $this->assertSame(500, $this->kid->fresh()->points);
    }

    public function test_every_good_night_pays_on_its_own(): void
    {
        // The picture is a week away, and a week is a very long time to a
        // five-year-old. Tonight is the hard part, so tonight pays.
        $this->service()->record($this->kid, SleepOutcome::OwnBed);

        $this->assertSame(100, $this->kid->fresh()->points);
    }

    public function test_a_bad_night_pays_nothing_and_takes_nothing(): void
    {
        $this->nights(1);
        $this->service()->record($this->kid->refresh(), SleepOutcome::Visited);

        $this->assertSame(100, $this->kid->fresh()->points);
    }

    public function test_the_nightly_reward_is_tapered_from_the_household(): void
    {
        app(SleepService::class)->setNightPoints($this->household, 25);

        $this->nights(2);

        $this->assertSame(50, $this->kid->fresh()->points);
    }

    public function test_a_parent_nudging_nights_does_not_pay_for_nights_never_slept(): void
    {
        // The nightly payment rides on the unique night row, not on the
        // counter — which is what lets a parent correct the total without
        // minting a dollar a night for the privilege. Twenty nights is two
        // finished pictures and nothing else: $10, not $20 + $10.
        $this->service()->adjust($this->kid, nights: 20);

        $this->assertSame(1000, $this->kid->fresh()->points);
    }

    public function test_correcting_the_run_hands_over_the_chest_that_was_earned(): void
    {
        // The reported bug: a parent typing in the nights a kid actually did —
        // away from home, card switched on late, a five-year-old who forgot to
        // tap — moved the number and withheld everything it had earned.
        $this->service()->adjust($this->kid, run: 7);

        $fresh = $this->kid->fresh();

        $this->assertSame(7, $fresh->pending_sleep_chest);
        $this->assertSame(
            SleepService::RUN_MILESTONES[3] + SleepService::RUN_MILESTONES[7],
            (int) BonusTicketEntry::where('profile_id', $this->kid->id)
                ->where('kind', TicketKind::Sleep)
                ->sum('amount'),
            'Every milestone on the way up is owed, not just the one landed on.',
        );
    }

    public function test_a_corrected_run_pays_each_milestone_once(): void
    {
        $this->service()->adjust($this->kid, run: 7);
        $paid = (int) BonusTicketEntry::where('kind', TicketKind::Sleep)->sum('amount');

        // Down and back up. Both marks only ever climb.
        $this->service()->adjust($this->kid->fresh(), run: 0);
        $this->service()->adjust($this->kid->fresh(), run: 7);

        $this->assertSame($paid, (int) BonusTicketEntry::where('kind', TicketKind::Sleep)->sum('amount'));
    }

    public function test_the_card_only_ever_names_a_chest_that_will_actually_pay(): void
    {
        // Reach seven, bank it, then break the run.
        $this->nights(7);
        $this->service()->openChest($this->kid->fresh());
        $this->nights(1, SleepOutcome::Visited);

        // Three and seven can never pay again — payRunMilestones() only pays
        // above the mark. Naming either of them would promise a chest that
        // never arrives, every morning, for as long as it took to say it.
        $card = $this->service()->cardFor($this->kid->fresh());

        $this->assertSame(0, $card['run']);
        $this->assertSame(14, $card['nextMilestone']);
    }

    public function test_the_chest_the_card_named_is_the_one_that_lands(): void
    {
        $this->nights(7);
        $this->service()->openChest($this->kid->fresh());
        $this->nights(1, SleepOutcome::Visited);

        $promised = $this->service()->cardFor($this->kid->fresh())['nextMilestone'];

        // Climb back to exactly what the card promised.
        $this->nights($promised);

        $this->assertSame($promised, $this->kid->fresh()->pending_sleep_chest);
    }

    public function test_a_run_that_climbs_past_several_milestones_pays_all_of_them(): void
    {
        $this->service()->adjust($this->kid, run: 30);

        $this->assertSame(
            SleepService::RUN_MILESTONES[3]
                + SleepService::RUN_MILESTONES[7]
                + SleepService::RUN_MILESTONES[14]
                + SleepService::RUN_MILESTONES[30],
            (int) BonusTicketEntry::where('kind', TicketKind::Sleep)->sum('amount'),
        );

        $this->assertSame(30, $this->kid->fresh()->pending_sleep_chest);
    }

    public function test_the_seventh_night_pays_both_the_night_and_the_picture(): void
    {
        $this->nights(Constellation::NIGHTS);

        // Seven nights at 100, plus the 500 for finishing the picture.
        $this->assertSame(1200, $this->kid->fresh()->points);
    }

    public function test_a_household_can_taper_what_a_constellation_pays(): void
    {
        $sleep = app(SleepService::class);
        $sleep->setNightPoints($this->household, 0);
        $sleep->setConstellationPoints($this->household->fresh(), 200);

        $this->nights(Constellation::NIGHTS);

        $this->assertSame(200, $this->kid->fresh()->points);
    }

    public function test_tapering_never_touches_a_constellation_already_paid(): void
    {
        app(SleepService::class)->setNightPoints($this->household, 0);

        $this->nights(Constellation::NIGHTS);
        $this->assertSame(500, $this->kid->fresh()->points);

        // Halfway through the taper. The first picture was worth what it was
        // worth; only the next one changes.
        app(SleepService::class)->setConstellationPoints($this->household->fresh(), 100);
        $this->nights(Constellation::NIGHTS);

        $this->assertSame(600, $this->kid->fresh()->points);
    }

    public function test_a_fully_tapered_household_still_finishes_pictures(): void
    {
        $sleep = app(SleepService::class);
        $sleep->setNightPoints($this->household, 0);
        $sleep->setConstellationPoints($this->household->fresh(), 0);

        $this->nights(Constellation::NIGHTS);

        $fresh = $this->kid->fresh();

        // The end of a taper: the picture is the reward, and the card carries
        // on exactly as before.
        $this->assertSame(1, Constellation::completedFrom($fresh->sleep_nights));
        $this->assertSame(0, $fresh->points);
        // A zero-amount row would be noise in the parent's feed.
        $this->assertSame(0, LedgerEntry::where('kind', LedgerKind::Earn)->count());
    }

    public function test_an_unrefreshed_household_still_knows_the_payout(): void
    {
        // A row just inserted doesn't carry column defaults the database
        // filled in — the trap the boss battle's counter fell into. Reading it
        // as null must not mean paying zero by accident.
        $fresh = Household::factory()->create(['sleep_card_enabled' => true]);

        $this->assertSame(500, app(SleepService::class)->constellationPoints($fresh));
    }

    public function test_the_card_shows_the_picture_being_drawn(): void
    {
        $this->nights(9);

        $card = $this->service()->cardFor($this->kid->fresh());

        $this->assertSame(1, $card['completed']);
        $this->assertSame(2, $card['starsLit']);
        // The one being worked on, not the one already finished.
        $this->assertSame(Constellation::number(2), $card['drawing']);
    }

    // --- Run milestones ---------------------------------------------------

    public function test_a_run_milestone_banks_tickets_and_queues_a_chest(): void
    {
        $this->nights(3);

        $fresh = $this->kid->fresh();

        $this->assertSame(3, $fresh->pending_sleep_chest);
        $this->assertSame(
            SleepService::RUN_MILESTONES[3],
            (int) BonusTicketEntry::where('profile_id', $this->kid->id)
                ->where('kind', TicketKind::Sleep)
                ->sum('amount'),
        );
    }

    public function test_the_chest_is_a_reveal_not_a_payment(): void
    {
        $this->nights(3);

        $before = $this->kid->fresh()->bonus_tickets;
        $opened = $this->service()->openChest($this->kid->fresh());

        $this->assertSame(3, $opened['nights']);
        $this->assertSame(SleepService::RUN_MILESTONES[3], $opened['tickets']);
        // The tickets landed with the milestone; opening only clears the flag.
        $this->assertSame($before, $this->kid->fresh()->bonus_tickets);
        $this->assertNull($this->kid->fresh()->pending_sleep_chest);
    }

    public function test_a_milestone_pays_once_even_across_broken_runs(): void
    {
        $this->nights(3);
        $paid = (int) BonusTicketEntry::where('kind', TicketKind::Sleep)->sum('amount');

        // Break it and climb back to three.
        $this->nights(1, SleepOutcome::Visited);
        $this->nights(3);

        $this->assertSame(
            $paid,
            (int) BonusTicketEntry::where('kind', TicketKind::Sleep)->sum('amount'),
            'Reaching three again must not pay a second time.',
        );
    }

    // --- The Night Saver perk ---------------------------------------------

    public function test_a_night_saver_buys_back_the_run(): void
    {
        $this->nights(4);
        $this->nights(1, SleepOutcome::Visited);

        $this->assertSame(0, $this->kid->fresh()->sleep_run);

        $this->assertTrue($this->service()->saveNight($this->kid->fresh()));

        // Back to what it would have been: the four before, plus the bought one.
        $this->assertSame(5, $this->kid->fresh()->sleep_run);
    }

    public function test_a_saved_night_does_not_buy_a_constellation(): void
    {
        // Nightly payout muted so the balance below is the picture's alone —
        // the six good nights would otherwise pay for themselves and hide it.
        app(SleepService::class)->setNightPoints($this->household, 0);

        $this->nights(6);
        $this->nights(1, SleepOutcome::Visited);
        $this->service()->saveNight($this->kid->fresh());

        // The run is rescued, but the picture is paid in points and a perk must
        // never be a way to buy those.
        $this->assertSame(7, $this->kid->fresh()->sleep_run);
        $this->assertSame(6, $this->kid->fresh()->sleep_nights);
        $this->assertSame(0, $this->kid->fresh()->points);
    }

    public function test_there_is_nothing_to_save_once_a_new_run_is_going(): void
    {
        $this->nights(2);
        $this->nights(1, SleepOutcome::Visited);
        $this->nights(1);

        // Buying now would splice a finished run onto a new one — the same
        // exploit the streak restore closes.
        $this->assertNotNull($this->service()->saveReason($this->kid->fresh()));
        $this->assertFalse($this->service()->saveNight($this->kid->fresh()));
    }

    public function test_a_night_cannot_be_bought_back_twice(): void
    {
        $this->nights(2);
        $this->nights(1, SleepOutcome::Visited);

        $this->assertTrue($this->service()->saveNight($this->kid->fresh()));
        $this->assertFalse($this->service()->saveNight($this->kid->fresh()));
    }

    public function test_the_perk_shop_refuses_a_night_saver_with_nothing_to_save(): void
    {
        $this->nights(2);

        $this->assertNotNull(
            app(PerkInventoryService::class)->blockedReason($this->kid->fresh(), PerkEffect::NightSaver),
        );
    }

    public function test_the_perk_shop_offers_a_night_saver_after_a_miss(): void
    {
        $this->nights(2);
        $this->nights(1, SleepOutcome::Visited);

        $this->assertNull(
            app(PerkInventoryService::class)->blockedReason($this->kid->fresh(), PerkEffect::NightSaver),
        );
    }

    public function test_a_kid_without_the_card_is_never_offered_a_night_saver(): void
    {
        $this->kid->update(['sleep_card_enabled' => false]);

        $this->assertNotNull(
            app(PerkInventoryService::class)->blockedReason($this->kid->fresh(), PerkEffect::NightSaver),
        );
    }

    // --- Parent adjustments -----------------------------------------------

    public function test_a_parent_can_correct_the_numbers(): void
    {
        $this->nights(2);

        $this->service()->adjust($this->kid->fresh(), nights: 10, run: 4);

        $fresh = $this->kid->fresh();

        $this->assertSame(10, $fresh->sleep_nights);
        $this->assertSame(4, $fresh->sleep_run);
        $this->assertSame(4, $fresh->sleep_best_run, 'A corrected run can set a new best.');
    }

    public function test_the_log_keeps_the_honest_answer(): void
    {
        $this->nights(1, SleepOutcome::Rough);

        $night = SleepNight::where('profile_id', $this->kid->id)->firstOrFail();

        // A parent looking for a pattern needs what actually happened, not what
        // the counters were later corrected to.
        $this->assertSame(SleepOutcome::Rough, $night->outcome);
    }
}
