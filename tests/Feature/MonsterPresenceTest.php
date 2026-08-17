<?php

namespace Tests\Feature;

use App\Enums\MonsterTier;
use App\Models\Chore;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\Profile;
use App\Services\HouseholdClock;
use App\Services\MonsterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * How the three tiers are told apart by eye.
 *
 * All three wear the same catalogue of faces, so without this a level 1 ice
 * cream monster has exactly the presence of the weekend away — which makes the
 * choice between them a reading exercise rather than a reaction.
 */
class MonsterPresenceTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        Chore::factory()->for($this->household)->create(['points' => 100]);

        Auth::guard('profile')->login(Profile::factory()->for($this->household)->create());
    }

    private function spawnAll(): void
    {
        foreach (MonsterTier::cases() as $tier) {
            app(MonsterService::class)->spawn($this->household, $tier, "Reward {$tier->value}", 1000);
        }
    }

    public function test_the_weather_thickens_with_the_tier(): void
    {
        $this->assertLessThan(MonsterTier::Two->dread(), MonsterTier::One->dread());
        $this->assertLessThan(MonsterTier::Three->dread(), MonsterTier::Two->dread());
    }

    public function test_only_the_biggest_monster_overflows_its_frame(): void
    {
        $this->assertSame(1.0, MonsterTier::One->artZoom());
        $this->assertGreaterThan(1.0, MonsterTier::Three->artZoom());
    }

    public function test_the_health_bar_is_cut_into_more_pieces_as_the_tier_rises(): void
    {
        $this->assertSame(1, MonsterTier::One->healthSegments());
        $this->assertSame(2, MonsterTier::Two->healthSegments());
        $this->assertSame(4, MonsterTier::Three->healthSegments());
    }

    public function test_the_arena_draws_each_tier_at_its_own_weather_and_scale(): void
    {
        $this->spawnAll();

        $html = Volt::test('kid.arena')->assertOk()->html();

        foreach (MonsterTier::cases() as $tier) {
            $this->assertStringContainsString((string) $tier->dread(), $html);
            $this->assertStringContainsString('scale('.$tier->artZoom().')', $html);
        }
    }

    public function test_the_long_game_gets_the_ornate_frame_and_the_widest_card(): void
    {
        $this->spawnAll();

        $html = Volt::test('kid.arena')->assertOk()->html();

        $this->assertStringContainsString('fq-frame-ornate', $html);
        $this->assertStringContainsString(MonsterTier::Three->cardBasis(), $html);
        $this->assertStringContainsString(MonsterTier::Three->epithet(), $html);
    }

    public function test_the_smaller_tiers_stay_plain(): void
    {
        app(MonsterService::class)->spawn($this->household, MonsterTier::One, 'Ice cream', 500);

        $html = Volt::test('kid.arena')->assertOk()->html();

        $this->assertStringNotContainsString('fq-frame-ornate', $html);
        $this->assertNull(MonsterTier::One->epithet());
    }

    public function test_the_picker_thumbnails_are_not_cropped(): void
    {
        $this->spawnAll();

        // A cropped thumbnail is just a blurry one, and the picker is a row of
        // them — the tier still reads off the label and the reward.
        $html = Volt::test('kid.quests')->assertOk()->html();

        $this->assertStringNotContainsString('scale('.MonsterTier::Three->artZoom().')', $html);
    }

    public function test_the_picker_fits_a_phone_and_can_be_scrolled_if_it_does_not(): void
    {
        $this->spawnAll();

        // The gate and the quest both have to be out of the way, or the tap
        // never reaches the picker. The quest is pinned rather than left to
        // draw itself: it picks at random, and a day it lands on the chore
        // under test is a day this fails for nothing to do with layout.
        $this->household->update(['require_quest_first' => false]);
        $chore = Chore::factory()->for($this->household)->create(['name' => 'Vacuum', 'points' => 100, 'min_age' => null]);

        DailyQuest::create([
            'household_id' => $this->household->id,
            'profile_id' => Auth::guard('profile')->id(),
            'chore_id' => Chore::where('household_id', $this->household->id)->where('id', '!=', $chore->id)->first()->id,
            'quest_date' => HouseholdClock::for($this->household)->today()->toDateString(),
            'revealed_at' => now(),
        ]);

        $html = Volt::test('kid.quests')
            ->call('claimChore', $chore->id)
            ->assertSee('Who takes the hit?')
            ->html();

        // Compact cards, so three of them stack inside a phone screen rather
        // than overflowing it.
        $this->assertStringContainsString('w-[74px]', $html);

        // And when they do overflow — a small screen, a long reward name — the
        // scroller is the plain fixed block, not the flex container. A `fixed`
        // element that both centres and scrolls strands whatever `align-items`
        // pushes past its top edge, which is exactly how this opened showing
        // the last card with the heading unreachable above it.
        $this->assertStringNotContainsString('fixed inset-0 z-50 flex', $html);
        $this->assertStringContainsString('flex min-h-full items-end justify-center', $html);
    }

    public function test_the_strip_names_the_level_of_every_monster(): void
    {
        $this->spawnAll();

        $strip = Volt::test('kid.quests')->assertOk();

        // Without this the strip is three peers: same art size, same bar, and
        // nothing anywhere saying which is the small one.
        foreach (MonsterTier::cases() as $tier) {
            $strip->assertSee($tier->badge());
        }
    }

    public function test_the_strip_shows_each_monsters_health_total(): void
    {
        app(MonsterService::class)->spawn($this->household, MonsterTier::One, 'Ice cream', 500);
        app(MonsterService::class)->spawn($this->household, MonsterTier::Three, 'Weekend away', 8000);

        // Untouched, both read 100% — the totals are the only thing separating
        // them at a glance.
        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('500 HP')
            ->assertSee('8,000 HP');
    }

    public function test_the_strip_grows_down_the_ladder(): void
    {
        $this->spawnAll();

        $html = Volt::test('kid.quests')->assertOk()->html();

        foreach (MonsterTier::cases() as $tier) {
            $this->assertStringContainsString($tier->stripArtWidth(), $html);
            $this->assertStringContainsString($tier->stripBarHeight(), $html);
        }

        // The ladder has to actually climb, not merely differ.
        $this->assertSame(
            ['w-[40px]', 'w-[52px]', 'w-[66px]'],
            array_map(fn (MonsterTier $tier) => $tier->stripArtWidth(), MonsterTier::cases()),
        );
    }

    public function test_the_strip_draws_each_tier_at_its_own_weather(): void
    {
        $this->spawnAll();

        $html = Volt::test('kid.quests')->assertOk()->html();

        // Every row passes its own dread rather than the one shared default the
        // strip used to hand all three.
        foreach (MonsterTier::cases() as $tier) {
            $this->assertStringContainsString((string) $tier->dread(), $html);
        }
    }

    public function test_the_long_game_watches_the_quest_board(): void
    {
        $this->spawnAll();

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('fq-watcher', false);
    }

    public function test_nothing_watches_when_the_long_game_is_empty(): void
    {
        app(MonsterService::class)->spawn($this->household, MonsterTier::One, 'Ice cream', 500);

        Volt::test('kid.quests')
            ->assertOk()
            ->assertDontSee('fq-watcher', false);
    }

    public function test_the_watcher_is_hidden_from_screen_readers_and_untouchable(): void
    {
        $this->spawnAll();

        $html = Volt::test('kid.quests')->assertOk()->html();

        // It is a mood rather than an element of the page: anything that reads
        // it out or lets it be tapped has made it part of the furniture.
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('isolate', $html);
    }
}
