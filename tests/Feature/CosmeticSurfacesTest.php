<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Cosmetic;
use App\Models\Household;
use App\Models\Profile;
use App\Services\CosmeticService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * A worn item rides the kid's identity, so it shows wherever the kid already
 * does: the login door, the header, the feed, the arcade board. Themes,
 * patterns, cabinets and tap effects are the exception — those only repaint the
 * wearer's own pages.
 */
class CosmeticSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
    }

    private function kidWearing(string $name, array $looks): Profile
    {
        $kid = Profile::factory()->for($this->household)->create(['name' => $name, 'bonus_tickets' => 50]);

        foreach ($looks as [$slot, $recipe]) {
            $item = Cosmetic::where('household_id', $this->household->id)->where('slot', $slot)->where('recipe', $recipe)->firstOrFail();
            app(CosmeticService::class)->buy($kid, $item);
        }

        app()->forgetScopedInstances();

        return $kid->fresh();
    }

    private function workToday(Profile $kid): void
    {
        ChoreCompletion::create([
            'chore_id' => Chore::factory()->for($this->household)->create()->id,
            'profile_id' => $kid->id,
            'status' => 'pending',
            'points_awarded' => 100,
            'submitted_at' => now(),
        ]);
    }

    public function test_the_login_tile_wears_the_face_frame_and_plate(): void
    {
        $this->kidWearing('Colton', [['avatar', 'skull'], ['frame', 'double'], ['plate', 'metal']]);

        $html = Volt::test('login')->html();

        $this->assertStringContainsString('recipe="skull"', $html);
        $this->assertStringContainsString('recipe="double"', $html);
        $this->assertStringContainsString('<fq-plate', $html);
        // The dark seat under a worn frame.
        $this->assertStringContainsString('inset 0 0 0 7px', $html);
    }

    public function test_a_kid_with_nothing_bought_keeps_the_letter_tile(): void
    {
        Profile::factory()->for($this->household)->create(['name' => 'Westin']);

        $html = Volt::test('login')->html();

        $this->assertStringNotContainsString('<fq-cosmetic', $html);
        $this->assertStringContainsString('>Westin<', $html);
    }

    public function test_powered_up_with_a_frame_lights_the_frame_instead_of_a_second_ring(): void
    {
        $framed = $this->kidWearing('Raylan', [['frame', 'stitch']]);
        $plain = Profile::factory()->for($this->household)->create(['name' => 'Ada']);

        $this->workToday($framed);
        $this->workToday($plain);

        $html = Volt::test('login')->html();

        $this->assertSame(1, substr_count($html, 'fq-frame-lit'));
        $this->assertSame(1, substr_count($html, 'fq-frame-halo'));
        // Only the unframed kid still wears the rainbow ring.
        $this->assertSame(1, substr_count($html, 'fq-powered-token'));
    }

    public function test_the_door_never_wears_a_theme_or_a_pattern(): void
    {
        $this->kidWearing('Colton', [['theme', 'ember'], ['pattern', 'carbon']]);

        $html = Volt::test('login')->html();

        $this->assertStringNotContainsString('data-fq-theme', $html);
        $this->assertStringNotContainsString('kind="pattern"', $html);
    }

    public function test_the_feed_avatar_wears_the_face_and_frame_held_still(): void
    {
        $kid = $this->kidWearing('Colton', [['avatar', 'cat'], ['frame', 'orbit']]);

        $html = view('components.feed.avatar', ['profile' => $kid])->render();

        $this->assertStringContainsString('recipe="cat"', $html);
        $this->assertStringContainsString('recipe="orbit"', $html);
        $this->assertSame(2, substr_count($html, ' still'));
        $this->assertStringNotContainsString('>C<', $html);
    }

    public function test_the_arcade_machine_wears_the_readers_own_cabinet(): void
    {
        $kid = $this->kidWearing('Colton', [['cabinet', 'chrome']]);

        Auth::guard('profile')->login($kid);

        Volt::test('arcade')->assertSee('<fq-cabinet', false)->assertSee('recipe="chrome"', false);
    }

    public function test_a_sibling_does_not_see_somebody_elses_cabinet(): void
    {
        $this->kidWearing('Colton', [['cabinet', 'chrome']]);
        $sibling = Profile::factory()->for($this->household)->create(['name' => 'Westin']);

        Auth::guard('profile')->login($sibling);

        Volt::test('arcade')->assertDontSee('<fq-cabinet', false);
    }

    private function pet(string $name, int $cost = 10): Cosmetic
    {
        return Cosmetic::create([
            'household_id' => $this->household->id,
            'slot' => 'pet',
            'art_path' => 'cosmetics/1/'.$name.'.png',
            'name' => $name,
            'cost' => $cost,
            'stock' => 'shelf',
            'published_at' => now(),
        ]);
    }

    public function test_the_login_door_carries_one_pet_per_kid_who_has_one_out(): void
    {
        $tabby = $this->pet('Tabby');
        $gremlin = $this->pet('Gremlin');

        $colton = Profile::factory()->for($this->household)->create(['name' => 'Colton', 'bonus_tickets' => 40, 'age' => 12]);
        $westin = Profile::factory()->for($this->household)->create(['name' => 'Westin', 'bonus_tickets' => 40, 'age' => 9]);
        // A third kid with no pet, so the row is not all animals.
        Profile::factory()->for($this->household)->create(['name' => 'Ada', 'age' => 6]);

        app(CosmeticService::class)->buy($colton, $tabby);
        app(CosmeticService::class)->buy($westin, $gremlin);
        app()->forgetScopedInstances();

        $html = Volt::test('login')->html();

        $this->assertStringContainsString('<fq-pets', $html);
        $this->assertSame(2, substr_count($html, 'cosmetics/art/'));
        // Each pet is penned around its own kid's tile, as a fraction of the row.
        $this->assertStringContainsString('&quot;home&quot;:0.1667', $html);
        // Every pet has its toy out on the door, powered up or not.
        $this->assertSame(2, substr_count($html, '&quot;toy&quot;:true'));
        // And nothing can be dragged about on a page anybody can open.
        $this->assertStringNotContainsString('<fq-pets drag', $html);
    }

    public function test_the_door_has_no_pet_layer_when_nobody_has_one_out(): void
    {
        Profile::factory()->for($this->household)->create(['name' => 'Westin']);

        Volt::test('login')->assertDontSee('<fq-pets', false);
    }

    public function test_a_kids_own_pet_is_on_every_page_of_theirs_and_can_be_dragged(): void
    {
        $kid = $this->kidWearing('Colton', []);
        app(CosmeticService::class)->buy($kid, $this->pet('Tabby'));
        app()->forgetScopedInstances();

        Auth::guard('profile')->login($kid->fresh());

        Volt::test('kid.bonus')
            ->assertSee('<fq-pets', false)
            ->assertSee('drag', false);
    }

    /**
     * A visit is worked out from the hour rather than rolled, so a refresh
     * cannot re-roll it — and it only happens on Home.
     */
    public function test_a_siblings_pet_visits_home_now_and_again_and_never_changes_its_mind(): void
    {
        $colton = Profile::factory()->for($this->household)->create(['name' => 'Colton', 'bonus_tickets' => 40]);
        $westin = Profile::factory()->for($this->household)->create(['name' => 'Westin', 'bonus_tickets' => 40]);

        app(CosmeticService::class)->buy($westin, $this->pet('Gremlin'));
        app()->forgetScopedInstances();

        $service = app(CosmeticService::class);
        $hours = collect(range(0, 23))->map(function (int $hour) use ($service, $colton) {
            $this->travelTo(Carbon::parse('2026-09-18 '.str_pad((string) $hour, 2, '0', STR_PAD_LEFT).':10', 'America/Chicago'));

            return $service->visitingPet($colton)?->name;
        });

        // Some hours, not all of them, and not none of them.
        $this->assertGreaterThan(0, $hours->filter()->count(), 'A sibling with a pet never visited all day.');
        $this->assertLessThan(24, $hours->filter()->count(), 'The visitor never went home.');

        // The same hour, asked twice, answers the same.
        $this->travelTo(Carbon::parse('2026-09-18 12:10', 'America/Chicago'));
        $this->assertSame($service->visitingPet($colton)?->name, app(CosmeticService::class)->visitingPet($colton->fresh())?->name);
    }

    public function test_a_sibling_with_no_pet_never_visits(): void
    {
        $colton = Profile::factory()->for($this->household)->create(['name' => 'Colton']);
        Profile::factory()->for($this->household)->create(['name' => 'Westin']);

        foreach (range(0, 23) as $hour) {
            $this->travelTo(Carbon::parse('2026-09-18 '.str_pad((string) $hour, 2, '0', STR_PAD_LEFT).':10', 'America/Chicago'));
            app()->forgetScopedInstances();

            $this->assertNull(app(CosmeticService::class)->visitingPet($colton));
        }
    }

    public function test_a_worn_tap_effect_is_on_the_kids_pages(): void
    {
        $kid = $this->kidWearing('Colton', [['spark', 'bats'], ['pattern', 'carbon']]);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.bonus')
            ->assertSee('<fq-spark', false)
            ->assertSee('recipe="bats"', false)
            ->assertSee('kind="pattern"', false);
    }
}
