<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\MonsterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * How much of itself the fight puts on the pages a kid actually lives on.
 *
 * Most of this file used to be about telling three tiers apart by eye — art
 * zoom, bar segments, an ornate frame on the long game — because three monsters
 * wearing the same catalogue of faces read as three peers. There is one now, so
 * the only question left is whether it is present enough to be worth clearing
 * the board for.
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

    private function spawn(string $reward = 'Weekend away', int $health = 1000): void
    {
        app(MonsterService::class)->spawn($this->household, $reward, $health);
    }

    public function test_the_strip_names_what_the_monster_is_guarding(): void
    {
        $this->spawn('Weekend away');

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('Boss Fight')
            ->assertSee('Weekend away');
    }

    public function test_the_strip_shows_the_health_total_not_just_a_percentage(): void
    {
        $this->spawn('Weekend away', 8000);

        // Untouched it reads 100%, which says nothing about whether this is a
        // week's work or an afternoon's.
        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('8,000 HP');
    }

    public function test_the_strip_stays_off_the_board_when_nothing_stands(): void
    {
        Volt::test('kid.quests')
            ->assertOk()
            ->assertDontSee('Boss Fight');
    }

    public function test_the_monster_watches_the_quest_board(): void
    {
        $this->spawn();

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('fq-watcher', false);
    }

    public function test_nothing_watches_when_the_arena_is_empty(): void
    {
        Volt::test('kid.quests')
            ->assertOk()
            ->assertDontSee('fq-watcher', false);
    }

    public function test_the_watcher_is_hidden_from_screen_readers_and_untouchable(): void
    {
        $this->spawn();

        $html = Volt::test('kid.quests')->assertOk()->html();

        // It is a mood rather than an element of the page: anything that reads
        // it out or lets it be tapped has made it part of the furniture.
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('isolate', $html);
    }
}
