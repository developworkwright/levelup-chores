<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\SpinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `wheel:reset-spin` — the console's Reset button, from a terminal.
 *
 * It is the same act, so it has to behave the same way: an OP charge goes back
 * in the pocket. The two routes disagreeing would mean a kid's ticket survived
 * or died depending on which one a parent happened to reach for.
 */
class ResetSpinCommandTest extends TestCase
{
    use RefreshDatabase;

    private function kid(): Profile
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->count(6)->create();

        return Profile::factory()->for($household)->create(['name' => 'Nova']);
    }

    public function test_it_clears_todays_spin(): void
    {
        $kid = $this->kid();
        $spins = app(SpinService::class);

        $spins->spin($kid);

        $this->artisan('wheel:reset-spin')->assertSuccessful();

        $this->assertFalse($spins->hasSpunToday($kid));
        $this->assertFalse($spins->isCharged($kid->refresh()));
    }

    public function test_it_hands_an_op_charge_back(): void
    {
        $kid = $this->kid();
        $spins = app(SpinService::class);

        $spins->charge($kid);
        $spins->spin($kid->refresh());

        $this->artisan('wheel:reset-spin', ['--kid' => 'Nova'])->assertSuccessful();

        $this->assertFalse($spins->hasSpunToday($kid));
        $this->assertTrue($spins->isCharged($kid->refresh()));
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $kid = $this->kid();
        $spins = app(SpinService::class);

        $spins->charge($kid);
        $spins->spin($kid->refresh());

        $this->artisan('wheel:reset-spin', ['--dry-run' => true])->assertSuccessful();

        $this->assertTrue($spins->hasSpunToday($kid));
        $this->assertFalse($spins->isCharged($kid->refresh()));
    }

    public function test_it_fails_on_a_name_that_matches_nobody(): void
    {
        $this->kid();

        $this->artisan('wheel:reset-spin', ['--kid' => 'Nobody'])->assertFailed();
    }
}
