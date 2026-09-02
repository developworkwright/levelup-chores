<?php

namespace Tests\Feature;

use App\Enums\CompletionStatus;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The parent console's chrome.
 *
 * Eleven tabs in a wrapping gold strip became four rail buttons and a sheet,
 * the same three mechanisms the kid console runs on. What is pinned here is the
 * boundary between them: the rail is four pages and never more, the sheet is
 * the only thing that lists everything, and the pending count rides on the
 * button that leads to it rather than in a tile of its own.
 */
class ParentNavTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $parent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->parent = Profile::factory()->for($this->household)->parent()->create(['name' => 'Mom']);

        Auth::guard('profile')->login($this->parent);
    }

    /**
     * Just the rail's buttons. The sheet lists every page in the console, so a
     * bare assertSee can't tell a rail button from a row inside the sheet.
     */
    private function rail(string $html): string
    {
        preg_match('/<nav\s+aria-label="Pages"\s+data-fq-rail.*?<\/nav>/s', $html, $matches);

        return $matches[0] ?? '';
    }

    /** A pending chore approval, which is one of the three things Home counts. */
    private function pendingApproval(): void
    {
        $kid = Profile::factory()->for($this->household)->create();
        $chore = Chore::factory()->for($this->household)->create();

        ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $kid->id,
            'status' => CompletionStatus::Pending,
            'points_awarded' => $chore->points,
            'submitted_at' => now(),
        ]);
    }

    public function test_the_rail_holds_four_pages_and_no_more(): void
    {
        $rail = $this->rail(Volt::test('parent.home')->html());

        foreach (['parent.home', 'parent.chores', 'parent.kids', 'parent.activity'] as $route) {
            $this->assertStringContainsString(route($route), $rail, $route.' belongs on the rail.');
        }

        // The seven set-up pages reach the sheet instead. A rail that grows
        // back to eleven is the thing this whole change undid.
        foreach ([
            'parent.loot', 'parent.lucky', 'parent.monsters',
            'parent.standings', 'parent.quotes', 'parent.arcade', 'parent.music',
        ] as $route) {
            $this->assertStringNotContainsString(route($route), $rail, $route.' should be a sheet row, not a rail button.');
        }
    }

    public function test_the_sheet_lists_every_page_including_the_rails_own(): void
    {
        $test = Volt::test('parent.home')->assertSee('Where to?');

        foreach ([
            'parent.home', 'parent.chores', 'parent.kids', 'parent.activity',
            'parent.loot', 'parent.lucky', 'parent.monsters',
            'parent.standings', 'parent.quotes', 'parent.arcade', 'parent.music',
        ] as $route) {
            $test->assertSee(route($route), false);
        }
    }

    public function test_the_sheet_marks_the_page_you_are_on(): void
    {
        Volt::test('parent.monsters')->assertSee("YOU'RE HERE", false);
    }

    /**
     * The count that used to be a PENDING tile in the header. On the button it
     * says both that something is waiting and where to go for it.
     */
    public function test_the_pending_count_rides_on_the_home_button(): void
    {
        $this->pendingApproval();

        $rail = $this->rail(Volt::test('parent.monsters')->html());

        $this->assertStringContainsString('1 thing waiting on you', $rail);
    }

    public function test_an_empty_queue_draws_no_count(): void
    {
        $rail = $this->rail(Volt::test('parent.home')->html());

        $this->assertStringNotContainsString('waiting on you', $rail);
    }

    /**
     * Seven of the eleven pages light no rail button, which on the old strip
     * could not happen. The glyph takes the gold so the bar still answers
     * "where am I?" from the Loot Shop or the music library.
     */
    public function test_a_sheet_only_page_lights_the_glyph(): void
    {
        $this->assertStringContainsString('fa-bars', $lit = Volt::test('parent.music')->html());
        $this->assertStringContainsString('var(--fq-gold-fill)', $this->glyph($lit));

        $this->assertStringNotContainsString('var(--fq-gold-fill)', $this->glyph(Volt::test('parent.home')->html()));
    }

    /** The ☰ button alone — the rail's own tiles use the same gold. */
    private function glyph(string $html): string
    {
        preg_match('/<button[^>]*aria-label="All pages[^"]*".*?<\/button>/s', $html, $matches);

        return $matches[0] ?? '';
    }
}
