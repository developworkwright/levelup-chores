<?php

namespace Tests\Feature;

use App\Enums\LedgerKind;
use App\Enums\SiblingOfferKind;
use App\Enums\TicketKind;
use App\Models\Household;
use App\Models\Profile;
use App\Models\SiblingOffer;
use App\Services\LedgerService;
use App\Services\SiblingOfferService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ParentActivityPageTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsParent(Household $household): Profile
    {
        $parent = Profile::factory()->parent()->for($household)->create();
        Auth::guard('profile')->login($parent);

        return $parent;
    }

    public function test_it_shows_the_points_ledger(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $this->actingAsParent($household);

        app(LedgerService::class)->record($household, $kid, LedgerKind::Earn, 100, 'Nova — Feed animals');

        Volt::test('parent.activity')
            ->assertSee('Activity Log')
            ->assertSee('Nova — Feed animals', false);
    }

    public function test_it_shows_both_legs_of_a_sibling_trade(): void
    {
        $household = Household::factory()->create();
        // Alex does the dishes, Sam pays for it — so Sam is the one who needs a balance.
        $alex = Profile::factory()->for($household)->create(['name' => 'Alex']);
        $sam = Profile::factory()->for($household)->create(['name' => 'Sam', 'points' => 400]);
        $this->actingAsParent($household);

        $offer = SiblingOffer::factory()->create([
            'household_id' => $household->id,
            'from_profile_id' => $alex->id,
            'to_profile_id' => $sam->id,
            'kind' => SiblingOfferKind::Earning,
            'description' => 'do your dishes',
            'points' => 100,
        ]);

        app(SiblingOfferService::class)->accept($offer, $sam);

        // Transfer is a newer LedgerKind than the feed's colour map — an
        // unhandled case would blow the whole page up rather than one row.
        Volt::test('parent.activity')
            ->assertOk()
            ->assertSee('Sam → Alex: do your dishes', false)
            ->assertSee('-100')
            ->assertSee('+100');
    }

    public function test_it_shows_ticket_activity_in_its_own_card(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $this->actingAsParent($household);

        app(TicketService::class)->record($kid, TicketKind::LevelUp, 1, 'Reached level 4');

        Volt::test('parent.activity')
            ->assertSee('Ticket Activity')
            ->assertSee('Reached level 4')
            ->assertSee('Level up');
    }

    public function test_the_two_currencies_stay_in_separate_cards(): void
    {
        // Tickets and points are different currencies — summing one list of
        // amounts across both would be meaningless, so they never merge.
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $this->actingAsParent($household);

        app(LedgerService::class)->record($household, $kid, LedgerKind::Earn, 100, 'Points entry');
        app(TicketService::class)->record($kid, TicketKind::Badge, 1, 'Ticket entry');

        Volt::test('parent.activity')
            ->assertSeeInOrder(['Activity Log', 'Points entry', 'Ticket Activity', 'Ticket entry']);
    }

    public function test_it_copes_with_nothing_logged(): void
    {
        $household = Household::factory()->create();
        $this->actingAsParent($household);

        Volt::test('parent.activity')
            ->assertSee('Nothing logged yet.')
            ->assertSee('No tickets yet.')
            ->assertSuccessful();
    }

    public function test_it_only_shows_this_households_tickets(): void
    {
        $household = Household::factory()->create();
        $this->actingAsParent($household);

        $outsider = Profile::factory()->for(Household::factory())->create();
        app(TicketService::class)->record($outsider, TicketKind::LevelUp, 1, 'Someone else entirely');

        Volt::test('parent.activity')->assertDontSee('Someone else entirely');
    }

    public function test_the_ledger_pages_back_through_everything_ever_recorded(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $this->actingAsParent($household);

        // Three pages' worth, oldest first, so the newest sits on page one.
        foreach (range(1, 50) as $index) {
            app(LedgerService::class)->record($household, $kid, LedgerKind::Earn, 10, "Entry number {$index}");
        }

        $page = Volt::test('parent.activity')->assertOk();

        // Matched with the closing tag so "Entry number 1" can't be satisfied
        // by "Entry number 19", and unescaped so the `<` survives the compare.
        $page->assertSee('Entry number 50')
            ->assertDontSee('Entry number 1<', false)
            ->assertSee('Page 1 of 3')
            ->assertSee('50 total');

        $page->call('nextPage', 'ledger')
            ->assertSee('Page 2 of 3')
            ->assertSee('Entry number 26')
            ->assertDontSee('Entry number 50');

        $page->call('nextPage', 'ledger')
            ->assertSee('Page 3 of 3')
            ->assertSee('Entry number 1<', false);

        $page->call('previousPage', 'ledger')
            ->assertSee('Page 2 of 3');
    }

    public function test_each_feed_pages_on_its_own_cursor(): void
    {
        // Two paginators on one screen: walking the ledger back must not drag
        // the ticket feed along with it.
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $this->actingAsParent($household);

        foreach (range(1, 30) as $index) {
            app(LedgerService::class)->record($household, $kid, LedgerKind::Earn, 10, "Ledger line {$index}");
            app(TicketService::class)->record($kid, TicketKind::Badge, 1, "Ticket line {$index}");
        }

        Volt::test('parent.activity')
            ->assertOk()
            ->call('nextPage', 'ledger')
            // Ledger moved to its older page; tickets stayed on their newest.
            ->assertSee('Ledger line 6')
            ->assertDontSee('Ledger line 30')
            ->assertSee('Ticket line 30');
    }

    public function test_a_single_page_of_activity_shows_no_pager(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $this->actingAsParent($household);

        app(LedgerService::class)->record($household, $kid, LedgerKind::Earn, 100, 'The only entry');

        Volt::test('parent.activity')
            ->assertOk()
            ->assertSee('The only entry')
            ->assertDontSee('Page 1 of');
    }

    public function test_a_kid_cannot_open_the_activity_page(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        Volt::test('parent.activity')->assertForbidden();
    }
}
