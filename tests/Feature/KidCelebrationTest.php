<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The kid shell announces anything that moved a balance since the kid last
 * looked. Most badges are handed out on a parent's approval, so without this
 * the ticket count just climbs between visits with nothing to explain it.
 */
class KidCelebrationTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create(['goal_target' => 1000, 'goal_now' => 0]);
        $this->kid = Profile::factory()->for($this->household)->create();
        Chore::factory()->for($this->household)->create();

        Auth::guard('profile')->login($this->kid);
    }

    private function award(string $key): Badge
    {
        $badge = Badge::where('key', $key)->firstOrFail();
        $this->kid->badges()->attach($badge->id, ['earned_at' => now()]);

        return $badge;
    }

    public function test_a_first_visit_seeds_the_marker_without_celebrating(): void
    {
        // Otherwise a kid with a full trophy case is met with a toast per badge
        // the first time this ships.
        $this->award('wheel_winner');

        Volt::test('kid.quests')
            ->assertOk()
            ->assertDontSee('rewards-earned');
    }

    public function test_a_badge_earned_between_visits_is_announced_once(): void
    {
        // First visit takes the baseline.
        Volt::test('kid.quests')->assertOk();

        $badge = $this->award('wheel_winner');

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('rewards-earned')
            ->assertSee('Badge Unlocked', false)
            ->assertSee($badge->name, false)
            ->assertSee('+1 ticket', false);

        // Seen now, so a second look stays quiet.
        Volt::test('kid.quests')
            ->assertOk()
            ->assertDontSee('rewards-earned');
    }

    public function test_the_announcement_names_the_xp_the_badge_paid(): void
    {
        Volt::test('kid.quests')->assertOk();

        $badge = $this->award('streak_14');
        $this->assertSame(400, $badge->xp_reward);

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('+400 XP', false);
    }

    public function test_a_level_up_is_announced_with_the_tickets_it_minted(): void
    {
        Volt::test('kid.quests')->assertOk();

        // Two levels in one go — a badge's XP can carry a kid past a boundary
        // on top of whatever put them there — so it's one card, not two.
        $this->kid->update(['xp' => Profile::XP_PER_LEVEL * 2]);
        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('rewards-earned')
            ->assertSee('Level Up', false)
            ->assertSee('Level 3', false)
            ->assertSee('+2 tickets', false);

        Volt::test('kid.quests')
            ->assertOk()
            ->assertDontSee('rewards-earned');
    }

    public function test_the_family_goal_is_announced_on_the_crossing_only(): void
    {
        Volt::test('kid.quests')->assertOk()->assertDontSee('rewards-earned');

        $this->household->update(['goal_now' => 1000, 'goal_name' => 'Pizza night']);

        // The guard hands back the instance it was handed at login, relations
        // and all. A real second page load resolves the profile from the
        // session and reads the household fresh, so say so here.
        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('rewards-earned')
            ->assertSee('Family Goal', false)
            ->assertSee('Pizza night', false);

        // Still at 100% until a parent resets it — which is not news twice.
        Volt::test('kid.quests')
            ->assertOk()
            ->assertDontSee('rewards-earned');
    }

    public function test_a_goal_already_met_on_arrival_is_not_announced(): void
    {
        $this->household->update(['goal_now' => 1000]);

        Volt::test('kid.quests')
            ->assertOk()
            ->assertDontSee('rewards-earned');
    }
}
