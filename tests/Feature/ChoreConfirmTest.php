<?php

namespace Tests\Feature;

use App\Enums\CompletionStatus;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The whole board row is the claim button, and kids kept tapping one expecting
 * to be told more about the chore — submitting a job they hadn't done for a
 * parent to approve.
 *
 * So a tap now opens a sheet instead of claiming. The sheet is both halves of
 * the fix: it answers the question they were actually asking, and it puts the
 * claim behind a second, labelled press.
 */
class ChoreConfirmTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ChoreService
    {
        return app(ChoreService::class);
    }

    /** Nothing is held back from the board, so this is just a household. */
    private function household(): Household
    {
        $household = Household::factory()->create();

        return $household;
    }

    private function chore(Household $household, string $name = 'Sweep the kitchen'): Chore
    {
        return Chore::factory()->for($household)->create([
            'name' => $name,
            'cadence' => 'daily',
            'points' => 250,
        ]);
    }

    private function loginKid(Household $household, string $name = 'Westin'): Profile
    {
        $kid = Profile::factory()->for($household)->create(['name' => $name]);
        Auth::guard('profile')->login($kid);

        return $kid;
    }

    public function test_tapping_a_row_asks_instead_of_claiming(): void
    {
        $household = $this->household();
        $kid = $this->loginKid($household);
        $chore = $this->chore($household);

        Volt::test('kid.quests')
            ->call('askChore', $chore->id)
            ->assertSet('confirmingChoreId', $chore->id);

        // The point of the whole change: the tap that used to hand the job in
        // now hands over nothing at all.
        $this->assertDatabaseCount('chore_completions', 0);
    }

    public function test_the_sheet_answers_what_the_row_could_not(): void
    {
        $household = $this->household();
        $this->loginKid($household);
        $chore = $this->chore($household);

        // They tapped it for information, so it has to carry some: what the job
        // is, what it pays, and the sentence saying what "yes" commits them to.
        Volt::test('kid.quests')
            ->call('askChore', $chore->id)
            ->assertSee('Sweep the kitchen')
            ->assertSee('Have you finished this one?')
            ->assertSee('a parent has to check it');
    }

    public function test_confirming_claims_it(): void
    {
        $household = $this->household();
        $kid = $this->loginKid($household);
        $chore = $this->chore($household);

        Volt::test('kid.quests')
            ->call('askChore', $chore->id)
            ->call('claimChore', $chore->id)
            ->assertSet('confirmingChoreId', null);

        $this->assertDatabaseHas('chore_completions', [
            'chore_id' => $chore->id,
            'profile_id' => $kid->id,
            'status' => CompletionStatus::Pending->value,
        ]);
    }

    public function test_backing_out_claims_nothing(): void
    {
        $household = $this->household();
        $this->loginKid($household);
        $chore = $this->chore($household);

        Volt::test('kid.quests')
            ->call('askChore', $chore->id)
            ->call('cancelChore')
            ->assertSet('confirmingChoreId', null);

        // "Not yet" is a real answer, and the commonest one these taps want.
        $this->assertDatabaseCount('chore_completions', 0);
    }

    public function test_a_stale_tap_still_explains_itself(): void
    {
        $household = $this->household();
        $this->loginKid($household);
        $sibling = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $chore = $this->chore($household);

        $this->service()->claim($sibling, $chore);

        // Cooldowns are household-wide, so a row can go stale between renders.
        // That tap has always been answered with a reason rather than silence,
        // and it must not open a sheet for a job nobody can take.
        Volt::test('kid.quests')
            ->call('askChore', $chore->id)
            ->assertSet('confirmingChoreId', null)
            ->assertSet('boardMessage', 'Nova got to Sweep the kitchen first!');
    }

    public function test_the_sheet_closes_itself_when_a_sibling_gets_there_first(): void
    {
        $household = $this->household();
        $this->loginKid($household);
        $sibling = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $chore = $this->chore($household);

        $page = Volt::test('kid.quests')
            ->call('askChore', $chore->id)
            ->assertSee('Have you finished this one?');

        $this->service()->claim($sibling, $chore);

        // A sheet can sit open for minutes. Leaving it up would offer a button
        // that can only fail; the row underneath already names Nova.
        $page->call('$refresh')
            ->assertDontSee('Have you finished this one?')
            ->assertSee('Nova got this one');
    }

    public function test_confirming_a_job_a_sibling_took_is_refused(): void
    {
        $household = $this->household();
        $kid = $this->loginKid($household);
        $sibling = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $chore = $this->chore($household);

        $page = Volt::test('kid.quests')->call('askChore', $chore->id);

        $this->service()->claim($sibling, $chore);

        // Never trust the sheet either — it is a button in a browser like any
        // other, and claimChore() re-checks everything server-side.
        $page->call('claimChore', $chore->id)
            ->assertSet('boardMessage', 'Nova got to Sweep the kitchen first!');

        $this->assertDatabaseMissing('chore_completions', [
            'chore_id' => $chore->id,
            'profile_id' => $kid->id,
        ]);
    }

    public function test_the_row_says_the_tap_opens_it(): void
    {
        $household = $this->household();
        $this->loginKid($household);
        $this->chore($household);

        // The tick is a picture, so the words live in the row's title and its
        // sr-only text. They have to name both halves of the tap now: it shows
        // the chore, and that is where it gets marked done.
        Volt::test('kid.quests')->assertSee('See it and mark it done');
    }
}
