<?php

namespace Tests\Feature;

use App\Enums\ChoreCadence;
use App\Enums\TicketKind;
use App\Models\BonusTicketEntry;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Notifications\HelpWantedPosted;
use App\Services\ChoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * A parent can flag the one job that actually needs doing. It goes to the top
 * of every kid's board wearing its own colour, and whoever finishes it earns a
 * bonus ticket — a currency the household mints for free, unlike points, which
 * are backed by real money.
 *
 * The flag lifts on its own overnight, the same way a deadline does, so the
 * board can't silently fill up with permanent flags.
 */
class HelpWantedChoreTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): ChoreService
    {
        return app(ChoreService::class);
    }

    /**
     * Boards exclude whichever chores make up today's quest hand, so fixtures
     * need a quest-eligible chore to absorb the deal.
     */
    private function household(): Household
    {
        $household = Household::factory()->create();

        Chore::factory()->for($household)->create([
            'name' => 'The quest',
            'quest_eligible' => true,
        ]);

        return $household;
    }

    /** @param  array<string, mixed>  $attributes */
    private function chore(Household $household, string $name = 'Take the bins out', array $attributes = []): Chore
    {
        return Chore::factory()->for($household)->create([
            'name' => $name,
            'quest_eligible' => false,
            ...$attributes,
        ]);
    }

    /**
     * Help-wanted tickets only.
     *
     * Never the profile's bonus_tickets total: an approval also pays XP, XP
     * crosses levels, and levels mint tickets of their own — so the balance
     * answers "did anything pay?" rather than "did the flag pay?".
     */
    private function helpWantedTickets(Profile $kid): int
    {
        return (int) BonusTicketEntry::where('profile_id', $kid->id)
            ->where('kind', TicketKind::HelpWanted)
            ->sum('amount');
    }

    /** @return array<string, mixed> */
    private function entryFor(Profile $kid, Chore $chore): array
    {
        return $this->service()->boardFor($kid->fresh())
            ->first(fn (array $entry) => $entry['chore']->id === $chore->id);
    }

    public function test_a_flagged_chore_reads_as_help_wanted_on_the_board(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $chore = $this->chore($household);

        $this->assertFalse($this->entryFor($kid, $chore)['helpWanted']);

        $this->service()->flagHelpWanted($chore);

        $this->assertTrue($this->entryFor($kid, $chore)['helpWanted']);
    }

    public function test_it_sorts_above_everything_else_that_wont_wait(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();

        // Both of the tiers it has to beat, and each paying more than the
        // flagged chore does — payout is the tiebreak *within* a tier, never
        // across them.
        $this->chore($household, 'One-time', ['cadence' => ChoreCadence::Once, 'points' => 400]);
        $this->chore($household, 'On a clock', ['expires_at' => now()->addHour(), 'points' => 400]);
        $asked = $this->chore($household, 'Take the bins out', ['points' => 25]);

        $this->service()->flagHelpWanted($asked);

        // A flag is the only urgency somebody actually aimed: a one-time chore
        // is urgent to whoever wants the points and a deadline is urgent to the
        // clock, but this row is urgent because someone in the house said so.
        $this->assertSame('Take the bins out', $this->service()->boardFor($kid->fresh())->first()['chore']->name);
    }

    public function test_the_flag_lifts_the_next_household_day(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $chore = $this->chore($household);

        $this->service()->flagHelpWanted($chore);

        Carbon::setTestNow(now()->addDay());

        // Nobody has to go and tidy it up, which is what stops a board where
        // half the rows shout — a parent re-asserts what is urgent each day.
        $this->assertFalse($this->entryFor($kid, $chore->refresh())['helpWanted']);
    }

    public function test_finishing_a_flagged_chore_earns_a_bonus_ticket(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = $this->chore($household);

        $this->service()->flagHelpWanted($chore);
        $completion = $this->service()->claim($kid, $chore);

        // Not at claim: the work has to be signed off by somebody who saw it,
        // exactly as the mystery bonus is.
        $this->assertSame(0, $this->helpWantedTickets($kid));

        $this->service()->approve($completion, $parent);

        $this->assertSame(ChoreService::HELP_WANTED_TICKETS, $this->helpWantedTickets($kid));
        $this->assertDatabaseHas('bonus_ticket_entries', [
            'profile_id' => $kid->id,
            'kind' => TicketKind::HelpWanted->value,
            'amount' => ChoreService::HELP_WANTED_TICKETS,
        ]);
    }

    public function test_an_ordinary_chore_earns_no_ticket(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = $this->chore($household);

        $this->service()->approve($this->service()->claim($kid, $chore), $parent);

        $this->assertSame(0, $this->helpWantedTickets($kid));
    }

    public function test_clearing_the_flag_after_the_claim_still_pays(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = $this->chore($household);

        $this->service()->flagHelpWanted($chore);
        $completion = $this->service()->claim($kid, $chore);

        // The flag is why they may have picked this chore over another. A
        // parent taking it back down before they get round to approving must
        // not reach back and cancel a reward the board already promised.
        $this->service()->clearHelpWanted($chore);
        $this->service()->approve($completion, $parent);

        $this->assertSame(ChoreService::HELP_WANTED_TICKETS, $this->helpWantedTickets($kid));
    }

    public function test_flagging_after_the_claim_pays_nothing(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = $this->chore($household);

        $completion = $this->service()->claim($kid, $chore);
        $this->service()->flagHelpWanted($chore);
        $this->service()->approve($completion, $parent);

        // The other half of the same rule. A flag is an ask, and work already
        // handed in was not done in answer to it.
        $this->assertSame(0, $this->helpWantedTickets($kid));
    }

    public function test_the_ticket_is_paid_once_however_many_times_the_chore_is_done(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $parent = Profile::factory()->parent()->for($household)->create();

        // Unlimited is the cadence with no cooldown at all, so it is the one a
        // kid could otherwise submit over and over as a ticket printer. The
        // flag asks for one job to get done, and it is answered once.
        $chore = $this->chore($household, 'Fold laundry', ['cadence' => ChoreCadence::Unlimited]);

        $this->service()->flagHelpWanted($chore);

        $this->service()->approve($this->service()->claim($kid, $chore), $parent);
        $this->service()->approve($this->service()->claim($kid, $chore), $parent);

        $this->assertSame(ChoreService::HELP_WANTED_TICKETS, $this->helpWantedTickets($kid));
        $this->assertSame(1, BonusTicketEntry::where('kind', TicketKind::HelpWanted)->count());
    }

    public function test_a_sibling_who_got_there_second_earns_nothing(): void
    {
        $household = $this->household();
        $first = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $second = Profile::factory()->for($household)->create(['name' => 'Westin']);
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = $this->chore($household, 'Fold laundry', ['cadence' => ChoreCadence::Unlimited]);

        $this->service()->flagHelpWanted($chore);

        $this->service()->approve($this->service()->claim($first, $chore), $parent);
        $this->service()->approve($this->service()->claim($second, $chore), $parent);

        $this->assertSame(ChoreService::HELP_WANTED_TICKETS, $this->helpWantedTickets($first));
        $this->assertSame(0, $this->helpWantedTickets($second));
    }

    public function test_a_new_day_makes_the_ticket_winnable_again(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = $this->chore($household);

        $this->service()->flagHelpWanted($chore);
        $this->service()->approve($this->service()->claim($kid, $chore), $parent);

        Carbon::setTestNow(now()->addDay());

        // Once per chore per household day, not once ever — the bins need
        // taking out again tomorrow, and asking again has to be worth the same.
        $this->service()->flagHelpWanted($chore->fresh());
        $this->service()->approve($this->service()->claim($kid->fresh(), $chore->fresh()), $parent);

        $this->assertSame(ChoreService::HELP_WANTED_TICKETS * 2, $this->helpWantedTickets($kid));
    }

    public function test_sent_back_work_earns_nothing(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = $this->chore($household);

        $this->service()->flagHelpWanted($chore);
        $this->service()->sendBack($this->service()->claim($kid, $chore), $parent);

        $this->assertSame(0, $this->helpWantedTickets($kid));
    }

    public function test_flagging_tells_the_kids(): void
    {
        Notification::fake();

        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        Profile::factory()->parent()->for($household)->create();
        $chore = $this->chore($household);

        $this->service()->flagHelpWanted($chore);

        // A flag nobody hears about until they next happen to open the board is
        // just a differently-coloured row. Kids only — the parent is the one
        // doing the asking.
        Notification::assertSentTo($kid, HelpWantedPosted::class);
        Notification::assertSentTimes(HelpWantedPosted::class, 1);
    }

    public function test_the_kid_board_wears_the_flag_and_names_the_prize(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $chore = $this->chore($household);

        $this->service()->flagHelpWanted($chore);

        Auth::guard('profile')->login($kid);

        // The badge is the whole mechanic on the kid's side: a row that merely
        // sorted to the top would read as arbitrary, and one that didn't name
        // the ticket would be asking for a favour rather than offering a deal.
        Volt::test('kid.quests')
            ->assertSee('Help wanted')
            ->assertSee('+'.ChoreService::HELP_WANTED_TICKETS.' ticket');
    }

    public function test_the_board_drops_the_prize_once_someone_else_has_it(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $chore = $this->chore($household);

        $this->service()->flagHelpWanted($chore);
        $this->service()->claim($sibling, $chore);

        Auth::guard('profile')->login($kid);

        // Cooldowns are household-wide, so the ticket is genuinely gone. The
        // ask still shows — it was still made — but advertising a prize on a
        // struck-through row would be promising something that isn't there.
        Volt::test('kid.quests')
            ->assertSee('Help wanted')
            ->assertDontSee('+'.ChoreService::HELP_WANTED_TICKETS.' ticket');
    }

    public function test_a_parent_toggles_the_flag_from_the_chores_page(): void
    {
        Notification::fake();

        $household = $this->household();
        Profile::factory()->for($household)->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = $this->chore($household);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')->call('toggleHelpWanted', $chore->id);

        $this->assertNotNull($chore->fresh()->help_wanted_at);

        // Same button both ways: on, then back off for "somebody did it another
        // way". The stamp is cleared outright rather than left to lapse.
        Volt::test('parent.chores')->call('toggleHelpWanted', $chore->id);

        $this->assertNull($chore->fresh()->help_wanted_at);
    }

    public function test_a_parent_cannot_flag_another_households_chore(): void
    {
        $household = $this->household();
        Profile::factory()->for($household)->create();
        $parent = Profile::factory()->parent()->for($household)->create();

        $theirs = $this->chore($this->household(), 'Their bins');

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')->call('toggleHelpWanted', $theirs->id);

        $this->assertNull($theirs->fresh()->help_wanted_at);
    }
}
