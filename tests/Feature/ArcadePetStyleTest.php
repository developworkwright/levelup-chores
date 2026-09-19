<?php

namespace Tests\Feature;

use App\Enums\ArcadeGame;
use App\Enums\PetStyle;
use App\Models\Cosmetic;
use App\Models\Household;
use App\Models\OwnedCosmetic;
use App\Models\PetEgg;
use App\Models\Profile;
use App\Services\CosmeticService;
use App\Services\PetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * A pet's style in the arcade: each game gives each style its own help — see
 * ArcadeGame::styleHelp() and the games' own code (arcade.js, fart-dash.js).
 */
class ArcadePetStyleTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(15, 0));
        $this->household = Household::factory()->create();
    }

    /** A profile with a pet of this style out, at this many chores of growth. */
    private function withPet(Profile $profile, PetStyle $style, int $growth = 0): Profile
    {
        $pet = Cosmetic::create([
            'household_id' => $this->household->id,
            'slot' => 'pet',
            'art_path' => 'cosmetics/1/rex.png',
            'name' => 'Rex',
            'cost' => 10,
            'stock' => 'shelf',
            'pet_rarity' => 'common',
            'pet_style' => $style,
            'published_at' => now(),
        ]);

        OwnedCosmetic::create([
            'household_id' => $this->household->id,
            'profile_id' => $profile->id,
            'cosmetic_id' => $pet->id,
            'tickets_paid' => 0,
            'growth' => $growth,
        ]);

        app(CosmeticService::class)->forget();
        app(CosmeticService::class)->wear($profile->fresh(), $pet);
        app()->forgetScopedInstances();

        return $profile->fresh();
    }

    /** Both games that have styles give every one of the four something. */
    public function test_the_tower_and_the_walk_give_every_style_its_own_help(): void
    {
        foreach ([ArcadeGame::StackTheMess, ArcadeGame::WindyWalkies] as $game) {
            $helps = array_map(fn (PetStyle $style) => $game->styleHelp($style), PetStyle::cases());

            $this->assertNotContains(null, $helps, $game->value);
            $this->assertCount(4, array_unique($helps), $game->value);
        }

        // Games not given styles yet give none, rather than one style something.
        $this->assertNull(ArcadeGame::GrandTour->styleHelp(PetStyle::Quick));
        $this->assertNull(ArcadeGame::SlimeTime->styleHelp(PetStyle::Lucky));
    }

    /**
     * The games still waiting for their pet styles. Take a game off this list
     * when it gets them — and never add a new one to it: a new game ships
     * with all four, or this test fails.
     */
    private const WITHOUT_STYLES_YET = [ArcadeGame::GrandTour, ArcadeGame::PenguinLaunch];

    public function test_every_ranked_game_gives_every_style_its_own_help(): void
    {
        foreach (ArcadeGame::ranked() as $game) {
            if (in_array($game, self::WITHOUT_STYLES_YET, true)) {
                continue;
            }

            $helps = array_map(fn (PetStyle $style) => $game->styleHelp($style), PetStyle::cases());

            $this->assertNotContains(null, $helps, "{$game->value} has no pet styles — give it all four (ArcadeGame::styleHelp() and the game's own code).");
            $this->assertCount(4, array_unique($helps), $game->value);
        }
    }

    /** The style works at any age: it is the kind of help, not a knack. */
    public function test_a_kids_pet_style_counts_from_a_baby(): void
    {
        $kid = $this->withPet(Profile::factory()->for($this->household)->create(), PetStyle::Lucky, growth: 0);

        $this->assertSame(PetStyle::Lucky, app(PetService::class)->styleFor($kid));
    }

    public function test_grown_ups_and_kids_with_an_egg_out_have_no_style(): void
    {
        $parent = $this->withPet(Profile::factory()->parent()->for($this->household)->create(), PetStyle::Big);
        $this->assertNull(app(PetService::class)->styleFor($parent));

        $kid = $this->withPet(Profile::factory()->for($this->household)->create(), PetStyle::Big);
        PetEgg::create(['household_id' => $this->household->id, 'profile_id' => $kid->id, 'tickets_paid' => 15]);

        $this->assertNull(app(PetService::class)->styleFor($kid->fresh()));
    }

    public function test_the_tower_is_handed_the_style_and_the_kid_is_told_what_it_does(): void
    {
        $kid = $this->withPet(Profile::factory()->for($this->household)->create(), PetStyle::Steady);
        Auth::guard('profile')->login($kid);

        $html = Volt::test('arcade')
            ->call('switchTo', ArcadeGame::StackTheMess->value)
            ->assertSee('data-pet-style-help="steady"', false)
            ->assertSee('steadies your first wobbly drop')
            ->html();

        $this->assertMatchesRegularExpression('/x-data="fqStacker\([^"]*steady[^"]*\)"/', $html);
    }

    public function test_the_walk_is_handed_the_style(): void
    {
        $kid = $this->withPet(Profile::factory()->for($this->household)->create(), PetStyle::Quick);
        Auth::guard('profile')->login($kid);

        Volt::test('arcade')
            ->call('switchTo', ArcadeGame::WindyWalkies->value)
            ->assertSee('pet-style="quick"', false)
            ->assertSee('4 lanes instead of 3');
    }

    /** A game with no styles yet says nothing about the pet at all. */
    public function test_a_game_without_styles_mentions_no_pet(): void
    {
        $kid = $this->withPet(Profile::factory()->for($this->household)->create(), PetStyle::Quick);
        Auth::guard('profile')->login($kid);

        Volt::test('arcade')
            ->call('switchTo', ArcadeGame::GrandTour->value)
            ->assertDontSee('data-pet-style-help', false);
    }

    public function test_a_grown_ups_pet_gives_their_games_nothing(): void
    {
        $parent = $this->withPet(Profile::factory()->parent()->for($this->household)->create(), PetStyle::Lucky);
        Auth::guard('profile')->login($parent);

        Volt::test('arcade')
            ->call('switchTo', ArcadeGame::WindyWalkies->value)
            ->assertDontSee('pet-style=', false)
            ->assertDontSee('data-pet-style-help', false);
    }
}
