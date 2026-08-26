<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Kids leave the quests page open for hours. The cost of a stale board isn't
 * a bad database row — the claim is already rejected server-side — it's a kid
 * scrubbing a bathtub their sibling claimed twenty minutes ago. So the board
 * has to say who took what, and a late tap has to explain itself.
 */
class BoardStalenessTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ChoreService
    {
        return app(ChoreService::class);
    }

    /**
     * One quest-eligible chore absorbs the daily quest assignment so the
     * chores under test are guaranteed to stay on the board.
     */
    private function household(): Household
    {
        $household = Household::factory()->create(['require_quest_first' => false]);
        Chore::factory()->for($household)->create(['name' => 'The quest', 'quest_eligible' => true]);

        return $household;
    }

    public function test_the_board_names_the_sibling_who_took_a_chore(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $chore = Chore::factory()->for($household)->create([
            'name' => 'Feed animals',
            'quest_eligible' => false,
            'cadence' => 'daily',
        ]);

        $this->service()->claim($sibling, $chore);

        $entry = $this->service()->boardFor($kid)->firstWhere('chore.id', $chore->id);

        $this->assertSame('done', $entry['state']);
        $this->assertSame('Nova', $entry['takenBy']->name);
    }

    public function test_a_kids_own_completion_is_not_attributed_to_anyone(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['quest_eligible' => false, 'cadence' => 'daily']);

        $this->service()->claim($kid, $chore);

        $entry = $this->service()->boardFor($kid)->firstWhere('chore.id', $chore->id);

        // Their own claim reads as 'pending', and naming them on their own
        // card would be nonsense.
        $this->assertSame('pending', $entry['state']);
        $this->assertNull($entry['takenBy']);
    }

    public function test_an_unclaimed_chore_has_nobody_on_it(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['quest_eligible' => false, 'cadence' => 'daily']);

        $entry = $this->service()->boardFor($kid)->firstWhere('chore.id', $chore->id);

        $this->assertSame('ready', $entry['state']);
        $this->assertNull($entry['takenBy']);
    }

    public function test_an_unlimited_chore_is_never_marked_taken(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $chore = Chore::factory()->for($household)->create(['quest_eligible' => false, 'cadence' => 'unlimited']);

        $this->service()->claim($sibling, $chore);

        $entry = $this->service()->boardFor($kid)->firstWhere('chore.id', $chore->id);

        // Everyone can do it, so a sibling's claim is not a claim on it.
        $this->assertSame('ready', $entry['state']);
        $this->assertNull($entry['takenBy']);
    }

    public function test_a_stale_tap_says_who_got_there_first(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $chore = Chore::factory()->for($household)->create([
            'name' => 'Feed animals',
            'quest_eligible' => false,
            'cadence' => 'daily',
        ]);

        Auth::guard('profile')->login($kid);

        // The sibling claims it after this kid's page was rendered.
        $this->service()->claim($sibling, $chore);

        Volt::test('kid.quests')
            ->call('claimChore', $chore->id)
            ->assertSet('boardMessage', 'Nova got to Feed animals first!');

        // And crucially, no second claim was recorded.
        $this->assertSame(1, $chore->completions()->count());
    }

    public function test_a_successful_claim_clears_a_previous_message(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $taken = Chore::factory()->for($household)->create(['quest_eligible' => false, 'cadence' => 'daily']);
        $free = Chore::factory()->for($household)->create(['quest_eligible' => false, 'cadence' => 'daily']);

        Auth::guard('profile')->login($kid);
        $this->service()->claim($sibling, $taken);

        Volt::test('kid.quests')
            ->call('claimChore', $taken->id)
            ->assertNotSet('boardMessage', null)
            ->call('claimChore', $free->id)
            ->assertSet('boardMessage', null);
    }

    public function test_a_gated_board_does_not_blame_a_sibling(): void
    {
        $household = Household::factory()->create(['require_quest_first' => true]);
        Chore::factory()->for($household)->create(['name' => 'The quest', 'quest_eligible' => true]);
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['quest_eligible' => false, 'cadence' => 'daily']);

        Auth::guard('profile')->login($kid);

        // Locked behind their own unfinished quest — nobody took anything.
        Volt::test('kid.quests')
            ->call('claimChore', $chore->id)
            ->assertSet('boardMessage', null);
    }

    public function test_the_refresh_button_picks_up_a_siblings_claim(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $chore = Chore::factory()->for($household)->create([
            'name' => 'Feed animals',
            'quest_eligible' => false,
            'cadence' => 'daily',
        ]);

        Auth::guard('profile')->login($kid);

        $page = Volt::test('kid.quests')->assertSee('Mark it done');

        $this->service()->claim($sibling, $chore);

        $page->call('refreshBoard')->assertSee('Taken by Nova');
    }

    public function test_refreshing_clears_a_stale_message(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $chore = Chore::factory()->for($household)->create(['quest_eligible' => false, 'cadence' => 'daily']);

        Auth::guard('profile')->login($kid);
        $this->service()->claim($sibling, $chore);

        // The refreshed board names Nova on the card itself, so keeping the
        // message next to it just says the same thing twice.
        Volt::test('kid.quests')
            ->call('claimChore', $chore->id)
            ->assertNotSet('boardMessage', null)
            ->call('refreshBoard')
            ->assertSet('boardMessage', null);
    }

    public function test_every_kid_tab_offers_a_refresh(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        // The button lives in the shared shell, so a tab that doesn't pass
        // refresh-action falls back to Livewire's generic $refresh. If that
        // wiring breaks, it breaks on four pages at once.
        foreach (['home', 'quests', 'loot', 'bonus', 'badges'] as $tab) {
            Volt::test("kid.{$tab}")->assertOk()->assertSee('Refresh');
        }
    }

    public function test_the_board_refreshes_without_a_page_load(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $chore = Chore::factory()->for($household)->create([
            'name' => 'Feed animals',
            'quest_eligible' => false,
            'cadence' => 'daily',
        ]);

        Auth::guard('profile')->login($kid);

        $page = Volt::test('kid.quests')->assertSee('Mark it done');

        $this->service()->claim($sibling, $chore);

        // What wire:poll triggers: a re-render with no remount.
        $page->call('$refresh')
            ->assertSee('Taken by Nova')
            ->assertSee('Nova got this one');
    }
}
