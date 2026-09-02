<?php

namespace Tests\Feature;

use App\Enums\AccentColor;
use App\Enums\ProfileRole;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SaveParentCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_parent_login(): void
    {
        $household = Household::factory()->create();

        $this->artisan('parent:save', ['name' => 'Mom', '--pin' => '4821', '--color' => 'magenta'])
            ->assertSuccessful();

        $parent = Profile::where('name', 'Mom')->firstOrFail();

        $this->assertSame($household->id, $parent->household_id);
        $this->assertSame(ProfileRole::Parent, $parent->role);
        $this->assertSame(AccentColor::Magenta, $parent->color);
        $this->assertTrue(Hash::check('4821', $parent->pin_hash));
    }

    public function test_a_new_parent_defaults_to_the_console_color(): void
    {
        Household::factory()->create();

        $this->artisan('parent:save', ['name' => 'Dad'])->assertSuccessful();

        $this->assertSame(AccentColor::Parent, Profile::where('name', 'Dad')->sole()->color);
    }

    public function test_it_renames_an_existing_parent_and_keeps_everything_attached(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->for($household)->parent()->create(['name' => 'Parent']);
        $parent->setPin('9090');
        $parent->save();

        $this->artisan('parent:save', ['name' => 'Mom', '--rename-from' => 'Parent', '--color' => 'coral'])
            ->assertSuccessful();

        $parent->refresh();

        // Same row, so approvals, quotes and anything else pointing at this
        // profile survive the rename.
        $this->assertSame('Mom', $parent->name);
        $this->assertSame(AccentColor::Coral, $parent->color);
        $this->assertTrue(Hash::check('9090', $parent->pin_hash));
        $this->assertSame(1, Profile::where('role', ProfileRole::Parent)->count());
    }

    public function test_renaming_a_parent_who_is_not_there_fails(): void
    {
        Household::factory()->create();

        $this->artisan('parent:save', ['name' => 'Mom', '--rename-from' => 'Nobody'])
            ->assertFailed();

        $this->assertSame(0, Profile::count());
    }

    public function test_it_updates_an_existing_parent_matched_by_name(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->for($household)->parent()->create(['name' => 'Dad']);

        $this->artisan('parent:save', ['name' => 'dad', '--pin' => '1357'])
            ->assertSuccessful();

        // Case-insensitive on every database, not just the forgiving ones.
        $this->assertTrue(Hash::check('1357', $parent->refresh()->pin_hash));
        $this->assertSame(1, Profile::where('role', ProfileRole::Parent)->count());
    }

    public function test_a_rename_cannot_collide_with_another_parent(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->parent()->create(['name' => 'Mom']);
        Profile::factory()->for($household)->parent()->create(['name' => 'Parent']);

        // Two parents with one name would make the profile picker unusable and
        // this command ambiguous forever after.
        $this->artisan('parent:save', ['name' => 'Mom', '--rename-from' => 'Parent'])
            ->assertFailed();

        $this->assertSame(1, Profile::where('name', 'Mom')->count());
        $this->assertSame(1, Profile::where('name', 'Parent')->count());
    }

    public function test_renaming_a_parent_to_its_own_name_is_not_a_collision(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->parent()->create(['name' => 'Mom']);

        // The clash check has to ignore the profile being renamed, or changing
        // only a color would report a conflict with itself.
        $this->artisan('parent:save', ['name' => 'Mom', '--rename-from' => 'Mom', '--color' => 'violet'])
            ->assertSuccessful();

        $this->assertSame(AccentColor::Violet, Profile::where('name', 'Mom')->sole()->color);
    }

    public function test_a_bad_pin_changes_nothing(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->for($household)->parent()->create(['name' => 'Dad']);
        $parent->setPin('1111');
        $parent->save();

        $this->artisan('parent:save', ['name' => 'Dad', '--pin' => '12'])->assertFailed();

        $this->assertTrue(Hash::check('1111', $parent->refresh()->pin_hash));
    }

    public function test_a_bad_color_changes_nothing(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->parent()->create(['name' => 'Dad', 'color' => AccentColor::Cyan]);

        $this->artisan('parent:save', ['name' => 'Dad', '--color' => 'chartreuse'])->assertFailed();

        $this->assertSame(AccentColor::Cyan, Profile::where('name', 'Dad')->sole()->color);
    }

    public function test_an_existing_parents_pin_is_left_alone_when_none_is_given(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->for($household)->parent()->create(['name' => 'Dad']);
        $parent->setPin('5555');
        $parent->save();

        $this->artisan('parent:save', ['name' => 'Dad', '--color' => 'gold'])->assertSuccessful();

        $this->assertTrue(Hash::check('5555', $parent->refresh()->pin_hash));
    }
}
