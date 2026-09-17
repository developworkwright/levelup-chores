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

    public function test_an_upload_that_fails_its_checks_can_only_be_a_draft(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->set('upload', $this->upload($this->ringPng(filledMiddle: true)))
            ->set('name', 'Face Already In It')
            ->call('publish')
            ->assertHasErrors('upload');

        $this->assertSame(0, Cosmetic::where('name', 'Face Already In It')->count());

        Volt::test('parent.cosmetics')
            ->set('upload', $this->upload($this->ringPng(filledMiddle: true)))
            ->set('name', 'Face Already In It')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $draft = Cosmetic::where('name', 'Face Already In It')->firstOrFail();

        $this->assertTrue($draft->isDraft());
        $this->assertFalse($draft->passesChecks());

        Volt::test('parent.cosmetics')->call('publishDraft', $draft->id);

        $this->assertTrue($draft->fresh()->isDraft());
    }

    public function test_a_draft_can_be_published_later_or_binned(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->set('upload', $this->upload($this->ringPng()))
            ->set('name', 'Later')
            ->call('saveDraft');

        Volt::test('parent.cosmetics')
            ->set('upload', $this->upload($this->ringPng()))
            ->set('name', 'Never')
            ->call('saveDraft');

        $later = Cosmetic::where('name', 'Later')->firstOrFail();
        $never = Cosmetic::where('name', 'Never')->firstOrFail();

        Volt::test('parent.cosmetics')
            ->assertSee('Drafts · not visible to anyone yet')
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
            ->assertSee('0 DRAFTS')
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
