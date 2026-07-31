<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class KidHeaderTest extends TestCase
{
    use RefreshDatabase;

    private function loginKid(array $attributes = []): Profile
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create($attributes);
        Chore::factory()->for($household)->count(2)->create();

        Auth::guard('profile')->login($kid);

        return $kid;
    }

    public function test_the_header_shows_points_streak_and_tickets(): void
    {
        $this->loginKid(['points' => 250, 'streak' => 4, 'bonus_tickets' => 7]);

        Volt::test('kid.quests')
            ->assertSee('PTS')
            ->assertSee('STREAK')
            ->assertSee('TICKETS')
            ->assertSee('7');
    }

    public function test_the_ticket_tile_links_to_the_bonus_shop(): void
    {
        $this->loginKid(['bonus_tickets' => 3]);

        Volt::test('kid.quests')->assertSee(route('kid.bonus'), false);
    }

    public function test_the_header_shows_tickets_on_every_kid_tab(): void
    {
        $this->loginKid(['bonus_tickets' => 5]);

        // It lives in the shared shell, so it should follow the kid around
        // rather than only existing on the page it was added for.
        foreach (['kid.quests', 'kid.loot', 'kid.badges', 'kid.bonus'] as $page) {
            Volt::test($page)->assertSee('TICKETS');
        }
    }
}
