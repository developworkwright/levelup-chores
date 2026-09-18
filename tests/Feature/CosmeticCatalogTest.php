<?php

namespace Tests\Feature;

use App\Enums\CosmeticFlavor;
use App\Enums\CosmeticMotion;
use App\Enums\CosmeticSlot;
use App\Enums\CosmeticStock;
use App\Models\Cosmetic;
use App\Models\Household;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cosmetic art lives in resources/js/cosmetics.js, shipped verbatim from the
 * design bundle; PHP holds the catalog rows, the theme colours and the motion
 * words. That is a seam, and this is the guard on it — the same arrangement
 * BossSkinCatalogTest keeps for monsters.js.
 *
 * An item the catalog sells but the artwork can't draw falls back to the
 * slot's first drawing, so a kid would pay for one thing and wear another.
 */
class CosmeticCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function source(): string
    {
        return file_get_contents(resource_path('js/cosmetics.js'));
    }

    /**
     * @return array<int, array{slot: string, key: string, name: string, cost: int, stock: string, flavor: string|null, motion: string|null}>
     */
    private function artworkItems(): array
    {
        preg_match('/var ITEMS = \[(.*?)\n  \];/s', $this->source(), $block);

        $this->assertNotEmpty($block, 'Could not find ITEMS in cosmetics.js.');

        preg_match_all(
            "/\{ slot: '(\w+)', key: '(\w+)', name: '([^']+)', cost: (\d+), stock: '(\w+)'(.*)$/m",
            $block[1],
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(function (array $m) {
            preg_match("/tag: '(\w+)'/", $m[6], $tag);
            preg_match("/anim: '(\w+)'/", $m[6], $anim);

            return [
                'slot' => $m[1],
                'key' => $m[2],
                'name' => $m[3],
                'cost' => (int) $m[4],
                'stock' => $m[5],
                'flavor' => $tag[1] ?? null,
                'motion' => $anim[1] ?? null,
            ];
        }, $matches);
    }

    public function test_the_php_catalog_is_the_artwork_catalog_row_for_row(): void
    {
        $this->assertCount(75, $this->artworkItems());
        $this->assertSame($this->artworkItems(), Cosmetic::defaults());
    }

    public function test_every_catalog_value_is_one_the_enums_know(): void
    {
        foreach (Cosmetic::defaults() as $item) {
            $this->assertNotNull(CosmeticSlot::tryFrom($item['slot']), $item['key']);
            $this->assertNotNull(CosmeticStock::tryFrom($item['stock']), $item['key']);

            if ($item['flavor'] !== null) {
                $this->assertNotNull(CosmeticFlavor::tryFrom($item['flavor']), $item['key']);
            }

            if ($item['motion'] !== null) {
                $this->assertNotNull(CosmeticMotion::tryFrom($item['motion']), $item['key']);
            }
        }
    }

    /**
     * The artwork's seven slots come first, in its order and with its words.
     * Pet is the app's own eighth and is deliberately not in the bundle: it has
     * no drawn-in-code art, only uploaded sprite sheets.
     */
    public function test_the_slots_start_with_the_artworks_slots_in_order(): void
    {
        preg_match_all("/\{ key: '(\w+)', name: '([^']+)', blurb: '([^']+)', icon: '([^']+)' \}/", $this->source(), $matches, PREG_SET_ORDER);

        $artwork = array_map(fn (array $m) => [$m[1], $m[2], $m[3], $m[4]], $matches);
        $slots = array_map(fn (CosmeticSlot $slot) => [$slot->value, $slot->label(), $slot->blurb(), $slot->icon()], CosmeticSlot::cases());

        $this->assertCount(7, $artwork);
        $this->assertSame($artwork, array_slice($slots, 0, 7));
        $this->assertSame([CosmeticSlot::Pet], array_slice(CosmeticSlot::cases(), 7));
    }

    /**
     * A pet's twelve poses are a contract between three things: the prompt that
     * asks for the sheet, the checks that name an empty cell, and the element
     * that crops one pose out of it. All three read this list.
     */
    public function test_the_pet_poses_are_the_same_in_the_prompt_the_enum_and_the_element(): void
    {
        $poses = CosmeticSlot::PET_POSES;
        $grid = CosmeticSlot::Pet->poseGrid();

        $this->assertCount($grid['cols'] * $grid['rows'], $poses);

        // The prompt numbers its cells; every pose has to be named in it.
        foreach ($poses as $pose) {
            $this->assertStringContainsStringIgnoringCase(
                $pose === 'toy' ? 'the toy on its own' : $pose,
                CosmeticSlot::PET_FAMILY_PROMPT,
                "The prompt never mentions the {$pose} pose.",
            );
        }

        preg_match(
            "/const poses = \[(.*?)\];/s",
            file_get_contents(resource_path('js/cosmetic-elements.js')),
            $block,
        );

        preg_match_all("/'(\w+)'/", $block[1] ?? '', $inElement);

        $this->assertSame($poses, $inElement[1], 'cosmetic-elements.js has drifted from PET_POSES.');
    }

    public function test_the_theme_colours_match_the_artwork(): void
    {
        preg_match('/var THEMES = \{(.*?)\n  \};/s', $this->source(), $block);
        preg_match_all("/(\w+): \{ name: '[^']+', bg: '([^']+)', panel: '([^']+)', line: '([^']+)', ink: '([^']+)', muted: '([^']+)', accent: '([^']+)', accent2: '([^']+)' \}/", $block[1], $matches, PREG_SET_ORDER);

        $artwork = [];

        foreach ($matches as $m) {
            $artwork[$m[1]] = ['bg' => $m[2], 'panel' => $m[3], 'line' => $m[4], 'ink' => $m[5], 'muted' => $m[6], 'accent' => $m[7], 'accent2' => $m[8]];
        }

        $this->assertSame($artwork, Cosmetic::THEMES);
    }

    public function test_the_motions_match_the_artwork_and_app_css_carries_its_keyframes(): void
    {
        preg_match('/var MOTION = \{(.*?)\n  \};/s', $this->source(), $block);
        preg_match_all("/(\w+): '([^']+)'/", $block[1], $matches, PREG_SET_ORDER);

        $this->assertSame(
            array_map(fn (array $m) => [$m[1], $m[2]], $matches),
            array_map(fn (CosmeticMotion $motion) => [$motion->value, $motion->css()], CosmeticMotion::cases()),
        );

        preg_match_all("/'(@keyframes fq\w+ .*?)'/", $this->source(), $keyframes);
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertCount(9, $keyframes[1]);

        foreach ($keyframes[1] as $rule) {
            $this->assertStringContainsString($rule, $css, 'app.css has drifted from FQCosmetics.KEYFRAMES.');
        }
    }

    public function test_the_artwork_defines_nothing_but_its_own_global(): void
    {
        foreach (['fetch(', 'XMLHttpRequest', 'eval(', 'new Function', 'document.cookie', 'localStorage', 'import ', 'require('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $this->source(), "cosmetics.js reaches for {$forbidden}.");
        }
    }

    public function test_a_household_is_seeded_with_the_whole_catalog_once(): void
    {
        $household = Household::factory()->create();

        Cosmetic::seedDefaults($household);

        $this->assertSame(75, Cosmetic::where('household_id', $household->id)->count());
        $this->assertSame(0, Cosmetic::where('household_id', $household->id)->whereNull('published_at')->count());
    }

    public function test_every_slot_but_frame_and_avatar_has_a_free_house_item(): void
    {
        foreach (CosmeticSlot::cases() as $slot) {
            $free = collect(Cosmetic::defaults())->where('slot', $slot->value)->where('cost', 0);

            $this->assertSame($slot->startsEmpty() ? 0 : 1, $free->count(), $slot->value);
        }
    }
}
