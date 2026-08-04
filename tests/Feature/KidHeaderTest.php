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

    public function test_the_header_shows_points_streak_and_tickets(): void
    {
        $this->loginKid(['points' => 250, 'streak' => 4, 'bonus_tickets' => 7]);

        Volt::test('kid.quests')
            ->assertSee('PTS')
            ->assertSee('STREAK')
            ->assertSee('TICKETS')
            ->assertSee('7');
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

    public function test_the_rail_carries_the_three_worlds_on_every_page(): void
    {
        $this->loginKid();

        foreach (['kid.quests', 'kid.loot', 'kid.stats'] as $page) {
            Volt::test($page)
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

        $earn = $this->pills(Volt::test('kid.quests')->html());

        $this->assertStringContainsString('Bonus Wheel', $earn);
        $this->assertStringContainsString('Trades', $earn);
        // Spend and Me pages belong to other worlds, so their pills stay folded
        // away until that world is opened.
        $this->assertStringNotContainsString('Loot Shop', $earn);
        $this->assertStringNotContainsString('Badges', $earn);

        $me = $this->pills(Volt::test('kid.stats')->html());

        $this->assertStringContainsString('Goals', $me);
        $this->assertStringContainsString('Badges', $me);
        $this->assertStringNotContainsString('Bonus Wheel', $me);
    }

    public function test_trades_stays_in_the_world_the_kid_came_in_through(): void
    {
        $this->loginKid();

        // Trades belongs to both Earn and Spend. Arriving from Spend has to
        // leave the Spend pills up rather than snapping back to Earn.
        $fromSpend = $this->pills(
            $this->withSession(['kid_world' => 'spend'])->get(route('kid.offers'))->assertOk()->content()
        );

        $this->assertStringContainsString('Loot Shop', $fromSpend);
        $this->assertStringNotContainsString('Bonus Wheel', $fromSpend);

        $fromEarn = $this->pills(
            $this->withSession(['kid_world' => 'earn'])->get(route('kid.offers'))->assertOk()->content()
        );

        $this->assertStringContainsString('Bonus Wheel', $fromEarn);
        $this->assertStringNotContainsString('Loot Shop', $fromEarn);
    }

    public function test_the_world_query_parameter_opens_that_world(): void
    {
        $this->loginKid();

        // What the rail's own links carry: arriving at Trades through Spend has
        // to open Spend even with nothing in the session yet.
        $pills = $this->pills(
            $this->get(route('kid.offers').'?world=spend')->assertOk()->content()
        );

        $this->assertStringContainsString('Bonus Shop', $pills);
        $this->assertStringNotContainsString('Quests', $pills);
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
            ->assertSee('1 sibling trade waiting on you');
    }
}
