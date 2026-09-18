<?php

namespace Tests\Feature;

use App\Enums\CosmeticSlot;
use App\Enums\PetStage;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Cosmetic;
use App\Models\Household;
use App\Models\OwnedCosmetic;
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

    public function test_a_young_pet_is_drawn_smaller_and_from_its_own_sheet_when_it_has_one(): void
    {
        $tabby = $this->pet('Tabby');
        $this->adopt($this->kid, $tabby);

        // No baby art yet: the adult sheet, drawn at baby size.
        $sprite = app(PetService::class)->spriteFor($this->kid->fresh());
        $this->assertSame(0.8, $sprite['scale']);
        $this->assertStringNotContainsString('stage=', $sprite['src']);

        Auth::guard('profile')->login($this->kid->fresh());
        Volt::test('kid.bonus')->assertSee('scale="0.8"', false);

        // Its own sheet is drawn at its true size already, so it is not shrunk again.
        $tabby->update(['baby_art_path' => 'cosmetics/'.$this->household->id.'/tabby-baby.png']);
        app()->forgetScopedInstances();

        $sprite = app(PetService::class)->spriteFor($this->kid->fresh());
        $this->assertStringContainsString('stage=baby', $sprite['src']);
        $this->assertSame(1.0, $sprite['scale']);

        // Borrowing the young sheet, it shrinks only by the difference.
        $this->assertSame(0.889, $this->pet('Gremlin', ['young_art_path' => 'x/young.png'])->drawScale(PetStage::Baby));
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
     * An all-ages sheet the way the prompt asks for one: six by six, baby rows
     * at the top, each age smaller than the one above, ruled grid and all.
     *
     * @param  array<string, array<int, int>>  $skip  poses to leave out, by age
     */
    private function familyPng(int $side = 1200, array $skip = [], bool $youngIsAdult = false, bool $checkerboard = false, bool $touching = false): string
    {
        $image = imagecreatetruecolor($side, $side);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);

        // The "transparency" a generator paints in instead of leaving any.
        if ($checkerboard) {
            $light = imagecolorallocate($image, 238, 238, 238);
            $dark = imagecolorallocate($image, 204, 204, 204);

            for ($y = 0; $y < $side; $y += 16) {
                for ($x = 0; $x < $side; $x += 16) {
                    imagefilledrectangle($image, $x, $y, $x + 15, $y + 15, (($x + $y) / 16) % 2 ? $dark : $light);
                }
            }
        }

        $fur = imagecolorallocate($image, 168, 116, 72);
        $cell = $side / 6;
        $heights = ['baby' => 0.40, 'young' => $youngIsAdult ? 0.70 : 0.55, 'adult' => 0.70];

        foreach (['baby', 'young', 'adult'] as $band => $age) {
            foreach (range(0, 11) as $index) {
                if (in_array($index, $skip[$age] ?? [], true)) {
                    continue;
                }

                $height = $cell * $heights[$age];
                $left = ($index % 6) * $cell;
                $bottom = ($band * 2 + intdiv($index, 6)) * $cell + $cell * 0.9;

                imagefilledellipse($image, (int) ($left + $cell / 2), (int) ($bottom - $height / 2), (int) ($height * 0.8), (int) $height, $fur);
            }
        }

        // What a real generator did: the adult idle's feet resting on the head
        // of the adult held below it, so the two rows never have an empty line
        // between them and the two dogs are one piece of art.
        if ($touching) {
            imagefilledrectangle($image, (int) ($cell * 0.46), (int) ($cell * 4.85), (int) ($cell * 0.54), (int) ($cell * 5.25), $fur);
        }

        // The grid a generator rules in whatever the prompt says.
        $ink = imagecolorallocate($image, 90, 40, 20);

        foreach ($touching ? [] : range(1, 5) as $line) {
            imagefilledrectangle($image, (int) ($line * $cell) - 1, 0, (int) ($line * $cell), $side - 1, $ink);
            imagefilledrectangle($image, 0, (int) ($line * $cell) - 1, $side - 1, (int) ($line * $cell), $ink);
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
            $this->assertSame([1024, 768], array_slice(getimagesizefromstring($family['sheets'][$age]), 0, 2));
        }

        // Each age keeps its true size, so the young one really is smaller.
        $this->assertLessThan($this->standingHeight($family['sheets']['young']), $this->standingHeight($family['sheets']['baby']));
        $this->assertLessThan($this->standingHeight($family['sheets']['adult']), $this->standingHeight($family['sheets']['young']));

        // Drawn at 40% of the cell against the adult's 70%, the baby is lifted
        // to the floor: never under 80% of the adult's height.
        $this->assertGreaterThanOrEqual(0.78, $this->standingHeight($family['sheets']['baby']) / $this->standingHeight($family['sheets']['adult']));
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
    public function test_two_dogs_drawn_touching_across_a_row_come_out_as_two_poses(): void
    {
        $family = app(CosmeticArt::class)->prepareFamily($this->familyPng(touching: true));
        $labels = array_column($family['checks'], 'label');

        $this->assertNotContains('fail', array_column($family['checks'], 'status'), json_encode($family['checks']));
        $this->assertContains('Adult: All 12 poses are there', $labels);
        $this->assertContains('Adult: One animal, one size', $labels);
        $this->assertStringContainsString('some were touching', $labels[0]);

        // The held cell has its dog, and the idle cell holds one dog, not two.
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
            ->assertSee('found all 36 poses')
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
        $this->assertSame(1.0, $pet->drawScale(PetStage::Baby));
    }

    /** A single four-by-three sheet is one age, and a pet needs all three. */
    public function test_a_pet_upload_that_is_not_the_square_is_refused(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->call('$set', 'slot', 'pet')
            ->set('upload', UploadedFile::fake()->createWithContent('adult.png', $this->petSheetPng()))
            ->assertSee('A pet needs all three ages in one square picture')
            ->set('name', 'Tabby')
            ->call('publish')
            ->assertHasErrors('upload');

        $this->assertSame(0, Cosmetic::where('name', 'Tabby')->count());
    }

    public function test_the_all_ages_prompt_lays_out_six_by_six_and_spells_out_the_held_pose(): void
    {
        $prompt = CosmeticSlot::PET_FAMILY_PROMPT;

        $this->assertStringContainsString('6 columns × 6 rows', $prompt);
        $this->assertStringContainsString('Rows 1–2: the BABY. Rows 3–4: the YOUNG pet. Rows 5–6: the ADULT.', $prompt);
        $this->assertStringContainsString('NOT sitting', $prompt);
        // Generators shrank the second row to fit the toy; one scale per age.
        $this->assertStringContainsString('ONE SCALE PER AGE', $prompt);
        $this->assertStringContainsString('make the toy smaller, never the animal', $prompt);
        // Each age's toy cell is cut into that age's sheet, so the toy can wear out.
        $this->assertStringContainsString('ragged, torn and patched with the ADULT', $prompt);
        $this->assertStringContainsString('Never repeat one age\'s drawing for another', $prompt);
        $this->assertStringContainsString('Hand back a PNG file', $prompt.CosmeticSlot::Pet->promptOutput());
        $this->assertStringContainsString('1024x1024 pixels', CosmeticSlot::Pet->promptOutput());
    }

    public function test_the_console_has_one_pet_prompt_and_no_per_age_uploads(): void
    {
        $this->pet('Tabby');
        Auth::guard('profile')->login($this->parent);

        $html = Volt::test('parent.cosmetics')->call('pickListSlot', 'pet')->html();

        $this->assertStringContainsString('6 columns × 6 rows', $html);
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
            // Its own baby art, drawn at its true size.
            ->assertSee('scale="1"', false)
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
}
