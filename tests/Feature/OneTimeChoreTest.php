<?php

namespace Tests\Feature;

use App\Enums\ChoreCadence;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * One-time chores are up for grabs exactly once. Unlike every other cadence
 * they have no clock: claiming one takes it off the whole household's board
 * until a parent puts it back.
 */
class OneTimeChoreTest extends TestCase
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
     * Boards exclude whichever chore became today's quest, so fixtures need one
     * quest-eligible chore to absorb the assignment.
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

    private function oneTimeChore(Household $household, string $name = 'Rake the leaves'): Chore
    {
        return Chore::factory()->for($household)->create([
            'name' => $name,
            'cadence' => ChoreCadence::Once,
            'quest_eligible' => false,
        ]);
    }

    public function test_claiming_takes_it_off_a_siblings_board_entirely(): void
    {
        $household = $this->household();
        $doer = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();
        $chore = $this->oneTimeChore($household);

        $this->assertNotNull($this->service()->boardFor($sibling)->firstWhere('chore.id', $chore->id));

        $this->service()->claim($doer, $chore);

        // Each kid gets their own request in real use, so read the sibling's
        // board the way their next page load would — from a fresh profile.
        $sibling = $sibling->fresh();

        // Not merely 'done' like a daily chore on cooldown — gone.
        $this->assertNull($this->service()->boardFor($sibling)->firstWhere('chore.id', $chore->id));
        $this->assertSame('done', $this->service()->stateFor($sibling, $chore->refresh()));
    }

    public function test_the_claimant_keeps_seeing_it_while_approval_is_pending(): void
    {
        $household = $this->household();
        $doer = Profile::factory()->for($household)->create();
        $chore = $this->oneTimeChore($household);

        $this->service()->claim($doer, $chore);

        // Vanishing the instant they tapped it would read as the claim failing.
        $entry = $this->service()->boardFor($doer)->firstWhere('chore.id', $chore->id);

        $this->assertNotNull($entry);
        $this->assertSame('pending', $entry['state']);
    }

    public function test_it_disappears_for_the_claimant_once_approved(): void
    {
        $household = $this->household();
        $parent = Profile::factory()->parent()->for($household)->create();
        $doer = Profile::factory()->for($household)->create();
        $chore = $this->oneTimeChore($household);

        $this->service()->approve($this->service()->claim($doer, $chore), $parent);

        $this->assertNull($this->service()->boardFor($doer)->firstWhere('chore.id', $chore->id));
    }

    public function test_it_does_not_come_back_the_next_day(): void
    {
        $household = $this->household();
        $parent = Profile::factory()->parent()->for($household)->create();
        $doer = Profile::factory()->for($household)->create();
        $chore = $this->oneTimeChore($household);

        $this->service()->approve($this->service()->claim($doer, $chore), $parent);

        Carbon::setTestNow(now()->addDays(30));

        // The whole point of the cadence: no clock puts it back, only a parent.
        $this->assertSame('done', $this->service()->stateFor($doer->refresh(), $chore->refresh()));
        $this->assertNull($this->service()->boardFor($doer->refresh())->firstWhere('chore.id', $chore->id));
    }

    public function test_a_rejected_claim_puts_it_back_up_for_grabs(): void
    {
        $household = $this->household();
        $parent = Profile::factory()->parent()->for($household)->create();
        $doer = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();
        $chore = $this->oneTimeChore($household);

        $completion = $this->service()->claim($doer, $chore);
        $this->service()->sendBack($completion, $parent);

        $this->assertNull($chore->refresh()->used_at);
        $this->assertSame('ready', $this->service()->stateFor($sibling, $chore));
        $this->assertNotNull($this->service()->boardFor($sibling)->firstWhere('chore.id', $chore->id));
    }

    public function test_sending_back_a_one_time_quest_lets_the_kid_redo_it(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();

        $chore = Chore::factory()->for($household)->create([
            'name' => 'Rake the leaves',
            'cadence' => ChoreCadence::Once,
            'quest_eligible' => true,
        ]);

        $this->service()->revealQuest($kid);
        $this->service()->claimQuest($kid);

        $completion = $chore->completions()->firstOrFail();
        $this->service()->sendBack($completion, $parent);

        // Both stamps have to lift together: the chore is spent *and* the quest
        // reads as done, so clearing either one alone still dead-ends the kid.
        $this->assertNull($chore->refresh()->used_at);
        $this->assertNull($this->service()->questFor($kid->fresh())->completed_at);

        $this->service()->claimQuest($kid->fresh());

        $this->assertSame(2, $chore->completions()->count());
    }

    public function test_reactivating_puts_it_back_the_same_day_it_was_approved(): void
    {
        $household = $this->household();
        $parent = Profile::factory()->parent()->for($household)->create();
        $doer = Profile::factory()->for($household)->create();
        $chore = $this->oneTimeChore($household);

        $this->service()->approve($this->service()->claim($doer, $chore), $parent);
        $this->service()->reopen($chore->refresh());

        // An approved-today completion still exists — reactivation has to clear
        // the chore outright, not leave it on a phantom daily cooldown.
        $this->assertSame('ready', $this->service()->stateFor($doer, $chore->refresh()));
    }

    public function test_a_second_kid_cannot_claim_one_someone_already_took(): void
    {
        $household = $this->household();
        $doer = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();
        $chore = $this->oneTimeChore($household);

        $this->service()->claim($doer, $chore);

        $this->assertNotSame('ready', $this->service()->stateFor($sibling, $chore->refresh()));
    }

    public function test_one_time_chores_sort_above_the_rest_of_the_board(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();

        Chore::factory()->for($household)->create(['name' => 'Daily one', 'quest_eligible' => false]);
        $once = $this->oneTimeChore($household);
        Chore::factory()->for($household)->create(['name' => 'Daily two', 'quest_eligible' => false]);

        $board = $this->service()->boardFor($kid);

        $this->assertSame($once->id, $board->first()['chore']->id);
    }

    public function test_a_spent_one_time_chore_is_never_handed_out_as_a_quest(): void
    {
        $household = Household::factory()->create();
        $doer = Profile::factory()->for($household)->create();
        $seeker = Profile::factory()->for($household)->create();

        $safe = Chore::factory()->for($household)->create(['name' => 'Safe quest', 'quest_eligible' => true]);
        $once = Chore::factory()->for($household)->create([
            'name' => 'Rake the leaves',
            'cadence' => ChoreCadence::Once,
            'quest_eligible' => true,
        ]);

        $this->service()->claim($doer, $once);

        // A blocked daily quest unblocks tomorrow; a spent one-time quest never
        // would, so it must not be assignable even as the fallback pick.
        $this->assertSame($safe->id, $this->service()->questFor($seeker)->chore_id);
    }

    public function test_a_spent_one_time_chore_is_never_the_mystery_chore(): void
    {
        $household = Household::factory()->create();
        $doer = Profile::factory()->for($household)->create();

        $safe = Chore::factory()->for($household)->create(['name' => 'Safe mystery']);
        $once = Chore::factory()->for($household)->create([
            'name' => 'Rake the leaves',
            'cadence' => ChoreCadence::Once,
        ]);

        $this->service()->claim($doer, $once);

        Carbon::setTestNow(now()->addDays(30));

        $this->assertSame($safe->id, $this->service()->mysteryChoreFor($household->refresh())?->id);
    }

    public function test_the_kid_board_flags_a_one_time_chore_and_drops_it_once_taken(): void
    {
        $household = $this->household();
        $doer = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $chore = $this->oneTimeChore($household);

        // A one-time chore is a fair mystery pick, and the mystery panel names
        // the chore once it's found — which would put "Rake the leaves" back on
        // the page for reasons that have nothing to do with the board. A hinted
        // chore wins the draw outright, so this pins it somewhere harmless.
        Chore::factory()->for($household)->create([
            'name' => 'Mystery decoy',
            'quest_eligible' => false,
            'hint' => 'A clue',
        ]);

        Auth::guard('profile')->login($sibling);

        Volt::test('kid.quests')
            ->assertSee('Rake the leaves')
            ->assertSee('One-time');

        $this->service()->claim($doer, $chore);

        Auth::guard('profile')->login($sibling->fresh());

        Volt::test('kid.quests')->assertDontSee('Rake the leaves');
    }

    public function test_a_parent_can_put_a_spent_one_time_chore_back_on_the_board(): void
    {
        $household = $this->household();
        $parent = Profile::factory()->parent()->for($household)->create();
        $doer = Profile::factory()->for($household)->create();
        $chore = $this->oneTimeChore($household);

        $this->service()->claim($doer, $chore);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')->call('reopen', $chore->id);

        $this->assertNull($chore->refresh()->used_at);
    }

    public function test_a_parent_cannot_reactivate_another_households_chore(): void
    {
        $parent = Profile::factory()->parent()->for(Household::factory())->create();

        $foreign = Chore::factory()->for(Household::factory())->create([
            'cadence' => ChoreCadence::Once,
            'used_at' => now(),
        ]);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')->call('reopen', $foreign->id);

        $this->assertNotNull($foreign->refresh()->used_at);
    }

    public function test_switching_a_spent_chore_off_one_time_clears_the_stamp(): void
    {
        $household = $this->household();
        $parent = Profile::factory()->parent()->for($household)->create();
        $doer = Profile::factory()->for($household)->create();
        $chore = $this->oneTimeChore($household);

        $this->service()->claim($doer, $chore);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')->call('setCadence', $chore->id, 'daily');

        // Otherwise flipping it back to One-time later would resurrect a stale
        // stamp and the chore would arrive already used.
        $this->assertNull($chore->refresh()->used_at);
    }
}
