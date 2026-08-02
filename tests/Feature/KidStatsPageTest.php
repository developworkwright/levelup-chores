<?php

namespace Tests\Feature;

use App\Enums\CompletionStatus;
use App\Enums\LedgerKind;
use App\Enums\RedemptionStatus;
use App\Models\Badge;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\LedgerEntry;
use App\Models\Profile;
use App\Models\Redemption;
use App\Models\Spin;
use App\Models\StoreItem;
use App\Models\StreakRepair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class KidStatsPageTest extends TestCase
{
    use RefreshDatabase;

    private function loginKid(Household $household, array $attributes = []): Profile
    {
        $kid = Profile::factory()->for($household)->create($attributes);
        Auth::guard('profile')->login($kid);

        return $kid;
    }

    private function approve(Profile $kid, Chore $chore, Carbon $submittedAt, int $points = 100): ChoreCompletion
    {
        return ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $kid->id,
            'status' => CompletionStatus::Approved,
            'points_awarded' => $points,
            'submitted_at' => $submittedAt,
            'decided_at' => $submittedAt,
        ]);
    }

    public function test_it_totals_chores_points_and_active_days(): void
    {
        $household = Household::factory()->create();
        $kid = $this->loginKid($household);
        $chore = Chore::factory()->for($household)->create(['name' => 'Dishes']);

        $this->approve($kid, $chore, now()->subDays(2), 120);
        $this->approve($kid, $chore, now()->subDays(2), 80);
        $this->approve($kid, $chore, now(), 100);

        LedgerEntry::create([
            'household_id' => $household->id,
            'profile_id' => $kid->id,
            'kind' => LedgerKind::Earn,
            'amount' => 300,
            'description' => 'Chores',
        ]);
        LedgerEntry::create([
            'household_id' => $household->id,
            'profile_id' => $kid->id,
            'kind' => LedgerKind::Spend,
            'amount' => -250,
            'description' => 'Movie night',
        ]);

        Volt::test('kid.stats')
            ->assertOk()
            ->assertSee('Chores done')
            ->assertSee('300')
            ->assertSee('≈ $3.00 all time')
            ->assertSee('250')
            // Two of the three chores landed on the same day.
            ->assertSee('days with a chore done');
    }

    public function test_it_splits_chores_done_into_today_this_week_and_lifetime(): void
    {
        $household = Household::factory()->create();
        $kid = $this->loginKid($household);
        $chore = Chore::factory()->for($household)->create();

        // 1 today, 2 more inside the last seven days, 1 well outside it.
        $this->approve($kid, $chore, now(), 40);
        $this->approve($kid, $chore, now()->subDays(2), 30);
        $this->approve($kid, $chore, now()->subDays(6), 30);
        $this->approve($kid, $chore, now()->subDays(20), 60);

        $html = Volt::test('kid.stats')->assertOk()->html();

        $this->assertMatchesRegularExpression('/>\s*1\s*<.*?Today/s', $html);
        $this->assertMatchesRegularExpression('/>\s*3\s*<.*?Last 7 days/s', $html);
        $this->assertMatchesRegularExpression('/>\s*4\s*<.*?Lifetime/s', $html);
        $this->assertStringContainsString('160 pts from chores', $html);
    }

    public function test_todays_count_ends_at_the_household_day_boundary(): void
    {
        // Claimed at 2am, which the household clock still calls yesterday — so
        // "Today" has to read 0 even though the calendar date matches.
        $household = Household::factory()->create(['day_boundary_hour' => 4]);
        $kid = $this->loginKid($household);
        $chore = Chore::factory()->for($household)->create();

        $this->travelTo(Carbon::parse('2026-03-11 09:00', $household->timezone));
        $this->approve($kid, $chore, Carbon::parse('2026-03-11 02:00', $household->timezone));

        $html = Volt::test('kid.stats')->assertOk()->html();

        $this->assertMatchesRegularExpression('/>\s*0\s*<.*?Today/s', $html);
        $this->assertMatchesRegularExpression('/>\s*1\s*<.*?Last 7 days/s', $html);
    }

    public function test_it_ranks_the_most_done_chores(): void
    {
        $household = Household::factory()->create();
        $kid = $this->loginKid($household);

        $dishes = Chore::factory()->for($household)->create(['name' => 'Dishes']);
        $trash = Chore::factory()->for($household)->create(['name' => 'Take out trash']);

        $this->approve($kid, $dishes, now(), 50);
        $this->approve($kid, $dishes, now()->subDay(), 50);
        $this->approve($kid, $dishes, now()->subDays(2), 50);
        $this->approve($kid, $trash, now(), 75);

        $rendered = Volt::test('kid.stats')->assertOk();

        $rendered->assertSee('Most done')
            ->assertSee('Dishes')
            ->assertSee('Take out trash')
            ->assertSee('×3')
            ->assertSee('150 pts');

        $html = $rendered->html();
        $this->assertLessThan(
            strpos($html, 'Take out trash'),
            strpos($html, 'Dishes'),
            'The chore done most often should be listed first.'
        );
    }

    public function test_only_approved_chores_count(): void
    {
        $household = Household::factory()->create();
        $kid = $this->loginKid($household);
        $chore = Chore::factory()->for($household)->create(['name' => 'Vacuum']);

        $this->approve($kid, $chore, now(), 100);

        foreach ([CompletionStatus::Pending, CompletionStatus::Rejected] as $status) {
            ChoreCompletion::create([
                'chore_id' => $chore->id,
                'profile_id' => $kid->id,
                'status' => $status,
                'points_awarded' => 100,
                'submitted_at' => now(),
            ]);
        }

        Volt::test('kid.stats')
            ->assertOk()
            ->assertSee('×1')
            ->assertSee('1 chore');
    }

    public function test_another_kids_chores_stay_out_of_the_totals(): void
    {
        $household = Household::factory()->create();
        $sibling = Profile::factory()->for($household)->create(['name' => 'Alex']);
        $kid = $this->loginKid($household, ['name' => 'Sam']);

        $mine = Chore::factory()->for($household)->create(['name' => 'My Chore']);
        $theirs = Chore::factory()->for($household)->create(['name' => 'Their Chore']);

        $this->approve($kid, $mine, now(), 100);
        $this->approve($sibling, $theirs, now(), 100);

        Volt::test('kid.stats')
            ->assertOk()
            ->assertSee('My Chore')
            ->assertDontSee('Their Chore');
    }

    public function test_the_best_day_follows_the_household_day_boundary(): void
    {
        // A chore claimed at 1am belongs to the previous household day, so it
        // has to stack with that day's chores rather than starting a new one.
        $household = Household::factory()->create(['day_boundary_hour' => 4]);
        $kid = $this->loginKid($household);
        $chore = Chore::factory()->for($household)->create(['name' => 'Dishes']);

        $evening = Carbon::parse('2026-03-10 20:00', $household->timezone);

        $this->approve($kid, $chore, $evening);
        $this->approve($kid, $chore, $evening->copy()->addHours(5));

        Volt::test('kid.stats')
            ->assertOk()
            ->assertSee('2 chores')
            ->assertSee('Mar 10, 2026');
    }

    public function test_it_records_the_best_day_and_the_longest_streak(): void
    {
        $household = Household::factory()->create();
        $kid = $this->loginKid($household, ['streak' => 1]);
        $chore = Chore::factory()->for($household)->create();

        // A three-chore day, then a busier four-chore day it has to beat.
        $good = Carbon::parse('2026-03-02 18:00', $household->timezone);
        $best = Carbon::parse('2026-03-05 18:00', $household->timezone);

        foreach (range(1, 3) as $ignored) {
            $this->approve($kid, $chore, $good);
        }

        foreach (range(1, 4) as $ignored) {
            $this->approve($kid, $chore, $best);
        }

        // Quests cleared on the 1st–4th, nothing on the 5th, then the 6th —
        // a four-day run followed by a one-day one.
        foreach (['03-01', '03-02', '03-03', '03-04', '03-06'] as $day) {
            DailyQuest::create([
                'household_id' => $household->id,
                'profile_id' => $kid->id,
                'chore_id' => $chore->id,
                'quest_date' => "2026-{$day}",
                'completed_at' => now(),
            ]);
        }

        Volt::test('kid.stats')
            ->assertOk()
            ->assertSee('Best day')
            ->assertSee('4 chores')
            ->assertSee('Mar 5, 2026')
            ->assertSee('Longest streak')
            ->assertSee('4d');
    }

    public function test_a_repaired_day_keeps_the_longest_streak_alive(): void
    {
        // A streak repair buys back a missed day for the running streak, so the
        // record has to treat it the same way rather than splitting the run.
        $household = Household::factory()->create();
        $kid = $this->loginKid($household);
        $chore = Chore::factory()->for($household)->create();

        foreach (['2026-04-01', '2026-04-02', '2026-04-04', '2026-04-05'] as $day) {
            DailyQuest::create([
                'household_id' => $household->id,
                'profile_id' => $kid->id,
                'chore_id' => $chore->id,
                'quest_date' => $day,
                'completed_at' => now(),
            ]);
        }

        StreakRepair::create(['profile_id' => $kid->id, 'repaired_date' => '2026-04-03']);

        Volt::test('kid.stats')
            ->assertOk()
            ->assertSee('Longest streak')
            ->assertSee('5d');
    }

    public function test_it_reports_the_quest_clear_rate(): void
    {
        $household = Household::factory()->create();
        $kid = $this->loginKid($household);
        $chore = Chore::factory()->for($household)->create();

        foreach (range(1, 4) as $daysAgo) {
            DailyQuest::create([
                'household_id' => $household->id,
                'profile_id' => $kid->id,
                'chore_id' => $chore->id,
                'quest_date' => now()->subDays($daysAgo)->toDateString(),
                'completed_at' => $daysAgo <= 3 ? now()->subDays($daysAgo) : null,
            ]);
        }

        Volt::test('kid.stats')
            ->assertOk()
            ->assertSee('Quests cleared')
            ->assertSee('75% of 4 handed out');
    }

    public function test_it_reports_wheel_spins_badges_and_cash_outs(): void
    {
        $household = Household::factory()->create();
        $kid = $this->loginKid($household, ['streak' => 5, 'xp' => 450]);
        $chore = Chore::factory()->for($household)->create();
        $item = StoreItem::factory()->for($household)->create(['cost' => 500]);

        Spin::create(['profile_id' => $kid->id, 'spin_date' => now()->subDay()->toDateString(), 'chore_id' => $chore->id, 'multiplier' => 2]);
        Spin::create(['profile_id' => $kid->id, 'spin_date' => now()->toDateString(), 'chore_id' => $chore->id, 'multiplier' => 3]);

        Redemption::create([
            'store_item_id' => $item->id,
            'profile_id' => $kid->id,
            'cost_snapshot' => 500,
            'status' => RedemptionStatus::Pending,
            'requested_at' => now(),
        ]);

        $kid->badges()->attach(Badge::where('key', 'wheel_winner')->firstOrFail()->id, ['earned_at' => now()]);

        Volt::test('kid.stats')
            ->assertOk()
            ->assertSee('1 landed 3×')
            ->assertSee('biggest: 500 pts')
            ->assertSee('1 / '.Badge::count())
            ->assertSee('LVL 3')
            ->assertSee('450 XP total')
            ->assertSee('5d');
    }

    public function test_a_brand_new_kid_sees_an_empty_board_not_an_error(): void
    {
        $household = Household::factory()->create();
        $this->loginKid($household);

        Volt::test('kid.stats')
            ->assertOk()
            ->assertSee('Nothing approved yet.')
            ->assertSee('none handed out yet')
            ->assertSee('still to come')
            ->assertSee('nothing cashed out yet');
    }

    public function test_a_parent_cannot_open_the_kid_stats_page(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();

        Auth::guard('profile')->login($parent);

        Volt::test('kid.stats')->assertForbidden();
    }
}
