<?php

namespace Tests\Feature;

use App\Enums\ArcadeGame;
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
 * Three bonus tickets to the top of a finished week — on each game.
 *
 * There is no scheduler: the week is settled by whoever opens the arcade next.
 * So the two things worth testing hardest are that it pays exactly once however
 * many times that happens, and that a grown-up topping a board closes that week
 * without collecting anything.
 *
 * A second game added a third: a week is now two settlements rather than
 * one, and closing the tower must not close the walk. One prize per game is a
 * product decision as much as a technical one — a combined champion would make
 * the second game pointless for whoever is already best at the first.
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
    private function lastWeek(Profile $profile, int $score, ?ArcadeGame $game = null): ArcadeScore
    {
        return ArcadeScore::create([
            'household_id' => $profile->household_id,
            'profile_id' => $profile->id,
            'game' => $game ?? ArcadeGame::default(),
            'codename' => $profile->name,
            'score' => $score,
            'week' => $this->arcade()->currentWeek(now()->subWeek()),
        ]);
    }

    public function test_the_top_of_a_finished_week_wins_three_tickets(): void
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

    public function test_each_game_pays_its_own_champion(): void
    {
        /*
         * The reason the prize is per game. Merged, the better player takes both
         * and the second game is worth nothing to anybody else — which is the
         * opposite of why it was built.
         */
        $climber = $this->kid('Nova');
        $walker = $this->kid('Rook');

        $this->lastWeek($climber, 44, ArcadeGame::StackTheMess);
        $this->lastWeek($walker, 12, ArcadeGame::StackTheMess);
        $this->lastWeek($walker, 31, ArcadeGame::WindyWalkies);
        $this->lastWeek($climber, 9, ArcadeGame::WindyWalkies);

        $this->arcade()->settle($this->household);

        $this->assertSame(ArcadeService::PRIZE_TICKETS, $climber->fresh()->bonus_tickets);
        $this->assertSame(ArcadeService::PRIZE_TICKETS, $walker->fresh()->bonus_tickets);
        $this->assertSame(2, ArcadeWeekPrize::count());
    }

    public function test_settling_one_games_week_leaves_anothers_open(): void
    {
        // The unique key that makes settlement exactly-once had to grow a third
        // column when the second game arrived. Without it, the first game
        // settled on a Monday would close the week for both and the other
        // game's champion would never be paid.
        $walker = $this->kid('Nova');
        $climber = $this->kid('Rook');

        $this->lastWeek($walker, 20, ArcadeGame::WindyWalkies);

        $this->arcade()->settle($this->household);

        $this->assertSame(1, ArcadeWeekPrize::count());

        // A run posted to the other game for the same, already-part-settled
        // week — a kid catching up on Monday morning.
        $this->lastWeek($climber, 15, ArcadeGame::StackTheMess);

        $this->arcade()->settle($this->household);

        $this->assertSame(ArcadeService::PRIZE_TICKETS, $climber->fresh()->bonus_tickets);
        $this->assertSame(2, ArcadeWeekPrize::count());
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
        $this->assertSame(ArcadeGame::default(), $prize->game);
    }

    public function test_the_week_in_progress_is_never_settled(): void
    {
        $kid = $this->kid();

        ArcadeScore::create([
            'household_id' => $kid->household_id,
            'profile_id' => $kid->id,
            'game' => ArcadeGame::default(),
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

    public function test_a_game_nobody_played_is_not_settled_at_all(): void
    {
        $kid = $this->kid();

        $this->lastWeek($kid, 20, ArcadeGame::WindyWalkies);

        $this->arcade()->settle($this->household);

        // Nothing to pay on the other one and nothing to say about it. A row
        // per empty week per game would be a table that grows forever whether
        // or not anybody plays.
        $this->assertSame(1, ArcadeWeekPrize::count());
        $this->assertSame(0, ArcadeWeekPrize::where('game', ArcadeGame::StackTheMess)->count());
    }

    public function test_a_week_nobody_played_at_all_is_not_settled_either(): void
    {
        $this->kid();

        $this->arcade()->settle($this->household);

        $this->assertSame(0, ArcadeWeekPrize::count());
    }

    public function test_one_houses_week_is_settled_without_touching_another(): void
    {
        $mine = $this->kid('Nova');
        $theirs = Profile::factory()->for(Household::factory())->create(['name' => 'Rook']);

        $this->lastWeek($mine, 10);
        $this->lastWeek($theirs, 90);

        $this->arcade()->settle($this->household);

        // The bigger run belongs to another house and cannot win this one's
        // week — nor can settling here quietly pay a stranger.
        $this->assertSame(ArcadeService::PRIZE_TICKETS, $mine->fresh()->bonus_tickets);
        $this->assertSame(0, $theirs->fresh()->bonus_tickets);
        $this->assertSame(1, ArcadeWeekPrize::count());
    }

    public function test_opening_either_game_settles_both(): void
    {
        // A kid who only ever plays one game should not be the reason the other
        // one never pays out, so settlement fans over the games rather than
        // following whichever game the page happens to be showing.
        $walker = $this->kid('Nova');
        $climber = $this->kid('Rook');

        $this->lastWeek($walker, 33, ArcadeGame::WindyWalkies);
        $this->lastWeek($climber, 33, ArcadeGame::StackTheMess);

        Auth::guard('profile')->login($walker);

        // Opening the page is the whole trigger — settlement happens on mount,
        // whichever game the rail happens to land on.
        Volt::test('kid.arcade')->assertOk();

        $this->assertSame(ArcadeService::PRIZE_TICKETS, $walker->fresh()->bonus_tickets);
        $this->assertSame(ArcadeService::PRIZE_TICKETS, $climber->fresh()->bonus_tickets);

        // And it says so on the board, so a kid finds out where they played
        // rather than only in their ticket balance. Asked of the walk by name:
        // the page opens on whatever is newest, and the champion line belongs
        // to the game showing.
        Volt::test('arcade')
            ->call('switchTo', ArcadeGame::WindyWalkies->value)
            ->assertSee('Last champion')
            ->assertSee('33 lanes');
    }

    public function test_the_champion_line_belongs_to_the_game_on_screen(): void
    {
        $walker = $this->kid('Nova');
        $climber = $this->kid('Rook');

        $this->lastWeek($walker, 33, ArcadeGame::WindyWalkies);
        $this->lastWeek($climber, 21, ArcadeGame::StackTheMess);

        Auth::guard('profile')->login($walker);

        Volt::test('arcade')
            ->call('switchTo', ArcadeGame::WindyWalkies->value)
            ->assertSee('33 lanes')
            ->call('switchTo', ArcadeGame::StackTheMess->value)
            ->assertSee('21 floors')
            ->assertDontSee('33 lanes');
    }

    public function test_the_board_says_what_a_week_is_worth_and_that_each_game_has_one(): void
    {
        $kid = $this->kid();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.arcade')
            ->assertSee('3 bonus tickets')
            ->assertSee('one prize per')
            ->assertSee('Grown-ups can win the week, but not the tickets.');
    }
}
