<?php

namespace Tests\Feature;

use App\Enums\ChoreCadence;
use App\Enums\CompletionStatus;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The adding-up card at the foot of the side-quest board.
 *
 * "I need exactly $4." A price band can't answer that — the kid would be doing
 * sums on top of sums — so the card holds two jobs, adds them up, and says how
 * far off the total is. It sits below the list because it answers a question
 * the list has already failed to answer; above the board, a kid had to step
 * over a calculator to get to the jobs.
 */
class QuestBoardAdderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Boards exclude every card in today's quest hand, so fixtures need one
     * quest-eligible chore to absorb the deal, and a hinted decoy to absorb the
     * mystery draw.
     */
    private function household(): Household
    {
        $household = Household::factory()->create();

        Chore::factory()->for($household)->create([
            'name' => 'The quest',
            'quest_eligible' => true,
        ]);

        Chore::factory()->for($household)->create([
            'name' => 'The decoy',
            'quest_eligible' => false,
            'points' => 100,
            'hint' => 'Somewhere warm',
        ]);

        return $household;
    }

    /** @param  array<string, mixed>  $attributes */
    private function chore(Household $household, string $name, int $points, array $attributes = []): Chore
    {
        return Chore::factory()->for($household)->create($attributes + [
            'name' => $name,
            'points' => $points,
            'quest_eligible' => false,
        ]);
    }

    private function loginKid(Household $household): Profile
    {
        $kid = Profile::factory()->for($household)->create(['name' => 'Sam']);
        Auth::guard('profile')->login($kid);

        return $kid;
    }

    /** @return array<int, string> */
    private function slotNames(mixed $page): array
    {
        return $page->viewData('slotChores')->pluck('name')->all();
    }

    public function test_it_opens_holding_two_jobs(): void
    {
        $household = $this->household();
        $this->chore($household, 'Sweep the porch', 200);
        $this->loginKid($household);

        $page = Volt::test('kid.quests')
            ->assertSet('adderOpen', true)
            ->assertSee('Both together')
            ->assertSee('Pick two for me');

        $this->assertCount(2, $this->slotNames($page));
    }

    public function test_the_target_starts_at_four_dollars_in_the_households_money(): void
    {
        $household = $this->household();
        $household->update(['points_per_dollar' => 50]);
        $this->chore($household, 'Sweep the porch', 200);
        $this->loginKid($household);

        Volt::test('kid.quests')
            ->assertSet('target', 200)
            ->assertSee('$4.00');
    }

    public function test_the_stepper_moves_a_dollar_a_tap_and_clamps_both_ends(): void
    {
        $household = $this->household();
        $this->chore($household, 'Sweep the porch', 200);
        $this->loginKid($household);

        $page = Volt::test('kid.quests')
            ->call('stepTarget', 1)
            ->assertSet('target', 500)
            ->call('stepTarget', -1)
            ->assertSet('target', 400);

        for ($tap = 0; $tap < 5; $tap++) {
            $page->call('stepTarget', -1);
        }

        // The floor. The minus greys in place rather than disappearing, so the
        // control never reflows under a thumb mid-tap.
        $page->assertSet('target', 100);

        for ($tap = 0; $tap < 30; $tap++) {
            $page->call('stepTarget', 1);
        }

        $page->assertSet('target', 2000);
    }

    public function test_stepping_a_slot_skips_whatever_the_other_one_holds(): void
    {
        // Two copies of one job is not a plan, it's a bug the kid has to work
        // out for himself.
        $household = $this->household();
        $this->chore($household, 'Sweep the porch', 200);
        $this->chore($household, 'Mow the lawn', 800);
        $this->loginKid($household);

        $page = Volt::test('kid.quests');

        for ($tap = 0; $tap < 6; $tap++) {
            $page->call('stepSlot', 0, 1);

            $names = $this->slotNames($page);

            $this->assertCount(2, $names);
            $this->assertNotSame($names[0], $names[1]);
        }
    }

    public function test_pick_two_for_me_lands_on_the_cheapest_pair_that_clears_it(): void
    {
        $household = $this->household();
        $this->chore($household, 'Wipe the table', 150);
        $this->chore($household, 'Sweep the porch', 250);
        $this->chore($household, 'Mow the lawn', 800);
        $this->loginKid($household);

        // $4.00 exactly: the decoy's 100 plus the porch's 250 is 350 and falls
        // short, so 150 + 250 is the cheapest pair that clears it.
        $page = Volt::test('kid.quests')->call('pickTwo');

        $this->assertSame(400, $page->viewData('slotTotal'));
        $this->assertSame(['Wipe the table', 'Sweep the porch'], $this->slotNames($page));
        $page->assertSee('perfect.');
    }

    public function test_a_pair_over_the_target_says_what_is_spare(): void
    {
        $household = $this->household();
        $this->chore($household, 'Sweep the porch', 350);
        $this->loginKid($household);

        Volt::test('kid.quests')
            ->call('pickTwo')
            ->assertSee('spare');
    }

    public function test_an_unreachable_target_says_how_far_off_it_is(): void
    {
        // The two dearest jobs still fall short. Leaving the slots alone and
        // saying how far is more use than silently rearranging two jobs that
        // still don't add up.
        $household = $this->household();
        $this->chore($household, 'Wipe the table', 150);
        $this->loginKid($household);

        $page = Volt::test('kid.quests')
            ->set('target', 2000)
            ->call('pickTwo');

        $this->assertSame(250, $page->viewData('slotTotal'));
        $page->assertSee('try the arrows');
    }

    public function test_a_slot_a_sibling_claims_mid_build_is_swapped_out(): void
    {
        // Cooldowns are household-wide, so a job can go while the kid is still
        // adding up. Never leave a dead job in a slot — and say so, because a
        // card that rearranges itself silently reads as a bug.
        $household = $this->household();
        $taken = $this->chore($household, 'Sweep the porch', 200);
        $this->chore($household, 'Mow the lawn', 800);
        $sibling = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $this->loginKid($household);

        $page = Volt::test('kid.quests');
        $this->assertContains('Sweep the porch', $this->slotNames($page));

        ChoreCompletion::create([
            'chore_id' => $taken->id,
            'profile_id' => $sibling->id,
            'status' => CompletionStatus::Pending,
            'points_awarded' => $taken->points,
            'submitted_at' => now(),
        ]);

        $page->call('refreshBoard');

        $this->assertNotContains('Sweep the porch', $this->slotNames($page));
        $this->assertSame('Sweep the porch just went — swapped in another job.', $page->viewData('slotNotice'));
        $page->assertSee('Sweep the porch just went');
    }

    public function test_the_card_stays_away_when_there_is_nothing_to_add_up(): void
    {
        // One claimable chore is not a pair.
        $household = $this->household();
        $only = $this->chore($household, 'Sweep the porch', 200);
        $sibling = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $this->loginKid($household);

        ChoreCompletion::create([
            'chore_id' => $only->id,
            'profile_id' => $sibling->id,
            'status' => CompletionStatus::Pending,
            'points_awarded' => $only->points,
            'submitted_at' => now(),
        ]);

        Volt::test('kid.quests')
            ->assertSet('adderSlots', [])
            ->assertDontSee('Both together');
    }

    public function test_hiding_it_leaves_one_row_that_offers_it_back(): void
    {
        $household = $this->household();
        $this->chore($household, 'Sweep the porch', 200, ['cadence' => ChoreCadence::Unlimited]);
        $this->loginKid($household);

        Volt::test('kid.quests')
            ->call('toggleAdder')
            ->assertSet('adderOpen', false)
            ->assertDontSee('Both together')
            ->assertSee('Want an exact amount?')
            ->call('toggleAdder')
            ->assertSee('Both together');
    }
}
