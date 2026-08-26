<?php

namespace Tests\Feature;

use App\Enums\CompletionStatus;
use App\Enums\PerkEffect;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\OwnedPerk;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * What a kid holding a Streak Restore is shown, which depends entirely on
 * whether there's a day worth buying back. A live streak with today's quest
 * still outstanding is not a broken one.
 */
class StreakRestoreOfferTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    private Chore $chore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create(['day_boundary_hour' => 4]);
        $this->kid = Profile::factory()->for($this->household)->create();
        $this->chore = Chore::factory()->for($this->household)->create(['points' => 10]);

        Auth::guard('profile')->login($this->kid);
    }

    private function clearQuestOn(string $date): void
    {
        $at = Carbon::parse("{$date} 12:00", $this->household->timezone);

        DailyQuest::create([
            'household_id' => $this->household->id,
            'profile_id' => $this->kid->id,
            'chore_id' => $this->chore->id,
            'quest_date' => $date,
            'revealed_at' => $at,
            'completed_at' => $at,
        ]);

        ChoreCompletion::create([
            'chore_id' => $this->chore->id,
            'profile_id' => $this->kid->id,
            'status' => CompletionStatus::Approved,
            'points_awarded' => 10,
            'submitted_at' => $at,
            'decided_at' => $at,
        ]);
    }

    private function holdARestore(int $count = 1): void
    {
        foreach (range(1, $count) as $ignored) {
            OwnedPerk::create([
                'profile_id' => $this->kid->id,
                'effect' => PerkEffect::StreakRestore,
                'source' => OwnedPerk::SOURCE_SHOP,
                'acquired_at' => now(),
            ]);
        }
    }

    public function test_a_live_streak_is_never_offered_a_rescue(): void
    {
        // Yesterday counted, so the chain is intact — today's quest simply
        // hasn't been done yet, which is not a break.
        $this->clearQuestOn('2026-03-03');
        $this->clearQuestOn('2026-03-04');
        $this->kid->update(['streak' => 2]);

        $this->travelTo(Carbon::parse('2026-03-05 09:00', $this->household->timezone));
        $this->holdARestore();

        $page = Volt::test('kid.home')->assertOk();

        $page->assertDontSee('Streak Rescue')
            // And no dead button under a healthy streak.
            ->assertDontSee('Use Streak Restore')
            ->assertSee('A Streak Restore is in your pocket', false)
            ->assertSee('Nothing to fix right now', false);

        // The header still reads the streak it actually has.
        $this->assertSame(2, $this->kid->fresh()->streak);
    }

    public function test_a_broken_streak_swaps_the_note_for_the_rescue_card(): void
    {
        $this->clearQuestOn('2026-03-01');
        $this->clearQuestOn('2026-03-02');
        $this->clearQuestOn('2026-03-03');
        $this->kid->update(['streak' => 3]);

        // Missed the 4th; standing on the 5th with today's quest untouched.
        $this->travelTo(Carbon::parse('2026-03-05 09:00', $this->household->timezone));
        $this->holdARestore();

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Streak Rescue')
            ->assertSee('Use Streak Restore')
            ->assertDontSee('Nothing to fix right now');
    }

    public function test_the_note_counts_the_restores_being_held(): void
    {
        $this->clearQuestOn('2026-03-04');
        $this->kid->update(['streak' => 1]);

        $this->travelTo(Carbon::parse('2026-03-05 09:00', $this->household->timezone));
        $this->holdARestore(2);

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('2 Streak Restores are in your pocket', false);
    }

    public function test_a_kid_holding_nothing_sees_no_note_at_all(): void
    {
        $this->clearQuestOn('2026-03-04');
        $this->kid->update(['streak' => 1]);

        $this->travelTo(Carbon::parse('2026-03-05 09:00', $this->household->timezone));

        Volt::test('kid.home')
            ->assertOk()
            ->assertDontSee('in your pocket');
    }
}
