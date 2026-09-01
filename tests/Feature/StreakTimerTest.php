<?php

namespace Tests\Feature;

use App\Enums\CompletionStatus;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Household;
use App\Models\Profile;
use App\Services\StreakService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The countdown on tonight's streak day.
 *
 * The kids could tell you a streak was worth something and not when they had to
 * act to keep one, so the deadline is now stated on a live timer above the day.
 * What is pinned here is the split that makes it work: the timer counts to
 * *bedtime*, which is a time a kid can act on, while the run itself lives until
 * the rollover — and the moment those two disagree, the strip says which one it
 * is counting rather than quietly swapping them.
 */
class StreakTimerTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create([
            'day_boundary_hour' => 4,
            'evening_watch_hour' => 19,
            'bedtime' => '21:00',
        ]);

        $this->kid = Profile::factory()->for($this->household)->create(['name' => 'Rex']);

        // The board draws a quest from what's eligible, and Home guards most of
        // the run on there being one.
        Chore::factory()->for($this->household)->count(6)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): void
    {
        $this->travelTo(Carbon::parse($time, $this->household->timezone));
    }

    private function window(): array
    {
        return app(StreakService::class)->streakWindowFor($this->kid);
    }

    private function wallTime(?Carbon $moment): ?string
    {
        return $moment?->copy()->setTimezone($this->household->timezone)->format('Y-m-d H:i');
    }

    private function claim(CompletionStatus $status): void
    {
        ChoreCompletion::create([
            'chore_id' => Chore::where('household_id', $this->household->id)->first()->id,
            'profile_id' => $this->kid->id,
            'status' => $status,
            'points_awarded' => $status === CompletionStatus::Approved ? 10 : 0,
            'submitted_at' => now(),
            'decided_at' => $status === CompletionStatus::Approved ? now() : null,
        ]);
    }

    /**
     * A run of $days signed-off days ending yesterday, plus the cached number
     * that goes with it. Both halves are needed: `profiles.streak` is a cache
     * and syncStreak() drops it to zero on every page load unless the days
     * behind it are actually there.
     */
    private function earnedRunEndingYesterday(int $days): void
    {
        $chore = Chore::where('household_id', $this->household->id)->first();

        for ($back = 1; $back <= $days; $back++) {
            $at = now()->copy()->subDays($back);

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

    public function test_the_countdown_points_at_bedtime_and_the_reset_stays_the_rollover(): void
    {
        $this->at('2026-05-01 20:00');

        $window = $this->window();

        $this->assertSame('2026-05-01 21:00', $this->wallTime($window['closesAt']));
        $this->assertSame('2026-05-02 04:00', $this->wallTime($window['resetsAt']));
        $this->assertFalse($window['overtime']);
    }

    public function test_a_half_hour_bedtime_is_kept(): void
    {
        $this->household->update(['bedtime' => '20:30']);
        $this->at('2026-05-01 19:00');

        $this->assertSame('2026-05-01 20:30', $this->wallTime($this->window()['closesAt']));
    }

    /**
     * The countdown **stops** at bedtime rather than re-pointing at the
     * rollover. Handing a kid who has just run out of evening a fresh six-hour
     * number is the "loads of time" feeling the whole timer exists to remove,
     * arriving at the worst possible moment for it. The day is still winnable
     * and `overtime` says so in words instead.
     */
    public function test_past_bedtime_the_countdown_stops_rather_than_re_pointing(): void
    {
        $this->at('2026-05-01 22:00');

        $window = $this->window();

        $this->assertTrue($window['overtime']);
        $this->assertNull($window['closesAt']);
        $this->assertSame('2026-05-02 04:00', $this->wallTime($window['resetsAt']));
    }

    /**
     * The small hours belong to the day that hasn't finished yet: 1am is still
     * last night's run to save, not a fresh day already three hours old.
     */
    public function test_after_midnight_but_before_the_boundary_still_counts_towards_yesterday(): void
    {
        $this->at('2026-05-02 01:00');

        $window = $this->window();

        $this->assertTrue($window['overtime']);
        $this->assertSame('2026-05-02 04:00', $this->wallTime($window['resetsAt']));
        $this->assertSame(180, (int) now()->utc()->diffInMinutes($window['resetsAt']));
    }

    public function test_the_boundary_hour_moves_the_reset_with_it(): void
    {
        $this->household->update(['day_boundary_hour' => 0]);
        $this->at('2026-05-01 22:00');

        $this->assertSame('2026-05-02 00:00', $this->wallTime($this->window()['resetsAt']));
    }

    /**
     * Switching bedtime off is the off switch for the *target*, not for the
     * timer: it goes back to counting the rollover, which is what it did before
     * bedtime existed.
     */
    public function test_a_household_with_no_bedtime_counts_to_the_rollover(): void
    {
        $this->household->update(['bedtime' => null]);
        $this->at('2026-05-01 22:00');

        $window = $this->window();

        $this->assertNull($window['bedtime']);
        $this->assertFalse($window['overtime']);
        $this->assertSame('2026-05-02 04:00', $this->wallTime($window['closesAt']));
    }

    public function test_a_claim_waiting_on_a_parent_stops_the_clock(): void
    {
        $this->at('2026-05-01 20:00');

        $this->assertFalse($this->window()['secured']);

        // Pending, deliberately: the kid has done their part, and a timer that
        // keeps running over a parent's inbox is blaming them for it.
        $this->claim(CompletionStatus::Pending);

        $this->assertTrue($this->window()['secured']);
    }

    public function test_an_approved_chore_secures_the_day(): void
    {
        $this->at('2026-05-01 20:00');
        $this->claim(CompletionStatus::Approved);

        $this->assertTrue($this->window()['secured']);
    }

    public function test_the_strip_turns_urgent_at_the_houses_evening_watch_hour(): void
    {
        $this->at('2026-05-01 18:59');
        $this->assertFalse($this->window()['urgent']);

        $this->at('2026-05-01 19:00');
        $this->assertTrue($this->window()['urgent']);
    }

    public function test_a_secured_day_is_neither_urgent_nor_overtime(): void
    {
        $this->at('2026-05-01 22:00');
        $this->claim(CompletionStatus::Pending);

        $window = $this->window();

        $this->assertTrue($window['secured']);
        $this->assertFalse($window['urgent']);
        $this->assertFalse($window['overtime']);
    }

    /**
     * An unusable watch hour is a legal row — the column is an unsigned tiny
     * integer and a model built in memory carries no value at all — and it must
     * mean "never draw anyone as at risk", not a crash on every kid page.
     */
    public function test_a_household_without_a_usable_watch_hour_is_never_urgent(): void
    {
        $this->household->update(['evening_watch_hour' => 200]);
        $this->at('2026-05-01 23:00');

        $this->assertFalse($this->window()['urgent']);
    }

    public function test_home_counts_down_to_bedtime_and_names_the_run(): void
    {
        $this->at('2026-05-01 12:00');
        $this->earnedRunEndingYesterday(6);

        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.home')
            ->assertSee('Keep your 6-day streak')
            ->assertSee('Get any chore signed off before bedtime at 9:00 PM and today counts towards your run.')
            ->assertSee('Until bedtime');
    }

    public function test_home_offers_a_first_streak_to_a_kid_who_has_none(): void
    {
        $this->at('2026-05-01 12:00');

        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.home')
            ->assertSee('Start a streak today')
            ->assertSee('before bedtime at 9:00 PM')
            ->assertDontSee('Keep your 0-day streak');
    }

    public function test_home_tells_a_kid_past_bedtime_that_the_day_is_still_winnable(): void
    {
        $this->at('2026-05-01 22:00');
        $this->earnedRunEndingYesterday(6);

        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.home')
            ->assertSee('Last chance for your 6-day streak')
            // Unescaped: this sentence is literal template text rather than an
            // interpolated string, so its apostrophes reach the page as typed.
            ->assertSee("Bedtime's been and gone.", escape: false)
            ->assertSee('before the day resets at 4:00 AM')
            // No number anywhere near it. A fresh six-hour countdown at half
            // nine is the false comfort the timer exists to remove.
            ->assertDontSee('Until bedtime')
            ->assertDontSee('Before it resets');
    }

    public function test_home_drops_the_countdown_once_the_day_is_in_the_bag(): void
    {
        $this->at('2026-05-01 12:00');
        $this->earnedRunEndingYesterday(6);
        $this->claim(CompletionStatus::Pending);

        Auth::guard('profile')->login($this->kid);

        // No timer over a day that is already won — the number would only be
        // something left to worry about.
        Volt::test('kid.home')
            ->assertSee("Today's in the bag")
            ->assertDontSee('Until bedtime')
            ->assertDontSee('Before it resets');
    }

    /**
     * The strip is Home's, in the streak section it explains. Everywhere else
     * the header tile is what carries the number — a second full strip at the
     * top of Quests was saying the same thing twice on the one page a kid is
     * least likely to be reading prose on.
     */
    public function test_the_quests_page_leaves_the_strip_to_home(): void
    {
        $this->at('2026-05-01 20:00');
        $this->earnedRunEndingYesterday(6);

        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.quests')
            ->assertDontSee("Don't lose your 6-day streak")
            // The header tile, which every kid page carries.
            ->assertSee('TILL BED');
    }

    public function test_the_header_tile_counts_down_and_links_to_the_streak_section(): void
    {
        $this->at('2026-05-01 20:00');
        $this->earnedRunEndingYesterday(6);

        Auth::guard('profile')->login($this->kid);

        // Every kid page, not just the two that draw the strip: the question
        // comes up while a kid is standing in the shop, not on Home.
        foreach (['kid.home', 'kid.quests', 'kid.loot', 'kid.badges'] as $page) {
            Volt::test($page)
                ->assertSee('TILL BED')
                ->assertSee(route('kid.home').'#streak');
        }
    }

    public function test_the_header_tile_shows_a_tick_once_the_day_is_secured(): void
    {
        $this->at('2026-05-01 20:00');
        $this->earnedRunEndingYesterday(6);
        $this->claim(CompletionStatus::Pending);

        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.loot')
            ->assertSee('STREAK SAFE')
            ->assertDontSee('TILL BED');
    }

    public function test_the_header_tile_stops_counting_past_bedtime(): void
    {
        $this->at('2026-05-01 22:00');
        $this->earnedRunEndingYesterday(6);

        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.loot')
            ->assertSee('PAST BED')
            ->assertDontSee('TILL BED');
    }
}
