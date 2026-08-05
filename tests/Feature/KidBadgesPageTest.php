<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class KidBadgesPageTest extends TestCase
{
    use RefreshDatabase;

    private function loginKid(): Profile
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        return $kid;
    }

    public function test_it_describes_every_visible_badge(): void
    {
        $this->loginKid();

        Volt::test('kid.badges')
            ->assertSee('First Quest')
            ->assertSee('Clear your very first daily quest.')
            ->assertSee('Wheel Winner')
            ->assertSee('Land a 3x multiplier on the Bonus Wheel.')
            ->assertSee('Busy Bee')
            ->assertSee('Get four chores approved in a single day.');
    }

    public function test_it_keeps_an_unearned_secret_badge_secret(): void
    {
        $this->loginKid();

        $secret = Badge::where('key', 'early_bird')->firstOrFail();
        $this->assertTrue($secret->hidden, 'early_bird is expected to be a hidden badge');

        Volt::test('kid.badges')
            ->assertSee('???')
            ->assertSee('A secret badge')
            ->assertDontSee('Early Bird')
            ->assertDontSee($secret->description);
    }

    public function test_earning_a_secret_badge_reveals_its_description(): void
    {
        $kid = $this->loginKid();

        $secret = Badge::where('key', 'early_bird')->firstOrFail();
        $kid->badges()->attach($secret->id, ['earned_at' => now()]);

        Volt::test('kid.badges')
            ->assertSee('Early Bird')
            ->assertSee($secret->description);
    }

    public function test_it_counts_how_many_are_earned(): void
    {
        $kid = $this->loginKid();

        $total = Badge::count();
        $kid->badges()->attach(Badge::where('key', 'wheel_winner')->firstOrFail()->id, ['earned_at' => now()]);

        Volt::test('kid.badges')
            ->assertSee("1 / {$total} EARNED")
            ->assertSee('Earned');
    }

    public function test_every_badge_has_a_description(): void
    {
        // A badge added without one would render an empty card rather than
        // failing loudly, so assert the data itself.
        $this->assertSame(0, Badge::whereNull('description')->orWhere('description', '')->count());
    }

    public function test_migrations_alone_provide_every_badge(): void
    {
        // RefreshDatabase runs migrations without the seeder. Every key
        // BadgeService awards has to exist here, because maybeAward() looks
        // the badge up and quietly does nothing when it's missing — so a gap
        // shows up as an achievement that simply never fires.
        $expected = [
            'first_quest', 'streak_3', 'streak_7', 'streak_14', 'streak_30',
            'big_spender', 'big_saver', 'wheel_winner', 'busy_bee',
            'perfect_board', 'early_bird', 'night_owl', 'team_effort', 'speed_runner',
            'chores_10', 'chores_50', 'chores_100', 'chores_365',
            'quest_10', 'quest_50',
            'earner_1000', 'earner_5000', 'earner_20000',
            'level_10', 'level_25',
            'spin_25', 'triple_threat',
            'chest_7', 'chest_30',
            'first_reward', 'big_ticket',
            'dealmaker', 'trade_10', 'gadgeteer',
            'comeback_kid', 'weekend_warrior', 'overachiever', 'all_rounder',
        ];

        $this->assertSame([], array_diff($expected, Badge::pluck('key')->all()));
        $this->assertSame(count($expected), Badge::count(), 'No duplicate or stray badge rows.');
    }

    public function test_a_parent_cannot_open_the_kid_badges_page(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();

        Auth::guard('profile')->login($parent);

        Volt::test('kid.badges')->assertForbidden();
    }
}
