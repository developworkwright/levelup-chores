<?php

namespace Tests\Feature;

use App\Enums\PrizeSlot;
use Tests\TestCase;

/**
 * The prize counter's art lives in resources/js/prizes.js, shipped verbatim
 * from handoff/design_handoff_arcade_tokens; PHP holds the catalog the counter
 * sells from (App\Enums\PrizeSlot::CATALOG). That is a seam, and this is the
 * guard on it — the same arrangement CosmeticCatalogTest keeps for the Locker.
 *
 * A prize the counter sells but the art can't draw falls back to the slot's
 * first drawing, so a kid would pay for a frisbee and get a ball.
 */
class PrizeCatalogTest extends TestCase
{
    private function source(): string
    {
        return file_get_contents(resource_path('js/prizes.js'));
    }

    /**
     * @return list<array{slot: string, key: string, name: string, cost: int}>
     */
    private function artworkCatalog(): array
    {
        preg_match('/const CATALOG = \[(.*?)\n  \];/s', $this->source(), $block);

        $this->assertNotEmpty($block, 'Could not find CATALOG in prizes.js.');

        preg_match_all(
            "/\{ slot: '(\w+)', key: '(\w+)', name: '([^']+)', cost: (\d+)/",
            $block[1],
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(fn (array $m) => [
            'slot' => $m[1],
            'key' => $m[2],
            'name' => $m[3],
            'cost' => (int) $m[4],
        ], $matches);
    }

    public function test_the_php_catalog_is_the_artwork_catalog_row_for_row(): void
    {
        $php = [];

        foreach (PrizeSlot::cases() as $slot) {
            foreach ($slot->items() as $item) {
                $php[] = ['slot' => $slot->value, ...$item];
            }
        }

        // The bundle appends new prizes at the end of CATALOG; the counter
        // shows them shelf by shelf. So compare slot by slot, in bundle order.
        $artwork = collect($this->artworkCatalog())->sortBy(fn (array $row) => array_search($row['slot'], ['snack', 'toy', 'bed'], true))->values()->all();

        $this->assertCount(33, $artwork);
        $this->assertSame($artwork, $php);
    }

    public function test_every_key_has_a_drawing_of_its_own(): void
    {
        $source = $this->source();

        foreach (PrizeSlot::cases() as $slot) {
            $table = ['snack' => 'SNACKS', 'toy' => 'TOYS', 'bed' => 'BEDS'][$slot->value];

            preg_match('/const '.$table.' = \{(.*?)\n  \};/s', $source, $block);
            preg_match_all('/^    (\w+): \(c\) =>/m', $block[1] ?? '', $drawn);

            foreach ($slot->items() as $item) {
                $this->assertContains($item['key'], $drawn[1], "{$slot->value} {$item['key']} has no drawing in prizes.js.");
            }
        }
    }

    public function test_only_the_house_snack_is_free(): void
    {
        foreach (PrizeSlot::cases() as $slot) {
            foreach ($slot->items() as $item) {
                if ($item['cost'] === 0) {
                    $this->assertSame([PrizeSlot::Snack, 'meat'], [$slot, $item['key']]);
                }
            }
        }
    }
}
