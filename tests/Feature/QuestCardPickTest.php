<?php

namespace Tests\Feature;

use App\Enums\ChoreCadence;
use App\Enums\CompletionStatus;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChoreService;
use App\Services\SpinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The daily quest is a hand of cards, not an assignment.
 *
 * @see ChoreService::dealHand()
 */
class QuestCardPickTest extends TestCase
{
    use RefreshDatabase;

    /** Same reasoning as QuestChestTest: the household day turns at 4am. */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::now()->startOfDay()->addHours(12));
    }

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
     * A household with one chore at each of the given point values.
     *
     * @param  array<int, int>  $points
     * @return array{0: Household, 1: Profile}
     */
    private function householdWithChores(array $points): array
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['age' => 10]);

        foreach ($points as $i => $value) {
            Chore::factory()->for($household)->create([
                'name' => "Chore {$i}",
                'points' => $value,
                'min_age' => null,
                'quest_eligible' => true,
            ]);
        }

        return [$household, $kid];
    }

    public function test_the_daily_quest_deals_a_hand_of_three(): void
    {
        [, $kid] = $this->householdWithChores([10, 20, 30, 40, 50, 60]);

        $quest = $this->service()->questFor($kid);

        $this->assertCount(ChoreService::HAND_SIZE, $quest->offeredChoreIds());
        $this->assertCount(ChoreService::HAND_SIZE, array_unique($quest->offeredChoreIds()));
    }

    public function test_the_hand_is_spread_across_the_point_range_rather_than_drawn_at_random(): void
    {
        // Nine chores in three clear bands. A random draw would land three from
        // the same band often enough to notice; the deal never can.
        [, $kid] = $this->householdWithChores([10, 12, 14, 100, 102, 104, 500, 502, 504]);

        $points = $this->service()->offeredChoresFor($kid)->pluck('points');

        $this->assertLessThan(20, $points[0]);
        $this->assertGreaterThan(90, $points[1]);
        $this->assertLessThan(110, $points[1]);
        $this->assertGreaterThan(490, $points[2]);
    }

    public function test_the_hand_is_stable_across_reads(): void
    {
        [, $kid] = $this->householdWithChores([10, 20, 30, 40, 50, 60]);

        $first = $this->service()->questFor($kid)->offeredChoreIds();

        $this->assertSame($first, $this->service()->questFor($kid)->offeredChoreIds());
        $this->assertSame($first, $this->service()->questFor($kid)->offeredChoreIds());
    }

    public function test_a_household_with_fewer_chores_than_the_hand_size_deals_short(): void
    {
        [, $kid] = $this->householdWithChores([10, 20]);

        $this->assertCount(2, $this->service()->questFor($kid)->offeredChoreIds());
    }

    public function test_a_household_with_one_chore_deals_a_single_card(): void
    {
        [, $kid] = $this->householdWithChores([10]);

        $quest = $this->service()->questFor($kid);

        $this->assertCount(1, $quest->offeredChoreIds());
        $this->assertSame($quest->chore_id, $quest->offeredChoreIds()[0]);
    }

    public function test_nothing_is_picked_until_a_card_is_taken(): void
    {
        [, $kid] = $this->householdWithChores([10, 20, 30, 40]);

        $quest = $this->service()->questFor($kid);

        $this->assertNull($quest->revealed_at);
        $this->assertNull($quest->dealt_at);
        $this->assertFalse($quest->isPicked());
        $this->assertFalse($this->service()->isQuestRevealedToday($kid));
    }

    public function test_choosing_a_card_sets_the_quest(): void
    {
        [, $kid] = $this->householdWithChores([10, 20, 30, 40, 50, 60]);

        $wanted = $this->service()->offeredChoresFor($kid)->last();

        $quest = $this->service()->chooseQuest($kid, $wanted->id);

        $this->assertNotNull($quest);
        $this->assertSame($wanted->id, $quest->chore_id);
        $this->assertNotNull($quest->revealed_at);
        $this->assertTrue($this->service()->isQuestRevealedToday($kid));
    }

    public function test_a_card_that_was_never_dealt_cannot_be_chosen(): void
    {
        [$household, $kid] = $this->householdWithChores([10, 20, 30, 40, 50, 60]);

        $dealt = $this->service()->questFor($kid)->offeredChoreIds();
        $notDealt = $household->chores->reject(fn (Chore $chore) => in_array($chore->id, $dealt, true))->first();

        $this->assertNull($this->service()->chooseQuest($kid, $notDealt->id));
        $this->assertNull($this->service()->questFor($kid)->revealed_at);
    }

    public function test_a_card_a_sibling_claimed_first_cannot_be_chosen(): void
    {
        [$household, $kid] = $this->householdWithChores([10, 20, 30, 40, 50, 60]);
        $sibling = Profile::factory()->for($household)->create(['age' => 12]);

        $target = $this->service()->offeredChoresFor($kid)->first();

        // Created directly rather than through claim(), which would draw the
        // day's mystery chore as a side effect — see the chores-and-quests
        // skill on setting up an already-claimed fixture.
        ChoreCompletion::create([
            'chore_id' => $target->id,
            'profile_id' => $sibling->id,
            'status' => CompletionStatus::Pending,
            'points_awarded' => $target->points,
            'submitted_at' => now(),
        ]);

        $this->assertNull($this->service()->chooseQuest($kid, $target->id));
        $this->assertNull($this->service()->questFor($kid)->revealed_at);
    }

    public function test_the_rest_of_the_hand_survives_a_sibling_taking_one_card(): void
    {
        [$household, $kid] = $this->householdWithChores([10, 20, 30, 40, 50, 60]);
        $sibling = Profile::factory()->for($household)->create(['age' => 12]);

        $hand = $this->service()->questFor($kid)->offeredChoreIds();
        $taken = Chore::find($hand[0]);

        ChoreCompletion::create([
            'chore_id' => $taken->id,
            'profile_id' => $sibling->id,
            'status' => CompletionStatus::Pending,
            'points_awarded' => $taken->points,
            'submitted_at' => now(),
        ]);

        // The placeholder chore_id is the card that just went, and re-dealing
        // over a hand the kid may already be looking at would be worse than
        // leaving two perfectly good cards on the table.
        $this->assertSame($hand, $this->service()->questFor($kid)->offeredChoreIds());

        $survivor = $this->service()->chooseQuest($kid, $hand[1]);

        $this->assertNotNull($survivor);
        $this->assertSame($hand[1], $survivor->chore_id);
    }

    public function test_a_hand_with_every_card_claimed_is_re_dealt(): void
    {
        [$household, $kid] = $this->householdWithChores([10, 20, 30, 40, 50, 60]);
        $sibling = Profile::factory()->for($household)->create(['age' => 12]);

        $hand = $this->service()->questFor($kid)->offeredChoreIds();

        foreach ($hand as $id) {
            $chore = Chore::find($id);

            ChoreCompletion::create([
                'chore_id' => $chore->id,
                'profile_id' => $sibling->id,
                'status' => CompletionStatus::Pending,
                'points_awarded' => $chore->points,
                'submitted_at' => now(),
            ]);
        }

        $fresh = $this->service()->questFor($kid)->offeredChoreIds();

        $this->assertNotEmpty(array_diff($fresh, $hand));
        $this->assertEmpty(array_intersect($fresh, $hand));
    }

    public function test_the_bold_card_is_the_biggest_one_and_pays_a_bonus(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);

        $hand = $this->service()->offeredChoresFor($kid);
        $bonuses = $this->service()->cardBonusesFor($kid);

        // Only the dearest card carries one, at half its own points.
        $this->assertSame([$hand->last()->id => 500], $bonuses);
    }

    public function test_a_hand_whose_cards_all_pay_the_same_has_no_bold_card(): void
    {
        [, $kid] = $this->householdWithChores([50, 50, 50]);

        $this->assertSame([], $this->service()->cardBonusesFor($kid));
    }

    public function test_taking_the_bold_card_pays_the_bonus_on_top(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);

        $bold = $this->service()->offeredChoresFor($kid)->last();
        $this->service()->chooseQuest($kid, $bold->id);
        $this->service()->claimQuest($kid);

        $completion = ChoreCompletion::where('profile_id', $kid->id)->latest('id')->first();

        // 1000 + 50% of 1000.
        $this->assertSame(1500, $completion->points_awarded);
    }

    public function test_taking_a_plain_card_pays_exactly_its_points(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);

        $plain = $this->service()->offeredChoresFor($kid)->first();
        $this->service()->chooseQuest($kid, $plain->id);
        $this->service()->claimQuest($kid);

        $completion = ChoreCompletion::where('profile_id', $kid->id)->latest('id')->first();

        $this->assertSame(10, $completion->points_awarded);
    }

    public function test_the_burned_cards_come_back_as_side_quests(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000, 2000]);

        $hand = $this->service()->questFor($kid)->offeredChoreIds();

        // Gated by the main quest, so the whole hand is off the board while the
        // kid is still deciding — the two they don't take have to come back.
        $this->assertEmpty(
            $this->service()->boardFor($kid)->pluck('chore.id')->intersect($hand)
        );

        $this->service()->chooseQuest($kid, $hand[0]);

        $board = $this->service()->boardFor($kid)->pluck('chore.id');

        $this->assertContains($hand[1], $board->all());
        $this->assertContains($hand[2], $board->all());
        $this->assertNotContains($hand[0], $board->all());
    }

    public function test_the_bonus_wheel_cannot_land_on_any_card_still_in_the_hand(): void
    {
        [$household, $kid] = $this->householdWithChores([10, 20, 30, 40, 50, 60, 70, 80]);
        $household->update(['spin_enabled' => true]);

        $hand = $this->service()->questFor($kid)->offeredChoreIds();

        $wheel = app(SpinService::class)->eligibleChoresFor($kid)->pluck('id');

        $this->assertEmpty($wheel->intersect($hand));
    }

    public function test_a_re_deal_replaces_the_whole_hand_and_shuts_the_chest(): void
    {
        [, $kid] = $this->householdWithChores([10, 20, 30, 40, 50, 60, 70, 80]);

        $hand = $this->service()->questFor($kid)->offeredChoreIds();
        $chosen = $this->service()->chooseQuest($kid, $hand[0]);

        $this->service()->dealQuestHand($kid);
        $this->assertNotNull($this->service()->questFor($kid)->dealt_at);

        $rerolled = $this->service()->rerollQuest($kid);

        $this->assertNotNull($rerolled);
        $this->assertNotSame($chosen->chore_id, $rerolled->chore_id);
        $this->assertNotContains($chosen->chore_id, $rerolled->offeredChoreIds());
        // A fresh hand deserves the chest animation again rather than a
        // silent relabel.
        $this->assertNull($rerolled->dealt_at);
        $this->assertNull($rerolled->revealed_at);
    }

    /**
     * Exactly the shape rows had before offered_chore_ids: a chore, and
     * nothing to say what it was chosen from.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function legacyQuest(Household $household, Profile $kid, Chore $chore, array $attributes = []): DailyQuest
    {
        $quest = DailyQuest::create([
            'household_id' => $household->id,
            'profile_id' => $kid->id,
            'chore_id' => $chore->id,
            'quest_date' => now()->toDateString(),
        ]);

        $quest->forceFill(['offered_chore_ids' => null, ...$attributes])->save();

        return $quest;
    }

    public function test_a_quest_written_before_the_hand_existed_is_dealt_one_on_the_next_read(): void
    {
        [$household, $kid] = $this->householdWithChores([10, 20, 30, 40]);

        // Mid-transition: the chest was opened on a row that never had a hand,
        // which is a page saying "pick your quest" over a single card.
        $this->legacyQuest($household, $kid, $household->chores->first(), ['dealt_at' => now()]);

        $quest = $this->service()->questFor($kid);

        $this->assertCount(ChoreService::HAND_SIZE, $quest->offeredChoreIds());
        // Shut again, so the cards come out of a chest rather than appearing
        // under one that is already open.
        $this->assertNull($quest->dealt_at);
        $this->assertNull($quest->revealed_at);
    }

    public function test_a_legacy_quest_the_kid_already_took_is_left_alone(): void
    {
        [$household, $kid] = $this->householdWithChores([10, 20, 30, 40]);
        $chore = $household->chores->first();

        $this->legacyQuest($household, $kid, $chore, [
            'dealt_at' => now()->subHour(),
            'revealed_at' => now()->subHour(),
        ]);

        $quest = $this->service()->questFor($kid);

        // They have a quest. Dealing a hand now would move it, and hang a bold
        // bonus on a day that was never dealt with one.
        $this->assertSame($chore->id, $quest->chore_id);
        $this->assertSame([$chore->id], $quest->offeredChoreIds());
        $this->assertSame(0, $this->service()->questBonusFor($kid));
    }

    public function test_a_legacy_quest_already_cleared_is_left_alone(): void
    {
        [$household, $kid] = $this->householdWithChores([10, 20, 30, 40]);
        $chore = $household->chores->first();

        $this->legacyQuest($household, $kid, $chore, [
            'dealt_at' => now()->subHour(),
            'revealed_at' => now()->subHour(),
            'completed_at' => now()->subMinutes(5),
        ]);

        $quest = $this->service()->questFor($kid);

        $this->assertSame($chore->id, $quest->chore_id);
        $this->assertNotNull($quest->completed_at);
    }

    public function test_an_unlimited_chore_stays_pickable_however_many_siblings_have_done_it(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['age' => 10]);
        $sibling = Profile::factory()->for($household)->create(['age' => 12]);

        $chore = Chore::factory()->for($household)->create([
            'points' => 25,
            'min_age' => null,
            'quest_eligible' => true,
            'cadence' => ChoreCadence::Unlimited,
        ]);

        ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $sibling->id,
            'status' => CompletionStatus::Pending,
            'points_awarded' => $chore->points,
            'submitted_at' => now(),
        ]);

        $this->assertNotNull($this->service()->chooseQuest($kid, $chore->id));
    }

    public function test_the_page_deals_the_hand_and_takes_a_card(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000, 2000]);

        Auth::guard('profile')->login($kid);

        $wanted = $this->service()->offeredChoresFor($kid)->last();

        Volt::test('kid.quests')
            ->assertSee('Pick your quest')
            ->call('dealHand')
            ->call('chooseQuest', $wanted->id)
            ->assertSee($wanted->name);

        $this->assertSame($wanted->id, $this->service()->questFor($kid)->chore_id);
    }

    public function test_the_page_explains_a_card_that_went_before_it_was_tapped(): void
    {
        [$household, $kid] = $this->householdWithChores([10, 100, 1000, 2000]);
        $sibling = Profile::factory()->for($household)->create(['age' => 12]);

        $target = $this->service()->offeredChoresFor($kid)->first();

        ChoreCompletion::create([
            'chore_id' => $target->id,
            'profile_id' => $sibling->id,
            'status' => CompletionStatus::Pending,
            'points_awarded' => $target->points,
            'submitted_at' => now(),
        ]);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->call('chooseQuest', $target->id)
            ->assertSee('pick another card');
    }
}
