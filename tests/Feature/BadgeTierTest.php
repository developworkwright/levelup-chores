<?php

namespace Tests\Feature;

use App\Enums\BadgeTier;
use App\Models\Badge;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BadgeTierTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tier_climbs_with_the_xp_reward(): void
    {
        $this->assertSame(BadgeTier::Silver, BadgeTier::fromXp(0));
        $this->assertSame(BadgeTier::Silver, BadgeTier::fromXp(BadgeTier::GOLD_XP - 1));
        $this->assertSame(BadgeTier::Gold, BadgeTier::fromXp(BadgeTier::GOLD_XP));
        $this->assertSame(BadgeTier::Gold, BadgeTier::fromXp(BadgeTier::RAINBOW_XP - 1));
        $this->assertSame(BadgeTier::Rainbow, BadgeTier::fromXp(BadgeTier::RAINBOW_XP));
        $this->assertSame(BadgeTier::Rainbow, BadgeTier::fromXp(10_000));
    }

    public function test_only_the_top_tier_animates(): void
    {
        $this->assertFalse(BadgeTier::Silver->isAnimated());
        $this->assertFalse(BadgeTier::Gold->isAnimated());
        $this->assertTrue(BadgeTier::Rainbow->isAnimated());
    }

    public function test_the_shipped_badges_land_across_all_three_tiers(): void
    {
        // A tier nobody can reach is a tier that may as well not exist, and a
        // rainbow that half the board qualifies for stops being a prize.
        $tiers = Badge::all()->groupBy(fn (Badge $badge) => $badge->tier()->value);

        $this->assertGreaterThan(0, $tiers->get('silver')?->count());
        $this->assertGreaterThan(0, $tiers->get('gold')?->count());
        $this->assertGreaterThan(0, $tiers->get('rainbow')?->count());
        $this->assertLessThan(
            Badge::count() / 4,
            $tiers->get('rainbow')->count(),
            'The rainbow tier should stay rare.'
        );
    }

    public function test_the_hardest_badges_are_the_rainbow_ones(): void
    {
        $rainbow = Badge::all()->filter(fn (Badge $badge) => $badge->tier() === BadgeTier::Rainbow);
        $rest = Badge::all()->reject(fn (Badge $badge) => $badge->tier() === BadgeTier::Rainbow);

        $this->assertGreaterThan($rest->max('xp_reward'), $rainbow->min('xp_reward'));
    }

    public function test_the_badges_page_labels_each_tier(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Auth::guard('profile')->login($kid);

        $rainbow = Badge::all()->first(fn (Badge $badge) => $badge->tier() === BadgeTier::Rainbow);
        $kid->badges()->attach($rainbow->id, ['earned_at' => now()]);

        Volt::test('kid.badges')
            ->assertOk()
            ->assertSee('SILVER · ')
            ->assertSee('GOLD · ')
            ->assertSee('RAINBOW · '.$rainbow->xp_reward.' XP')
            ->assertDontSee('AMETHYST');
    }

    public function test_an_earned_rainbow_badge_gets_the_moving_treatment(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Auth::guard('profile')->login($kid);

        $rainbow = Badge::all()->first(fn (Badge $badge) => $badge->tier() === BadgeTier::Rainbow);
        $kid->badges()->attach($rainbow->id, ['earned_at' => now()]);

        // The animated classes carry no inline colour, which is the whole
        // point — an inline one would outrank the keyframes and freeze the
        // drift on whichever hue it happened to start from.
        Volt::test('kid.badges')
            ->assertOk()
            ->assertSee('fq-rainbow-star')
            ->assertSee('fq-rainbow-ring')
            ->assertSee('fq-rainbow-ink')
            ->assertSee('background-size: 300% 100%');
    }

    public function test_an_unearned_rainbow_badge_stays_still(): void
    {
        // A locked badge is a flat, dim star whatever it's worth — the drift
        // is the reward, so nothing should move until it's won.
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Auth::guard('profile')->login($kid);

        $this->assertTrue(
            Badge::all()->contains(fn (Badge $badge) => $badge->tier() === BadgeTier::Rainbow),
            'A rainbow badge has to exist for this to be worth asserting.'
        );

        Volt::test('kid.badges')
            ->assertOk()
            ->assertDontSee('fq-rainbow-star')
            ->assertDontSee('fq-rainbow-ring')
            ->assertDontSee('fq-rainbow-ink')
            ->assertDontSee('background-size');
    }

    public function test_a_gold_badge_keeps_its_flat_colours(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        Auth::guard('profile')->login($kid);

        $gold = Badge::all()->first(fn (Badge $badge) => $badge->tier() === BadgeTier::Gold);
        $kid->badges()->attach($gold->id, ['earned_at' => now()]);

        Volt::test('kid.badges')
            ->assertOk()
            ->assertSee('var(--fq-tier-gold-ring)')
            ->assertDontSee('fq-rainbow-star');
    }
}
