<?php

namespace Tests\Feature;

use App\Enums\ChoreCadence;
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

    public function test_setCadence_ignores_an_invalid_value(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['cadence' => 'daily']);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')
            ->call('setCadence', $chore->id, 'yearly');

        $this->assertSame(ChoreCadence::Daily, $chore->refresh()->cadence);
    }
}
