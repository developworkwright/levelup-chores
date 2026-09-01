<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ChoreSearchTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsParent(Household $household): Profile
    {
        $parent = Profile::factory()->parent()->for($household)->create();
        Auth::guard('profile')->login($parent);

        return $parent;
    }

    public function test_it_filters_chores_by_name(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['name' => 'Feed animals']);
        Chore::factory()->for($household)->create(['name' => 'Sweep the floor']);
        $this->actingAsParent($household);

        Volt::test('parent.chores')
            ->set('search', 'feed')
            ->assertSee('Feed animals')
            ->assertDontSee('Sweep the floor');
    }

    public function test_the_search_is_case_insensitive(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['name' => 'Feed animals']);
        $this->actingAsParent($household);

        Volt::test('parent.chores')
            ->set('search', 'ANIMALS')
            ->assertSee('Feed animals');
    }

    public function test_it_matches_anywhere_in_the_name(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['name' => 'Put away dishes']);
        $this->actingAsParent($household);

        Volt::test('parent.chores')
            ->set('search', 'away')
            ->assertSee('Put away dishes');
    }

    public function test_an_empty_search_shows_everything(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['name' => 'Feed animals']);
        Chore::factory()->for($household)->create(['name' => 'Sweep the floor']);
        $this->actingAsParent($household);

        Volt::test('parent.chores')
            ->set('search', '   ')
            ->assertSee('Feed animals')
            ->assertSee('Sweep the floor');
    }

    public function test_no_matches_shows_a_message(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['name' => 'Feed animals']);
        $this->actingAsParent($household);

        Volt::test('parent.chores')
            ->set('search', 'zzzznothing')
            ->assertSee('No chores match');
    }

    public function test_clearing_the_search_restores_the_list(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['name' => 'Feed animals']);
        Chore::factory()->for($household)->create(['name' => 'Sweep the floor']);
        $this->actingAsParent($household);

        Volt::test('parent.chores')
            ->set('search', 'feed')
            ->assertDontSee('Sweep the floor')
            ->call('clearSearch')
            ->assertSee('Sweep the floor');
    }

    public function test_wildcards_are_treated_as_literal_text(): void
    {
        // Without escaping, "%" would match every chore rather than none.
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['name' => 'Feed animals']);
        Chore::factory()->for($household)->create(['name' => 'Do 50% of the laundry']);
        $this->actingAsParent($household);

        Volt::test('parent.chores')
            ->set('search', '50%')
            ->assertSee('Do 50% of the laundry', false)
            ->assertDontSee('Feed animals');
    }

    public function test_an_underscore_is_literal_too(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['name' => 'Feed animals']);
        $this->actingAsParent($household);

        // An unescaped underscore is a single-character wildcard, so this
        // would otherwise match "Feed animals" via "Fe_d".
        Volt::test('parent.chores')
            ->set('search', 'Fe_d')
            ->assertSee('No chores match');
    }

    public function test_adding_a_chore_clears_an_active_search(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['name' => 'Feed animals']);
        $this->actingAsParent($household);

        Volt::test('parent.chores')
            ->set('search', 'feed')
            ->set('newChoreName', 'Take out the bins')
            ->call('addChore')
            // A new chore that doesn't match the filter would otherwise look
            // like it was never created.
            ->assertSet('search', '')
            ->assertSee('Take out the bins');
    }

    public function test_the_search_never_reaches_another_household(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['name' => 'Feed animals']);
        Chore::factory()->for(Household::factory())->create(['name' => 'Feed the neighbours cat']);
        $this->actingAsParent($household);

        // The grouped where() in the scope must not escape and swallow the
        // household condition on the outer query.
        Volt::test('parent.chores')
            ->set('search', 'feed')
            ->assertSee('Feed animals')
            ->assertDontSee('Feed the neighbours cat');
    }

    public function test_the_scope_can_be_used_directly(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['name' => 'Feed animals']);
        Chore::factory()->for($household)->create(['name' => 'Sweep the floor']);

        $matches = Chore::where('household_id', $household->id)->matching('sweep')->pluck('name');

        $this->assertSame(['Sweep the floor'], $matches->all());
    }

    /**
     * The SQL scope and the in-memory matcher are separate implementations of
     * the same idea, so they're pinned together here — a term that behaves
     * differently between them is a bug in one of the two.
     */
    public function test_the_sql_scope_and_the_php_matcher_always_agree(): void
    {
        $household = Household::factory()->create();

        foreach (['Feed animals', 'Sweep the floor', 'Do 50% of the laundry', 'Tidy_up'] as $name) {
            Chore::factory()->for($household)->create(['name' => $name]);
        }

        $chores = Chore::where('household_id', $household->id)->get();

        foreach (['feed', 'ANIMALS', 'the', '50%', '_', 'Fe_d', 'zzz', '', '  '] as $term) {
            $viaSql = Chore::where('household_id', $household->id)
                ->matching($term)->orderBy('id')->pluck('name')->all();

            $viaPhp = $chores->filter(fn (Chore $chore) => $chore->matches($term))
                ->pluck('name')->values()->all();

            $this->assertSame($viaSql, $viaPhp, "Search for \"{$term}\" disagreed between SQL and PHP.");
        }
    }

    /**
     * A household whose board is predictable: one quest-eligible chore to
     * soak up the daily assignment, and the rest excluded from that draw so
     * they always stay on the board. Without this, whichever chore became the
     * quest would vanish from the board at random and flake the assertions.
     */
    private function householdWithBoard(array $names): Household
    {
        $household = Household::factory()->create();

        Chore::factory()->for($household)->create(['name' => 'The daily quest', 'quest_eligible' => true]);

        foreach ($names as $name) {
            Chore::factory()->for($household)->create(['name' => $name, 'quest_eligible' => false]);
        }

        return $household;
    }

    /**
     * The board row, by its key rather than by the chore's name.
     *
     * The bonus wheel sits above the board and writes every chore it can land
     * on around its face, so a filtered-out name is still on the page — it is
     * just no longer a row. The search filters the board; it was never meant to
     * reach into the wheel.
     */
    private function boardRow(Chore $chore): string
    {
        return 'wire:key="chore-'.$chore->id.'"';
    }

    public function test_a_kid_can_search_their_side_quests(): void
    {
        $household = $this->householdWithBoard(['Feed animals', 'Sweep the floor', 'Put away dishes']);
        Auth::guard('profile')->login(Profile::factory()->for($household)->create());

        $dishes = Chore::where('name', 'Put away dishes')->firstOrFail();

        Volt::test('kid.quests')
            ->set('search', 'sweep')
            ->assertSee('Sweep the floor')
            ->assertDontSee($this->boardRow($dishes), escape: false);
    }

    public function test_a_kid_searching_for_nothing_gets_a_message(): void
    {
        $household = $this->householdWithBoard(['Feed animals', 'Sweep the floor']);
        Auth::guard('profile')->login(Profile::factory()->for($household)->create());

        Volt::test('kid.quests')
            ->set('search', 'zzzznothing')
            ->assertSee('Nothing matches');
    }

    public function test_a_kid_clearing_the_search_restores_the_board(): void
    {
        $household = $this->householdWithBoard(['Feed animals', 'Sweep the floor', 'Put away dishes']);
        Auth::guard('profile')->login(Profile::factory()->for($household)->create());

        $dishes = Chore::where('name', 'Put away dishes')->firstOrFail();

        Volt::test('kid.quests')
            ->set('search', 'sweep')
            ->assertDontSee($this->boardRow($dishes), escape: false)
            ->call('clearSearch')
            ->assertSee($this->boardRow($dishes), escape: false);
    }

    public function test_searching_the_board_never_reveals_a_hidden_chore(): void
    {
        // The board already excludes today's quest and anything age-gated;
        // filtering must narrow that list, never reach past it.
        $household = $this->householdWithBoard(['Open to all']);
        Chore::factory()->for($household)->create([
            'name' => 'Open to older kids',
            'min_age' => 15,
            'quest_eligible' => false,
        ]);

        Auth::guard('profile')->login(Profile::factory()->for($household)->create(['age' => 6]));

        Volt::test('kid.quests')
            ->set('search', 'open')
            ->assertSee('Open to all')
            ->assertDontSee('Open to older kids');
    }
}
