<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyChest;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChestService;
use App\Services\ChoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileXpCommandTest extends TestCase
{
    use RefreshDatabase;

    private function approvedChores(Profile $kid, int $count): void
    {
        $chore = Chore::factory()->for($kid->household)->create();

        for ($i = 0; $i < $count; $i++) {
            ChoreCompletion::create([
                'chore_id' => $chore->id,
                'profile_id' => $kid->id,
                'status' => 'approved',
                'points_awarded' => 100,
                'submitted_at' => now(),
                'decided_at' => now(),
            ]);
        }
    }

    public function test_it_rebuilds_xp_from_approvals_badges_and_chests(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['xp' => 0]);

        $this->approvedChores($kid, 4);

        $badge = Badge::where('key', 'first_quest')->firstOrFail();
        $kid->badges()->attach($badge->id, ['earned_at' => now()]);

        DailyChest::create([
            'profile_id' => $kid->id,
            'chest_date' => now()->toDateString(),
            'reward_kind' => ChestService::KIND_XP,
            'reward_amount' => 50,
        ]);

        $this->artisan('xp:reconcile')->assertSuccessful();

        $expected = (4 * ChoreService::XP_PER_CHORE) + $badge->xp_reward + 50;
        $this->assertSame($expected, $kid->refresh()->xp);
    }

    public function test_it_recovers_badge_xp_that_was_never_paid(): void
    {
        // The bug this exists for: a badge attached before xp_reward existed
        // granted nothing, and maybeAward() will never re-award it.
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['xp' => 0]);

        $badge = Badge::where('key', 'streak_7')->firstOrFail();
        $this->assertGreaterThan(0, $badge->xp_reward);
        $kid->badges()->attach($badge->id, ['earned_at' => now()]);

        $this->artisan('xp:reconcile')->assertSuccessful();

        $this->assertSame($badge->xp_reward, $kid->refresh()->xp);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['xp' => 0]);
        $this->approvedChores($kid, 3);

        $this->artisan('xp:reconcile --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(0, $kid->refresh()->xp);
    }

    public function test_it_never_lowers_xp_by_default(): void
    {
        $household = Household::factory()->create();
        // Hand-credited well past anything the records account for.
        $kid = Profile::factory()->for($household)->create(['xp' => 5000]);
        $this->approvedChores($kid, 1);

        $this->artisan('xp:reconcile')->assertSuccessful();

        $this->assertSame(5000, $kid->refresh()->xp);
    }

    public function test_allow_decrease_opts_into_shrinking(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['xp' => 5000]);
        $this->approvedChores($kid, 1);

        $this->artisan('xp:reconcile --allow-decrease')->assertSuccessful();

        $this->assertSame(ChoreService::XP_PER_CHORE, $kid->refresh()->xp);
    }

    public function test_it_mints_the_tickets_for_levels_the_rebuild_crossed(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create([
            'xp' => 0,
            'bonus_tickets' => 0,
            'tickets_granted_through_level' => 1,
        ]);

        // 12 approvals at 50 is 600 XP — level 4, so three levels crossed.
        $this->approvedChores($kid, 12);

        $this->artisan('xp:reconcile')->assertSuccessful();

        $kid->refresh();
        $this->assertSame(4, $kid->level());
        $this->assertSame(3, $kid->bonus_tickets);
        $this->assertSame(4, $kid->tickets_granted_through_level);
    }

    public function test_running_it_twice_does_not_double_up(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['xp' => 0, 'bonus_tickets' => 0]);
        $this->approvedChores($kid, 8);

        $this->artisan('xp:reconcile')->assertSuccessful();
        $xpAfterFirst = $kid->refresh()->xp;
        $ticketsAfterFirst = $kid->bonus_tickets;

        $this->artisan('xp:reconcile')
            ->expectsOutputToContain('Every kid already matches')
            ->assertSuccessful();

        $kid->refresh();
        $this->assertSame($xpAfterFirst, $kid->xp);
        $this->assertSame($ticketsAfterFirst, $kid->bonus_tickets);
    }

    public function test_it_can_target_a_single_kid(): void
    {
        $household = Household::factory()->create();
        $raylan = Profile::factory()->for($household)->create(['name' => 'Raylan', 'xp' => 0]);
        $westin = Profile::factory()->for($household)->create(['name' => 'Westin', 'xp' => 0]);

        $this->approvedChores($raylan, 2);
        $this->approvedChores($westin, 2);

        $this->artisan('xp:reconcile --kid=Raylan')->assertSuccessful();

        $this->assertSame(2 * ChoreService::XP_PER_CHORE, $raylan->refresh()->xp);
        $this->assertSame(0, $westin->refresh()->xp, 'Westin was not targeted.');
    }

    public function test_an_unknown_kid_fails_rather_than_reconciling_everyone(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['xp' => 0]);
        $this->approvedChores($kid, 2);

        $this->artisan('xp:reconcile --kid=Nobody')->assertFailed();

        $this->assertSame(0, $kid->refresh()->xp);
    }

    public function test_it_leaves_parents_alone(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create(['xp' => 77]);

        $this->artisan('xp:reconcile')->assertSuccessful();

        $this->assertSame(77, $parent->refresh()->xp);
    }

    public function test_pending_and_rejected_completions_do_not_earn_xp(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['xp' => 0]);
        $chore = Chore::factory()->for($household)->create();

        foreach (['pending', 'rejected'] as $status) {
            ChoreCompletion::create([
                'chore_id' => $chore->id,
                'profile_id' => $kid->id,
                'status' => $status,
                'points_awarded' => 100,
                'submitted_at' => now(),
            ]);
        }

        $this->artisan('xp:reconcile')->assertSuccessful();

        $this->assertSame(0, $kid->refresh()->xp);
    }

    public function test_the_reset_command_undoes_exactly_what_approval_granted(): void
    {
        // The two constants used to be separate literals. If they drift, an
        // undone day leaves XP behind or takes too much away.
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create(['xp' => 0]);
        $chore = Chore::factory()->for($household)->create(['points' => 100, 'min_age' => 1]);

        $completion = app(ChoreService::class)->claim($kid, $chore);
        app(ChoreService::class)->approve($completion, $parent);

        $this->assertSame(ChoreService::XP_PER_CHORE, $kid->refresh()->xp);

        $this->artisan('quest:reset-today')->assertSuccessful();

        $this->assertSame(0, $kid->refresh()->xp);
    }
}
