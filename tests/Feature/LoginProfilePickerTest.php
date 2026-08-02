<?php

namespace Tests\Feature;

use App\Enums\AccentColor;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class LoginProfilePickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_a_kids_level_rather_than_their_age(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create([
            'name' => 'Nova',
            'age' => 12,
            'xp' => Profile::XP_PER_LEVEL * 3,
        ]);

        Volt::test('login')
            ->assertSee('Nova')
            ->assertSee('LVL 4')
            ->assertDontSee('Age 12');
    }

    public function test_a_brand_new_kid_shows_level_one(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create(['name' => 'Scout', 'xp' => 0]);

        Volt::test('login')->assertSee('LVL 1');
    }

    public function test_each_tile_wears_the_kids_streak(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create(['name' => 'Nova', 'streak' => 4]);

        Volt::test('login')->assertSee('4d');
    }

    public function test_the_parent_is_a_console_link_rather_than_an_avatar_tile(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->parent()->for($household)->create(['name' => 'Rowan']);

        // The console is a door for grown-ups, not one of the avatars to pick —
        // so it sits below the row under a neutral label, and a parent gets no
        // level, streak or XP bar of their own.
        Volt::test('login')
            ->assertSee('Grown-ups')
            ->assertSee('Parent console')
            ->assertDontSee('Rowan')
            ->assertDontSee('LVL');
    }

    public function test_several_parents_are_each_named_so_they_can_be_told_apart(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->parent()->for($household)->create(['name' => 'Rowan']);
        Profile::factory()->parent()->for($household)->create(['name' => 'Sage']);

        // "Grown-ups" is only unambiguous while there's one of them.
        Volt::test('login')
            ->assertSee('Rowan')
            ->assertSee('Sage')
            ->assertDontSee('Grown-ups');
    }

    public function test_the_lilac_kid_is_dealt_into_the_middle_of_the_row(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create(['name' => 'Alder', 'age' => 13, 'color' => AccentColor::Cyan]);
        Profile::factory()->for($household)->create(['name' => 'Birch', 'age' => 8, 'color' => AccentColor::Lime]);
        Profile::factory()->for($household)->create(['name' => 'Cedar', 'age' => 6, 'color' => AccentColor::Gold]);

        $html = Volt::test('login')->html();

        // Plain oldest-first would seat Lime next to Gold, and the two yellows
        // read as one wide tile. Pulling the lilac kid into the middle splits
        // them: Birch, Alder, Cedar.
        $this->assertLessThan(strpos($html, 'Alder'), strpos($html, 'Birch'));
        $this->assertLessThan(strpos($html, 'Cedar'), strpos($html, 'Alder'));
    }

    public function test_a_lone_kid_sits_straight(): void
    {
        // The fan divides by one less than the count, so a single tile is the
        // case that would blow up on a naive implementation.
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create(['name' => 'Nova']);

        Volt::test('login')
            ->assertOk()
            ->assertSee('--fq-tilt: 0deg', false);
    }

    public function test_age_is_still_stored_because_chore_gating_needs_it(): void
    {
        // Only the label changed — min_age gating reads this column, so it
        // has to survive being taken off the picker.
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['age' => 9]);

        $this->assertSame(9, $kid->refresh()->age);
    }
}
