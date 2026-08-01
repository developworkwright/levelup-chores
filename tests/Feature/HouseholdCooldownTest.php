<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A once-a-day chore is done once by the family, not once per kid — the
 * dishes don't need washing three times because there are three children.
 */
class HouseholdCooldownTest extends TestCase
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

    public function test_one_kid_claiming_takes_a_daily_chore_off_everyone_elses_board(): void
    {
        $household = Household::factory()->create();
        $doer = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['cadence' => 'daily']);

        $this->service()->claim($doer, $chore);

        $this->assertSame('pending', $this->service()->stateFor($doer, $chore));
        $this->assertSame('done', $this->service()->stateFor($sibling, $chore));
    }

    public function test_it_stays_claimed_after_approval(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $doer = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['cadence' => 'daily']);

        $this->service()->approve($this->service()->claim($doer, $chore), $parent);

        $this->assertSame('done', $this->service()->stateFor($doer, $chore));
        $this->assertSame('done', $this->service()->stateFor($sibling, $chore));
    }

    public function test_a_rejected_claim_reopens_the_chore_for_everyone(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $doer = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['cadence' => 'daily']);

        $completion = $this->service()->claim($doer, $chore);
        $this->assertSame('done', $this->service()->stateFor($sibling, $chore));

        $this->service()->sendBack($completion, $parent);

        // Sending it back means it wasn't really done, so the family can
        // still do it today.
        $this->assertSame('ready', $this->service()->stateFor($sibling, $chore));
        $this->assertSame('ready', $this->service()->stateFor($doer, $chore));
    }

    public function test_the_lock_lifts_the_next_day(): void
    {
        $household = Household::factory()->create();
        $doer = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['cadence' => 'daily']);

        $parent = Profile::factory()->parent()->for($household)->create();
        $this->service()->approve($this->service()->claim($doer, $chore), $parent);

        Carbon::setTestNow(now()->addDay());

        $this->assertSame('ready', $this->service()->stateFor($sibling->refresh(), $chore->refresh()));
    }

    public function test_a_weekly_chore_locks_the_household_for_the_week(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $doer = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['cadence' => 'weekly']);

        $this->service()->approve($this->service()->claim($doer, $chore), $parent);

        Carbon::setTestNow(now()->addDays(3));
        $this->assertSame('done', $this->service()->stateFor($sibling->refresh(), $chore->refresh()));

        Carbon::setTestNow(now()->addDays(5));
        $this->assertSame('ready', $this->service()->stateFor($sibling->refresh(), $chore->refresh()));
    }

    public function test_an_unlimited_chore_stays_open_to_everyone(): void
    {
        $household = Household::factory()->create();
        $doer = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['cadence' => 'unlimited']);

        $this->service()->claim($doer, $chore);

        // Laundry can happen more than once a day, by more than one kid.
        $this->assertSame('ready', $this->service()->stateFor($doer, $chore));
        $this->assertSame('ready', $this->service()->stateFor($sibling, $chore));
    }

    public function test_the_board_hides_a_chore_a_sibling_already_claimed(): void
    {
        $household = Household::factory()->create(['require_quest_first' => false]);
        $doer = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();

        Chore::factory()->for($household)->create(['name' => 'The quest', 'quest_eligible' => true]);
        $shared = Chore::factory()->for($household)->create([
            'name' => 'Feed animals',
            'quest_eligible' => false,
            'cadence' => 'daily',
        ]);

        $this->service()->claim($doer, $shared);

        $entry = $this->service()->boardFor($sibling)->firstWhere('chore.id', $shared->id);

        $this->assertSame('done', $entry['state']);
    }

    public function test_a_sibling_cannot_claim_a_chore_already_taken(): void
    {
        $household = Household::factory()->create();
        $doer = Profile::factory()->for($household)->create();
        $sibling = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['cadence' => 'daily']);

        $this->service()->claim($doer, $chore);

        // The page guards on stateFor before claiming, so this is the check
        // that actually stops a second kid getting in.
        $this->assertNotSame('ready', $this->service()->stateFor($sibling, $chore));
    }
}
