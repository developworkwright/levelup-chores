<?php

namespace Tests\Feature;

use App\Enums\CosmeticSlot;
use App\Models\Cosmetic;
use App\Models\Household;
use App\Models\Profile;
use App\Services\CosmeticArt;
use App\Services\CosmeticService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ParentCosmeticsTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $parent;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('drawings');

        $this->household = Household::factory()->create();
        $this->parent = Profile::factory()->parent()->for($this->household)->create();
    }

    /** A 512px PNG: a transparent square with a ring drawn round it, optionally filled in. */
    private function ringPng(bool $filledMiddle = false, bool $transparent = true, int $size = 512): string
    {
        $image = imagecreatetruecolor($size, $size);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, $transparent ? 127 : 0));
        imagealphablending($image, true);

        $gold = imagecolorallocate($image, 255, 201, 61);
        imagesetthickness($image, 30);
        imageellipse($image, $size / 2, $size / 2, (int) ($size * 0.86), (int) ($size * 0.86), $gold);

        if ($filledMiddle) {
            imagefilledellipse($image, $size / 2, $size / 2, (int) ($size * 0.5), (int) ($size * 0.5), $gold);
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function upload(string $png, string $name = 'antlers.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $png);
    }

    public function test_a_clean_frame_passes_every_hard_check(): void
    {
        $checks = app(CosmeticArt::class)->inspect($this->ringPng(), CosmeticSlot::Frame);

        $this->assertNotContains('fail', array_column($checks, 'status'));
        $this->assertContains('Middle 62% is clear', array_column($checks, 'label'));
    }

    public function test_a_frame_with_something_in_the_middle_fails(): void
    {
        $checks = app(CosmeticArt::class)->inspect($this->ringPng(filledMiddle: true), CosmeticSlot::Frame);

        $this->assertContains('fail', array_column($checks, 'status'));
    }

    public function test_the_wrong_size_and_a_solid_background_both_fail(): void
    {
        $labels = array_column(
            array_filter(app(CosmeticArt::class)->inspect($this->ringPng(transparent: false, size: 300), CosmeticSlot::Frame), fn ($c) => $c['status'] === 'fail'),
            'label',
        );

        $this->assertContains('300×300 — needs to be 512×512', $labels);
        $this->assertContains('The background isn\'t transparent', $labels);
    }

    public function test_something_that_is_not_a_png_is_refused(): void
    {
        $checks = app(CosmeticArt::class)->inspect('<?php echo 1;', CosmeticSlot::Frame);

        $this->assertSame([['label' => 'Not a PNG this can read', 'status' => 'fail']], $checks);
    }

    public function test_a_grown_up_publishes_an_upload_straight_to_the_shop(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->set('upload', $this->upload($this->ringPng()))
            ->set('name', 'Gilded Antlers')
            ->set('slot', 'frame')
            ->set('stock', 'limited')
            ->set('flavor', 'animal')
            ->set('motion', 'spin')
            ->call('bumpCost', 3)
            ->call('publish')
            ->assertHasNoErrors()
            ->assertSee('Gilded Antlers is in the shop.');

        $item = Cosmetic::where('name', 'Gilded Antlers')->firstOrFail();

        $this->assertNotNull($item->published_at);
        $this->assertSame(8, $item->cost);
        $this->assertSame('limited', $item->stock->value);
        $this->assertNull($item->recipe);
        Storage::disk('drawings')->assertExists($item->art_path);
        $this->assertStringStartsWith('cosmetics/'.$this->household->id.'/', $item->art_path);
    }

    /**
     * There is no draft: art that fails is never saved at all, and is tossed
     * to make way for the next picture.
     */
    public function test_an_upload_that_fails_its_checks_is_never_saved_and_can_be_tossed(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->assertDontSee('Save draft')
            ->set('upload', $this->upload($this->ringPng(filledMiddle: true)))
            ->set('name', 'Face Already In It')
            ->call('publish')
            ->assertHasErrors('upload')
            ->call('toss')
            ->assertSet('upload', null)
            // Said under the upload box, where the grown-up is looking.
            ->assertSee('data-upload-note', false)
            ->assertSee('Discarded — nothing was saved.');

        $this->assertSame(0, Cosmetic::where('name', 'Face Already In It')->count());
    }

    /** Drafts saved before the draft state was dropped can still be dealt with. */
    public function test_an_old_draft_can_still_be_published_or_binned(): void
    {
        Auth::guard('profile')->login($this->parent);
        $disk = Storage::disk('drawings');

        [$later, $never] = collect(['Later', 'Never'])->map(function (string $name) use ($disk) {
            $path = 'cosmetics/'.$this->household->id.'/'.$name.'.png';
            $disk->put($path, $this->ringPng());

            return Cosmetic::create([
                'household_id' => $this->household->id, 'slot' => 'frame', 'art_path' => $path,
                'name' => $name, 'cost' => 5, 'stock' => 'shelf', 'checks' => [],
            ]);
        })->all();

        Volt::test('parent.cosmetics')
            ->assertSee('Old drafts')
            ->call('publishDraft', $later->id)
            ->call('binDraft', $never->id);

        $this->assertFalse($later->fresh()->isDraft());
        $this->assertNull($never->fresh());
        Storage::disk('drawings')->assertMissing($never->art_path);
    }

    public function test_a_published_item_is_pulled_rather_than_deleted(): void
    {
        Auth::guard('profile')->login($this->parent);
        $double = Cosmetic::where('household_id', $this->household->id)->where('recipe', 'double')->firstOrFail();

        Volt::test('parent.cosmetics')->call('binDraft', $double->id)->call('toggleStock', $double->id);

        $this->assertNotNull($double->fresh());
        $this->assertTrue($double->fresh()->isPulled());

        Volt::test('parent.cosmetics')->call('toggleStock', $double->id);

        $this->assertFalse($double->fresh()->isPulled());
    }

    public function test_the_catalog_shows_the_counters_and_the_prompt(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->assertSee('75 LIVE')
            // No drafts any more, so no counter for them unless old ones remain.
            ->assertDontSee('DRAFTS')
            ->assertSee('Need art? Start from this prompt')
            ->assertSee('Double Ring');
    }

    /** A seamless, opaque 512px background: a dark ground with dots well clear of the edges. */
    private function patternPng(bool $seam = false): string
    {
        $image = imagecreatetruecolor(512, 512);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 22, 12));
        $dot = imagecolorallocate($image, 74, 52, 24);

        foreach ([64, 192, 320, 448] as $x) {
            foreach ([64, 192, 320, 448] as $y) {
                imagefilledellipse($image, $x, $y, 20, 20, $dot);
            }
        }

        if ($seam) {
            imagefilledrectangle($image, 0, 0, 511, 6, imagecolorallocate($image, 240, 240, 240));
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_a_background_can_be_uploaded_and_published(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->call('$set', 'slot', 'pattern')
            ->assertSee('solid, seamless')
            ->set('upload', $this->upload($this->patternPng(), 'paws.png'))
            ->assertSee('Tiles without a seam')
            ->set('name', 'Paw Trail, Printed')
            ->call('publish')
            ->assertHasNoErrors();

        $item = Cosmetic::where('name', 'Paw Trail, Printed')->firstOrFail();

        $this->assertSame(CosmeticSlot::Pattern, $item->slot);
        $this->assertFalse($item->isDraft());
    }

    public function test_a_background_with_a_seam_is_held_back_as_a_draft(): void
    {
        $labels = array_column(app(CosmeticArt::class)->inspect($this->patternPng(seam: true), CosmeticSlot::Pattern), 'label');

        $this->assertContains('Seam visible when tiled — reupload', $labels);
    }

    public function test_every_prompt_has_an_upload_slot_with_the_same_name(): void
    {
        Auth::guard('profile')->login($this->parent);

        $page = Volt::test('parent.cosmetics');

        foreach (CosmeticSlot::uploadable() as $slot) {
            $page->assertSee("wire:click=\"\$set('slot', '{$slot->value}')\"", false)
                ->assertSee("\$wire.set('slot', '{$slot->value}')", false);
        }

        $page->assertSee('Home pattern')->assertDontSee('>Background<', false);
    }

    /** A 512px PNG of pure noise — incompressible, so it weighs about 800 KB. */
    private function heavyPng(): string
    {
        $image = imagecreatetruecolor(512, 512);
        mt_srand(7);

        for ($y = 0; $y < 512; $y++) {
            for ($x = 0; $x < 512; $x++) {
                imagesetpixel($image, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_a_file_heavier_than_the_old_cap_but_under_the_stated_one_is_accepted(): void
    {
        $png = $this->heavyPng();
        $this->assertGreaterThan(720 * 1024, strlen($png));

        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->call('$set', 'slot', 'pattern')
            ->assertSee('max 1 MB')
            ->set('upload', $this->upload($png, 'noise.png'))
            ->assertHasNoErrors('upload');
    }

    public function test_a_file_over_the_cap_is_refused_in_the_same_words_as_the_hint(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->set('upload', UploadedFile::fake()->createWithContent('huge.png', $this->ringPng().str_repeat("\0", 1100 * 1024)))
            ->assertHasErrors('upload')
            ->assertSee('That picture is over 1 MB');
    }

    public function test_every_slot_states_the_cap_its_rule_enforces(): void
    {
        $this->assertSame('1 MB', CosmeticSlot::Frame->uploadLimitLabel());
        $this->assertSame('1.5 MB', CosmeticSlot::Cabinet->uploadLimitLabel());

        foreach (CosmeticSlot::uploadable() as $slot) {
            // Kept under PHP's own ceiling, or PHP drops the file before the
            // app can explain why.
            $this->assertLessThan(2048, $slot->uploadSpec()['max_kb'], $slot->value);
        }
    }

    /**
     * One age's pet sheet with the grid ruled in over the top, as a generator
     * rules one whatever the prompt asked for: eighteen poses, six by three.
     *
     * @param  array<int, int>  $skipCells  poses to leave out
     */
    private function petSheetPng(array $skipCells = [], bool $gridLines = true, bool $bleed = false, float $scale = 1): string
    {
        $image = imagecreatetruecolor((int) (1536 * $scale), (int) (768 * $scale));
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);

        $fur = imagecolorallocate($image, 168, 116, 72);
        $cell = 256 * $scale;

        foreach (range(0, 17) as $index) {
            if (in_array($index, $skipCells, true)) {
                continue;
            }

            $left = ($index % 6) * $cell;
            $top = intdiv($index, 6) * $cell;
            $width = $bleed ? $cell : (int) ($cell * 0.6);

            imagefilledellipse($image, (int) ($left + $cell / 2), (int) ($top + $cell / 2), $width, (int) ($cell * 0.6), $fur);
        }

        if ($gridLines) {
            $ink = imagecolorallocate($image, 90, 40, 20);

            foreach ([1, 2, 3, 4, 5] as $column) {
                imagefilledrectangle($image, $column * $cell - 1, 0, $column * $cell, 768 * $scale - 1, $ink);
            }

            foreach ([1, 2] as $row) {
                imagefilledrectangle($image, 0, $row * $cell - 1, 1536 * $scale - 1, $row * $cell, $ink);
            }

            // The foot line a generator rules across a row of cells.
            imagefilledrectangle($image, 0, (int) ($cell * 0.9), 1536 * $scale - 1, (int) ($cell * 0.9) + 1, $ink);
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** @return array<int, array{label: string, status: string}> */
    private function inspectPet(string $png): array
    {
        $art = app(CosmeticArt::class);
        $tidied = $art->normalize($png, CosmeticSlot::Pet);

        return [...$tidied['checks'], ...$art->inspect($tidied['binary'], CosmeticSlot::Pet)];
    }

    public function test_a_pet_sheet_passes_once_its_ruled_grid_is_rubbed_out(): void
    {
        $checks = $this->inspectPet($this->petSheetPng());
        $labels = array_column($checks, 'label');

        $this->assertNotContains('fail', array_column($checks, 'status'), implode(' | ', $labels));
        $this->assertContains('All 18 poses are there', $labels);
        $this->assertContains('Every pose stays inside its cell', $labels);
        $this->assertStringContainsString('Rubbed out', implode(' ', $labels));
    }

    public function test_a_pet_sheet_with_a_missing_pose_names_the_empty_cell(): void
    {
        // Cell 17 is Sleep, cell 18 the toy.
        $labels = array_column(
            array_filter($this->inspectPet($this->petSheetPng(skipCells: [16, 17])), fn ($c) => $c['status'] === 'fail'),
            'label',
        );

        $this->assertContains('Nothing in the sleep, toy cells', $labels);
    }

    /**
     * A pose against its cell edge is worth saying and not worth refusing: the
     * harm is a sliver of the neighbour down the side of a 78px sprite, where
     * refusing costs a grown-up a sheet that is otherwise perfect.
     */
    public function test_a_pose_against_its_cell_edge_warns_rather_than_refuses(): void
    {
        $checks = $this->inspectPet($this->petSheetPng(bleed: true));

        $this->assertNotContains('fail', array_column($checks, 'status'));
        $this->assertStringContainsString(
            'sits right on the cell edge',
            implode(' ', array_column(array_filter($checks, fn ($c) => $c['status'] === 'warn'), 'label')),
        );
    }

    public function test_a_bigger_sheet_in_the_same_shape_is_shrunk_rather_than_refused(): void
    {
        $checks = $this->inspectPet($this->petSheetPng(scale: 1.5));
        $labels = array_column($checks, 'label');

        $this->assertNotContains('fail', array_column($checks, 'status'), implode(' | ', $labels));
        $this->assertContains('Resized from 2304×1152 to 1536×768', $labels);
        $this->assertContains('1536×768, PNG', $labels);
    }

    /**
     * The sheet a real generator handed back: 1800x896 rather than 1536x768,
     * and the "transparent" background drawn in as an actual grey-and-white
     * checkerboard. Both are the app's problem to solve, not the parent's.
     */
    private function checkerboardSheetPng(): string
    {
        $image = imagecreatetruecolor(1800, 896);
        $light = imagecolorallocate($image, 255, 255, 255);
        $dark = imagecolorallocate($image, 229, 229, 229);

        for ($y = 0; $y < 896; $y += 16) {
            for ($x = 0; $x < 1800; $x += 16) {
                imagefilledrectangle($image, $x, $y, $x + 15, $y + 15, (($x + $y) / 16) % 2 ? $dark : $light);
            }
        }

        $fur = imagecolorallocate($image, 168, 116, 72);
        $eye = imagecolorallocate($image, 255, 255, 255);
        $cellWidth = 1800 / 6;
        $cellHeight = 896 / 3;

        foreach (range(0, 17) as $index) {
            $cx = (int) ((($index % 6) + 0.5) * $cellWidth);
            $cy = (int) ((intdiv($index, 6) + 0.5) * $cellHeight);

            imagefilledellipse($image, $cx, $cy, (int) ($cellWidth * 0.6), (int) ($cellHeight * 0.6), $fur);
            // A white patch *inside* the drawing: the flood must leave it alone.
            imagefilledellipse($image, $cx - 20, $cy - 10, 18, 18, $eye);
        }

        $ink = imagecolorallocate($image, 90, 40, 20);

        foreach ([1, 2, 3, 4, 5] as $column) {
            imagefilledrectangle($image, (int) ($column * $cellWidth) - 1, 0, (int) ($column * $cellWidth), 895, $ink);
        }

        foreach ([1, 2] as $row) {
            imagefilledrectangle($image, 0, (int) ($row * $cellHeight) - 1, 1799, (int) ($row * $cellHeight), $ink);
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * A pet whose drawing encloses some of the background — a curled tail, a
     * gap between two legs — over a painted-in checkerboard. The hole is
     * background and has to go; a flat white patch in the same place is a
     * drawing and has to stay.
     */
    private function sheetWithHolesPng(): string
    {
        $image = imagecreatetruecolor(1536, 768);
        $light = imagecolorallocate($image, 255, 255, 255);
        $dark = imagecolorallocate($image, 229, 229, 229);

        $checker = function (int $x, int $y) use ($light, $dark) {
            return ((intdiv($x, 16) + intdiv($y, 16)) % 2) ? $dark : $light;
        };

        for ($y = 0; $y < 768; $y++) {
            for ($x = 0; $x < 1536; $x++) {
                imagesetpixel($image, $x, $y, $checker($x, $y));
            }
        }

        $fur = imagecolorallocate($image, 168, 116, 72);
        $eye = imagecolorallocate($image, 252, 252, 252);

        foreach (range(0, 17) as $index) {
            $cx = (($index % 6) * 256) + 128;
            $cy = (intdiv($index, 6) * 256) + 128;

            // A ring of fur with the background showing through the middle.
            imagefilledellipse($image, $cx, $cy, 150, 150, $fur);

            for ($y = $cy - 40; $y <= $cy + 40; $y++) {
                for ($x = $cx - 40; $x <= $cx + 40; $x++) {
                    if (($x - $cx) ** 2 + ($y - $cy) ** 2 <= 40 ** 2) {
                        imagesetpixel($image, $x, $y, $checker($x, $y));
                    }
                }
            }

            // And a flat white patch inside the drawing — an eye. White art that
            // touches the background *is* cut, and cannot not be: nothing can
            // tell it from the background it is joined to. Real art has an
            // outline round it, which is what keeps them apart.
            imagefilledellipse($image, $cx + 45, $cy - 40, 22, 22, $eye);
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_background_trapped_inside_the_drawing_is_cut_but_flat_white_art_is_kept(): void
    {
        $art = app(CosmeticArt::class);
        $tidied = $art->normalize($this->sheetWithHolesPng(), CosmeticSlot::Pet);
        $clean = imagecreatefromstring($tidied['binary']);

        $transparent = fn (int $x, int $y) => ((imagecolorat($clean, $x, $y) >> 24) & 0x7F) >= 110;

        // The middle of the ring: background, gone.
        $this->assertTrue($transparent(128, 128), 'The hole inside the drawing kept its background.');
        // The ring itself: art, kept.
        $this->assertFalse($transparent(128, 128 - 60), 'The drawing itself was cut away.');
        // The flat white patch enclosed by the drawing: art, kept.
        $this->assertFalse($transparent(173, 88), 'A flat white part of the drawing was cut away.');

        $this->assertNotContains('fail', array_column($art->inspect($tidied['binary'], CosmeticSlot::Pet), 'status'));
    }

    public function test_a_sheet_with_the_checkerboard_painted_in_is_cleaned_up_rather_than_refused(): void
    {
        $checks = $this->inspectPet($this->checkerboardSheetPng());
        $labels = array_column($checks, 'label');

        $this->assertNotContains('fail', array_column($checks, 'status'), implode(' | ', $labels));
        $this->assertContains('Resized from 1800×896 to 1536×768', $labels);
        $this->assertContains('Cut out the painted-in background', $labels);
        $this->assertContains('All 18 poses are there', $labels);
        $this->assertContains('Transparent background', $labels);
    }

    /**
     * The line stripper has to know when to give up. Art that really does run
     * edge to edge is not a grid, and rubbing every row out would leave an
     * empty sheet that passed its transparency check on the way.
     */
    public function test_a_picture_that_is_all_art_keeps_every_row_of_it(): void
    {
        $image = imagecreatetruecolor(1536, 768);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 90, 60));
        ob_start();
        imagepng($image);
        $solid = (string) ob_get_clean();

        $labels = array_column($this->inspectPet($solid), 'label');

        $this->assertNotContains('Rubbed out 768 ruled grid lines', $labels);
        $this->assertStringNotContainsString('Rubbed out', implode(' ', $labels));
    }

    /**
     * The bundled prompts say what to draw and never said what file to hand
     * back, which is how a JPEG or a painted-on checkerboard gets generated.
     */
    public function test_every_prompt_ends_by_asking_for_a_png_at_that_slots_size(): void
    {
        Auth::guard('profile')->login($this->parent);

        $page = Volt::test('parent.cosmetics');

        foreach (CosmeticSlot::uploadable() as $slot) {
            $spec = $slot->uploadSpec();
            $output = $slot->promptOutput();

            $this->assertStringContainsString('Hand back a PNG file', $output);
            $this->assertStringContainsString("{$slot->promptSize()} pixels", $output);
            $this->assertStringContainsString(
                $spec['alpha'] ? 'REAL transparency' : 'No transparency',
                $output,
                $slot->value,
            );

            // And it reaches the page, alongside the prompt it belongs to.
            $page->assertSee(str_replace("\n", '\n', 'Hand back a PNG file'), false);
            $page->assertSee("{$slot->promptSize()} pixels", false);
        }
    }

    public function test_the_pet_prompt_asks_for_every_age_and_no_ruled_grid(): void
    {
        $prompt = CosmeticSlot::PET_FAMILY_PROMPT.CosmeticSlot::Pet->promptOutput();

        $this->assertStringContainsString('9 columns × 6 rows', $prompt);
        $this->assertStringContainsString('ruled foot line', $prompt);
        $this->assertStringContainsString('1536x1024 pixels', $prompt);
        $this->assertStringContainsString('REAL transparency', $prompt);
    }

    public function test_a_kid_cannot_open_the_console(): void
    {
        $kid = Profile::factory()->for($this->household)->create();

        $this->actingAs($kid, 'profile')->get(route('parent.cosmetics'))->assertForbidden();
    }

    public function test_published_art_is_public_and_a_draft_is_not(): void
    {
        $art = app(CosmeticArt::class);
        $path = $art->store($this->household->id, $this->ringPng());

        $published = Cosmetic::create([
            'household_id' => $this->household->id, 'slot' => 'frame', 'art_path' => $path,
            'name' => 'Out', 'cost' => 3, 'stock' => 'shelf', 'published_at' => now(),
        ]);
        $draft = Cosmetic::create([
            'household_id' => $this->household->id, 'slot' => 'frame', 'art_path' => $path,
            'name' => 'Hidden', 'cost' => 3, 'stock' => 'shelf',
        ]);

        $this->get($published->artUrl())->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get($draft->artUrl())->assertNotFound();

        $kid = Profile::factory()->for($this->household)->create();
        $this->actingAs($kid, 'profile')->get($draft->artUrl())->assertNotFound();
        $this->actingAs($this->parent, 'profile')->get($draft->artUrl())->assertOk();
    }

    public function test_an_uploaded_item_is_bought_and_drawn_like_any_other(): void
    {
        $path = app(CosmeticArt::class)->store($this->household->id, $this->ringPng());
        $item = Cosmetic::create([
            'household_id' => $this->household->id, 'slot' => 'frame', 'art_path' => $path,
            'name' => 'Antlers', 'cost' => 2, 'stock' => 'shelf', 'published_at' => now(),
        ]);
        $kid = Profile::factory()->for($this->household)->create(['bonus_tickets' => 5]);

        app(CosmeticService::class)->buy($kid, $item);

        $html = Volt::test('login')->html();

        $this->assertStringContainsString('src="'.e($item->artUrl()).'"', $html);
    }
}
