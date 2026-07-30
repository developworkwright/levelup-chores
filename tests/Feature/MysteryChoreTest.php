<?php

namespace Tests\Feature;

use App\Enums\CompletionStatus;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyMystery;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChoreService;
use App\Services\HouseholdClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class MysteryChoreTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ChoreService
    {
        return app(ChoreService::class);
    }

    public function test_a_mystery_chore_is_picked_automatically_with_no_parent_action(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['name' => 'Only candidate']);

        $chore = $this->service()->mysteryChoreFor($household);

        $this->assertNotNull($chore);
        $this->assertSame('Only candidate', $chore->name);
        $this->assertSame(1, DailyMystery::where('household_id', $household->id)->count());
    }

    public function test_the_pick_is_stable_across_repeated_calls_the_same_day(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->count(10)->create();

        $service = $this->service();
        $first = $service->mysteryChoreFor($household)->id;
        $second = $service->mysteryChoreFor($household)->id;

        $this->assertSame($first, $second);
        $this->assertSame(1, DailyMystery::where('household_id', $household->id)->count());
    }

    public function test_an_age_gated_chore_is_never_picked(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['name' => 'Too old for this', 'min_age' => 10]);
        $onlyEligible = Chore::factory()->for($household)->create(['name' => 'Any age']);

        $chore = $this->service()->mysteryChoreFor($household);

        $this->assertSame($onlyEligible->id, $chore->id);
    }

    public function test_an_unlimited_cadence_chore_is_never_picked(): void
    {
        // Unlimited chores are always freely repeatable by everyone — that
        // contradicts "first one to find it wins", so they're excluded.
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['name' => 'Laundry', 'cadence' => 'unlimited']);
        $onlyEligible = Chore::factory()->for($household)->create(['name' => 'Normal chore']);

        $chore = $this->service()->mysteryChoreFor($household);

        $this->assertSame($onlyEligible->id, $chore->id);
    }

    public function test_a_chore_already_completed_today_is_never_picked(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $alreadyDone = Chore::factory()->for($household)->create(['name' => 'Already done today']);
        $stillOpen = Chore::factory()->for($household)->create(['name' => 'Still open']);

        // Deliberately not using service()->claim() here: it internally
        // calls mysteryChoreFor() for its own bonus check, which could
        // itself make the day's first (persisted) pick before this
        // completion exists to exclude it from candidacy.
        ChoreCompletion::create([
            'chore_id' => $alreadyDone->id,
            'profile_id' => $kid->id,
            'status' => CompletionStatus::Pending,
            'points_awarded' => $alreadyDone->points,
            'submitted_at' => now(),
        ]);

        $chore = $this->service()->mysteryChoreFor($household);

        $this->assertSame($stillOpen->id, $chore->id);
    }

    public function test_returns_null_when_nothing_is_eligible(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['min_age' => 10]);
        Chore::factory()->for($household)->create(['cadence' => 'unlimited']);

        $this->assertNull($this->service()->mysteryChoreFor($household));
    }

    public function test_claiming_the_mystery_chore_folds_the_bonus_into_points_awarded(): void
    {
        $household = Household::factory()->create(['require_quest_first' => false]);
        $winner = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['points' => 100]);

        // The only eligible chore, so it's guaranteed to become the mystery.
        $completion = $this->service()->claim($winner, $chore);

        $this->assertSame(100 + ChoreService::MYSTERY_BONUS_POINTS, $completion->points_awarded);

        $claimant = $this->service()->mysteryClaimant($chore);
        $this->assertNotNull($claimant);
        $this->assertSame($winner->id, $claimant->profile_id);
    }

    public function test_the_mystery_chore_stays_in_the_normal_side_quest_board(): void
    {
        $household = Household::factory()->create(['require_quest_first' => false]);
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['name' => 'Sweep the porch']);
        $filler = Chore::factory()->for($household)->create(['name' => 'Filler chore']);

        // Pin today's quest to the filler chore — otherwise the mystery
        // chore could randomly be picked as the quest itself and correctly
        // NOT show on the side-quest board, which isn't what this checks.
        DailyQuest::create([
            'household_id' => $household->id,
            'profile_id' => $kid->id,
            'chore_id' => $filler->id,
            'quest_date' => HouseholdClock::for($household)->today(),
        ]);

        $board = $this->service()->boardFor($kid);

        $this->assertTrue($board->contains(fn ($entry) => $entry['chore']->id === $chore->id));
    }

    public function test_a_second_kid_cannot_claim_the_mystery_chore_once_someone_else_has(): void
    {
        $household = Household::factory()->create(['require_quest_first' => false]);
        $first = Profile::factory()->for($household)->create();
        $second = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['points' => 500]);
        // Unlimited cadence excludes it from mystery candidacy, guaranteeing
        // $chore (the only other eligible candidate) wins the pick.
        Chore::factory()->for($household)->create(['name' => 'Filler chore', 'cadence' => 'unlimited']);

        $this->service()->claim($first, $chore);

        Auth::guard('profile')->login($second);
        Volt::test('kid.quests')->call('claimChore', $chore->id);

        $this->assertSame(
            1,
            ChoreCompletion::where('chore_id', $chore->id)->count(),
            'Only the first claim should have created a completion row.'
        );
    }

    public function test_state_for_shows_ready_for_others_until_claimed_then_done(): void
    {
        $household = Household::factory()->create();
        $first = Profile::factory()->for($household)->create();
        $second = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create();

        $this->assertSame('ready', $this->service()->stateFor($second, $chore));

        $this->service()->claim($first, $chore);

        $this->assertSame('done', $this->service()->stateFor($second, $chore));
        $this->assertSame('pending', $this->service()->stateFor($first, $chore));
    }

    public function test_a_rejected_mystery_claim_reopens_it_for_everyone(): void
    {
        $household = Household::factory()->create(['require_quest_first' => false]);
        $parent = Profile::factory()->parent()->for($household)->create();
        $first = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create();

        $completion = $this->service()->claim($first, $chore);
        $this->service()->sendBack($completion, $parent);

        $this->assertNull($this->service()->mysteryClaimant($chore));
    }

    public function test_the_quests_page_teases_the_bonus_amount_without_the_board_entry_showing_it(): void
    {
        // The chore's NAME is expected to show plainly in the side-quest
        // board, same as any chore — only its mystery status/bonus is
        // secret. This checks the board entry's own payout stays normal
        // (100) while the separate status card teases the real bonus.
        $household = Household::factory()->create(['require_quest_first' => false]);
        $kid = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create(['name' => 'Sweep the porch', 'points' => 100]);
        $filler = Chore::factory()->for($household)->create(['name' => 'Filler quest chore']);

        // Pin today's quest to the filler chore so the mystery chore is
        // guaranteed to still be sitting on the side-quest board.
        DailyQuest::create([
            'household_id' => $household->id,
            'profile_id' => $kid->id,
            'chore_id' => $filler->id,
            'quest_date' => HouseholdClock::for($household)->today(),
        ]);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->assertSee('Still not completed')
            ->assertSee('Sweep the porch')
            ->assertSeeInOrder(['Sweep the porch', '+100 pts'])
            ->assertSee('+'.ChoreService::MYSTERY_BONUS_POINTS.' pts');
    }

    public function test_the_quests_page_reveals_who_completed_it(): void
    {
        $household = Household::factory()->create(['require_quest_first' => false]);
        $winner = Profile::factory()->for($household)->create(['name' => 'Winner Kid']);
        $other = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create();
        Chore::factory()->for($household)->create(['name' => 'Filler chore', 'cadence' => 'unlimited']);

        $this->service()->claim($winner, $chore);

        Auth::guard('profile')->login($other);

        Volt::test('kid.quests')->assertSee('Completed by Winner Kid');
    }

    public function test_the_winner_sees_their_own_congratulations_not_better_luck_next_time(): void
    {
        $household = Household::factory()->create(['require_quest_first' => false]);
        $winner = Profile::factory()->for($household)->create(['name' => 'Winner Kid']);
        $chore = Chore::factory()->for($household)->create();
        Chore::factory()->for($household)->create(['name' => 'Filler chore', 'cadence' => 'unlimited']);

        $this->service()->claim($winner, $chore);

        Auth::guard('profile')->login($winner);

        Volt::test('kid.quests')
            ->assertSee('You found it')
            ->assertDontSee('Better luck next time')
            ->assertDontSee('Completed by Winner Kid');
    }
}
