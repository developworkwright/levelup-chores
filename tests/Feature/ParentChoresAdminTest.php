<?php

namespace Tests\Feature;

use App\Enums\ChoreCadence;
use App\Enums\ChoreCategory;
use App\Enums\ChoreEffort;
use App\Enums\ChoreIcon;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ParentChoresAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_parent_can_write_a_hint_for_a_chore(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['hint' => null]);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')
            ->call('setHint', $chore->id, "  The hungry ones can't ask for it themselves.  ");

        $this->assertSame("The hungry ones can't ask for it themselves.", $chore->refresh()->hint);
    }

    public function test_blanking_the_hint_clears_it(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['hint' => 'An existing clue']);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')->call('setHint', $chore->id, '   ');

        $this->assertNull($chore->refresh()->hint);
    }

    public function test_a_parent_cannot_write_a_hint_on_another_households_chore(): void
    {
        $parent = Profile::factory()->parent()->for(Household::factory())->create();
        $foreign = Chore::factory()->for(Household::factory())->create(['hint' => null]);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')->call('setHint', $foreign->id, 'Leaked clue');

        $this->assertNull($foreign->refresh()->hint);
    }

    public function test_a_parent_can_toggle_a_chores_quest_eligibility(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['quest_eligible' => true]);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')
            ->call('toggleQuestEligible', $chore->id);

        $this->assertFalse($chore->refresh()->quest_eligible);
    }

    public function test_a_parent_can_toggle_a_chores_wheel_eligibility_without_touching_the_quest_one(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = Chore::factory()->for($household)->create([
            'name' => 'Put the groceries away',
            'quest_eligible' => true,
            'wheel_eligible' => true,
        ]);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')
            ->call('toggleWheelEligible', $chore->id)
            ->assertSee('Excluded from wheel')
            ->assertDontSee('Excluded from quest');

        $chore->refresh();
        $this->assertFalse($chore->wheel_eligible);
        $this->assertTrue($chore->quest_eligible);
    }

    public function test_a_parent_cannot_bar_another_households_chore_from_the_wheel(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $theirs = Chore::factory()->for(Household::factory()->create())->create(['wheel_eligible' => true]);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')->call('toggleWheelEligible', $theirs->id);

        $this->assertTrue($theirs->refresh()->wheel_eligible);
    }

    public function test_a_parent_can_set_a_chores_cadence_to_unlimited(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['cadence' => 'daily']);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')
            ->call('setCadence', $chore->id, 'unlimited');

        $this->assertSame(ChoreCadence::Unlimited, $chore->refresh()->cadence);
    }

    public function test_set_cadence_ignores_an_invalid_value(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['cadence' => 'daily']);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')
            ->call('setCadence', $chore->id, 'yearly');

        $this->assertSame(ChoreCadence::Daily, $chore->refresh()->cadence);
    }

    public function test_a_parent_cycles_a_chores_effort_through_and_back_to_not_said(): void
    {
        // Three states rather than a switch: "nobody has said" is a real answer
        // and a parent has to be able to get back to it. Only Hard work is
        // load-bearing — it's what the kid board's Muscle chip collects.
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = Chore::factory()->for($household)->create();

        Auth::guard('profile')->login($parent);

        $this->assertNull($chore->effort);

        $page = Volt::test('parent.chores');

        $page->call('cycleEffort', $chore->id);
        $this->assertSame(ChoreEffort::Light, $chore->refresh()->effort);

        $page->call('cycleEffort', $chore->id);
        $this->assertSame(ChoreEffort::Heavy, $chore->refresh()->effort);

        $page->call('cycleEffort', $chore->id);
        $this->assertNull($chore->refresh()->effort);
    }

    public function test_effort_cannot_be_set_on_another_households_chore(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $theirs = Chore::factory()->for(Household::factory()->create())->create();

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')->call('cycleEffort', $theirs->id);

        $this->assertNull($theirs->refresh()->effort);
    }

    public function test_a_parent_files_a_chore_under_a_category(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = Chore::factory()->for($household)->create();

        Auth::guard('profile')->login($parent);

        $this->assertNull($chore->category);

        Volt::test('parent.chores')
            ->call('setCategory', $chore->id, ChoreCategory::Kitchen->value);

        $this->assertSame(ChoreCategory::Kitchen, $chore->refresh()->category);
    }

    public function test_an_empty_value_puts_a_chore_back_to_no_category(): void
    {
        // Not a deletion — an unfiled chore collects under Other, so a kid
        // picking a chip can never make it vanish off the board entirely.
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['category' => ChoreCategory::Kitchen]);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')->call('setCategory', $chore->id, '');

        $this->assertNull($chore->refresh()->category);
        $this->assertSame(ChoreCategory::Other, ChoreCategory::forChore($chore));
    }

    public function test_setting_a_category_ignores_an_invalid_value(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['category' => ChoreCategory::Kitchen]);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')->call('setCategory', $chore->id, 'dungeon');

        // tryFrom() returns null, which is the "not said" state rather than an
        // error — the same landing spot as clearing it deliberately.
        $this->assertNull($chore->refresh()->category);
    }

    public function test_a_parent_cannot_file_another_households_chore(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $theirs = Chore::factory()->for(Household::factory()->create())->create();

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')->call('setCategory', $theirs->id, ChoreCategory::Kitchen->value);

        $this->assertNull($theirs->refresh()->category);
    }

    public function test_the_category_is_independent_of_the_chores_face(): void
    {
        // Changing the picture must not move the chore to a different chip.
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['category' => ChoreCategory::Outside]);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')->call('setIcon', $chore->id, ChoreIcon::Dishes->value);

        $chore->refresh();
        $this->assertSame(ChoreIcon::Dishes->faClass(), $chore->icon);
        $this->assertSame(ChoreCategory::Outside, $chore->category);
    }
}
