<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Models\SiblingOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

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

    public function test_the_header_shows_points_and_tickets(): void
    {
        $this->loginKid(['points' => 250, 'streak' => 4, 'bonus_tickets' => 7]);

        Volt::test('kid.quests')
            ->assertSee('PTS')
            ->assertSee('TICKETS')
            ->assertSee('7');
    }

    public function test_the_header_carries_no_streak_badge_or_sound_tiles(): void
    {
        // The bank had grown into a row of numbers a kid can't act on. The
        // streak and the badge wall each have a page of their own, and the one
        // mute button left is the arcade's, which writes the same key.
        $this->loginKid(['streak' => 4]);

        Volt::test('kid.quests')
            ->assertDontSee('STREAK')
            ->assertDontSee('BADGES')
            ->assertDontSee('Sound on');
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
        foreach (['kid.quests', 'kid.loot', 'kid.badges', 'kid.bonus'] as $page) {
            Volt::test($page)->assertSee('TICKETS');
        }
    }

    public function test_the_rail_carries_every_world_on_every_page(): void
    {
        $this->loginKid();

        foreach (['kid.quests', 'kid.loot', 'kid.stats'] as $page) {
            Volt::test($page)
                // Home leads the rail from every page — it is the way back to
                // the front of the day, and a kid who is lost reaches for the
                // first button without reading the rest.
                ->assertSee('Home')
                ->assertSee('Earn')
                ->assertSee('Spend')
                ->assertSee('Me');
        }
    }

    /**
     * Just the page-pill row. Pages name each other all over their own copy, so
     * a bare assertSee can't tell a pill from a link in a card.
     */
    private function pills(string $html): string
    {
        preg_match('/<nav\s+aria-label="Pages in .*?<\/nav>/s', $html, $matches);

        return $matches[0] ?? '';
    }

    public function test_a_page_shows_only_its_own_worlds_pills(): void
    {
        $this->loginKid();

        // Earn is down to the board alone now that the wheel moved to Home, so
        // it draws no pill row at all — a single pill marked as the open page
        // is a control with nowhere to go.
        $this->assertSame('', $this->pills(Volt::test('kid.quests')->html()));

        $house = $this->pills(Volt::test('kid.arena')->html());

        $this->assertStringContainsString('Arena', $house);
        // Swaps and jobs share one page, and the pill names both so a kid can
        // see where each of them lives.
        $this->assertStringContainsString('Trades &amp; Jobs', $house);
        // Other worlds' pages stay folded away until that world is opened.
        $this->assertStringNotContainsString('Loot Shop', $house);

        $me = $this->pills(Volt::test('kid.stats')->html());

        $this->assertStringContainsString('Goals', $me);
        $this->assertStringContainsString('Badges', $me);
        $this->assertStringNotContainsString('Arena', $me);

        // The rail opens a world's *first* page, and Me leads with Stats —
        // "how am I doing" before "what am I saving for".
        $this->assertLessThan(
            strpos($me, 'Goals'),
            strpos($me, 'Stats'),
            'Stats should be the first pill in Me, and so the page the rail opens.',
        );
    }

    /**
     * A session world only holds while it still contains the open page.
     *
     * No page belongs to two worlds any more — Trades was the only one that
     * did, and it now lives in House alone. So the half of `$holdsPage` that
     * still earns its keep is the *rejection*: a stale `kid_world` from
     * wherever the kid was last must not stick to a page that world doesn't
     * hold. That is what this pins, and it is why the check can't be dropped
     * along with the dual-world page that first needed it.
     */
    public function test_a_stale_world_gives_way_to_the_pages_own(): void
    {
        $this->loginKid();

        $stale = $this->pills(
            $this->withSession(['kid_world' => 'spend'])->get(route('kid.trades'))->assertOk()->content()
        );

        // Spend no longer holds Trades, so the rail falls back to House.
        $this->assertStringContainsString('Arena', $stale);
        $this->assertStringNotContainsString('Loot Shop', $stale);

        $held = $this->pills(
            $this->withSession(['kid_world' => 'house'])->get(route('kid.trades'))->assertOk()->content()
        );

        $this->assertStringContainsString('Arena', $held);
        $this->assertStringNotContainsString('Loot Shop', $held);
    }

    public function test_the_world_query_parameter_opens_that_world(): void
    {
        $this->loginKid();

        // What the rail's own links carry: arriving at the Arena through House
        // has to open House even with nothing in the session yet.
        $pills = $this->pills(
            $this->get(route('kid.arena').'?world=house')->assertOk()->content()
        );

        $this->assertStringContainsString('Trades &amp; Jobs', $pills);
        $this->assertStringNotContainsString('Loot Shop', $pills);
    }

    public function test_a_world_carries_the_badge_of_a_page_inside_it(): void
    {
        $kid = $this->loginKid();
        $sibling = Profile::factory()->for($kid->household)->create();

        SiblingOffer::factory()->create([
            'household_id' => $kid->household_id,
            'from_profile_id' => $sibling->id,
            'to_profile_id' => $kid->id,
        ]);

        // Waiting on the Trades page, but the count has to be visible from the
        // Quests tab — that's the point of hanging it on the world.
        Volt::test('kid.quests')
            ->assertSee('1 thing waiting on you');
    }
}
