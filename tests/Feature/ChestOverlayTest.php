<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The prize overlay is Alpine-driven, so its behaviour can't be exercised
 * here — but the guard that keeps it from re-firing on every page load can
 * be, and that's the part that regressed.
 */
class ChestOverlayTest extends TestCase
{
    use RefreshDatabase;

    private function kidWithChores(): Profile
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->count(3)->create();

        return Profile::factory()->for($household)->create();
    }

    public function test_the_prize_overlay_is_gated_on_having_just_opened(): void
    {
        // A chest that was already open starts in the 'revealed' phase, so
        // gating the overlay on the phase alone replays the celebration every
        // time the kid comes back to the tab.
        $kid = $this->kidWithChores();
        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')->assertSee("phase === 'revealed' && justOpened", false);
    }

    public function test_a_chest_never_renders_pre_flagged_as_just_opened(): void
    {
        $kid = $this->kidWithChores();

        // Reveal the quest so its chest loads in the 'revealed' phase — the
        // exact state that used to trigger the overlay on arrival.
        app(ChoreService::class)->revealQuest($kid);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->assertSee('justOpened: false', false)
            ->assertDontSee('justOpened: true', false);
    }

    public function test_an_already_revealed_quest_still_renders_its_chest_open(): void
    {
        $kid = $this->kidWithChores();

        app(ChoreService::class)->revealQuest($kid);

        Auth::guard('profile')->login($kid);

        // Suppressing the overlay must not also hide the revealed content.
        Volt::test('kid.quests')
            ->assertSee("phase: 'revealed'", false)
            ->assertSee(app(ChoreService::class)->questFor($kid)->chore->name);
    }
}
