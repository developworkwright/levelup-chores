<?php

namespace Tests\Feature;

use App\Enums\ArcadeGame;
use App\Enums\TokenKind;
use App\Models\ArcadeScore;
use App\Models\ArcadeWeekPrize;
use App\Models\Household;
use App\Models\Profile;
use App\Models\TokenEntry;
use App\Services\ArcadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Thirty arcade tokens to the top of a finished week — on each game. (It was
 * three bonus tickets until the prize counter arrived.)
 *
 * There is no scheduler: the week is settled by whoever opens the arcade next.
 * So the two things worth testing hardest are that it pays exactly once however
 * many times that happens, and that a grown-up topping a board closes that week
 * without collecting anything — the tokens pass down to the best kid below.
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

    public function test_the_top_of_a_finished_week_wins_thirty_tokens(): void
    {
        $winner = $this->kid('Nova');
        $other = $this->kid('Rook');

        $this->lastWeek($other, 12);
        $this->lastWeek($winner, 31);

        $this->arcade()->settle($this->household);

        $this->assertSame(ArcadeService::PRIZE_TOKENS, $winner->fresh()->arcade_tokens);
        $this->assertSame(0, $other->fresh()->arcade_tokens);

        // Through TokenService, so the entries and the cached balance are
        // written in one transaction and cannot drift.
        $entry = TokenEntry::sole();

        $this->assertSame(TokenKind::WeeklyPrize, $entry->kind);
        $this->assertSame(ArcadeService::PRIZE_TOKENS, $entry->amount);
        $this->assertSame($winner->id, $entry->profile_id);

        // Nothing in tickets any more.
        $this->assertSame(0, $winner->fresh()->bonus_tickets);
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

        $this->assertSame(ArcadeService::PRIZE_TOKENS, $climber->fresh()->arcade_tokens);
        $this->assertSame(ArcadeService::PRIZE_TOKENS, $walker->fresh()->arcade_tokens);
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

        $this->assertSame(ArcadeService::PRIZE_TOKENS, $climber->fresh()->arcade_tokens);
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

        $this->assertSame(ArcadeService::PRIZE_TOKENS, $winner->fresh()->arcade_tokens);
        $this->assertSame(1, TokenEntry::count());
        $this->assertSame(1, ArcadeWeekPrize::count());
    }

    public function test_a_grown_up_can_win_the_week_and_the_tokens_go_to_the_best_kid_below(): void
    {
        $parent = $this->parent('Dad');
        $kid = $this->kid('Nova');
        $slower = $this->kid('Rook');

        $this->lastWeek($slower, 9);
        $this->lastWeek($kid, 18);
        $this->lastWeek($parent, 44);

        $this->arcade()->settle($this->household);

        // The user's call when the prize moved to tokens: a grown-up on top
        // still takes the week, and the prize passes down to the best kid on
        // the board rather than going to nobody.
        $this->assertSame(0, $parent->fresh()->arcade_tokens);
        $this->assertSame(ArcadeService::PRIZE_TOKENS, $kid->fresh()->arcade_tokens);
        $this->assertSame(0, $slower->fresh()->arcade_tokens);

        // The week still records who took it, which is what the board's
        // "last champion" line reads.
        $prize = ArcadeWeekPrize::sole();

        $this->assertSame($parent->id, $prize->profile_id);
        $this->assertSame($kid->id, $prize->paid_profile_id);
        $this->assertSame(ArcadeService::PRIZE_TOKENS, $prize->tokens);
        $this->assertSame(0, $prize->tickets);
        $this->assertSame(44, $prize->score);
        $this->assertSame(ArcadeGame::default(), $prize->game);
    }

    public function test_a_week_only_grown_ups_played_pays_nobody(): void
    {
        $parent = $this->parent('Dad');
        $this->kid('Nova');

        $this->lastWeek($parent, 44);

        $this->arcade()->settle($this->household);

        $prize = ArcadeWeekPrize::sole();

        $this->assertNull($prize->paid_profile_id);
        $this->assertSame(0, $prize->tokens);
        $this->assertSame(0, TokenEntry::count());
    }

    public function test_the_winner_is_told_once_on_the_arcade_page(): void
    {
        $kid = $this->kid('Nova');
        $this->lastWeek($kid, 31);

        Auth::guard('profile')->login($kid);

        Volt::test('arcade')
            ->assertSee('You took the board')
            ->assertSee('+30 tokens')
            ->call('dismissWin')
            ->assertDontSee('You took the board');

        $this->assertNotNull(ArcadeWeekPrize::sole()->seen_at);

        Volt::test('arcade')->assertDontSee('You took the board');
    }

    public function test_a_handed_down_win_says_who_topped_it(): void
    {
        $parent = $this->parent('Dad');
        $kid = $this->kid('Nova');

        $this->lastWeek($kid, 18);
        $this->lastWeek($parent, 44);

        Auth::guard('profile')->login($kid);

        Volt::test('arcade')->assertSee('Dad topped it');
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
        $this->assertSame(0, $kid->fresh()->arcade_tokens);
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
        $this->assertSame(ArcadeService::PRIZE_TOKENS, $mine->fresh()->arcade_tokens);
        $this->assertSame(0, $theirs->fresh()->arcade_tokens);
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

        $this->assertSame(ArcadeService::PRIZE_TOKENS, $walker->fresh()->arcade_tokens);
        $this->assertSame(ArcadeService::PRIZE_TOKENS, $climber->fresh()->arcade_tokens);

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

        // The walker's own win card names the walk too, whichever game is
        // showing; it is read and put away first so only the board is asked.
        Volt::test('arcade')
            ->call('dismissWin')
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
            ->assertSee('30 tokens every Sunday')
            ->assertSee('one prize per')
            ->assertSee('the tokens go to the best kid below them');
    }
}
