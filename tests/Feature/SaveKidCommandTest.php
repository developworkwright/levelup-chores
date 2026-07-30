<?php

namespace Tests\Feature;

use App\Enums\AccentColor;
use App\Enums\ProfileRole;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SaveKidCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_kid_profile(): void
    {
        $household = Household::factory()->create();

        $this->artisan('kid:save', ['name' => 'Nova', '--age' => 12, '--pin' => '1234'])
            ->assertSuccessful();

        $kid = Profile::where('name', 'Nova')->firstOrFail();

        $this->assertSame($household->id, $kid->household_id);
        $this->assertSame(ProfileRole::Kid, $kid->role);
        $this->assertSame(12, $kid->age);
        $this->assertTrue(Hash::check('1234', $kid->pin_hash));
    }

    public function test_it_updates_the_age_of_an_existing_kid(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova', 'age' => 12]);

        $this->artisan('kid:save', ['name' => 'Nova', '--age' => 13])
            ->assertSuccessful();

        $this->assertSame(13, $kid->refresh()->age);
        // Updating shouldn't quietly mint a second profile for the same kid.
        $this->assertSame(1, Profile::where('name', 'Nova')->count());
    }

    public function test_it_matches_an_existing_kid_regardless_of_case(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova', 'age' => 12]);

        $this->artisan('kid:save', ['name' => 'nOvA', '--age' => 13])
            ->assertSuccessful();

        $this->assertSame(13, $kid->refresh()->age);
        $this->assertSame(1, Profile::count());
    }

    public function test_updating_without_a_pin_leaves_the_existing_one_alone(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $kid->setPin('1234');
        $kid->save();

        $this->artisan('kid:save', ['name' => 'Nova', '--age' => 13])
            ->assertSuccessful();

        $this->assertTrue(Hash::check('1234', $kid->refresh()->pin_hash));
    }

    public function test_changing_the_pin_clears_a_standing_lockout(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create([
            'name' => 'Nova',
            'failed_pin_attempts' => 5,
            'locked_until' => now()->addHour(),
        ]);

        $this->artisan('kid:save', ['name' => 'Nova', '--pin' => '9999'])
            ->assertSuccessful();

        $kid->refresh();
        $this->assertSame(0, $kid->failed_pin_attempts);
        $this->assertNull($kid->locked_until);
        $this->assertFalse($kid->isLocked());
    }

    public function test_it_assigns_an_unused_color_when_none_is_given(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create(['color' => AccentColor::Lime]);

        $this->artisan('kid:save', ['name' => 'Nova', '--age' => 12, '--pin' => '1234'])
            ->assertSuccessful();

        $this->assertNotSame(AccentColor::Lime, Profile::where('name', 'Nova')->firstOrFail()->color);
    }

    public function test_it_accepts_an_explicit_color(): void
    {
        Household::factory()->create();

        $this->artisan('kid:save', ['name' => 'Nova', '--age' => 12, '--pin' => '1234', '--color' => 'violet'])
            ->assertSuccessful();

        $this->assertSame(AccentColor::Violet, Profile::where('name', 'Nova')->firstOrFail()->color);
    }

    public function test_it_rejects_the_reserved_parent_color(): void
    {
        Household::factory()->create();

        $this->artisan('kid:save', ['name' => 'Nova', '--age' => 12, '--pin' => '1234', '--color' => 'parent'])
            ->assertFailed();

        $this->assertSame(0, Profile::where('name', 'Nova')->count());
    }

    public function test_it_rejects_an_out_of_range_age(): void
    {
        Household::factory()->create();

        $this->artisan('kid:save', ['name' => 'Nova', '--age' => 0, '--pin' => '1234'])
            ->assertFailed();

        $this->artisan('kid:save', ['name' => 'Nova', '--age' => 99, '--pin' => '1234'])
            ->assertFailed();

        $this->assertSame(0, Profile::where('name', 'Nova')->count());
    }

    public function test_it_rejects_a_pin_that_is_not_four_digits(): void
    {
        Household::factory()->create();

        $this->artisan('kid:save', ['name' => 'Nova', '--age' => 12, '--pin' => '12'])
            ->assertFailed();

        $this->assertSame(0, Profile::where('name', 'Nova')->count());
    }

    public function test_it_requires_an_age_when_creating(): void
    {
        Household::factory()->create();

        $this->artisan('kid:save', ['name' => 'Nova', '--pin' => '1234'])
            ->assertFailed();

        $this->assertSame(0, Profile::where('name', 'Nova')->count());
    }

    public function test_it_falls_back_to_the_default_pin_when_creating(): void
    {
        Household::factory()->create();

        $this->artisan('kid:save', ['name' => 'Nova', '--age' => 12])
            ->assertSuccessful();

        // A known starter PIN the parent changes from Kids & Points.
        $this->assertTrue(Hash::check('1111', Profile::where('name', 'Nova')->firstOrFail()->pin_hash));
    }

    public function test_it_never_matches_the_parent_profile(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->parent()->for($household)->create(['name' => 'Parent']);

        $this->artisan('kid:save', ['name' => 'Parent', '--age' => 12, '--pin' => '1234'])
            ->assertSuccessful();

        // The parent is left untouched; a separate kid profile is created.
        $this->assertSame(1, Profile::where('role', ProfileRole::Parent)->count());
        $this->assertSame(1, Profile::where('role', ProfileRole::Kid)->count());
    }

    public function test_it_fails_when_no_household_exists(): void
    {
        $this->artisan('kid:save', ['name' => 'Nova', '--age' => 12, '--pin' => '1234'])
            ->assertFailed();

        $this->assertSame(0, Profile::count());
    }
}
