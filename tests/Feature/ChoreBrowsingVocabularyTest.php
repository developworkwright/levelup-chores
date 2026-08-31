<?php

namespace Tests\Feature;

use App\Enums\ChoreCategory;
use App\Enums\ChoreIcon;
use App\Enums\PriceBand;
use App\Models\Chore;
use Tests\TestCase;

/**
 * The words the side-quest board browses by: price bands and categories.
 *
 * Bands are four constants over `chores.points` — no column, since the price
 * is already there. Categories are their own column that a parent sets, and
 * the tests below mostly exist to hold the line that they are **not** read off
 * `chores.icon`: that is a card face for a kid who can't read the name, and
 * deriving a category from it made choosing a nicer picture move the chore to
 * a different chip.
 */
class ChoreBrowsingVocabularyTest extends TestCase
{
    public function test_the_four_bands_tile_the_range_with_no_gaps(): void
    {
        // Min-inclusive, max-exclusive, so no chore can fall between two
        // shelves and vanish from every band at once.
        for ($points = 100; $points <= 2000; $points += 25) {
            $matches = array_filter(
                PriceBand::cases(),
                fn (PriceBand $band) => $band->contains($points, 100),
            );

            $this->assertCount(1, $matches, "{$points} points landed in ".count($matches).' bands');
        }
    }

    public function test_there_is_no_shelf_below_a_dollar(): void
    {
        // A household board starts at $1, so the band below would always be
        // empty. A 50-point chore simply isn't on any shelf.
        foreach (PriceBand::cases() as $band) {
            $this->assertFalse($band->contains(50, 100));
        }
    }

    public function test_the_top_band_is_open_ended(): void
    {
        $this->assertNull(PriceBand::RareOnes->toDollars());
        $this->assertTrue(PriceBand::RareOnes->contains(50_000, 100));
    }

    public function test_a_band_reads_in_the_households_own_money(): void
    {
        // "$2–5" has to mean $2 to $5 whatever a point is worth locally.
        $this->assertTrue(PriceBand::ARealJob->contains(100, 50));
        $this->assertFalse(PriceBand::ARealJob->contains(100, 100));
    }

    public function test_the_category_is_never_read_off_the_icon(): void
    {
        // The regression this guards is the one the column exists to fix: the
        // icon is a card face a pre-reader picks the chore by, and it briefly
        // decided the category too, so choosing a nicer picture moved the chore
        // to a different chip. Every kitchen-faced icon here, no category set —
        // all of it must still come back Other.
        foreach (ChoreIcon::cases() as $icon) {
            $chore = new Chore(['name' => 'A job', 'icon' => $icon->faClass()]);

            $this->assertSame(
                ChoreCategory::Other,
                ChoreCategory::forChore($chore),
                "{$icon->value} is deciding a category it has no business deciding",
            );
        }
    }

    public function test_a_chore_nobody_has_filed_falls_into_other_rather_than_disappearing(): void
    {
        // Picking a chip must never be able to hide a chore from every chip.
        $this->assertSame(ChoreCategory::Other, ChoreCategory::forChore(new Chore));
    }

    public function test_a_filed_chore_browses_where_the_parent_put_it(): void
    {
        $chore = new Chore(['icon' => ChoreIcon::Dishes->faClass(), 'category' => ChoreCategory::Outside]);

        $this->assertSame(ChoreCategory::Outside, ChoreCategory::forChore($chore));
    }

    public function test_every_category_names_an_icon_font_awesome_free_actually_ships(): void
    {
        // A Pro-only name is worse than a wrong picture: the class resolves,
        // the glyph never ships, and the chip comes out blank. Read the same
        // way ChoreIconTest reads it — the free package's stylesheet is the
        // list of what exists.
        $stylesheet = @file_get_contents(base_path('node_modules/@fortawesome/fontawesome-free/css/fontawesome.css'));

        if ($stylesheet === false) {
            $this->markTestSkipped('Font Awesome is not installed — run npm install.');
        }

        // The board's three special chips ride alongside the categories and
        // have the same problem, so they are checked here rather than nowhere.
        $classes = array_merge(
            array_map(fn (ChoreCategory $case) => $case->faClass(), ChoreCategory::cases()),
            ['fa-solid fa-rotate-left', 'fa-solid fa-sun', 'fa-solid fa-dumbbell'],
        );

        foreach ($classes as $class) {
            $name = str_replace('fa-solid ', '', $class);

            $this->assertStringContainsString(
                "\n.{$name} {",
                $stylesheet,
                "{$class} is not in the free set.",
            );
        }
    }
}
