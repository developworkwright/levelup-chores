<?php

namespace Tests\Feature;

use App\Enums\BountyKind;
use App\Enums\BountyStatus;
use App\Enums\CompletionStatus;
use App\Enums\MonsterTier;
use App\Enums\TradeAsset;
use App\Models\Badge;
use App\Models\Bounty;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\Profile;
use App\Services\BountyService;
use App\Services\ChoreService;
use App\Services\MonsterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The Quests page as the "Loot Tray" handoff lays it out.
 *
 * The pieces themselves are covered by the suites that own them — chests,
 * streaks, the mystery chore, the boss. What's tested here is the arrangement:
 * that the three extras sit in one tray, that the sections come in the order
 * the handoff fixes, and that the two things the redesign newly put on this
 * page (the bounty board and the badges count) are there and work.
 */
class QuestPageLayoutTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        // Pinned to the middle of the day, like BountyBoardTest: settling a
        // bounty runs badge evaluation, and the wall-clock badges would
        // otherwise mint XP into an overnight run's assertions.
        $this->household = Household::factory()->create(['require_quest_first' => false]);

        $this->travelTo(Carbon::parse('2026-05-01 12:00', $this->household->timezone));

        $this->kid = Profile::factory()->for($this->household)->create(['name' => 'Rex', 'points' => 500]);

        Chore::factory()->for($this->household)->count(3)->create();

        Auth::guard('profile')->login($this->kid);
    }

    public function test_the_streak_slot_never_reads_as_a_day_you_are_already_on(): void
    {
        // The mock's "Day 14 · 6 to go" only works when the milestone is
        // obviously ahead of you. On a fresh profile the same shape rendered
        // "DAY 3 · 3 TO GO" beside a header reading 0d, and the slot looked
        // like it was telling you what day of the streak you were on.
        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('Start a streak')
            ->assertDontSee('Day 3 ·', escape: false);

        // A real day behind the counter, not just the number: the page expires
        // a streak with nothing under it, and a bare update() would be zeroed
        // again before the slot rendered.
        $this->giveKidAStreak(1);

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('2 days to go')
            ->assertDontSee('Day 3 ·', escape: false);
    }

    public function test_the_streak_card_does_not_tell_a_kid_with_no_streak_to_keep_it_alive(): void
    {
        // escape: false because this is literal copy in the template rather
        // than an echoed variable, so Blade leaves the apostrophe alone.
        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee("Clear today's quest to start a streak", escape: false)
            ->assertDontSee('Keep the streak alive');
    }

    /** A genuine run of cleared quests, so syncStreak() leaves the streak alone. */
    private function giveKidAStreak(int $days): void
    {
        $chore = Chore::where('household_id', $this->household->id)->firstOrFail();

        foreach (range(1, $days) as $daysAgo) {
            $at = now()->copy()->subDays($daysAgo);

            DailyQuest::create([
                'household_id' => $this->household->id,
                'profile_id' => $this->kid->id,
                'chore_id' => $chore->id,
                'quest_date' => $at->toDateString(),
                'revealed_at' => $at,
                'completed_at' => $at,
            ]);

            ChoreCompletion::create([
                'chore_id' => $chore->id,
                'profile_id' => $this->kid->id,
                'status' => CompletionStatus::Approved,
                'points_awarded' => 10,
                'submitted_at' => $at,
                'decided_at' => $at,
            ]);
        }

        $this->kid->update(['streak' => $days]);
    }

    public function test_the_streak_slot_leads_to_the_track_when_there_is_nothing_to_open(): void
    {
        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('See the streak chest track')
            ->assertSee('id="streak-card"', escape: false)
            // The tap target is a scroll, not a navigation — the point is
            // showing the kid where on this page the answer lives.
            ->assertSee('getElementById', escape: false);
    }

    public function test_a_waiting_streak_chest_opens_rather_than_scrolling(): void
    {
        $this->kid->update(['streak' => 3, 'pending_streak_chest' => 3]);

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('Open your streak chest')
            ->assertDontSee('See the streak chest track');
    }

    public function test_the_streak_track_draws_its_milestones_as_growing_chests(): void
    {
        // The payout curve has to be readable by a kid who isn't going to
        // compare "100" against "4000" in their head, so the chests carry it.
        $html = Volt::test('kid.quests')->assertOk()->html();

        preg_match_all('/width: (\d+)px; height: \d+px/', $html, $matches);

        $widths = array_map('intval', $matches[1]);

        $this->assertCount(count(ChoreService::STREAK_BONUSES), $widths, 'One chest per milestone.');
        $this->assertSame($widths, array_values(array_unique($widths)), 'No two chests the same size.');

        $sorted = $widths;
        sort($sorted);

        $this->assertSame($sorted, $widths, 'The chests grow along the track.');
    }

    public function test_the_loot_tray_holds_all_three_extras(): void
    {
        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('Loot Tray')
            ->assertSee('Bonus chest')
            ->assertSee('Streak chest')
            ->assertSee('Bonus wheel');
    }

    public function test_the_chest_graphics_keep_their_body_colour(): void
    {
        // Regression. The jiggle used to ride on an Alpine :style binding, and
        // a style binding owns the whole attribute — which is where the flat
        // chest keeps its body colour. On every frame the binding evaluated to
        // "not opening" the chest rendered as an empty box, band and lock
        // floating on nothing.
        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('background: linear-gradient(180deg, #ffe98a, #e0b312)', escape: false)
            ->assertSee('background: var(--fq-chest-blue-fill)', escape: false)
            ->assertDontSee('x-bind:style', escape: false)
            ->assertDontSee(':style="phase', escape: false);
    }

    public function test_the_opening_glow_is_anchored_to_the_middle_of_its_chest(): void
    {
        // Regression, three times over now.
        //
        // The first two attempts centred the glow with percentage offsets and
        // let the keyframe's transform supply the pull-back, and both landed it
        // somewhere off the chest. The third centred it with `inset: 0;
        // margin: auto`, which is correct only while the glow is smaller than
        // the thing it lights — and it never is. CSS 2.1 §10.3.7 abandons the
        // equal-margins rule the moment those margins would go negative, pins
        // margin-left to 0 instead, and lets the rest hang off to the right. It
        // measured 22px out on this page.
        //
        // So the size travels as --fq-glow-size and .fq-glow centres off half
        // of it. Sizing the halo with a width utility instead leaves that
        // calc() with nothing to work from.
        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('--fq-glow-size: 90px', escape: false)
            ->assertSee('--fq-glow-size: 120px', escape: false)
            ->assertDontSee('fq-glow h-[', escape: false)
            ->assertDontSee('margin:auto', escape: false)
            // The chest's keyframe only ever scales, so nothing about where the
            // halo sits depends on the animation running.
            ->assertDontSee('animation: fq-glow-pulse 1s', escape: false);
    }

    /** A monster standing at the long-game tier, which is what draws the strip. */
    private function standUpBoss(string $reward = 'Pizza night', int $health = 1000): void
    {
        app(MonsterService::class)->spawn($this->household, MonsterTier::Three, $reward, $health);
    }

    public function test_the_sections_come_in_the_order_the_handoff_fixes(): void
    {
        $this->standUpBoss();

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSeeInOrder([
                "Today's Target",
                'Loot Tray',
                "Today's main quest is inside",
                'Boss Fight',
                'Gratitude Quest',
                'Side Quests',
                'Bounty Board',
                'Streak Chest',
            ], escape: false);
    }

    public function test_the_wheel_slot_points_at_the_wheel_until_it_is_spun(): void
    {
        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('Not spun yet')
            ->assertSee(route('kid.wheel'));
    }

    public function test_the_wheel_slot_reports_the_boost_once_it_is_spun(): void
    {
        $chore = Chore::factory()->for($this->household)->create(['name' => 'Feed the cat']);

        $this->kid->spins()->create([
            'spin_date' => now($this->household->timezone)->toDateString(),
            'chore_id' => $chore->id,
            'multiplier' => 2,
        ]);

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('2x on Feed the cat')
            ->assertDontSee('Not spun yet');
    }

    public function test_the_tray_pill_announces_the_mystery_chore_in_both_states(): void
    {
        $service = app(ChoreService::class);
        $parent = Profile::factory()->parent()->for($this->household)->create();

        $chore = $service->mysteryChoreFor($this->household);

        Volt::test('kid.quests')->assertOk()->assertSee('Mystery chore live');

        $service->approve($service->claim($this->kid, $chore), $parent);

        Volt::test('kid.quests')->assertOk()->assertSee('Mystery chore found');
    }

    public function test_the_bounty_board_shows_a_takeable_job_and_takes_it(): void
    {
        $sibling = Profile::factory()->for($this->household)->create(['name' => 'Nova', 'points' => 500]);

        $job = app(BountyService::class)->post(
            $sibling,
            BountyKind::Wanted,
            TradeAsset::Points,
            120,
            'Make my bed',
        );

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('Bounty Board')
            ->assertSee('Make my bed')
            ->assertSee('You get')
            ->call('takeJob', $job->id)
            // Thrown out of the button that was pressed rather than rained from
            // the top of the screen. The coordinates only exist on the client,
            // so the payload names the origin and the overlay looks up where
            // the last tap landed.
            ->assertDispatched(
                'celebrate',
                fn (string $event, array $params) => $params['motion'] === 'burst'
                    && $params['origin'] === 'tap',
            );

        $job->refresh();

        $this->assertSame(BountyStatus::Claimed, $job->status);
        $this->assertSame($this->kid->id, $job->claimed_by_profile_id);
    }

    public function test_a_job_the_kid_posted_never_reaches_their_own_board(): void
    {
        app(BountyService::class)->post(
            $this->kid,
            BountyKind::Wanted,
            TradeAsset::Points,
            120,
            'Sweep my room',
        );

        // You cannot take your own job, so offering it here would be a button
        // that only ever refuses.
        Volt::test('kid.quests')
            ->assertOk()
            ->assertDontSee('Sweep my room')
            ->assertSee('Nothing up for grabs');
    }

    public function test_losing_the_race_for_a_job_explains_itself_on_the_page(): void
    {
        $sibling = Profile::factory()->for($this->household)->create(['name' => 'Nova', 'points' => 500]);
        $third = Profile::factory()->for($this->household)->create(['name' => 'Scout', 'points' => 500]);

        $job = app(BountyService::class)->post(
            $sibling,
            BountyKind::Wanted,
            TradeAsset::Points,
            120,
            'Make my bed',
        );

        $component = Volt::test('kid.quests')->assertOk()->assertSee('Make my bed');

        // A sibling gets there between the render and the tap.
        app(BountyService::class)->claim($job, $third);

        $component
            ->call('takeJob', $job->id)
            ->assertNotDispatched('celebrate')
            ->assertSee('no longer up for grabs');

        $this->assertSame($third->id, $job->refresh()->claimed_by_profile_id);
    }

    public function test_a_job_the_kid_cannot_afford_offers_the_shortfall_instead_of_the_button(): void
    {
        $sibling = Profile::factory()->for($this->household)->create(['name' => 'Nova', 'points' => 500]);
        $this->kid->update(['points' => 30]);

        // An offered job is paid for by whoever takes it.
        app(BountyService::class)->post(
            $sibling,
            BountyKind::Offered,
            TradeAsset::Points,
            200,
            'Wash the car',
        );

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('You pay')
            ->assertSee('Need 170 pts')
            ->assertDontSee('Hire them');
    }

    public function test_the_badge_grid_is_gone_and_the_header_counts_them_instead(): void
    {
        $badge = Badge::where('key', 'first_quest')->firstOrFail();
        $this->kid->badges()->attach($badge->id, ['earned_at' => now()]);

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('BADGES')
            ->assertSee(route('kid.badges'))
            // The wall itself lives on the page built for it. Two things the
            // old grid put on every render: the name of a badge nobody has
            // earned, and the placeholder a hidden one shows until they do.
            ->assertDontSee('Chore Legend')
            ->assertDontSee('???');
    }

    public function test_the_boss_caption_carries_the_pending_count(): void
    {
        $this->standUpBoss();

        $service = app(ChoreService::class);
        $service->claim($this->kid, Chore::where('household_id', $this->household->id)->first());

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('Boss Fight')
            ->assertSee('1 PENDING');
    }

    public function test_a_household_with_nothing_standing_draws_no_boss(): void
    {
        // Nothing spawned, so there is no arena to draw and no strip for it.
        Volt::test('kid.quests')
            ->assertOk()
            ->assertDontSee('Boss Fight')
            ->assertDontSee('Family Goal');
    }
}
