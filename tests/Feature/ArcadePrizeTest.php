<?php

namespace Tests\Feature;

use App\Enums\TicketKind;
use App\Models\ArcadeScore;
use App\Models\ArcadeWeekPrize;
use App\Models\BonusTicketEntry;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ArcadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Three bonus tickets to the tallest tower of a finished week.
 *
 * There is no scheduler: the week is settled by whoever opens the arcade next.
 * So the two things worth testing hardest are that it pays exactly once however
 * many times that happens, and that a grown-up topping the board closes the
 * week without collecting anything.
 */
class ArcadePrizeTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
    }

    private function arcade(): ArcadeService
    {
        return app(ArcadeService::class);
    }

    private function kid(string $name = 'Nova'): Profile
    {
        return Profile::factory()->for($this->household)->create(['name' => $name]);
    }

    private function parent(string $name = 'Dad'): Profile
    {
        return Profile::factory()->for($this->household)->parent()->create(['name' => $name]);
    }

    /** A run in a week that has already finished. */
    private function lastWeek(Profile $profile, int $score): ArcadeScore
    {
        return ArcadeScore::create([
            'household_id' => $profile->household_id,
            'profile_id' => $profile->id,
            'codename' => $profile->name,
            'score' => $score,
            'week' => $this->arcade()->currentWeek(now()->subWeek()),
        ]);
    }

    public function test_the_tallest_tower_of_a_finished_week_wins_three_tickets(): void
    {
        $winner = $this->kid('Nova');
        $other = $this->kid('Rook');

        $this->lastWeek($other, 12);
        $this->lastWeek($winner, 31);

        $this->arcade()->settle($this->household);

        $this->assertSame(ArcadeService::PRIZE_TICKETS, $winner->fresh()->bonus_tickets);
        $this->assertSame(0, $other->fresh()->bonus_tickets);

        // Through TicketService, so the entries and the cached balance are
        // written in one transaction and cannot drift.
        $entry = BonusTicketEntry::sole();

        $this->assertSame(TicketKind::Arcade, $entry->kind);
        $this->assertSame(ArcadeService::PRIZE_TICKETS, $entry->amount);
        $this->assertSame($winner->id, $entry->profile_id);
    }

    public function test_a_week_is_only_ever_paid_once(): void
    {
        $winner = $this->kid();
        $this->lastWeek($winner, 20);

        // Every visit to the arcade settles. A kid who opens it four times on a
        // Monday must not be four times better off than one who opens it once.
        $this->arcade()->settle($this->household);
        $this->arcade()->settle($this->household);
        $this->arcade()->settle($this->household);

        $this->assertSame(ArcadeService::PRIZE_TICKETS, $winner->fresh()->bonus_tickets);
        $this->assertSame(1, BonusTicketEntry::count());
        $this->assertSame(1, ArcadeWeekPrize::count());
    }

    public function test_a_grown_up_can_win_the_week_but_not_the_tickets(): void
    {
        $parent = $this->parent('Dad');
        $kid = $this->kid('Nova');

        $this->lastWeek($kid, 18);
        $this->lastWeek($parent, 44);

        $this->arcade()->settle($this->household);

        $this->assertSame(0, $parent->fresh()->bonus_tickets);
        // And not passed down to the runner-up either: they did not win.
        $this->assertSame(0, $kid->fresh()->bonus_tickets);
        $this->assertSame(0, BonusTicketEntry::count());

        // The week is still settled and still records who took it, which is
        // what stops it being re-checked and re-lost every single page load.
        $prize = ArcadeWeekPrize::sole();

        $this->assertSame($parent->id, $prize->profile_id);
        $this->assertSame(0, $prize->tickets);
        $this->assertSame(44, $prize->score);
    }

    public function test_the_week_in_progress_is_never_settled(): void
    {
        $kid = $this->kid();

        ArcadeScore::create([
            'household_id' => $kid->household_id,
            'profile_id' => $kid->id,
            'codename' => $kid->name,
            'score' => 25,
            'week' => $this->arcade()->currentWeek(),
        ]);

        $this->arcade()->settle($this->household);

        // Paying the leader on Wednesday would make the rest of the week
        // pointless, and the board says Sunday.
        $this->assertSame(0, $kid->fresh()->bonus_tickets);
        $this->assertSame(0, ArcadeWeekPrize::count());
    }

    public function test_a_week_nobody_played_is_not_settled_at_all(): void
    {
        $this->kid();

        $this->arcade()->settle($this->household);

        // Nothing to pay and nothing to say. A row per empty week would be a
        // table that grows forever whether or not anybody plays.
        $this->assertSame(0, ArcadeWeekPrize::count());
    }

    public function test_one_houses_week_is_settled_without_touching_another(): void
    {
        $mine = $this->kid('Nova');
        $theirs = Profile::factory()->for(Household::factory())->create(['name' => 'Rook']);

        $this->lastWeek($mine, 10);
        $this->lastWeek($theirs, 90);

        $this->arcade()->settle($this->household);

        // The taller tower belongs to another house and cannot win this one's
        // week — nor can settling here quietly pay a stranger.
        $this->assertSame(ArcadeService::PRIZE_TICKETS, $mine->fresh()->bonus_tickets);
        $this->assertSame(0, $theirs->fresh()->bonus_tickets);
        $this->assertSame(1, ArcadeWeekPrize::count());
    }

    public function test_opening_the_cabinet_is_what_pays_out(): void
    {
        $winner = $this->kid();
        $this->lastWeek($winner, 33);

        Auth::guard('profile')->login($winner);

        Volt::test('kid.arcade')
            ->assertOk()
            // And says so on the board, so a kid finds out where they played
            // rather than only in their ticket balance.
            ->assertSee('Last champion')
            ->assertSee('33 floors');

        $this->assertSame(ArcadeService::PRIZE_TICKETS, $winner->fresh()->bonus_tickets);
    }

    public function test_the_board_says_what_the_week_is_worth(): void
    {
        $kid = $this->kid();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.arcade')
            ->assertSee('3 bonus tickets')
            ->assertSee('Grown-ups can win the week, but not the tickets.');
    }
}
