<?php

namespace Tests\Feature;

use App\Enums\BossSkin;
use App\Models\ArcadeScore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ArcadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The arcade sits on `/`, which has no auth. Most of what is tested here is
 * therefore not about the game at all — it is about what a stranger with the
 * URL can put on the page and what the page tells them back.
 */
class ArcadeTest extends TestCase
{
    use RefreshDatabase;

    private function arcade(): ArcadeService
    {
        return app(ArcadeService::class);
    }

    public function test_a_run_lands_on_this_weeks_board(): void
    {
        Volt::test('arcade')->call('post', 23, 0, 0);

        $score = ArcadeScore::sole();

        $this->assertSame(23, $score->score);
        $this->assertSame($this->arcade()->currentWeek(), $score->week);
    }

    public function test_a_codename_is_rebuilt_from_the_vocabulary_rather_than_taken_as_text(): void
    {
        // The browser sends two integers. Whatever it sends, the string that
        // reaches the column is one the server chose from its own word lists —
        // there is no path from a request to arbitrary text on a public page.
        Volt::test('arcade')->call('post', 5, 2, 3);

        $expected = ArcadeService::ADJECTIVES[2].' '.ArcadeService::nouns()[3];

        $this->assertSame($expected, ArcadeScore::sole()->codename);
    }

    public function test_out_of_range_indexes_wrap_instead_of_escaping_the_vocabulary(): void
    {
        $codename = $this->arcade()->codename(99999, -7);

        [$adjective, $noun] = explode(' ', $codename, 2);

        $this->assertContains($adjective, ArcadeService::ADJECTIVES);
        $this->assertContains($noun, ArcadeService::nouns());
    }

    public function test_the_codename_vocabulary_follows_the_monster_roster(): void
    {
        // Derived rather than listed, so a new skin joins the arcade for free.
        $this->assertCount(count(BossSkin::cases()), ArcadeService::nouns());
        $this->assertContains('BALLOONHEAD', ArcadeService::nouns());
        $this->assertContains('MOLDKING', ArcadeService::nouns());
    }

    public function test_an_impossible_score_is_not_written(): void
    {
        $this->assertNull($this->arcade()->post(ArcadeService::MAX_SCORE + 1, 0, 0));
        $this->assertNull($this->arcade()->post(0, 0, 0));
        $this->assertNull($this->arcade()->post(-4, 0, 0));

        $this->assertSame(0, ArcadeScore::count());
    }

    public function test_the_board_stops_listening_once_a_visitor_has_posted_enough(): void
    {
        $component = Volt::test('arcade');

        for ($i = 0; $i < ArcadeService::POSTS_PER_HOUR; $i++) {
            $component->call('post', 3, 0, 0);
        }

        $component->call('post', 999, 0, 0);

        $this->assertSame(ArcadeService::POSTS_PER_HOUR, ArcadeScore::count());
        $this->assertSame(0, ArcadeScore::where('score', 999)->count());

        RateLimiter::clear('arcade-post:'.session()->getId());
    }

    public function test_the_board_shows_this_week_and_not_last(): void
    {
        // Last week's run is deliberately the smaller one: the all-time line
        // under the board would otherwise show it and this would assert nothing.
        ArcadeScore::create(['codename' => 'SALTY RATTLE', 'score' => 8, 'week' => '1999-W01']);
        ArcadeScore::create(['codename' => 'GRIM DRIP', 'score' => 12, 'week' => $this->arcade()->currentWeek()]);

        Volt::test('arcade')
            ->assertSee('GRIM DRIP')
            ->assertDontSee('SALTY RATTLE');
    }

    public function test_last_weeks_giant_still_holds_the_all_time_record(): void
    {
        // The weekly reset is what gives a new player a shot at the board; the
        // all-time line is what stops the reset from erasing the big run.
        ArcadeScore::create(['codename' => 'SALTY RATTLE', 'score' => 40, 'week' => '1999-W01']);

        Volt::test('arcade')
            ->assertSee('All-time record')
            ->assertSee('40 floors');
    }

    public function test_a_tie_is_broken_by_who_got_there_first(): void
    {
        $week = $this->arcade()->currentWeek();

        $first = ArcadeScore::create(['codename' => 'SALTY RATTLE', 'score' => 9, 'week' => $week]);
        $second = ArcadeScore::create(['codename' => 'GRIM DRIP', 'score' => 9, 'week' => $week]);

        $this->assertSame($first->id, $this->arcade()->weeklyTop()->first()->id);
        $this->assertSame($second->id, $this->arcade()->weeklyTop()->last()->id);
    }

    public function test_the_board_is_a_top_ten(): void
    {
        $week = $this->arcade()->currentWeek();

        for ($i = 1; $i <= 14; $i++) {
            ArcadeScore::create(['codename' => 'SALTY RATTLE', 'score' => $i, 'week' => $week]);
        }

        $top = $this->arcade()->weeklyTop();

        $this->assertCount(10, $top);
        $this->assertSame(14, $top->first()->score);
        $this->assertSame(5, $top->last()->score);
    }

    public function test_a_score_is_labelled_with_how_high_it_got(): void
    {
        $arcade = $this->arcade();

        $this->assertSame('On the rug', $arcade->altitude(0));
        $this->assertSame('Sofa height', $arcade->altitude(3));
        $this->assertSame('Sofa height', $arcade->altitude(5));
        $this->assertSame('Ceiling', $arcade->altitude(18));
        $this->assertSame('Outer space', $arcade->altitude(400));
    }

    public function test_a_posted_run_says_nothing_about_who_posted_it(): void
    {
        // The whole privacy argument for putting a game on `/`, as an assertion:
        // the table has nowhere to put a person even if a future change wanted to.
        Volt::test('arcade')->call('post', 12, 1, 1);

        $this->assertSame(
            ['id', 'codename', 'score', 'week', 'created_at'],
            Schema::getColumnListing('arcade_scores')
        );
    }

    public function test_the_arcade_is_on_the_public_login_page(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create(['name' => 'Nova']);

        $this->get('/')
            ->assertOk()
            ->assertSee('The Arcade')
            ->assertSee('Stack')
            ->assertSee('tallest');
    }

    public function test_the_game_does_not_leak_a_kid_into_the_page_it_sits_on(): void
    {
        // The arcade renders inside the login page, so anything it queried
        // about the family would end up on a world-readable page. It queries
        // nothing: the board is the only data it has.
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create(['name' => 'Nova', 'streak' => 7]);

        $html = Volt::test('arcade')->html();

        $this->assertStringNotContainsString('Nova', $html);
    }
}
