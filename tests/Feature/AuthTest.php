<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeKid(string $pin = '1234'): Profile
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $kid->setPin($pin);
        $kid->save();

        return $kid;
    }

    public function test_correct_pin_logs_in_and_redirects_by_role(): void
    {
        $kid = $this->makeKid('1234');

        $this->withSession([]);

        Volt::test('pin-entry', ['profile' => $kid])
            ->call('press', '1')
            ->call('press', '2')
            ->call('press', '3')
            ->call('press', '4')
            ->assertRedirect('/kid');

        $this->assertAuthenticatedAs($kid, 'profile');
    }

    public function test_wrong_pin_shows_error_and_does_not_log_in(): void
    {
        $kid = $this->makeKid('1234');

        Volt::test('pin-entry', ['profile' => $kid])
            ->call('press', '9')
            ->call('press', '9')
            ->call('press', '9')
            ->call('press', '9')
            ->assertSet('error', true);

        $this->assertGuest('profile');
    }

    public function test_five_failed_attempts_locks_the_profile(): void
    {
        $kid = $this->makeKid('1234');

        $component = Volt::test('pin-entry', ['profile' => $kid]);

        for ($i = 0; $i < 5; $i++) {
            $component->call('press', '9')->call('press', '9')->call('press', '9')->call('press', '9');
        }

        $kid->refresh();
        $this->assertTrue($kid->isLocked());
        $this->assertNotNull($component->get('lockedMessage'));
    }

    public function test_correct_pin_is_rejected_while_locked_out(): void
    {
        $kid = $this->makeKid('1234');
        $kid->failed_pin_attempts = 4;
        $kid->save();

        $component = Volt::test('pin-entry', ['profile' => $kid]);
        // 5th wrong attempt trips the lock...
        $component->call('press', '9')->call('press', '9')->call('press', '9')->call('press', '9');

        // ...and the *correct* PIN is still refused while locked.
        $component->call('press', '1')->call('press', '2')->call('press', '3')->call('press', '4');

        $this->assertGuest('profile');
    }

    public function test_kid_session_cannot_access_parent_routes(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();

        $this->actingAs($kid, 'profile');

        $this->get('/parent/home')->assertForbidden();
    }

    public function test_parent_session_cannot_access_kid_routes(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();

        $this->actingAs($parent, 'profile');

        $this->get('/kid/quests')->assertForbidden();
    }
}
