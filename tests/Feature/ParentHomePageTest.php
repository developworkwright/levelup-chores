<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The parent landing page.
 *
 * Called Approvals until it stopped being one queue: it holds jobs on offer,
 * chore approvals, redemptions and lucky wins, and now the feelings card, which
 * asks nothing of anybody. The *sections* keep their own names.
 */
class ParentHomePageTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $parent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->parent = Profile::factory()->for($this->household)->parent()->create(['name' => 'Mom']);
    }

    public function test_the_old_approvals_url_still_lands_on_home(): void
    {
        Auth::guard('profile')->login($this->parent);

        // Push notifications already sitting on a phone carry the old path, and
        // a parent tapping an approval alert must not find a 404.
        $this->get('/parent/approvals')->assertRedirect('/parent/home');
    }

    public function test_the_parent_landing_route_goes_to_home(): void
    {
        Auth::guard('profile')->login($this->parent);

        $this->get('/parent')->assertRedirect('/parent/home');
    }

    public function test_the_tab_is_called_home_and_the_queue_keeps_its_own_name(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.home')
            ->assertOk()
            // The page is Home; the section on it is still Chore Approvals.
            ->assertSee('Chore Approvals');
    }
}
