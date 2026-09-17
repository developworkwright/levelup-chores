<?php

namespace Tests\Feature;

use App\Enums\Feeling;
use App\Models\Household;
use App\Models\Profile;
use App\Models\StoreItem;
use App\Services\FeelingService;
use App\Services\HouseholdClock;
use App\Services\MealService;
use App\Services\StoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The parent landing page.
 *
 * Called Approvals until it stopped being one queue: it holds jobs on offer,
 * chore approvals, redemptions and lucky wins, and now the feelings card, which
 * asks nothing of anybody. The *sections* keep their own names.
 */
class ParentHomePageTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $parent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->parent = Profile::factory()->for($this->household)->parent()->create(['name' => 'Mom']);
    }

    public function test_the_old_approvals_url_still_lands_on_home(): void
    {
        Auth::guard('profile')->login($this->parent);

        // Push notifications already sitting on a phone carry the old path, and
        // a parent tapping an approval alert must not find a 404.
        $this->get('/parent/approvals')->assertRedirect('/parent/home');
    }

    public function test_the_parent_landing_route_goes_to_home(): void
    {
        Auth::guard('profile')->login($this->parent);

        $this->get('/parent')->assertRedirect('/parent/home');
    }

    public function test_the_tab_is_called_home_and_the_queue_keeps_its_own_name(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.home')
            ->assertOk()
            // The page is Home; the section on it is still Chore Approvals.
            ->assertSee('Chore Approvals');
    }

    /*
     * ------------------------------------------------------------------
     * The rows — the kid's Home, for the grown-ups
     * ------------------------------------------------------------------
     */

    public function test_every_row_is_on_the_index_queues_first(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.home')
            ->assertOk()
            ->assertSeeInOrder([
                "toggleRow('approvals')",
                "toggleRow('redemptions')",
                "toggleRow('jobs')",
                "toggleRow('lucky')",
                "toggleRow('feelings')",
                "toggleRow('meals')",
            ], escape: false)
            // Gratitude is a page of its own in the menu, with every entry.
            ->assertDontSee("toggleRow('gratitude')", escape: false)
            ->assertDontSee("toggleRow('celebration')", escape: false);
    }

    /** Nothing opens itself: the rows say what is waiting, and a tap opens it. */
    public function test_every_row_starts_shut(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.home')
            ->assertSet('openRow', null)
            ->assertDontSee("Queue's clear. Nothing to approve.", escape: false)
            ->assertSee('All clear');
    }

    /** A queue with anything in it is lit; a clear one is not. */
    public function test_a_queue_with_something_in_it_is_highlighted_but_not_opened(): void
    {
        $kid = Profile::factory()->for($this->household)->create(['name' => 'Sam', 'points' => 500]);
        $item = StoreItem::factory()->for($this->household)->create(['name' => 'Movie night', 'cost' => 100]);
        app(StoreService::class)->redeem($kid, $item);

        Auth::guard('profile')->login($this->parent);

        $page = Volt::test('parent.home')
            ->assertSet('openRow', null)
            ->assertSee('1 WAITING')
            ->assertSee('1 waiting on you');

        $html = $page->html();

        // Tile and row, both lit — and only the redemptions pair.
        $this->assertSame(2, substr_count($html, 'data-attention'));
        $this->assertMatchesRegularExpression('/data-attention[^>]*>\s*<span[^>]*>🎁/u', $html);

        // A notification, never a fill: gold is what an *open* row looks like,
        // and a highlight in the same family read as "already open".
        preg_match_all('/<button[^>]*data-attention[^>]*>/', $html, $lit);
        foreach ($lit[0] as $tag) {
            $this->assertStringNotContainsString('fq-gold', $tag);
        }
        $this->assertStringContainsString('inset 4px 0 0 var(--fq-streak)', $html);

        $page->call('toggleRow', 'redemptions')->assertSee('Mark fulfilled');
    }

    public function test_one_row_is_open_at_a_time_and_tapping_it_again_shuts_it(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.home')
            ->call('toggleRow', 'feelings')
            ->assertSee('How are you feeling today?')
            ->assertDontSee("Queue's clear. Nothing to approve.", escape: false)
            ->call('toggleRow', 'feelings')
            ->assertSet('openRow', null)
            ->assertDontSee('How are you feeling today?');
    }

    public function test_the_meals_row_lists_the_menu_and_links_to_the_planner(): void
    {
        $meals = app(MealService::class);
        $today = HouseholdClock::for($this->household)->today();
        $meals->set($this->household, $this->parent, $today, 'Tacos');
        $meals->set($this->household, $this->parent, $today->copy()->addDays(2), 'Curry');

        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.home')
            ->assertSee('Tonight · Tacos')
            ->call('toggleRow', 'meals')
            ->assertSeeInOrder(['Tacos', 'Curry'])
            ->assertSee(route('parent.meals'), false);
    }

    /**
     * Tomorrow's dinner is tomorrow's, even with nothing set for tonight. The
     * house's midnight and the date column's midnight are hours apart, and the
     * gap between them used to truncate tomorrow down to "Tonight".
     */
    public function test_tomorrows_dinner_is_not_called_tonights(): void
    {
        $today = HouseholdClock::for($this->household)->today();
        app(MealService::class)->set($this->household, $this->parent, $today->copy()->addDay(), 'Lasagne');

        Auth::guard('profile')->login($this->parent);

        $html = Volt::test('parent.home')
            ->assertSee('Tonight · not set')
            ->call('toggleRow', 'meals')
            ->assertSeeInOrder(['Tomorrow', 'Lasagne'])
            ->html();

        $this->assertSame(1, substr_count($html, 'Tonight'), 'Only the unset row face may say Tonight.');
    }

    /** Nobody has answered, and the row says so rather than "everyone has". */
    public function test_the_feelings_row_counts_nobody_when_nobody_has_said(): void
    {
        Profile::factory()->for($this->household)->count(2)->create();

        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.home')->assertSee('0 OF 3 SAID');

        Profile::factory()->for($this->household)->create();
        $kid = $this->household->profiles()->where('id', '!=', $this->parent->id)->first();
        app(FeelingService::class)->record($kid, Feeling::Happy);

        Volt::test('parent.home')->assertSee('1 OF 4 SAID');
    }

    /** The feed has a column of its own now, with nothing under it to protect. */
    public function test_the_feed_is_beside_the_rows_uncapped_and_without_its_quiet_half(): void
    {
        Auth::guard('profile')->login($this->parent);

        $html = Volt::test('parent.home')->html();

        preg_match('/<div[^>]*data-feed-messages[^>]*>/s', $html, $messages);
        $this->assertStringNotContainsString('overflow-y-auto', $messages[0]);
        $this->assertStringNotContainsString('Today in the house', $html);
        $this->assertStringContainsString('lg:grid-cols-[340px_minmax(0,1fr)]', $html);
    }
}
