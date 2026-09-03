<?php

namespace Tests\Feature;

use App\Enums\ArcadeGame;
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
            // The fourth slot held House — Household and the trades — and
            // holds the Arcade instead. See the rail's own comment: those two
            // are news that comes looking for a kid, and the arcade is the one
            // page that is worth nothing unless it is a tap from everywhere.
            $this->assertStringContainsString('Arcade', $rail);
            $this->assertStringNotContainsString('House', $rail);
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
            [route('kid.home'), route('kid.quests'), route('kid.loot'), route('kid.arcade')],
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
        // A segment row only ever holds the siblings of the page you are on,
        // and Shop is the only rail button left holding two pages.
        $this->assertStringNotContainsString('Household', $shop);
    }

    public function test_a_page_with_no_sibling_draws_no_segment_row(): void
    {
        $this->loginKid();

        // Not only for the rail's own single-page buttons: everything reached
        // from the sheet is on its own too, and a lone segment marked as the
        // open page is a control with nowhere to go.
        //
        // Household and the trades are in that second group now. They were a
        // pair behind the House button and lost their segment row with it —
        // the sheet's "The house" heading is what keeps them together.
        foreach (['kid.home', 'kid.quests', 'kid.stats', 'kid.journal', 'kid.arcade', 'kid.household', 'kid.trades'] as $page) {
            $this->assertSame('', $this->segments(Volt::test($page)->html()), $page.' should draw no segments.');
        }
    }

    public function test_the_sheet_lists_every_page_including_the_rails_own(): void
    {
        $this->loginKid();

        $test = Volt::test('kid.home')->assertSee('Where to?');

        foreach ([
            'kid.home', 'kid.quests', 'kid.loot', 'kid.journal',
            'kid.household', 'kid.trades', 'kid.bonus', 'kid.arcade',
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
    public function test_the_arcade_is_reachable_from_the_sheet(): void
    {
        $this->loginKid();

        Volt::test('kid.home')->assertSee('Arcade');

        $this->get(route('kid.arcade'))->assertOk()->assertSee('Arcade');
    }

    /**
     * The row's "new" used to be a flag hardcoded in the shell — the same for
     * everybody, and lit until somebody remembered to delete the line. It
     * counts games this kid has not met now, because news arrives one game
     * at a time and there are more games coming.
     */
    public function test_the_arcade_row_counts_the_games_this_kid_has_not_met(): void
    {
        $this->loginKid(['arcade_seen_at' => ArcadeGame::StackTheMess->releasedOn()]);

        Volt::test('kid.home')->assertSee('1 new');
    }

    public function test_the_arcade_row_says_nothing_once_they_have_been(): void
    {
        $this->loginKid(['arcade_seen_at' => now()]);

        // Not "0 new", and not a rim that never goes out: a kid who has seen
        // every game is being told nothing, so the row says nothing.
        Volt::test('kid.home')->assertSee('Arcade')->assertDontSee('new</span>', false);
    }

    public function test_a_rail_button_carries_the_counts_of_both_its_pages(): void
    {
        $kid = $this->loginKid();

        StoreItem::factory()->count(2)->create(['household_id' => $kid->household_id]);

        // New loot is waiting on the Loot page, which sits behind Shop with the
        // Bonus Shop — and the count has to be visible from the Quests tab.
        // That's the point of hanging it on a rail button rather than on the
        // page that owns it.
        $rail = $this->rail(Volt::test('kid.quests')->html());

        $this->assertStringContainsString('2 things waiting on you', $rail);
    }

    /**
     * The other half of swapping House off the rail for the Arcade.
     *
     * A swap from a sibling used to light the House button from anywhere in
     * the console. There is no House button now, so the count has to land on
     * the ☰ instead — a kid who is never told a trade is waiting will not go
     * looking for one, and the Trades page is two taps away behind a panel.
     */
    public function test_a_count_with_no_rail_button_lands_on_the_sheet_glyph(): void
    {
        $kid = $this->loginKid();
        $sibling = Profile::factory()->for($kid->household)->create();

        SiblingOffer::factory()->create([
            'household_id' => $kid->household_id,
            'from_profile_id' => $sibling->id,
            'to_profile_id' => $kid->id,
        ]);

        $html = Volt::test('kid.quests')->html();

        // Not on the rail, which no longer has a button that owns it...
        $this->assertStringNotContainsString('1 thing waiting on you', $this->rail($html));
        // ...and said in words on the glyph rather than left as a bare number,
        // which on a ☰ is a puzzle rather than a message.
        $this->assertStringContainsString('All pages — 1 thing waiting on you', $html);
    }

    /**
     * A count is only worth putting on a segment if it survives the segment
     * not being the open one — otherwise the kid has to visit a panel to find
     * out whether it wanted them.
     */
    public function test_an_inactive_segment_keeps_its_count(): void
    {
        $kid = $this->loginKid();

        StoreItem::factory()->count(2)->create(['household_id' => $kid->household_id]);

        // Standing on the Bonus Shop, with new loot waiting on the segment
        // beside it. Shop is the last rail button holding two pages, so it is
        // the only place a segment row is drawn at all.
        $segments = $this->segments(Volt::test('kid.bonus')->html());

        $this->assertStringContainsString('2 things waiting on you', $segments);
    }

    /**
     * The whole `?world=` / `session('kid_world')` resolution went with the
     * worlds. A rail button's state is now simply "is this the open page", so
     * nothing about the console can be steered from outside it.
     */
    public function test_nothing_in_the_nav_depends_on_a_remembered_world(): void
    {
        $this->loginKid();

        // A world on the query string and a stale, contradicting one in the
        // session. The Loot page sits behind Shop whatever either of them says,
        // because the rail is looking at the open page and nothing else.
        $steered = $this->withSession(['kid_world' => 'house'])
            ->get(route('kid.loot').'?world=me')
            ->assertOk()
            ->content();

        $rail = $this->rail($steered);

        $this->assertStringContainsString('aria-current="page"', $rail);
        $this->assertStringContainsString(route('kid.loot'), $rail);

        // And the trades page, which is exactly what the worlds used to steer,
        // opens on its own with no rail button to its name and writes nothing.
        $this->flushSession();
        $this->get(route('kid.trades'))->assertOk();

        $this->assertStringNotContainsString(
            'aria-current="page"',
            $this->rail($this->get(route('kid.household'))->assertOk()->content()),
            'Household is reached from the sheet now; no rail button should claim it.',
        );

        $this->assertNull(session('kid_world'), 'The shell should no longer write a world to the session.');
    }
}
