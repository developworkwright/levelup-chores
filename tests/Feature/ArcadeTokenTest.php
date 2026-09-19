<?php

namespace Tests\Feature;

use App\Enums\ArcadeGame;
use App\Enums\CompletionStatus;
use App\Enums\TicketKind;
use App\Enums\TokenKind;
use App\Exceptions\InsufficientTokensException;
use App\Models\ArcadeScore;
use App\Models\BonusTicketEntry;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Household;
use App\Models\Profile;
use App\Models\TokenEntry;
use App\Services\ArcadeService;
use App\Services\ChoreService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Every game pays arcade tokens the moment a run ends, up to a daily cap that
 * each chore claimed raises — handoff/design_handoff_arcade_tokens.
 *
 * The cap is the thing that keeps the arcade pointing back at the chores, so
 * most of what is here is about it: what counts toward it, what raises it, what
 * a run past it is told, and what is never limited by it.
 */
class ArcadeTokenTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        // Mid-afternoon, well clear of the household day's 4am rollover.
        $this->travelTo(now()->setTime(15, 0));
        $this->household = Household::factory()->create();
    }

    private function tokens(): TokenService
    {
        return app(TokenService::class);
    }

    private function kid(string $name = 'Nova'): Profile
    {
        return Profile::factory()->for($this->household)->create(['name' => $name]);
    }

    /** Posts a run the way the page does, and pays it. */
    private function play(Profile $kid, int $score, ArcadeGame $game = ArcadeGame::StackTheMess): ?array
    {
        $before = $this->tokens()->bestToday($kid, $game);
        $run = app(ArcadeService::class)->post($kid, $game, $score);

        return $this->tokens()->payRun($kid, $game, $score, $before, $run);
    }

    private function claim(Profile $kid): ChoreCompletion
    {
        $chore = Chore::factory()->for($this->household)->create();

        return app(ChoreService::class)->claim($kid, $chore);
    }

    public function test_a_run_pays_one_for_having_a_go_and_one_per_rung_reached(): void
    {
        $kid = $this->kid();

        // 13 floors passes Sofa height, Light switch, Picture rail and Window
        // height. The ground floor is where every run starts, so it is "had a
        // go" rather than a rung of its own.
        $payout = $this->play($kid, 13);

        $this->assertSame(['Had a go', 'Sofa height', 'Light switch', 'Picture rail', 'Window height'], array_column($payout['lines'], 'name'));
        $this->assertSame(5, $payout['paid']);
        $this->assertSame(0, $payout['from']);
        $this->assertSame(5, $payout['to']);
        $this->assertSame('Window height', $payout['rung']);
        $this->assertSame(5, $kid->fresh()->arcade_tokens);
        $this->assertSame(TokenKind::Run, TokenEntry::sole()->kind);
    }

    public function test_a_rung_pays_once_a_day_on_each_game(): void
    {
        $kid = $this->kid();

        $this->play($kid, 13);

        // Lower again: only the go. Higher: only the rungs above today's best.
        $this->assertSame(1, $this->play($kid, 7)['paid']);
        $this->assertSame(['Had a go', 'Top shelf', 'Ceiling'], array_column($this->play($kid, 19)['lines'], 'name'));

        // Another game has its own ladder and its own day.
        $this->assertSame(3, $this->play($kid, 9, ArcadeGame::WindyWalkies)['paid']);
    }

    public function test_the_rungs_come_back_the_next_household_day(): void
    {
        $kid = $this->kid();

        $this->play($kid, 13);

        $this->travelTo(now()->addDay());

        $this->assertSame(5, $this->play($kid, 13)['paid']);
    }

    public function test_the_machine_pays_thirty_a_day_before_any_chores(): void
    {
        $kid = $this->kid();

        $this->assertSame(TokenService::BASE_CAP, $this->tokens()->capFor($kid));

        // Six runs to Window height is thirty tokens on the nose. Each run is
        // pushed back into yesterday afterwards, so the rungs are new again and
        // every one of the six pays in full.
        for ($run = 0; $run < 6; $run++) {
            $this->play($kid, 13);
            ArcadeScore::query()->update(['created_at' => now()->subDay()]);
        }

        $this->assertSame(30, $this->tokens()->earnedToday($kid));

        // ...and the next one pays nothing, and says what it could not pay.
        $payout = $this->play($kid, 13);

        $this->assertSame(0, $payout['paid']);
        $this->assertSame(5, $payout['lost']);
        $this->assertFalse($payout['lines'][0]['paid']);
        $this->assertSame(30, $kid->fresh()->arcade_tokens);
    }

    public function test_a_run_that_hits_the_cap_pays_the_lines_that_fit_and_greys_the_rest(): void
    {
        $kid = $this->kid();

        $this->tokens()->record($kid, TokenKind::Run, 28, 'Earlier today');

        $payout = $this->play($kid, 13);

        $this->assertSame(2, $payout['paid']);
        $this->assertSame(3, $payout['lost']);
        $this->assertSame([true, true, false, false, false], array_column($payout['lines'], 'paid'));
    }

    public function test_every_chore_claimed_today_adds_fifteen(): void
    {
        $kid = $this->kid();

        $this->claim($kid);
        $this->claim($kid);

        // Claimed, not approved: nobody has looked at either yet.
        $this->assertSame(TokenService::BASE_CAP + 2 * TokenService::CAP_PER_CHORE, $this->tokens()->capFor($kid));
    }

    public function test_a_claim_sent_back_stops_counting(): void
    {
        $kid = $this->kid();

        $this->claim($kid)->update(['status' => CompletionStatus::Rejected]);

        $this->assertSame(TokenService::BASE_CAP, $this->tokens()->capFor($kid));
    }

    public function test_yesterdays_chores_do_not_raise_todays_cap(): void
    {
        $kid = $this->kid();

        $this->claim($kid);
        $this->travelTo(now()->addDay());

        $this->assertSame(TokenService::BASE_CAP, $this->tokens()->capFor($kid));
    }

    public function test_the_weekly_prize_and_a_refund_are_not_the_machine_paying(): void
    {
        $kid = $this->kid();

        $this->tokens()->record($kid, TokenKind::WeeklyPrize, 30, 'Won the week');
        $this->tokens()->record($kid, TokenKind::Refund, 20, 'Sweets refunded');

        $this->assertSame(0, $this->tokens()->earnedToday($kid));
        $this->assertSame(50, $kid->fresh()->arcade_tokens);
    }

    public function test_grown_ups_are_paid_nothing_for_a_run(): void
    {
        $dad = Profile::factory()->for($this->household)->parent()->create();

        $this->assertNull($this->play($dad, 40));
        $this->assertSame(0, TokenEntry::count());

        // The run still reaches the board, which is the grown-ups' whole game.
        $this->assertSame(1, ArcadeScore::count());
    }

    public function test_the_toy_pays_two_the_first_time_it_is_opened_each_day(): void
    {
        $kid = $this->kid();

        $this->assertSame(TokenService::TOY_TOKENS, $this->tokens()->payToy($kid, ArcadeGame::SlimeTime));
        $this->assertNull($this->tokens()->payToy($kid, ArcadeGame::SlimeTime));

        $this->travelTo(now()->addDay());

        $this->assertSame(TokenService::TOY_TOKENS, $this->tokens()->payToy($kid, ArcadeGame::SlimeTime));
        $this->assertNull($this->tokens()->payToy($kid, ArcadeGame::StackTheMess));
    }

    public function test_ten_tokens_buy_a_ticket(): void
    {
        $kid = $this->kid();
        $this->tokens()->record($kid, TokenKind::Adjustment, 12, 'Seed');

        $this->tokens()->buyTicket($kid);

        $this->assertSame(2, $kid->fresh()->arcade_tokens);
        $this->assertSame(1, $kid->fresh()->bonus_tickets);
        $this->assertSame(TicketKind::Tokens, BonusTicketEntry::sole()->kind);

        $this->expectException(InsufficientTokensException::class);
        $this->tokens()->buyTicket($kid);
    }

    public function test_the_page_shows_the_payout_under_the_game(): void
    {
        $kid = $this->kid();
        Auth::guard('profile')->login($kid);

        Volt::test('arcade')
            ->call('switchTo', ArcadeGame::StackTheMess->value)
            ->call('post', 13)
            ->assertSee('+5 tokens')
            ->assertSee('Had a go')
            ->assertSee('Window height')
            ->assertSee('0 &rarr; 5', false);
    }

    public function test_the_win_pops_up_on_the_game_screen_itself(): void
    {
        $kid = $this->kid();
        Auth::guard('profile')->login($kid);

        $html = Volt::test('arcade')
            ->call('switchTo', ArcadeGame::StackTheMess->value)
            ->call('post', 13)
            ->html();

        // Inside the machine, where the game-over score is — not only on the
        // card under it, which a phone has scrolled off the bottom.
        $machine = substr($html, strpos($html, 'wire:key="machine-'));

        $this->assertStringContainsString('fq-win-pop', $machine);
        $this->assertStringContainsString('1 for playing &middot; 4 new rungs', $html);
    }

    public function test_a_capped_run_pops_up_saying_the_machine_is_empty(): void
    {
        $kid = $this->kid();
        $this->tokens()->record($kid, TokenKind::Run, TokenService::BASE_CAP, 'A big afternoon');
        Auth::guard('profile')->login($kid);

        Volt::test('arcade')
            ->call('switchTo', ArcadeGame::StackTheMess->value)
            ->call('post', 13)
            ->assertSee('No tokens')
            ->assertSee('Machine&rsquo;s empty &mdash; a chore refills it', false);
    }

    public function test_pets_can_neither_perch_on_nor_cover_the_machine(): void
    {
        $kid = $this->kid();
        Auth::guard('profile')->login($kid);

        // The pet layer is z-30 (pets.js); the machine sits above it, and is
        // marked so pets.js never counts it or anything in it as a perch.
        $this->assertMatchesRegularExpression(
            '/wire:key="machine-[^"]+"\s+data-fq-no-perch\s+class="[^"]*\bz-\[35\]/',
            Volt::test('arcade')->html(),
        );
    }

    public function test_a_lit_sign_on_the_machines_points_at_the_counter(): void
    {
        $kid = $this->kid();
        $this->tokens()->record($kid, TokenKind::Adjustment, 42, 'Seed');
        Auth::guard('profile')->login($kid);

        Volt::test('arcade')
            ->assertSee('42</strong> to spend', false)
            ->call('showTab', 'prizes')
            ->assertDontSee('to spend');
    }

    public function test_a_grown_up_sees_no_payout_meter_or_prizes_tab(): void
    {
        $dad = Profile::factory()->for($this->household)->parent()->create();
        Auth::guard('profile')->login($dad);

        Volt::test('arcade')
            ->call('switchTo', ArcadeGame::StackTheMess->value)
            ->call('post', 13)
            ->assertDontSee('Had a go')
            ->assertDontSee('TODAY')
            ->assertDontSee("showTab('prizes')", false)
            ->call('showTab', 'prizes')
            ->assertSet('tab', 'play');
    }

    public function test_opening_the_toy_on_the_page_pays_it(): void
    {
        $kid = $this->kid();
        Auth::guard('profile')->login($kid);

        Volt::test('arcade')
            ->call('switchTo', ArcadeGame::SlimeTime->value)
            ->assertSet('toyPaid', TokenService::TOY_TOKENS)
            ->assertSee('for opening it today');

        $this->assertSame(TokenService::TOY_TOKENS, $kid->fresh()->arcade_tokens);
    }

    public function test_the_empty_machine_offers_a_chore_that_refills_it_in_place(): void
    {
        $kid = $this->kid();
        $chore = Chore::factory()->for($this->household)->create(['name' => 'Put the dishwasher on', 'points' => 30]);
        $this->tokens()->record($kid, TokenKind::Run, TokenService::BASE_CAP, 'A big afternoon');

        Auth::guard('profile')->login($kid);

        $page = Volt::test('arcade')
            ->assertSee('The machine&rsquo;s empty!', false)
            ->assertSee('Put the dishwasher on')
            ->call('claimRefill', $chore->id);

        $this->assertSame(1, ChoreCompletion::where('profile_id', $kid->id)->count());
        $this->assertSame(TokenService::BASE_CAP + TokenService::CAP_PER_CHORE, $this->tokens()->capFor($kid));

        $page->assertDontSee('The machine&rsquo;s empty!', false);
    }

    public function test_a_refill_for_another_households_chore_is_refused(): void
    {
        $kid = $this->kid();
        $theirs = Chore::factory()->create();

        Auth::guard('profile')->login($kid);

        Volt::test('arcade')
            ->call('claimRefill', $theirs->id)
            ->assertSet('refillNote', 'That one just went — here is another.');

        $this->assertSame(0, ChoreCompletion::count());
    }

    public function test_the_empty_machine_with_nothing_left_to_claim_says_so_kindly(): void
    {
        $kid = $this->kid();
        $this->tokens()->record($kid, TokenKind::Run, TokenService::BASE_CAP, 'A big afternoon');

        Auth::guard('profile')->login($kid);

        Volt::test('arcade')
            ->assertSee('You beat the whole board')
            ->assertSee('the cap is on tokens, never on playing');
    }
}
