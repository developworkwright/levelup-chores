<?php

namespace Tests\Feature;

use App\Enums\LedgerKind;
use App\Enums\TicketKind;
use App\Models\Household;
use App\Models\Profile;
use App\Services\LedgerService;
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

    public function test_a_kid_cannot_open_the_activity_page(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        Volt::test('parent.activity')->assertForbidden();
    }
}
