<?php

namespace Tests\Feature;

use App\Enums\CompletionStatus;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * A streak that lapsed overnight, and the window a Streak Restore has to save
 * it in. `profiles.streak` is a cache, so the point of most of these is that
 * the cached number stops lying the morning after a miss.
 */
class StreakDecayTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    private Profile $parent;

    private Chore $chore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create(['day_boundary_hour' => 4]);
        $this->parent = Profile::factory()->parent()->for($this->household)->create();
        $this->kid = Profile::factory()->for($this->household)->create();
        $this->chore = Chore::factory()->for($this->household)->create(['points' => 10]);
    }

    /** A cleared and approved quest on the given household day. */
    private function clearQuestOn(string $date): void
    {
        $at = Carbon::parse("{$date} 12:00", $this->household->timezone);

        $quest = DailyQuest::create([
            'household_id' => $this->household->id,
            'profile_id' => $this->kid->id,
            'chore_id' => $this->chore->id,
            'quest_date' => $date,
            'revealed_at' => $at,
            'completed_at' => $at,
        ]);

        $completion = ChoreCompletion::create([
            'chore_id' => $quest->chore_id,
            'profile_id' => $this->kid->id,
            'status' => CompletionStatus::Approved,
            'points_awarded' => 10,
            'submitted_at' => $at,
            'decided_at' => $at,
        ]);

        $this->assertSame(CompletionStatus::Approved, $completion->status);
    }

    private function onDay(string $date): void
    {
        $this->travelTo(Carbon::parse("{$date} 09:00", $this->household->timezone));
    }

    public function test_a_missed_day_expires_the_cached_streak(): void
    {
        // Three days cleared, then nothing on the 4th. The header carried the
        // stale 3 until an approval happened to refresh it.
        foreach (['2026-03-01', '2026-03-02', '2026-03-03'] as $day) {
            $this->clearQuestOn($day);
        }

        $this->kid->update(['streak' => 3]);
        $this->onDay('2026-03-05');

        app(ChoreService::class)->syncStreak($this->kid);

        $this->assertSame(0, $this->kid->refresh()->streak);
    }

    public function test_a_streak_survives_the_day_before_todays_quest_is_done(): void
    {
        // Mid-morning with today's quest still outstanding is not a lapse —
        // the chain is simply still anchored on yesterday.
        $this->clearQuestOn('2026-03-01');
        $this->clearQuestOn('2026-03-02');
        $this->kid->update(['streak' => 2]);

        $this->onDay('2026-03-03');

        app(ChoreService::class)->syncStreak($this->kid);

        $this->assertSame(2, $this->kid->refresh()->streak);
    }

    public function test_the_milestone_high_water_mark_survives_a_lapse(): void
    {
        // Otherwise a kid could let a streak die and re-earn every bonus.
        // Set outside the fillable list on purpose — the mark is not
        // mass-assignable, which is half of what protects it.
        $this->kid->streak = 7;
        $this->kid->streak_milestone_paid_through = 7;
        $this->kid->save();

        $this->onDay('2026-03-05');

        app(ChoreService::class)->syncStreak($this->kid);

        $this->kid->refresh();

        $this->assertSame(0, $this->kid->streak);
        $this->assertSame(7, $this->kid->streak_milestone_paid_through);
    }

    public function test_a_restore_offers_the_missed_day_and_what_it_buys_back(): void
    {
        foreach (['2026-03-01', '2026-03-02', '2026-03-03', '2026-03-04'] as $day) {
            $this->clearQuestOn($day);
        }

        // Missed the 5th; standing on the 6th with today's quest untouched.
        $this->onDay('2026-03-06');

        $preview = app(ChoreService::class)->repairPreview($this->kid);

        $this->assertNotNull($preview);
        $this->assertSame('2026-03-05', $preview['date']->toDateString());
        $this->assertSame(5, $preview['restoresTo']);
    }

    public function test_clearing_todays_quest_closes_the_restore_window(): void
    {
        foreach (['2026-03-01', '2026-03-02', '2026-03-03'] as $day) {
            $this->clearQuestOn($day);
        }

        $this->onDay('2026-03-05');

        $service = app(ChoreService::class);
        $this->assertNotNull($service->repairableStreakDate($this->kid));

        // Today's quest goes in, which starts a fresh chain of one — the
        // broken day is now behind a live streak and no longer savable.
        $service->claimQuest($this->kid);

        $this->assertNull($service->repairableStreakDate($this->kid));
        $this->assertNull($service->repairPreview($this->kid));
        $this->assertNull($service->repairStreak($this->kid));
    }

    public function test_several_missed_days_cannot_be_bought_back(): void
    {
        // A restore buys one day. Two days gone means the chain is over, and
        // repairing yesterday alone would manufacture a one-day streak.
        $this->clearQuestOn('2026-03-01');
        $this->clearQuestOn('2026-03-02');

        $this->onDay('2026-03-05');

        $service = app(ChoreService::class);

        $this->assertNull($service->repairableStreakDate($this->kid));
        $this->assertNull($service->repairPreview($this->kid));
    }

    public function test_a_restore_used_in_time_rebuilds_the_whole_chain(): void
    {
        foreach (['2026-03-01', '2026-03-02', '2026-03-03'] as $day) {
            $this->clearQuestOn($day);
        }

        $this->kid->update(['streak' => 3]);
        $this->onDay('2026-03-05');

        $service = app(ChoreService::class);
        $service->syncStreak($this->kid);
        $this->assertSame(0, $this->kid->refresh()->streak);

        $service->repairStreak($this->kid);

        // Four days: the three cleared, plus the bought-back 4th.
        $this->assertSame(4, $this->kid->refresh()->streak);
    }

    public function test_the_quests_page_shows_the_rescue_offer_and_what_it_restores(): void
    {
        foreach (['2026-03-01', '2026-03-02', '2026-03-03'] as $day) {
            $this->clearQuestOn($day);
        }

        $this->kid->update(['streak' => 3]);
        $this->onDay('2026-03-05');

        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('Streak Rescue')
            ->assertSee('Mar 4, 2026')
            ->assertSee('4-day streak')
            ->assertSee('Use it before you clear');
    }
}
