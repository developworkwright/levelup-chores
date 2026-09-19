<?php

namespace Tests\Feature;

use App\Enums\CosmeticSlot;
use App\Enums\CosmeticStock;
use App\Enums\PetStage;
use App\Exceptions\CosmeticUnavailableException;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Cosmetic;
use App\Models\Household;
use App\Models\OwnedCosmetic;
use App\Models\PetEgg;
use App\Models\Profile;
use App\Models\SiblingOffer;
use App\Notifications\ChoreReviewed;
use App\Services\ChoreService;
use App\Services\CosmeticArt;
use App\Services\CosmeticService;
use App\Services\PetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Pets grow up with the kid's chores — baby, young, grown up — and keep their
 * size when put away. See App\Services\PetService.
 */
class PetGrowthTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $parent;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('drawings');

        $this->household = Household::factory()->create();
        $this->parent = Profile::factory()->parent()->for($this->household)->create();
        $this->kid = Profile::factory()->for($this->household)->create(['name' => 'Colton', 'bonus_tickets' => 100]);
    }

    private function pet(string $name, array $attributes = []): Cosmetic
    {
        return Cosmetic::create([
            'household_id' => $this->household->id,
            'slot' => 'pet',
            'art_path' => 'cosmetics/'.$this->household->id.'/'.$name.'.png',
            'name' => $name,
            'cost' => 10,
            'stock' => 'shelf',
            'published_at' => now(),
            ...$attributes,
        ]);
    }

    private function adopt(Profile $kid, Cosmetic $pet): void
    {
        app(CosmeticService::class)->buy($kid, $pet);
        app()->forgetScopedInstances();
    }

    private function approveChores(Profile $kid, int $count): void
    {
        foreach (range(1, $count) as $ignored) {
            $completion = ChoreCompletion::create([
                'chore_id' => Chore::factory()->for($this->household)->create()->id,
                'profile_id' => $kid->id,
                'status' => 'pending',
                'points_awarded' => 10,
                'submitted_at' => now(),
            ]);

            app(ChoreService::class)->approve($completion, $this->parent);
        }

        app()->forgetScopedInstances();
    }

    private function growthOf(Profile $kid, Cosmetic $pet): int
    {
        return OwnedCosmetic::where('profile_id', $kid->id)->where('cosmetic_id', $pet->id)->value('growth');
    }

    /** A clean 4x3 sheet, or one with poses missing. */
    private function petSheetPng(array $skipCells = []): string
    {
        $image = imagecreatetruecolor(1024, 768);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);
        $fur = imagecolorallocate($image, 168, 116, 72);

        foreach (range(0, 11) as $index) {
            if (! in_array($index, $skipCells, true)) {
                imagefilledellipse($image, ($index % 4) * 256 + 128, intdiv($index, 4) * 256 + 128, 150, 150, $fur);
            }
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_the_stages_start_at_ten_and_thirty_chores(): void
    {
        $this->assertSame(PetStage::Baby, PetStage::forGrowth(0));
        $this->assertSame(PetStage::Baby, PetStage::forGrowth(9));
        $this->assertSame(PetStage::Young, PetStage::forGrowth(10));
        $this->assertSame(PetStage::Young, PetStage::forGrowth(29));
        $this->assertSame(PetStage::Adult, PetStage::forGrowth(30));
        $this->assertSame(PetStage::Adult, PetStage::forGrowth(500));
    }

    public function test_every_approved_chore_grows_the_pet_that_is_out_and_says_so_when_it_grows_up(): void
    {
        Notification::fake();
        $tabby = $this->pet('Tabby');
        $this->adopt($this->kid, $tabby);

        $this->approveChores($this->kid, 9);
        $this->assertSame(9, $this->growthOf($this->kid, $tabby));
        $this->assertSame(PetStage::Baby, app(PetService::class)->stageOf($this->kid, $tabby));

        $this->approveChores($this->kid, 1);
        $this->assertSame(PetStage::Young, app(PetService::class)->stageOf($this->kid, $tabby));

        $bodies = collect(Notification::sent($this->kid, ChoreReviewed::class))
            ->map(fn (ChoreReviewed $sent) => (fn () => $this->body)->call($sent));

        $this->assertSame(1, $bodies->filter(fn (string $body) => str_contains($body, 'Your pet is growing up!'))->count());
        $this->assertStringContainsString('growing up', $bodies->last());
    }

    public function test_a_pet_put_away_keeps_its_size_and_only_the_one_out_grows(): void
    {
        $tabby = $this->pet('Tabby');
        $gremlin = $this->pet('Gremlin');
        $this->adopt($this->kid, $tabby);
        $this->approveChores($this->kid, 12);

        // Out with the other one: it starts as a baby, and the tabby stops growing.
        $this->adopt($this->kid, $gremlin);
        $this->approveChores($this->kid, 3);

        $this->assertSame(12, $this->growthOf($this->kid, $tabby));
        $this->assertSame(3, $this->growthOf($this->kid, $gremlin));

        // And back again: no reset, and it carries on from where it was.
        app(CosmeticService::class)->wear($this->kid->fresh(), $tabby);
        app()->forgetScopedInstances();
        $this->approveChores($this->kid, 1);

        $this->assertSame(13, $this->growthOf($this->kid, $tabby));
        $this->assertSame(PetStage::Young, app(PetService::class)->stageOf($this->kid, $tabby));
    }

    public function test_a_kid_with_no_pet_out_approves_chores_as_before(): void
    {
        $this->approveChores($this->kid, 2);

        $this->assertSame(2, ChoreCompletion::where('status', 'approved')->count());
    }

    public function test_the_kid_can_raise_it_again_from_a_baby_from_the_locker(): void
    {
        $tabby = $this->pet('Tabby');
        $this->adopt($this->kid, $tabby);
        $this->approveChores($this->kid, 31);

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.locker')
            ->call('pickSlot', 'pet')
            ->assertSee('Grown up')
            ->assertSee('Raise again from a baby')
            ->call('raiseAgain', $tabby->id)
            ->assertSee('Tabby is a baby again.')
            ->assertSee('10 more chores and it grows up')
            // Nothing to go back to once it is a baby already.
            ->assertDontSee('Raise again from a baby');

        $this->assertSame(0, $this->growthOf($this->kid, $tabby));
    }

    public function test_a_kid_cannot_reset_a_siblings_pet(): void
    {
        $tabby = $this->pet('Tabby');
        $sibling = Profile::factory()->for($this->household)->create(['bonus_tickets' => 40]);
        $this->adopt($sibling, $tabby);
        $this->approveChores($sibling, 5);

        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.locker')->call('raiseAgain', $tabby->id);

        $this->assertSame(5, $this->growthOf($sibling, $tabby));
    }

    public function test_the_locker_shows_the_pet_out_with_a_feed_button(): void
    {
        $tabby = $this->pet('Tabby');
        $this->adopt($this->kid, $tabby);
        $this->approveChores($this->kid, 4);

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.locker')
            ->call('pickSlot', 'pet')
            ->assertSee('data-pet-out', false)
            ->assertSee('fq-pet-feed', false)
            // And a tap on anything empty on any of their pages feeds it too.
            ->assertSee('feed-on-tap', false)
            ->assertSee('Tap anywhere empty', false)
            ->assertSee('6 more chores and it grows up');
    }

    public function test_a_traded_pet_arrives_as_grown_as_it_left(): void
    {
        $tabby = $this->pet('Tabby', ['stock' => 'limited']);
        OwnedCosmetic::create([
            'household_id' => $this->household->id,
            'profile_id' => $this->kid->id,
            'cosmetic_id' => $tabby->id,
            'tickets_paid' => 10,
            'growth' => 17,
        ]);
        $sibling = Profile::factory()->for($this->household)->create();

        app(CosmeticService::class)->handOver($tabby, $this->kid, $sibling);

        $this->assertSame(PetStage::Young, app(PetService::class)->stageOf($sibling, $tabby));
    }

    /** Three fixed sizes, one per age — the art has no say in how big a pet is. */
    public function test_each_age_is_drawn_at_its_own_fixed_size(): void
    {
        $this->assertSame([80, 100, 120], array_map(fn (PetStage $stage) => $stage->pixels(), PetStage::cases()));
        // Scales on the pet layer's 78px box.
        $this->assertSame(1.026, PetStage::Baby->scale());
        $this->assertSame(1.282, PetStage::Young->scale());
        $this->assertSame(1.538, PetStage::Adult->scale());

        $tabby = $this->pet('Tabby', ['baby_art_path' => 'cosmetics/'.$this->household->id.'/tabby-baby.png']);
        $this->adopt($this->kid, $tabby);

        $sprite = app(PetService::class)->spriteFor($this->kid->fresh());
        $this->assertStringContainsString('stage=baby', $sprite['src']);
        $this->assertSame(1.026, $sprite['scale']);

        Auth::guard('profile')->login($this->kid->fresh());
        Volt::test('kid.bonus')->assertSee('scale="1.026"', false);

        // The same size whichever sheet it is drawn from.
        $this->assertSame(1.026, $this->pet('Gremlin', ['young_art_path' => 'x/young.png'])->drawScale(PetStage::Baby));
    }

    public function test_a_young_pet_with_only_baby_art_uses_the_adult_sheet(): void
    {
        $tabby = $this->pet('Tabby', ['baby_art_path' => 'x/baby.png']);

        $this->assertSame(PetStage::Adult, $tabby->drawnStage(PetStage::Young));
        $this->assertSame(PetStage::Baby, $tabby->drawnStage(PetStage::Baby));
        $this->assertSame(PetStage::Young, $this->pet('Gremlin', ['young_art_path' => 'x/young.png'])->drawnStage(PetStage::Baby));
    }

    public function test_the_art_route_serves_the_stage_sheet_and_falls_back_to_the_adult(): void
    {
        $disk = Storage::disk('drawings');
        $disk->put('cosmetics/1/adult.png', 'ADULT');
        $disk->put('cosmetics/1/baby.png', 'BABY');

        $tabby = $this->pet('Tabby', ['art_path' => 'cosmetics/1/adult.png', 'baby_art_path' => 'cosmetics/1/baby.png']);

        $this->assertSame('BABY', $this->get(route('cosmetics.art', ['cosmetic' => $tabby, 'stage' => 'baby']))->streamedContent());
        $this->assertSame('ADULT', $this->get(route('cosmetics.art', ['cosmetic' => $tabby, 'stage' => 'young']))->streamedContent());
        $this->assertSame('ADULT', $this->get(route('cosmetics.art', ['cosmetic' => $tabby]))->streamedContent());
    }

    /**
     * An all-ages sheet the way the prompt asks for one: nine by six, baby rows
     * at the top, each age smaller than the one above, ruled grid and all.
     * `$side` is its height; it is half as wide again.
     *
     * @param  array<string, array<int, int>>  $skip  poses to leave out, by age
     */
    private function familyPng(int $side = 1200, array $skip = [], bool $youngIsAdult = false, bool $checkerboard = false, bool $touching = false, bool $crowded = false, bool $tailUp = false, bool $joined = false): string
    {
        $width = (int) ($side * 1.5);
        $image = imagecreatetruecolor($width, $side);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);

        // The "transparency" a generator paints in instead of leaving any.
        if ($checkerboard) {
            $light = imagecolorallocate($image, 238, 238, 238);
            $dark = imagecolorallocate($image, 204, 204, 204);

            for ($y = 0; $y < $side; $y += 16) {
                for ($x = 0; $x < $width; $x += 16) {
                    imagefilledrectangle($image, $x, $y, $x + 15, $y + 15, (($x + $y) / 16) % 2 ? $dark : $light);
                }
            }
        }

        $fur = imagecolorallocate($image, 168, 116, 72);
        // Each age its own coat, as real art is: a copied age is the adult's coat again.
        $coats = [
            'baby' => imagecolorallocate($image, 236, 196, 140),
            'young' => imagecolorallocate($image, 204, 150, 96),
            'adult' => $fur,
        ];
        $cell = $side / 6;
        $heights = ['baby' => 0.40, 'young' => $youngIsAdult ? 0.70 : 0.55, 'adult' => 0.70];

        foreach (['baby', 'young', 'adult'] as $band => $age) {
            foreach (range(0, 17) as $index) {
                if (in_array($index, $skip[$age] ?? [], true)) {
                    continue;
                }

                $height = $cell * $heights[$age];
                $left = ($index % 9) * $cell;
                $bottom = ($band * 2 + intdiv($index, 9)) * $cell + $cell * 0.9;

                imagefilledellipse($image, (int) ($left + $cell / 2), (int) ($bottom - $height / 2), (int) ($height * 0.8), (int) $height, $coats[$youngIsAdult && $age === 'young' ? 'adult' : $age]);

                // A pet on its back with its tail curled up behind it, higher
                // than its paws — the gremlin that balanced its bone on its tail.
                if ($tailUp && $index === 15) {
                    $tailLeft = (int) ($left + $cell / 2 - $height * 0.42);
                    imagefilledrectangle($image, $tailLeft, (int) ($bottom - $height * 1.15), $tailLeft + (int) ($cell * 0.05), (int) ($bottom - $height * 0.4), $coats[$age]);
                }
            }
        }

        // What a real generator did: the adult idle's feet resting on the head
        // of the adult held below it, so the two rows never have an empty line
        // between them and the two dogs are one piece of art.
        // Packed the way a real generator packed a kitten: every row touching
        // the one below it (a bridge down every column-one gap, like the
        // scruff hand reaching up), and the young sleeping pose's tail curled
        // against the tossing one beside it.
        if ($crowded) {
            foreach (range(1, 5) as $line) {
                imagefilledrectangle($image, (int) ($cell * 0.47), (int) ($cell * ($line - 0.15)), (int) ($cell * 0.53), (int) ($cell * ($line + 0.35)), $fur);
            }

            imagefilledrectangle($image, (int) ($cell * 3.6), (int) ($cell * 3.8), (int) ($cell * 4.4), (int) ($cell * 3.84), $fur);
        }

        // Two of the adults drawn joined side by side, as a real gremlin sheet
        // had its idle and blink: one piece of art across two cells.
        if ($joined) {
            imagefilledrectangle($image, (int) ($cell * 0.6), (int) ($cell * 4.6), (int) ($cell * 1.4), (int) ($cell * 4.66), $fur);
        }

        if ($touching) {
            imagefilledrectangle($image, (int) ($cell * 0.46), (int) ($cell * 4.85), (int) ($cell * 0.54), (int) ($cell * 5.25), $fur);
        }

        // The grid a generator rules in whatever the prompt says.
        $ink = imagecolorallocate($image, 90, 40, 20);

        foreach ($touching || $joined ? [] : range(1, 8) as $line) {
            imagefilledrectangle($image, (int) ($line * $cell) - 1, 0, (int) ($line * $cell), $side - 1, $ink);
        }

        foreach ($touching || $joined ? [] : range(1, 5) as $line) {
            imagefilledrectangle($image, 0, (int) ($line * $cell) - 1, $width - 1, (int) ($line * $cell), $ink);
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function standingHeight(string $sheet): int
    {
        $image = imagecreatefromstring($sheet);
        $top = null;
        $bottom = null;

        for ($y = 0; $y < 256; $y++) {
            for ($x = 0; $x < 256; $x += 2) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) < 110) {
                    $top ??= $y;
                    $bottom = $y;
                }
            }
        }

        return $bottom - $top;
    }

    public function test_one_square_sheet_is_cut_into_three_ages_that_pass_their_checks(): void
    {
        $art = app(CosmeticArt::class);
        $png = $this->familyPng();

        $this->assertTrue($art->isFamilySheet($png));
        $this->assertFalse($art->isFamilySheet($this->petSheetPng()));

        $family = $art->prepareFamily($png);

        $this->assertNotContains('fail', array_column($family['checks'], 'status'), json_encode($family['checks']));

        foreach (['baby', 'young', 'adult'] as $age) {
            $this->assertNotNull($family['sheets'][$age], $age);
            $this->assertSame([1536, 768], array_slice(getimagesizefromstring($family['sheets'][$age]), 0, 2));
        }

        // Every age is cut at the same full size — the baby drawn at 40% of
        // the cell comes out as tall as the adult drawn at 70%, because how
        // big each age looks is PetStage::pixels(), not the drawing.
        foreach (['baby', 'young', 'adult'] as $age) {
            $this->assertEqualsWithDelta(0.7 * 256, $this->standingHeight($family['sheets'][$age]), 8, $age);
        }
    }

    public function test_a_big_sheet_with_the_checkerboard_painted_in_is_cleaned_and_cut(): void
    {
        $family = app(CosmeticArt::class)->prepareFamily($this->familyPng(side: 1536, checkerboard: true));

        $this->assertNotContains('fail', array_column($family['checks'], 'status'), json_encode($family['checks']));
        $this->assertContains('Cut out the painted-in background', array_column($family['checks'], 'label'));
        $this->assertNotNull($family['sheets']['baby']);
        $this->assertNotNull($family['sheets']['young']);
    }

    /**
     * Rows a generator let touch are split where the animals sit, and two dogs
     * drawn joined are cut apart where they meet — each whole, in its own cell.
     */
    /**
     * A kitten sheet packed so tight no row had a clean gap, with the sleeping
     * pose's tail against the toss beside it, fell back to even strips and lost
     * the sleeping kitten into the toss cell.
     */
    public function test_a_sheet_where_every_row_touches_still_finds_every_pose(): void
    {
        $family = app(CosmeticArt::class)->prepareFamily($this->familyPng(crowded: true, skip: [], youngIsAdult: false));
        $labels = array_column($family['checks'], 'label');

        $this->assertNotContains('fail', array_column($family['checks'], 'status'), json_encode($family['checks']));
        $this->assertStringNotContainsString('even strips', $labels[0]);
        $this->assertNotNull($family['sheets']['young']);
    }

    public function test_two_dogs_drawn_touching_across_a_row_come_out_as_two_poses(): void
    {
        $family = app(CosmeticArt::class)->prepareFamily($this->familyPng(touching: true));
        $labels = array_column($family['checks'], 'label');

        $this->assertNotContains('fail', array_column($family['checks'], 'status'), json_encode($family['checks']));
        $this->assertContains('Adult: All 18 poses are there', $labels);
        $this->assertContains('Adult: One animal, one size', $labels);
        $this->assertStringContainsString('some were touching', $labels[0]);

        // The cell below has its dog, and the idle cell holds one dog, not two.
        $adult = imagecreatefromstring($family['sheets']['adult']);
        $idleTop = null;

        for ($y = 0; $y < 256 && $idleTop === null; $y++) {
            for ($x = 0; $x < 256; $x += 2) {
                if (((imagecolorat($adult, $x, $y) >> 24) & 0x7F) < 110) {
                    $idleTop = $y;

                    break;
                }
            }
        }

        $this->assertGreaterThan(40, $idleTop, 'The idle cell was stretched to hold two dogs.');
    }

    public function test_an_age_that_repeats_the_drawing_above_it_fails(): void
    {
        $family = app(CosmeticArt::class)->prepareFamily($this->familyPng(youngIsAdult: true));

        $this->assertNull($family['sheets']['young']);
        $this->assertContains('Young: it is the adult drawing again — generate the picture again', array_column($family['checks'], 'label'));
        $this->assertContains('fail', array_column($family['checks'], 'status'));
    }

    /** Every pet has all three ages, so a younger age with a pose missing fails like the adult. */
    public function test_any_age_missing_a_pose_fails(): void
    {
        $art = app(CosmeticArt::class);

        foreach (['baby', 'young', 'adult'] as $age) {
            $family = $art->prepareFamily($this->familyPng(skip: [$age => [6]]));

            $this->assertContains('fail', array_column($family['checks'], 'status'), $age);
        }
    }

    public function test_a_square_pet_upload_publishes_with_all_three_ages(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->call('$set', 'slot', 'pet')
            ->set('upload', UploadedFile::fake()->createWithContent('family.png', $this->familyPng()))
            ->assertSee('found all 54 poses')
            ->set('name', 'Gremlin')
            ->set('stock', 'limited')
            ->set('effect', 'rainbow')
            ->call('bumpCost', 15)
            ->call('publish')
            ->assertHasNoErrors();

        $pet = Cosmetic::where('name', 'Gremlin')->firstOrFail();

        $this->assertFalse($pet->isDraft());
        $this->assertSame(20, $pet->cost);
        $this->assertSame('rainbow', $pet->effect->value);

        foreach ($pet->artPaths() as $path) {
            Storage::disk('drawings')->assertExists($path);
        }

        $this->assertCount(3, $pet->artPaths());
        $this->assertSame(PetStage::Baby->scale(), $pet->drawScale(PetStage::Baby));
    }

    /** A single four-by-three sheet is one age, and a pet needs all three. */
    public function test_a_pet_upload_that_is_not_the_whole_sheet_is_refused(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->call('$set', 'slot', 'pet')
            ->set('upload', UploadedFile::fake()->createWithContent('adult.png', $this->petSheetPng()))
            ->assertSee('A pet needs all three ages in one 3:2 picture')
            ->set('name', 'Tabby')
            ->call('publish')
            ->assertHasErrors('upload');

        $this->assertSame(0, Cosmetic::where('name', 'Tabby')->count());
    }

    public function test_the_all_ages_prompt_lays_out_nine_by_six_and_spells_out_the_held_pose(): void
    {
        $prompt = CosmeticSlot::PET_FAMILY_PROMPT;

        $this->assertStringContainsString('9 columns × 6 rows', $prompt);
        $this->assertStringContainsString('Rows 1–2: the BABY. Rows 3–4: the YOUNG pet. Rows 5–6: the ADULT.', $prompt);
        $this->assertStringContainsString('NOT sitting', $prompt);
        // Stray toys, and drawings joined together — a scruff hand reaching
        // into the row above was one — are what generators got wrong. The toy
        // is only ever on its own: the app draws it into the empty paws.
        $this->assertStringContainsString('EXACTLY ONE cell of each age', $prompt);
        $this->assertStringContainsString('their paws are EMPTY', $prompt);
        $this->assertStringContainsString('EVERY DRAWING IS ITS OWN ISLAND', $prompt);
        $this->assertStringContainsString('Draw NO hand, arm or person holding it', $prompt);
        // Generators shrank the second row to fit the toy; one scale per age.
        $this->assertStringContainsString('ONE SCALE PER AGE', $prompt);
        // Each age's toy cell is cut into that age's sheet, so the toy can wear out.
        $this->assertStringContainsString('ragged, torn and patched with the ADULT', $prompt);
        $this->assertStringContainsString('Never repeat one age\'s drawing for another', $prompt);
        $this->assertStringContainsString('Hand back a PNG file', $prompt.CosmeticSlot::Pet->promptOutput());
        $this->assertStringContainsString('1536x1024 pixels', CosmeticSlot::Pet->promptOutput());
    }

    public function test_the_console_has_one_pet_prompt_and_no_per_age_uploads(): void
    {
        $this->pet('Tabby');
        Auth::guard('profile')->login($this->parent);

        $html = Volt::test('parent.cosmetics')->call('pickListSlot', 'pet')->html();

        $this->assertStringContainsString('9 columns × 6 rows', $html);
        $this->assertStringNotContainsString('Pet · one age', $html);
        $this->assertStringNotContainsString('data-stage-chip', $html);
    }

    /**
     * A draft saved under a check that has since been fixed is checked again
     * when published, rather than staying stuck behind the old failure.
     */
    public function test_a_draft_stuck_on_an_old_failed_check_publishes_once_it_passes_today(): void
    {
        $family = app(CosmeticArt::class)->prepareFamily($this->familyPng());
        $disk = Storage::disk('drawings');
        $disk->put('cosmetics/1/cosmo.png', $family['sheets']['adult']);

        $draft = $this->pet('Cosmo', [
            'art_path' => 'cosmetics/1/cosmo.png',
            'published_at' => null,
            'checks' => [['label' => 'Adult: The background isn\'t transparent', 'status' => 'fail']],
        ]);

        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->assertSee('CHECK AGAIN')
            ->call('publishDraft', $draft->id)
            ->assertSee('Cosmo is in the shop.');

        $this->assertFalse($draft->fresh()->isDraft());
    }

    /**
     * Try it out: the new pet runs about on the grown-up's own screen, at any
     * age, straight from the cut — and nothing is saved until Publish.
     */
    public function test_a_new_pet_can_be_tried_out_at_every_age_before_it_is_published(): void
    {
        Auth::guard('profile')->login($this->parent);

        $page = Volt::test('parent.cosmetics')
            ->call('$set', 'slot', 'pet')
            ->set('upload', UploadedFile::fake()->createWithContent('family.png', $this->familyPng()))
            ->set('name', 'Cosmo')
            ->assertSee('Try it out')
            ->assertDontSee('data-pet-trial', false)
            ->call('tryOut', 'baby')
            ->assertSee('data-pet-trial="baby"', false)
            ->assertSee('sheet="data:image/png;base64,', false)
            // Drawn at the baby's size, as a kid would see it.
            ->assertSee('scale="1.026"', false)
            ->call('tryOut', 'adult')
            ->assertSee('data-pet-trial="adult"', false);

        $this->assertSame(0, Cosmetic::where('name', 'Cosmo')->count(), 'Trying it out saved something.');

        $page->call('publish')->assertHasNoErrors()->assertSee('Cosmo is in the shop.')->assertDontSee('data-pet-trial', false);

        $this->assertCount(3, Cosmetic::where('name', 'Cosmo')->firstOrFail()->artPaths());
    }

    public function test_a_pet_with_an_age_copied_from_another_cannot_be_published(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->call('$set', 'slot', 'pet')
            ->set('upload', UploadedFile::fake()->createWithContent('family.png', $this->familyPng(youngIsAdult: true)))
            ->set('name', 'Copycat')
            ->call('publish')
            ->assertHasErrors('upload');

        $this->assertSame(0, Cosmetic::where('name', 'Copycat')->count());
    }

    /**
     * Pets made before every pet had three ages are taken out of the game on
     * deploy — ownership, the worn column, and any open trade with one in it.
     */
    public function test_the_clean_up_migration_removes_pets_without_every_age(): void
    {
        $disk = Storage::disk('drawings');
        $disk->put('cosmetics/1/old.png', 'OLD');
        $old = $this->pet('Old', ['art_path' => 'cosmetics/1/old.png', 'stock' => 'limited']);
        $kept = $this->pet('Kept', ['baby_art_path' => 'x/baby.png', 'young_art_path' => 'x/young.png']);

        $this->adopt($this->kid, $kept);
        $this->adopt($this->kid, $old);
        $sibling = Profile::factory()->for($this->household)->create(['points' => 100]);

        $offer = SiblingOffer::create([
            'household_id' => $this->household->id,
            'from_profile_id' => $this->kid->id,
            'to_profile_id' => $sibling->id,
            'give_asset' => 'cosmetic',
            'give_amount' => 0,
            'give_cosmetic_id' => $old->id,
            'get_asset' => 'points',
            'get_amount' => 10,
            'status' => 'pending',
            'expires_at' => now()->addDay(),
        ]);

        (require database_path('migrations/2026_09_18_013112_remove_pets_without_every_age.php'))->up();

        $this->assertNull(Cosmetic::find($old->id));
        $this->assertNotNull(Cosmetic::find($kept->id));
        $this->assertNull($this->kid->fresh()->worn_pet_id);
        $this->assertSame(0, OwnedCosmetic::where('cosmetic_id', $old->id)->count());
        $this->assertSame(1, OwnedCosmetic::where('cosmetic_id', $kept->id)->count());
        $this->assertSame('cancelled', $offer->fresh()->status->value);
        $disk->assertMissing('cosmetics/1/old.png');
    }

    /**
     * A grown-up can have a pet too, for testing: free, aged by hand, running
     * about their own pages — and nothing a kid would notice.
     */
    public function test_a_grown_up_takes_a_pet_out_for_free_and_picks_its_age(): void
    {
        $tabby = $this->pet('Tabby', ['baby_art_path' => 'x/baby.png', 'young_art_path' => 'x/young.png']);
        $ticketsBefore = $this->parent->fresh()->bonus_tickets;

        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->call('pickListSlot', 'pet')
            ->assertSee('TAKE OUT')
            ->call('takeOutPet', $tabby->id)
            ->assertSee('data-my-pet="'.$tabby->id.'"', false)
            // On the grown-up's own page, like a kid's pet on theirs.
            ->assertSee('<fq-pets', false)
            ->assertSee('feed-on-tap', false)
            ->assertSee('stage=baby', false)
            ->call('petAge', 'adult')
            ->assertSee('scale="'.PetStage::Adult->scale().'"', false);

        $copy = OwnedCosmetic::where('profile_id', $this->parent->id)->where('cosmetic_id', $tabby->id)->firstOrFail();
        $this->assertSame(0, $copy->tickets_paid);
        $this->assertSame(PetStage::Adult->startsAt(), $copy->growth);
        $this->assertSame($ticketsBefore, $this->parent->fresh()->bonus_tickets);

        // Never a visitor on a kid's pages: siblings only.
        foreach (range(0, 23) as $hour) {
            $this->travelTo(now()->startOfDay()->addHours($hour));
            app()->forgetScopedInstances();
            $this->assertNull(app(CosmeticService::class)->visitingPet($this->kid->fresh()));
        }

        Volt::test('parent.cosmetics')->call('putAwayPet')->assertDontSee('<fq-pets', false);
        $this->assertNull($this->parent->fresh()->worn_pet_id);
    }

    /**
     * A picture too heavy for PHP's upload limit is sent by the browser as a
     * WebP instead, and is a PNG again before anything looks at it.
     */
    public function test_a_webp_upload_is_turned_back_into_a_png_and_publishes(): void
    {
        $image = imagecreatefromstring($this->familyPng());
        imagesavealpha($image, true);
        ob_start();
        imagewebp($image, null, 95);
        $webp = (string) ob_get_clean();

        $png = app(CosmeticArt::class)->asPng($webp);
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($png)[2]);
        $this->assertSame($webp === $png, false);
        $this->assertSame('not a picture', app(CosmeticArt::class)->asPng('not a picture'));

        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->call('$set', 'slot', 'pet')
            ->set('upload', UploadedFile::fake()->createWithContent('family.webp', $webp))
            ->assertHasNoErrors()
            ->assertSee('found all 54 poses')
            ->set('name', 'Webby')
            ->call('publish')
            ->assertHasNoErrors();

        $pet = Cosmetic::where('name', 'Webby')->firstOrFail();

        foreach ($pet->artPaths() as $path) {
            $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring(Storage::disk('drawings')->get($path))[2]);
        }
    }

    public function test_an_egg_only_pet_is_never_in_the_shop(): void
    {
        $surprise = $this->pet('Surprise', ['stock' => 'egg']);

        $this->assertFalse(app(CosmeticService::class)->isForSale($surprise));
        $this->assertFalse(app(CosmeticService::class)->shelfFor($this->kid, CosmeticSlot::Pet)->contains('id', $surprise->id));
        $this->assertSame([CosmeticStock::Shelf, CosmeticStock::Rotating, CosmeticStock::Limited], CosmeticStock::forSlot(CosmeticSlot::Frame));
        $this->assertContains(CosmeticStock::Egg, CosmeticStock::forSlot(CosmeticSlot::Pet));
    }

    /**
     * Every egg-only pet is one egg in the shop, in its own colour, and the
     * first kid to buy it has it — gone for the whole house.
     */
    public function test_each_egg_is_one_pet_and_gone_for_everybody_once_bought(): void
    {
        $red = $this->pet('Redling', ['stock' => 'egg']);
        $blue = $this->pet('Bluey', ['stock' => 'egg']);
        $sibling = Profile::factory()->for($this->household)->create(['bonus_tickets' => 40]);
        $pets = app(PetService::class);

        $this->assertSame([$red->id, $blue->id], $pets->eggsForSale($this->household)->pluck('id')->all());
        $this->assertNotSame(PetEgg::hueFor($red->id), PetEgg::hueFor($blue->id));

        $egg = $pets->buyEgg($this->kid, $red);

        $this->assertSame($red->id, $egg->cosmetic_id);
        $this->assertSame(100 - PetEgg::PRICE, $this->kid->fresh()->bonus_tickets);
        app()->forgetScopedInstances();
        $this->assertSame([$blue->id], app(PetService::class)->eggsForSale($this->household)->pluck('id')->all());

        // The sibling can't have the one already bought...
        try {
            app(PetService::class)->buyEgg($sibling, $red);
            $this->fail('Two kids bought the same egg.');
        } catch (CosmeticUnavailableException $e) {
            $this->assertSame('Somebody got to that egg first.', $e->getMessage());
        }

        // ...but can have the other one.
        app(PetService::class)->buyEgg($sibling, $blue);
        $this->assertTrue(app(PetService::class)->eggsForSale($this->household)->isEmpty());
    }

    public function test_a_kid_cracks_one_egg_at_a_time(): void
    {
        $red = $this->pet('Redling', ['stock' => 'egg']);
        $blue = $this->pet('Bluey', ['stock' => 'egg']);
        app(PetService::class)->buyEgg($this->kid, $red);
        app()->forgetScopedInstances();

        $this->assertFalse(app(PetService::class)->canBuyEgg($this->kid));
        $this->expectException(CosmeticUnavailableException::class);
        app(PetService::class)->buyEgg($this->kid->fresh(), $blue);
    }

    public function test_chores_crack_the_egg_instead_of_growing_a_pet_and_the_fifth_hatches_the_pet_inside(): void
    {
        Notification::fake();
        $tabby = $this->pet('Tabby');
        $this->adopt($this->kid, $tabby);
        $this->pet('Decoy', ['stock' => 'egg']);
        $surprise = $this->pet('Surprise', ['stock' => 'egg']);
        app(PetService::class)->buyEgg($this->kid->fresh(), $surprise);
        app()->forgetScopedInstances();

        $this->approveChores($this->kid, 4);

        $egg = PetEgg::firstOrFail();
        $this->assertSame(4, $egg->cracks);
        $this->assertFalse($egg->isHatched());
        $this->assertSame(0, $this->growthOf($this->kid, $tabby), 'The egg took the chores, not the pet.');

        $this->approveChores($this->kid, 1);

        $egg->refresh();
        $this->assertTrue($egg->isHatched());
        $this->assertSame($surprise->id, $egg->hatched_cosmetic_id, 'It hatched the pet in that egg.');
        $this->assertSame($surprise->id, $this->kid->fresh()->worn_pet_id);
        $this->assertSame(0, $this->growthOf($this->kid, $surprise), 'It hatches as a baby.');

        $bodies = collect(Notification::sent($this->kid, ChoreReviewed::class))
            ->map(fn (ChoreReviewed $sent) => (fn () => $this->body)->call($sent));
        $this->assertStringContainsString('Your egg cracked! 1 more chore and it hatches.', $bodies[3]);
        $this->assertStringContainsString('Your egg hatched — meet Surprise!', $bodies->last());
    }

    public function test_the_egg_is_out_on_the_kids_pages_in_its_colour_and_the_hatching_plays_once(): void
    {
        $surprise = $this->pet('Surprise', ['stock' => 'egg']);
        $hue = PetEgg::hueFor($surprise->id);
        app(PetService::class)->buyEgg($this->kid, $surprise);
        app()->forgetScopedInstances();
        $this->approveChores($this->kid, 2);

        Auth::guard('profile')->login($this->kid->fresh());
        Volt::test('kid.bonus')->assertSee('egg="2"', false)->assertSee('egg-hue="'.$hue.'"', false)->assertDontSee('sheet="', false);
        Volt::test('kid.locker')->call('pickSlot', 'pet')->assertSee('data-egg-out', false)->assertSee('3 more chores and it hatches');

        $this->approveChores($this->kid, 3);
        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.bonus')->assertSee('hatch="'.$hue.'"', false)->assertDontSee('egg="', false);
        Volt::test('kid.bonus')->assertDontSee('hatch="', false);
        $this->assertNotNull(PetEgg::firstOrFail()->revealed_at);
    }

    public function test_an_egg_on_the_login_door_is_drawn_smaller_than_on_the_kids_pages(): void
    {
        $surprise = $this->pet('Surprise', ['stock' => 'egg']);
        app(PetService::class)->buyEgg($this->kid, $surprise);
        app()->forgetScopedInstances();

        $html = Volt::test('login')->html();

        $this->assertStringContainsString('&quot;egg&quot;:0', $html);
        $this->assertStringContainsString('&quot;scale&quot;:'.round(PetStage::Baby->scale() * 0.6, 3), $html);
    }

    public function test_an_egg_bought_hatches_even_if_its_pet_is_pulled_afterwards(): void
    {
        $surprise = $this->pet('Surprise', ['stock' => 'egg']);
        app(PetService::class)->buyEgg($this->kid, $surprise);
        $surprise->update(['pulled_at' => now()]);
        app()->forgetScopedInstances();

        $this->approveChores($this->kid, 5);

        $this->assertSame('Surprise', PetEgg::firstOrFail()->hatchedInto->name);
    }

    /**
     * A kid who can't afford an egg is told so on the eggs' own card — the
     * refusal used to land at the top of the page, out of sight.
     */
    public function test_a_kid_short_of_tickets_is_told_on_the_egg_card(): void
    {
        $surprise = $this->pet('Surprise', ['stock' => 'egg']);
        $this->kid->update(['bonus_tickets' => 11]);
        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.locker')
            ->call('pickSlot', 'pet')
            ->assertSee('data-egg-short', false)
            ->assertSee('4 more tickets')
            ->call('buyEgg', $surprise->id)
            ->assertSee('data-egg-note', false)
            ->assertSee('Not enough tickets — need 4 more.');

        $this->assertSame(0, PetEgg::count());
    }

    public function test_a_kid_picks_an_egg_by_its_colour_in_the_locker(): void
    {
        $red = $this->pet('Redling', ['stock' => 'egg']);
        $this->pet('Bluey', ['stock' => 'egg']);
        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.locker')
            ->call('pickSlot', 'pet')
            ->assertSee('data-eggs-for-sale', false)
            ->assertSee('data-egg-colour="'.mb_strtolower(PetEgg::colourName(PetEgg::hueFor($red->id))).'"', false)
            // Nothing gives away what's inside.
            ->assertDontSee('Redling')
            ->call('buyEgg', $red->id)
            ->assertSee('data-egg-out', false)
            ->assertSee('Hatch yours first');

        $this->assertSame($red->id, PetEgg::firstOrFail()->cosmetic_id);
    }

    public function test_a_grown_up_can_make_an_egg_only_pet_but_not_an_egg_only_frame(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->call('$set', 'slot', 'pet')
            ->assertSee('Egg only')
            ->set('upload', UploadedFile::fake()->createWithContent('family.png', $this->familyPng()))
            ->set('name', 'Surprise')
            ->set('stock', 'egg')
            ->call('publish')
            ->assertHasNoErrors();

        $this->assertSame(CosmeticStock::Egg, Cosmetic::where('name', 'Surprise')->firstOrFail()->stock);

        Volt::test('parent.cosmetics')
            ->call('$set', 'slot', 'frame')
            ->assertDontSee('Egg only')
            ->set('stock', 'egg')
            ->call('publish')
            ->assertHasErrors('stock');
    }

    public function test_tossing_a_pet_being_tried_out_leaves_nothing_behind(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->call('$set', 'slot', 'pet')
            ->set('upload', UploadedFile::fake()->createWithContent('family.png', $this->familyPng()))
            ->set('name', 'Cosmo')
            ->call('tryOut', 'baby')
            ->call('toss')
            ->assertSet('upload', null)
            ->assertSet('trialAge', null)
            ->assertDontSee('data-pet-trial', false);

        $this->assertSame(0, Cosmetic::where('name', 'Cosmo')->count());
        $this->assertSame([], Storage::disk('drawings')->allFiles());
    }

    public function test_binning_a_draft_pet_takes_every_sheet_with_it(): void
    {
        $disk = Storage::disk('drawings');
        $disk->put('cosmetics/1/a.png', 'A');
        $disk->put('cosmetics/1/b.png', 'B');
        $draft = $this->pet('Draft', ['art_path' => 'cosmetics/1/a.png', 'baby_art_path' => 'cosmetics/1/b.png', 'published_at' => null]);

        Auth::guard('profile')->login($this->parent);
        Volt::test('parent.cosmetics')->call('binDraft', $draft->id);

        $disk->assertMissing('cosmetics/1/a.png');
        $disk->assertMissing('cosmetics/1/b.png');
    }

    /**
     * Play, toss and back are drawn with empty paws, and the app puts the toy
     * in them — so the cut records where the paws are, for every age.
     */
    public function test_the_cut_records_where_the_paws_and_the_toy_are(): void
    {
        $family = app(CosmeticArt::class)->prepareFamily($this->familyPng());

        foreach (['baby', 'young', 'adult'] as $age) {
            $anchors = $family['anchors'][$age];

            $this->assertSame(['play', 'toss', 'back', 'toy'], array_keys($anchors), $age);

            // The test animals are ovals in the middle of their cells: held-up
            // paws are the middle of the top, and the pounce reaches right.
            $this->assertEqualsWithDelta(0.5, $anchors['back'][0], 0.03, $age);
            $this->assertLessThan(0.5, $anchors['back'][1], $age);
            $this->assertGreaterThan(0.6, $anchors['play'][0], $age);
            $this->assertCount(4, $anchors['toy'], $age);
        }
    }

    /**
     * Two animals drawn joined side by side are one piece, so there is no gap
     * between them to find. The row is split where they meet instead — never
     * cut into even strips, which sliced a wide landed gremlin in two.
     */
    public function test_two_animals_joined_side_by_side_are_split_where_they_meet(): void
    {
        $family = app(CosmeticArt::class)->prepareFamily($this->familyPng(joined: true));
        $labels = array_column($family['checks'], 'label');

        $this->assertNotContains('fail', array_column($family['checks'], 'status'), json_encode($family['checks']));
        $this->assertStringNotContainsString('even strips', $labels[0]);
        $this->assertStringContainsString('some were touching', $labels[0]);
        $this->assertEqualsWithDelta(0.7 * 256, $this->standingHeight($family['sheets']['adult']), 8);
    }

    public function test_the_paws_are_found_over_the_body_even_when_the_tail_reaches_higher(): void
    {
        $family = app(CosmeticArt::class)->prepareFamily($this->familyPng(tailUp: true));

        foreach (['baby', 'young', 'adult'] as $age) {
            $this->assertEqualsWithDelta(0.5, $family['anchors'][$age]['back'][0], 0.08, $age);
        }
    }

    public function test_a_published_pet_hands_the_layer_its_eighteen_pose_layout(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->call('$set', 'slot', 'pet')
            ->set('upload', UploadedFile::fake()->createWithContent('family.png', $this->familyPng()))
            ->set('name', 'Gremlin')
            ->call('tryOut', 'baby')
            ->assertSee('rig="{&quot;poses&quot;:18', false)
            ->call('publish')
            ->assertHasNoErrors();

        $pet = Cosmetic::where('name', 'Gremlin')->firstOrFail();

        $this->assertSame(['baby', 'young', 'adult'], array_keys($pet->pet_rig['anchors']));

        $this->adopt($this->kid, $pet);
        $sprite = app(PetService::class)->spriteFor($this->kid->fresh());

        $this->assertSame(18, $sprite['rig']['poses']);
        $this->assertSame($pet->pet_rig['anchors']['baby'], $sprite['rig']['anchors']);

        Auth::guard('profile')->login($this->kid->fresh());
        Volt::test('kid.bonus')->assertSee('rig="{&quot;poses&quot;:18', false);
    }

    /** A pet made before the re-grid keeps working, on its old layout. */
    public function test_a_pet_from_before_the_re_grid_has_no_rig_and_is_flagged_for_new_art(): void
    {
        $tabby = $this->pet('Tabby', ['baby_art_path' => 'x/baby.png', 'young_art_path' => 'x/young.png']);
        $this->adopt($this->kid, $tabby);

        $this->assertNull(app(PetService::class)->spriteFor($this->kid->fresh())['rig']);

        Auth::guard('profile')->login($this->kid->fresh());
        Volt::test('kid.bonus')->assertDontSee(' rig="', false);

        Auth::guard('profile')->login($this->parent);
        Volt::test('parent.cosmetics')
            ->call('pickListSlot', 'pet')
            ->assertSee('data-old-pet-art', false)
            ->assertSee('NEW ART');
    }

    /** A picture made from the old prompt is turned away with a reason. */
    public function test_an_old_square_all_ages_sheet_is_refused_with_a_reason(): void
    {
        $image = imagecreatetruecolor(1200, 1200);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledellipse($image, 600, 600, 300, 300, imagecolorallocate($image, 168, 116, 72));
        ob_start();
        imagepng($image);
        $square = (string) ob_get_clean();

        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->call('$set', 'slot', 'pet')
            ->set('upload', UploadedFile::fake()->createWithContent('old.png', $square))
            ->assertSee('That is the old square sheet with 12 poses')
            ->set('name', 'Oldie')
            ->call('publish')
            ->assertHasErrors('upload');

        $this->assertSame(0, Cosmetic::where('name', 'Oldie')->count());
    }

    /**
     * New art goes onto the same pet, so a kid who owns it keeps it — grown
     * as far as it was — and its name, price and stock stay as they were.
     */
    public function test_new_art_replaces_a_pets_sheets_without_taking_it_from_anyone(): void
    {
        $disk = Storage::disk('drawings');
        $disk->put('cosmetics/1/old-adult.png', 'OLD');
        $disk->put('cosmetics/1/old-baby.png', 'OLD');
        $tabby = $this->pet('Tabby', [
            'art_path' => 'cosmetics/1/old-adult.png',
            'baby_art_path' => 'cosmetics/1/old-baby.png',
            'stock' => 'limited',
            'cost' => 12,
        ]);
        OwnedCosmetic::create([
            'household_id' => $this->household->id,
            'profile_id' => $this->kid->id,
            'cosmetic_id' => $tabby->id,
            'tickets_paid' => 12,
            'growth' => 17,
        ]);

        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->call('pickListSlot', 'pet')
            ->call('replaceArt', $tabby->id)
            ->assertSet('slot', 'pet')
            ->assertSee('New art for Tabby')
            ->set('upload', UploadedFile::fake()->createWithContent('family.png', $this->familyPng()))
            ->assertSee('Put the new art on Tabby')
            ->call('publish')
            ->assertHasNoErrors()
            ->assertSee('Tabby has its new art.')
            ->assertSet('replacing', null);

        $fresh = $tabby->fresh();

        $this->assertSame(1, Cosmetic::where('slot', 'pet')->count(), 'New art made a new pet.');
        $this->assertSame(['Tabby', 12, 'limited'], [$fresh->name, $fresh->cost, $fresh->stock->value]);
        $this->assertCount(3, $fresh->artPaths());
        $this->assertNotNull($fresh->pet_rig);
        $this->assertSame(17, $this->growthOf($this->kid, $tabby));

        foreach ($fresh->artPaths() as $path) {
            $disk->assertExists($path);
        }

        $disk->assertMissing('cosmetics/1/old-adult.png');
        $disk->assertMissing('cosmetics/1/old-baby.png');
    }

    public function test_new_art_that_fails_its_checks_leaves_the_pet_as_it_was(): void
    {
        $tabby = $this->pet('Tabby', ['baby_art_path' => 'x/baby.png', 'young_art_path' => 'x/young.png']);

        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->call('replaceArt', $tabby->id)
            ->set('upload', UploadedFile::fake()->createWithContent('family.png', $this->familyPng(youngIsAdult: true)))
            ->call('publish')
            ->assertHasErrors('upload');

        $this->assertSame('cosmetics/'.$this->household->id.'/Tabby.png', $tabby->fresh()->art_path);
        $this->assertNull($tabby->fresh()->pet_rig);
    }
}
