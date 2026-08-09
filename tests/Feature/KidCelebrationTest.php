<?php

namespace Tests\Feature;

use App\Enums\BossSkin;
use App\Models\Badge;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\BossService;
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
     * The behaviour lives in resources/js/app.js and the markup in
     * <x-overlays>, joined only by this name — so a rename on either side
     * silently unhooks every celebration in the app with nothing to catch it.
     */
    public function test_the_overlay_is_wired_to_its_alpine_component(): void
    {
        $this->get(route('kid.quests'))
            ->assertOk()
            ->assertSee('x-data="fqCelebrations"', false)
            ->assertSee('celebrate($event.detail)', false);

        $this->assertStringContainsString(
            "Alpine.data('fqCelebrations'",
            file_get_contents(resource_path('js/app.js')),
        );
    }

    /**
     * The handoff from the shell to the overlay has no retry behind it: the
     * marker saying a kid has been told is cleared by the same render that
     * emits the event, so an event nobody is listening for loses the news for
     * good. Both guards are asserted because either one alone is enough to
     * work, which is exactly how one of them gets quietly dropped.
     */
    public function test_the_overlay_is_listening_before_the_shell_announces(): void
    {
        Volt::test('kid.quests')->assertOk();

        $this->kid->update(['xp' => Profile::XP_PER_LEVEL]);
        $this->reload();

        // Deferred past Alpine's init walk, so every root exists first.
        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('x-init="$nextTick(() => $dispatch(\'rewards-earned\'', false);

        $this->kid->update(['xp' => Profile::XP_PER_LEVEL * 2, 'level_seen' => 2]);

        // And the listener is set up ahead of the page that fires at it.
        $this->get(route('kid.quests'))
            ->assertOk()
            ->assertSeeInOrder(['x-data="fqCelebrations"', 'rewards-earned'], false);
    }

    /**
     * The shell hands its rewards over as an @js payload, which Livewire
     * renders as JSON.parse('...') with every quote escaped — so a fragment
     * has to be escaped the same way before it can be found in the markup.
     */
    private function asRendered(string $json): string
    {
        return str_replace('"', '\\u0022', $json);
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

    /**
     * A level goes up, so its confetti does too — fired from the bottom
     * corners rather than dropped from the top.
     */
    public function test_a_level_up_fires_its_confetti_upward(): void
    {
        Volt::test('kid.quests')->assertOk();

        $this->kid->update(['xp' => Profile::XP_PER_LEVEL]);
        $this->reload();

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee($this->asRendered('"style":"star"'), false)
            ->assertSee($this->asRendered('"motion":"cannon"'), false)
            ->assertSee($this->asRendered('"hero":"level"'), false);
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

    /**
     * The sibling's row being flagged is not the same as the sibling being
     * told. Everything above this tests the kid who landed the final blow,
     * which is the one case that was never in doubt.
     */
    public function test_a_sibling_who_did_nothing_still_gets_the_whole_celebration(): void
    {
        $sibling = Profile::factory()->for($this->household)->create(['name' => 'Scout']);

        $this->crossTheGoal();

        Auth::guard('profile')->logout();
        session()->flush();
        Auth::guard('profile')->login($sibling->fresh());

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('rewards-earned')
            ->assertSee($this->asRendered('"hero":"boss"'), false)
            ->assertSee($this->asRendered('"motion":"fireworks"'), false)
            // Named, not "You" — the sibling was not the one who did it.
            ->assertSee($this->kid->name.' landed the final blow', false);
    }

    public function test_the_goal_celebration_waits_through_a_sign_out(): void
    {
        Volt::test('kid.quests')->assertOk()->assertDontSee('landed the final blow');

        $this->crossTheGoal();

        // Signed out and back in with nothing left in the session — the case
        // the old session-backed marker lost outright.
        Auth::guard('profile')->logout();
        session()->flush();
        $this->reload();

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('rewards-earned')
            ->assertSee('landed the final blow', false)
            ->assertSee('Pizza night', false);

        // Shown once. The bar stays at 100% until a parent resets it, which is
        // not news a second time.
        $this->reload();

        Volt::test('kid.quests')
            ->assertOk()
            // The card's own note. "Family Goal" is also the heading of the
            // progress panel that sits on this page every day.
            ->assertDontSee('landed the final blow');
    }

    /**
     * The rarest thing in the app and the only one the whole household worked
     * on, so it takes the tier nothing else uses and the shells that go with
     * it. A downed boss lands with a thud rather than a chime.
     */
    public function test_a_downed_boss_gets_the_epic_tier_and_fireworks(): void
    {
        Volt::test('kid.quests')->assertOk();

        $this->crossTheGoal();
        $this->reload();

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee($this->asRendered('"tier":"epic"'), false)
            ->assertSee($this->asRendered('"motion":"fireworks"'), false)
            ->assertSee($this->asRendered('"sound":"impact"'), false)
            ->assertSee($this->asRendered('"hero":"boss"'), false);
    }

    /**
     * The knockout draws the monster from the skin the defeat was stamped
     * with, not from whoever the household is fighting now — a kid days late
     * to the news would otherwise watch the *next* monster fall over.
     */
    public function test_the_knockout_carries_the_skin_that_was_beaten(): void
    {
        Volt::test('kid.quests')->assertOk();

        $this->crossTheGoal();

        // The household moves on the moment the goal is reset, which is
        // exactly the gap this has to survive.
        $beaten = $this->kid->fresh()->pending_boss_name;
        $this->assertSame(BossSkin::Gnash->label(), $beaten);

        $this->household->update(['boss_skin' => BossSkin::Gnash->next()->value]);
        $this->reload();

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee($this->asRendered('"skin":"gnash"'), false);
    }

    /**
     * A defeat queued before the boss battle existed has no name to work
     * back from, and a renamed monster has one that no longer matches. Both
     * lose the picture, neither loses the celebration.
     */
    public function test_a_defeat_with_no_recognisable_name_still_celebrates(): void
    {
        Volt::test('kid.quests')->assertOk();

        $this->crossTheGoal();
        $this->kid->forceFill(['pending_boss_name' => null])->save();
        $this->reload();

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('rewards-earned')
            ->assertSee('Pizza night', false)
            ->assertDontSee($this->asRendered('"hero":"boss"'), false);
    }

    /**
     * A name that no longer matches a case — a monster renamed since the defeat
     * was queued — drops the set piece rather than opening it on an empty gap
     * where the body should be. The knockout is mostly artwork; without the
     * artwork there is nothing to stage.
     */
    public function test_a_renamed_monster_falls_back_to_the_plain_goal_card(): void
    {
        Volt::test('kid.quests')->assertOk();

        $this->crossTheGoal();
        $this->kid->forceFill(['pending_boss_name' => 'Gnashh'])->save();
        $this->reload();

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('rewards-earned')
            // Still announced, still named, still epic — just not staged.
            ->assertSee('Gnashh is down!', false)
            ->assertSee($this->asRendered('"tier":"epic"'), false)
            ->assertDontSee($this->asRendered('"hero":"boss"'), false);
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
            ->assertDontSee('landed the final blow');
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
            ->assertSee('landed the final blow', false)
            ->assertSee('Pizza night', false);
    }

    public function test_the_kill_is_pinned_to_the_monster_that_was_standing(): void
    {
        $this->crossTheGoal();

        // A parent starting the next goal rotates the skin. The card has to
        // keep naming the one that actually died, for the same reason it keeps
        // naming the goal that was actually reached.
        app(BossService::class)->startNewBattle($this->household);
        $this->reload();

        Volt::test('kid.quests')
            ->assertOk()
            // The card still names the monster that actually died...
            ->assertSee(BossSkin::default()->label(), false)
            // ...while the sidebar has already moved on to the one now
            // standing. Both on the same page at once is the point: the kill
            // is history, the arena is live.
            ->assertSee(BossSkin::default()->next()->label(), false);
    }
}
