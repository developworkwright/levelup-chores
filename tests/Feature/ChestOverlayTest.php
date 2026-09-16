<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChestService;
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

        Volt::test('kid.home')->call('toggleRow', 'chest')->assertSee("phase === 'revealed' && justOpened", false);
    }

    public function test_a_chest_never_renders_pre_flagged_as_just_opened(): void
    {
        $kid = $this->kidWithChores();

        // Open the bonus chest so it loads in the 'revealed' phase — the exact
        // state that used to trigger the overlay on arrival. It used to be the
        // quest chest that was put in that state here; that one is gone, and
        // the rule belongs to <x-chest> rather than to any one chest.
        app(ChestService::class)->open($kid);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')->call('toggleRow', 'chest')
            ->assertSee('justOpened: false', false)
            ->assertDontSee('justOpened: true', false);
    }

    /**
     * The halo is centred off half its own size, so the size has to reach the
     * stylesheet as a number. Sizing it with a width utility instead leaves
     * .fq-glow solving `calc(var(--fq-glow-size) / -2)` against nothing, and
     * the halo goes back to hanging off the chest's right-hand side.
     */
    public function test_the_opening_halo_hands_its_size_to_the_stylesheet(): void
    {
        $kid = $this->kidWithChores();
        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')->call('toggleRow', 'chest')->assertSee('--fq-glow-size: 120px', false);

        $this->assertStringContainsString(
            'margin-left: calc(var(--fq-glow-size) / -2)',
            file_get_contents(resource_path('css/app.css')),
        );
    }

    /**
     * The suspense before the server call and the jiggle it shadows are one
     * timing kept in two files, so this is the seam they drift at — shorten the
     * wait alone and the chest is still shaking when the prize lands; shorten
     * the animation alone and it sits still waiting to be opened.
     */
    public function test_the_chest_suspense_outlasts_the_jiggle_it_shadows(): void
    {
        $kid = $this->kidWithChores();
        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')->call('toggleRow', 'chest')->assertSee('setTimeout(resolve, 2600)', false);

        $this->assertStringContainsString(
            'animation: fq-chest-jiggle 2.5s',
            file_get_contents(resource_path('css/app.css')),
        );
    }

    /**
     * A chest's toast has to outlast its own reveal card, or the badge and
     * level cards queued behind it land on top of a chest still showing its
     * prize.
     */
    public function test_the_chest_toast_outlasts_the_card_it_covers(): void
    {
        $kid = $this->kidWithChores();
        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')->call('toggleRow', 'chest')
            ->assertSee('hold: 2600', false)
            ->assertSee('show = false, 2200', false);
    }

    /**
     * Home's day column is 340px wide on a desktop, so its chests keep the
     * stacked phone card there too — the side-by-side row squeezed the title
     * and copy down to a word per line.
     */
    public function test_home_draws_its_chest_stacked_at_every_width(): void
    {
        $kid = $this->kidWithChores();
        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')->call('toggleRow', 'chest')
            ->assertSee("Open today's bonus chest")
            ->assertDontSee('sm:flex-row sm:gap-[18px]', false)
            ->assertDontSee('sm:min-w-[260px]', false);
    }

    public function test_an_already_opened_chest_still_renders_open(): void
    {
        $kid = $this->kidWithChores();

        app(ChestService::class)->open($kid);

        Auth::guard('profile')->login($kid);

        // Suppressing the overlay must not also hide the revealed content.
        Volt::test('kid.home')->call('toggleRow', 'chest')
            ->assertSee("phase: 'revealed'", false)
            ->assertSee('Banked');
    }
}
