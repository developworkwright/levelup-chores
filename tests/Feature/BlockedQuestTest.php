<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cooldowns are household-wide, so a sibling can finish the chore that was
 * handed to you as today's quest. Left alone that dead-ends the kid's whole
 * day — no quest completion and no streak night. The quest silently moves to
 * something they can actually do.
 */
class BlockedQuestTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ChoreService
    {
        return app(ChoreService::class);
    }

    /**
     * Pins a kid's quest to a specific chore so the assertions aren't at the
     * mercy of the random draw.
     */
    private function assignQuest(Profile $profile, Chore $chore): DailyQuest
    {
        $quest = $this->service()->questFor($profile);
        $quest->forceFill(['chore_id' => $chore->id, 'revealed_at' => now()])->save();

        return $quest->refresh();
    }

    public function test_a_quest_claimed_by_a_sibling_is_swapped_out(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();

        $taken = Chore::factory()->for($household)->create(['cadence' => 'daily', 'quest_eligible' => true]);
        Chore::factory()->for($household)->create(['cadence' => 'daily', 'quest_eligible' => true]);

        $this->assignQuest($kid, $taken);
        $this->service()->claim($sibling, $taken);

        $quest = $this->service()->questFor($kid);

        $this->assertNotSame($taken->id, $quest->chore_id);
        $this->assertSame('ready', $this->service()->stateFor($kid, $quest->chore));
    }

    public function test_the_swapped_quest_replays_the_chest(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();

        $taken = Chore::factory()->for($household)->create(['cadence' => 'daily', 'quest_eligible' => true]);
        Chore::factory()->for($household)->create(['cadence' => 'daily', 'quest_eligible' => true]);

        $this->assignQuest($kid, $taken);
        $this->service()->claim($sibling, $taken);

        // Same deal as a paid reroll: a new quest is worth a fresh reveal
        // rather than a silent relabel of the card they already opened.
        $this->assertNull($this->service()->questFor($kid)->revealed_at);
    }

    public function test_it_leaves_the_quest_alone_when_the_kid_holds_it_themselves(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();

        $mine = Chore::factory()->for($household)->create(['cadence' => 'daily', 'quest_eligible' => true]);
        Chore::factory()->for($household)->create(['cadence' => 'daily', 'quest_eligible' => true]);

        $this->assignQuest($kid, $mine);
        $this->service()->claim($kid, $mine);

        // Their own pending claim is progress, not a blockage.
        $this->assertSame($mine->id, $this->service()->questFor($kid)->chore_id);
    }

    public function test_it_leaves_a_completed_quest_alone(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();

        $done = Chore::factory()->for($household)->create(['cadence' => 'daily', 'quest_eligible' => true]);
        Chore::factory()->for($household)->create(['cadence' => 'daily', 'quest_eligible' => true]);

        $this->assignQuest($kid, $done);
        $this->service()->claimQuest($kid);
        $this->service()->approve($kid->choreCompletions()->latest('id')->first(), $parent);

        $quest = $this->service()->questFor($kid);

        // The chore reads as claimed by them, but the quest is banked —
        // rerolling here would erase a finished quest and cost them a streak day.
        $this->assertSame($done->id, $quest->chore_id);
        $this->assertNotNull($quest->completed_at);
    }

    public function test_an_unlimited_quest_chore_is_never_swapped(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();

        $shared = Chore::factory()->for($household)->create(['cadence' => 'unlimited', 'quest_eligible' => true]);
        Chore::factory()->for($household)->create(['cadence' => 'daily', 'quest_eligible' => true]);

        $this->assignQuest($kid, $shared);
        $this->service()->claim($sibling, $shared);

        // Unlimited chores never lock, so a sibling doing one blocks nobody.
        $this->assertSame($shared->id, $this->service()->questFor($kid)->chore_id);
    }

    public function test_it_keeps_the_blocked_quest_when_there_is_nothing_to_swap_to(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();

        $only = Chore::factory()->for($household)->create(['cadence' => 'daily', 'quest_eligible' => true]);

        $this->assignQuest($kid, $only);
        $this->service()->claim($sibling, $only);

        // Nothing better to offer, so the page still renders rather than blowing up.
        $this->assertSame($only->id, $this->service()->questFor($kid)->chore_id);
    }

    public function test_it_does_not_swap_onto_another_blocked_chore(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();

        $taken = Chore::factory()->for($household)->create(['cadence' => 'daily', 'quest_eligible' => true]);
        $alsoTaken = Chore::factory()->for($household)->create(['cadence' => 'daily', 'quest_eligible' => true]);
        $free = Chore::factory()->for($household)->create(['cadence' => 'daily', 'quest_eligible' => true]);

        $this->assignQuest($kid, $taken);
        $this->service()->claim($sibling, $taken);
        $this->service()->claim($sibling, $alsoTaken);

        // A swap onto an already-claimed chore would leave them exactly as stuck.
        $this->assertSame($free->id, $this->service()->questFor($kid)->chore_id);
    }

    public function test_a_fresh_quest_avoids_chores_the_family_already_cleared(): void
    {
        $household = Household::factory()->create();
        $sibling = Profile::factory()->for($household)->create();

        $taken = Chore::factory()->for($household)->create(['cadence' => 'daily', 'quest_eligible' => true]);
        $free = Chore::factory()->for($household)->create(['cadence' => 'daily', 'quest_eligible' => true]);

        $this->service()->claim($sibling, $taken);

        // A kid who logs in late shouldn't be handed a dead quest to begin with.
        $latecomer = Profile::factory()->for($household)->create();

        $this->assertSame($free->id, $this->service()->questFor($latecomer)->chore_id);
    }

    public function test_a_latecomer_still_gets_a_quest_when_the_board_is_cleared(): void
    {
        $household = Household::factory()->create();
        $sibling = Profile::factory()->for($household)->create();

        $only = Chore::factory()->for($household)->create(['cadence' => 'daily', 'quest_eligible' => true]);
        $this->service()->claim($sibling, $only);

        $latecomer = Profile::factory()->for($household)->create();

        // A blocked quest beats a RuntimeException on the dashboard.
        $this->assertSame($only->id, $this->service()->questFor($latecomer)->chore_id);
    }

    public function test_a_paid_reroll_also_avoids_claimed_chores(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();

        $mine = Chore::factory()->for($household)->create(['cadence' => 'daily', 'quest_eligible' => true]);
        $taken = Chore::factory()->for($household)->create(['cadence' => 'daily', 'quest_eligible' => true]);
        $free = Chore::factory()->for($household)->create(['cadence' => 'daily', 'quest_eligible' => true]);

        $this->assignQuest($kid, $mine);
        $this->service()->claim($sibling, $taken);

        // They spent tickets on this — landing them on a dead chore is a scam.
        $this->assertSame($free->id, $this->service()->rerollQuest($kid)?->chore_id);
    }

    public function test_a_paid_reroll_returns_null_when_every_alternative_is_claimed(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();

        $mine = Chore::factory()->for($household)->create(['cadence' => 'daily', 'quest_eligible' => true]);
        $taken = Chore::factory()->for($household)->create(['cadence' => 'daily', 'quest_eligible' => true]);

        $this->assignQuest($kid, $mine);
        $this->service()->claim($sibling, $taken);

        // Null is what tells the perk to refuse and hand the ticket back.
        $this->assertNull($this->service()->rerollQuest($kid));
    }
}
