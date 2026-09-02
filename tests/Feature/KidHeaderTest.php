<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Models\SiblingOffer;
use App\Models\StoreItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The kid console's chrome: the header, the rail, the sheet and the segments.
 *
 * Worlds are gone, and with them the second pill row. What replaces them is
 * three mechanisms that each do one job, and most of what is pinned here is
 * the boundary between them — a rail button always navigates, a segment only
 * ever swaps two siblings, and the sheet is the only thing that lists
 * everything.
 */
class KidHeaderTest extends TestCase
{
    use RefreshDatabase;

    private function loginKid(array $attributes = []): Profile
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create($attributes);
        Chore::factory()->for($household)->count(2)->create();

        Auth::guard('profile')->login($kid);

        return $kid;
    }

    /**
     * Just the rail's four buttons. The sheet lists every page in the app, so
     * a bare assertSee can't tell a rail button from a row inside the sheet.
     */
    private function rail(string $html): string
    {
        preg_match('/<nav\s+aria-label="Pages"\s+data-fq-rail.*?<\/nav>/s', $html, $matches);

        return $matches[0] ?? '';
    }

    /** Just the segment row, for the same reason. */
    private function segments(string $html): string
    {
        preg_match('/<nav\s+aria-label="[^"]* pages".*?<\/nav>/s', $html, $matches);

        return $matches[0] ?? '';
    }

    public function test_the_header_shows_points_and_tickets(): void
    {
        $this->loginKid(['points' => 250, 'bonus_tickets' => 7]);

        Volt::test('kid.quests')
            ->assertSee('PTS')
            ->assertSee('TICKETS')
            ->assertSee('7');
    }

    /**
     * One readout left the header, and it went somewhere it says more.
     *
     * The badge count is a door, so it is a row in the sheet.
     *
     * The streak came back after this was first written, but as a clock rather
     * than a tally — "3h 20m TILL BED" instead of "4d STREAK". The tally was
     * the thing worth removing: a number pinned above every page that a kid
     * can't act on. A countdown is a number they can.
     */
    public function test_the_header_carries_no_badge_count_or_streak_tally(): void
    {
        $this->loginKid(['streak' => 4]);

        $html = Volt::test('kid.stats')->html();

        $this->assertStringNotContainsString('BADGES', $html);
        // The tally's own label. Not the number beside it — "4d" turns up by
        // chance in a Livewire checksum often enough to fail on a coin toss,
        // which cost one debugging session already. The clock that replaced it
        // says TILL BED, PAST BED or SAFE, and never this.
        $this->assertStringNotContainsString('STREAK', $html);
    }

    /**
     * The XP bar under the name, which went out with the world rail and came
     * straight back.
     *
     * It was dropped on the argument that the rank chip above it already shows
     * progress. That is true of the rank and false of the level: the chip
     * repaints every fifth level and says nothing on the four in between, so
     * without the bar there is nowhere in the app that shows a kid they are
     * part of the way to the next one.
     */
    public function test_the_header_shows_how_far_through_the_level_a_kid_is(): void
    {
        // Half way through the first level — see Profile::LEVEL_BANDS.
        $kid = $this->loginKid(['xp' => 100]);

        $this->assertSame(50.0, $kid->xpBarPercent());

        Volt::test('kid.quests')->assertSee('width:50%', false);
    }

    public function test_the_ticket_tile_links_to_the_bonus_shop(): void
    {
        $this->loginKid(['bonus_tickets' => 3]);

        Volt::test('kid.quests')->assertSee(route('kid.bonus'), false);
    }

    public function test_the_header_shows_tickets_on_every_kid_tab(): void
    {
        $this->loginKid(['bonus_tickets' => 5]);

        // It lives in the shared shell, so it should follow the kid around
        // rather than only existing on the page it was added for.
        foreach (['kid.quests', 'kid.loot', 'kid.badges', 'kid.bonus', 'kid.arcade'] as $page) {
            Volt::test($page)->assertSee('TICKETS');
        }
    }

    public function test_the_rail_carries_the_same_four_buttons_on_every_page(): void
    {
        $this->loginKid();

        foreach (['kid.quests', 'kid.loot', 'kid.stats', 'kid.arcade'] as $page) {
            $rail = $this->rail(Volt::test($page)->html());

            // Home leads the rail from every page — it is the way back to the
            // front of the day, and a kid who is lost reaches for the first
            // button without reading the rest.
            $this->assertStringContainsString('Home', $rail);
            $this->assertStringContainsString('Quests', $rail);
            $this->assertStringContainsString('Shop', $rail);
            $this->assertStringContainsString('House', $rail);
        }
    }

    /**
     * The complaint that drove the whole change: a rail button used to be a
     * folder. Pressing "Spend" revealed a row instead of arriving somewhere.
     */
    public function test_every_rail_button_is_a_link_to_a_page(): void
    {
        $this->loginKid();

        $rail = $this->rail(Volt::test('kid.home')->html());

        preg_match_all('/<a\s[^>]*href="([^"]+)"/', $rail, $matches);

        $this->assertSame(
            [route('kid.home'), route('kid.quests'), route('kid.loot'), route('kid.arena')],
            $matches[1],
            'Each rail button should open the first page behind it.',
        );
    }

    public function test_a_rail_button_lights_for_either_of_its_pages(): void
    {
        $this->loginKid();

        foreach (['kid.loot', 'kid.bonus'] as $page) {
            $this->assertStringContainsString(
                'aria-current="page"',
                $this->rail(Volt::test($page)->html()),
                $page.' should light the Shop button.',
            );
        }

        // And lands you back where you already are, so the button you are
        // standing on can never move you.
        $rail = $this->rail(Volt::test('kid.bonus')->html());

        $this->assertStringContainsString(route('kid.bonus'), $rail);
        $this->assertStringNotContainsString(route('kid.loot'), $rail);
    }

    public function test_a_page_with_a_sibling_draws_a_segment_row(): void
    {
        $this->loginKid();

        $shop = $this->segments(Volt::test('kid.loot')->html());

        $this->assertStringContainsString('Loot', $shop);
        $this->assertStringContainsString('Bonus', $shop);
        // The other button's pages stay where they are. A segment row only
        // ever holds the siblings of the page you are on.
        $this->assertStringNotContainsString('Arena', $shop);

        $house = $this->segments(Volt::test('kid.trades')->html());

        $this->assertStringContainsString('Arena', $house);
        $this->assertStringContainsString('Trades', $house);
    }

    public function test_a_page_with_no_sibling_draws_no_segment_row(): void
    {
        $this->loginKid();

        // Not only for the rail's own single-page buttons: everything reached
        // from the sheet is on its own too, and a lone segment marked as the
        // open page is a control with nowhere to go.
        foreach (['kid.home', 'kid.quests', 'kid.stats', 'kid.journal', 'kid.arcade'] as $page) {
            $this->assertSame('', $this->segments(Volt::test($page)->html()), $page.' should draw no segments.');
        }
    }

    public function test_the_sheet_lists_every_page_including_the_rails_own(): void
    {
        $this->loginKid();

        $test = Volt::test('kid.home')->assertSee('Where to?');

        foreach ([
            'kid.home', 'kid.quests', 'kid.loot', 'kid.journal',
            'kid.arena', 'kid.trades', 'kid.bonus', 'kid.arcade',
            'kid.stats', 'kid.goal', 'kid.badges',
        ] as $route) {
            $test->assertSee(route($route), false);
        }

        // The two controls that left the header, because neither is anything
        // a kid reaches for mid-day.
        $test->assertSee(route('logout'), false)->assertSee('Sound on');
    }

    public function test_the_sheet_marks_the_page_you_are_on(): void
    {
        $this->loginKid();

        Volt::test('kid.trades')->assertSee("YOU'RE HERE", false);
    }

    /**
     * The arcade is the reason the sheet exists: it belonged to none of the
     * five worlds the old rail was built out of, so there was nowhere to put
     * it. Under the sheet, shipping a page costs one row.
     */
    public function test_the_arcade_is_reachable_and_flagged_as_new(): void
    {
        $this->loginKid();

        Volt::test('kid.home')->assertSee('Arcade')->assertSee('NEW');

        $this->get(route('kid.arcade'))->assertOk()->assertSee('Arcade');
    }

    public function test_a_rail_button_carries_the_counts_of_both_its_pages(): void
    {
        $kid = $this->loginKid();
        $sibling = Profile::factory()->for($kid->household)->create();

        SiblingOffer::factory()->create([
            'household_id' => $kid->household_id,
            'from_profile_id' => $sibling->id,
            'to_profile_id' => $kid->id,
        ]);

        StoreItem::factory()->count(2)->create(['household_id' => $kid->household_id]);

        // Waiting on the Trades and Loot pages, but both counts have to be
        // visible from the Quests tab — that's the point of hanging them on a
        // rail button rather than on the page that owns them.
        $rail = $this->rail(Volt::test('kid.quests')->html());

        $this->assertStringContainsString('1 thing waiting on you', $rail);
        $this->assertStringContainsString('2 things waiting on you', $rail);
    }

    /**
     * A count is only worth putting on a segment if it survives the segment
     * not being the open one — otherwise the kid has to visit a panel to find
     * out whether it wanted them.
     */
    public function test_an_inactive_segment_keeps_its_count(): void
    {
        $kid = $this->loginKid();
        $sibling = Profile::factory()->for($kid->household)->create();

        SiblingOffer::factory()->create([
            'household_id' => $kid->household_id,
            'from_profile_id' => $sibling->id,
            'to_profile_id' => $kid->id,
        ]);

        $segments = $this->segments(Volt::test('kid.arena')->html());

        $this->assertStringContainsString('1 thing waiting on you', $segments);
    }

    /**
     * The whole `?world=` / `session('kid_world')` resolution went with the
     * worlds. A rail button's state is now simply "is this the open page", so
     * nothing about the console can be steered from outside it.
     */
    public function test_nothing_in_the_nav_depends_on_a_remembered_world(): void
    {
        $this->loginKid();

        $steered = $this->withSession(['kid_world' => 'spend'])
            ->get(route('kid.trades').'?world=spend')
            ->assertOk()
            ->content();

        $rail = $this->rail($steered);

        // A stale world in the session and a world on the query string, and
        // the rail lights House either way — because it is looking at the open
        // page and nothing else.
        $this->assertStringContainsString('aria-current="page"', $rail);
        $this->assertStringContainsString(route('kid.trades'), $rail);

        $this->flushSession();
        $this->get(route('kid.arena'))->assertOk();

        $this->assertNull(session('kid_world'), 'The shell should no longer write a world to the session.');
    }
}
