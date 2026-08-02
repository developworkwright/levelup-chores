<?php

namespace Tests\Feature;

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

    public function test_the_parent_still_shows_console(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->parent()->for($household)->create(['name' => 'Parent']);

        Volt::test('login')
            ->assertSee('Console')
            ->assertDontSee('LVL');
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
