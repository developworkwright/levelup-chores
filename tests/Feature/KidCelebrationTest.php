<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChoreService;
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

    /**
     * Hands over a badge a moment after whatever came before it, so it lands
     * strictly after the watermark the last visit wrote.
     */
    private function award(string $key): Badge
    {
        $this->travel(1)->seconds();

        $badge = Badge::where('key', $key)->firstOrFail();
        $this->kid->badges()->attach($badge->id, ['earned_at' => now()]);

        return $badge;
    }

    /** Re-reads the profile the guard hands to the page, markers and all. */
    private function reload(): void
    {
        Auth::guard('profile')->login($this->kid->fresh());
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

    public function test_a_level_up_survives_being_signed_out_in_between(): void
    {
        // The marker is a column, so a fresh session is not a fresh slate —
        // this is the whole point of not keeping it in the session.
        Volt::test('kid.quests')->assertOk();

        $this->kid->update(['xp' => Profile::XP_PER_LEVEL]);

        Auth::guard('profile')->logout();
        session()->flush();
        $this->reload();

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('rewards-earned')
            ->assertSee('Level 2', false);
    }

    public function test_a_level_up_is_announced_with_the_tickets_it_minted(): void
    {
        Volt::test('kid.quests')->assertOk();

        // Two levels in one go — a badge's XP can carry a kid past a boundary
        // on top of whatever put them there — so it's one card, not two.
        $this->kid->update(['xp' => Profile::XP_PER_LEVEL * 2]);
        $this->reload();

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

    /** Approves a chore worth enough to finish the family goal off. */
    private function crossTheGoal(): void
    {
        $this->household->update(['goal_name' => 'Pizza night']);

        $parent = Profile::factory()->parent()->for($this->household)->create();
        $chore = Chore::factory()->for($this->household)->create(['points' => 1000]);

        $service = app(ChoreService::class);
        $service->approve($service->claim($this->kid, $chore), $parent);
    }

    public function test_crossing_the_goal_queues_the_celebration_for_every_kid(): void
    {
        // A parent tapping approve is a screen no kid is looking at, so the
        // moment has to wait on each of their profiles — including siblings
        // who had nothing to do with the chore that finished it.
        $sibling = Profile::factory()->for($this->household)->create();

        $this->crossTheGoal();

        $this->assertSame('Pizza night', $this->kid->fresh()->pending_goal_celebration);
        $this->assertSame('Pizza night', $sibling->fresh()->pending_goal_celebration);
    }

    public function test_the_goal_celebration_waits_through_a_sign_out(): void
    {
        Volt::test('kid.quests')->assertOk()->assertDontSee('Everyone pulled together');

        $this->crossTheGoal();

        // Signed out and back in with nothing left in the session — the case
        // the old session-backed marker lost outright.
        Auth::guard('profile')->logout();
        session()->flush();
        $this->reload();

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('rewards-earned')
            ->assertSee('Everyone pulled together', false)
            ->assertSee('Pizza night', false);

        // Shown once. The bar stays at 100% until a parent resets it, which is
        // not news a second time.
        $this->reload();

        Volt::test('kid.quests')
            ->assertOk()
            // The card's own note. "Family Goal" is also the heading of the
            // progress panel that sits on this page every day.
            ->assertDontSee('Everyone pulled together');
    }

    public function test_a_goal_already_met_on_arrival_is_not_announced(): void
    {
        // Nothing queued it, so there is nothing owed — a kid joining a
        // household that finished its goal last month hears nothing.
        $this->household->update(['goal_now' => 1000]);

        Volt::test('kid.quests')
            ->assertOk()
            // The card's own note. "Family Goal" is also the heading of the
            // progress panel that sits on this page every day.
            ->assertDontSee('Everyone pulled together');
    }

    public function test_a_renamed_goal_still_announces_the_one_that_was_reached(): void
    {
        $this->crossTheGoal();

        // A parent resets and repoints the goal before the kid ever logs in.
        $this->household->update(['goal_now' => 0, 'goal_name' => 'Trip to the zoo']);
        $this->reload();

        // The goal panel on the page now reads "Trip to the zoo", so the only
        // thing that can still be naming the old one is the card.
        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('Everyone pulled together', false)
            ->assertSee('Pizza night', false);
    }
}
