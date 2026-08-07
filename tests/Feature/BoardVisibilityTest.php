<?php

namespace Tests\Feature;

use App\Enums\CompletionStatus;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Cooldowns are household-wide and deadlines close chores mid-afternoon, so a
 * kid's board fills up with cards they can't act on. The toggle clears them out
 * without pretending they were never there.
 */
class BoardVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Boards exclude whichever chore became today's quest, so fixtures need one
     * quest-eligible chore to absorb the assignment. The hinted decoy absorbs
     * the mystery draw the same way — hinted chores win it outright, and an
     * unpinned pick gets named on the page once found, which flakes any
     * assertDontSee on a chore name.
     */
    private function household(array $attributes = [], bool $withDecoy = true): Household
    {
        $household = Household::factory()->create($attributes + ['require_quest_first' => false]);

        Chore::factory()->for($household)->create([
            'name' => 'The quest',
            'quest_eligible' => true,
        ]);

        if ($withDecoy) {
            Chore::factory()->for($household)->create([
                'name' => 'The decoy',
                'quest_eligible' => false,
                'hint' => 'Somewhere warm',
            ]);
        }

        return $household;
    }

    private function chore(Household $household, string $name, array $attributes = []): Chore
    {
        return Chore::factory()->for($household)->create($attributes + [
            'name' => $name,
            'quest_eligible' => false,
        ]);
    }

    /**
     * Built directly rather than through claim(), which would make the day's
     * mystery pick as a side effect — possibly landing on this very chore.
     */
    private function claimedBy(Chore $chore, Profile $profile): void
    {
        ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $profile->id,
            'status' => CompletionStatus::Pending,
            'points_awarded' => $chore->points,
            'submitted_at' => now(),
        ]);
    }

    private function loginKid(Household $household): Profile
    {
        $kid = Profile::factory()->for($household)->create(['name' => 'Sam']);
        Auth::guard('profile')->login($kid);

        return $kid;
    }

    public function test_the_board_shows_everything_until_asked_not_to(): void
    {
        $household = $this->household();
        $taken = $this->chore($household, 'Feed animals');
        $this->claimedBy($taken, Profile::factory()->for($household)->create(['name' => 'Nova']));
        $this->loginKid($household);

        Volt::test('kid.quests')
            ->assertSet('hideUnavailable', false)
            ->assertSee('Feed animals')
            ->assertSee('Nova got this one')
            ->assertSee('Hide 1');
    }

    public function test_toggling_hides_a_chore_a_sibling_took(): void
    {
        $household = $this->household();
        $taken = $this->chore($household, 'Feed animals');
        $this->claimedBy($taken, Profile::factory()->for($household)->create(['name' => 'Nova']));
        $this->chore($household, 'Sweep the floor');
        $this->loginKid($household);

        Volt::test('kid.quests')
            ->call('toggleUnavailable')
            ->assertDontSee('Feed animals')
            ->assertSee('Sweep the floor')
            ->assertSee('Show 1 more');
    }

    public function test_toggling_hides_a_chore_whose_deadline_passed(): void
    {
        $household = $this->household();
        $this->chore($household, 'Feed animals', ['expires_at' => now()->subHour()]);
        $this->loginKid($household);

        Volt::test('kid.quests')
            ->assertSee('Feed animals')
            ->call('toggleUnavailable')
            ->assertDontSee('Feed animals');
    }

    public function test_toggling_back_restores_the_board(): void
    {
        $household = $this->household();
        $taken = $this->chore($household, 'Feed animals');
        $this->claimedBy($taken, Profile::factory()->for($household)->create(['name' => 'Nova']));
        $this->loginKid($household);

        Volt::test('kid.quests')
            ->call('toggleUnavailable')
            ->assertDontSee('Feed animals')
            ->call('toggleUnavailable')
            ->assertSee('Feed animals');
    }

    public function test_a_kids_own_pending_claim_is_never_hidden(): void
    {
        // Their card is the only proof the tap went through — hiding it would
        // read as the chore having been taken away from them.
        $household = $this->household();
        $mine = $this->chore($household, 'Feed animals');
        $kid = $this->loginKid($household);
        $this->claimedBy($mine, $kid);

        Volt::test('kid.quests')
            ->call('toggleUnavailable')
            ->assertSee('Feed animals')
            ->assertSee('Pending approval');
    }

    public function test_locked_chores_are_never_hidden(): void
    {
        // Gating locks the entire board until the main quest is done, so
        // hiding locked chores would leave a kid staring at nothing.
        $household = $this->household(['require_quest_first' => true]);
        $this->chore($household, 'Feed animals');
        $this->loginKid($household);

        Volt::test('kid.quests')
            ->call('toggleUnavailable')
            ->assertSee('Feed animals')
            ->assertSee('Locked');
    }

    public function test_the_toggle_stays_out_of_the_way_on_a_clear_board(): void
    {
        $household = $this->household();
        $this->chore($household, 'Feed animals');
        $this->loginKid($household);

        Volt::test('kid.quests')
            ->assertDontSee('Hide 1')
            ->assertDontSee('Show 1 more');
    }

    public function test_hiding_the_whole_board_says_where_it_went(): void
    {
        // An empty column with no explanation reads as a loading bug.
        $household = $this->household(withDecoy: false);
        $taken = $this->chore($household, 'Feed animals');
        $this->claimedBy($taken, Profile::factory()->for($household)->create(['name' => 'Nova']));
        $this->loginKid($household);

        Volt::test('kid.quests')
            ->call('toggleUnavailable')
            ->assertSee('Everything else is taken or closed for today');
    }

    public function test_the_search_counter_measures_against_what_is_on_screen(): void
    {
        // "1 / 3" while two of the three are hidden compares the search to a
        // board the kid can't see.
        $household = $this->household(withDecoy: false);
        $taken = $this->chore($household, 'Feed animals');
        $this->claimedBy($taken, Profile::factory()->for($household)->create(['name' => 'Nova']));
        $this->chore($household, 'Sweep the floor');
        $this->chore($household, 'Sweep the porch');
        $this->loginKid($household);

        Volt::test('kid.quests')
            ->call('toggleUnavailable')
            ->set('search', 'floor')
            ->assertSee('1 / 2');
    }

    public function test_the_count_offered_survives_the_toggle_being_on(): void
    {
        // It's the number of chores the button promises to bring back, so it
        // can't be counted off the board the button just emptied.
        $household = $this->household();
        foreach (['Feed animals', 'Sweep the floor'] as $name) {
            $this->claimedBy(
                $this->chore($household, $name),
                Profile::factory()->for($household)->create(['name' => 'Nova']),
            );
        }
        $this->loginKid($household);

        Volt::test('kid.quests')
            ->assertSee('Hide 2')
            ->call('toggleUnavailable')
            ->assertSee('Show 2 more');
    }
}
