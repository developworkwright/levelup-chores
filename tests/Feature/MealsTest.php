<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Meal;
use App\Models\Profile;
use App\Services\FeedService;
use App\Services\HouseholdClock;
use App\Services\MealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * What's for dinner — the service, and the line the kids read.
 *
 * The decision this file exists to pin is the one in the house card: dinner is
 * drawn *above* the feelings gate. Everything else on that card is behind "say
 * how your day went first", which is a rule protecting the feelings — and a kid
 * must never have to file a feeling to find out what's for tea.
 */
class MealsTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    private Profile $mom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->kid = Profile::factory()->for($this->household)->create(['name' => 'Raylan']);
        $this->mom = Profile::factory()->parent()->for($this->household)->create(['name' => 'Mom']);

        app(FeedService::class)->ensureRooms($this->household);
    }

    private function today(): Carbon
    {
        return HouseholdClock::for($this->household)->today();
    }

    /*
     * ------------------------------------------------------------------
     * The service
     * ------------------------------------------------------------------
     */

    public function test_a_dinner_is_set_for_a_household_day(): void
    {
        $meal = app(MealService::class)->set($this->household, $this->mom, $this->today(), '  Tacos  ', ' with rice ');

        $this->assertSame('Tacos', $meal->name);
        $this->assertSame('with rice', $meal->note);
        $this->assertSame((int) $this->mom->id, (int) $meal->set_by_profile_id);
        $this->assertTrue($meal->served_on->isSameDay($this->today()));
    }

    /** The unique index is what makes this an update rather than a second row. */
    public function test_setting_the_same_night_twice_replaces_it_rather_than_stacking(): void
    {
        $meals = app(MealService::class);

        $meals->set($this->household, $this->mom, $this->today(), 'Tacos');
        $meals->set($this->household, $this->mom, $this->today(), 'Spaghetti');

        $this->assertDatabaseCount('meals', 1);
        $this->assertSame('Spaghetti', $meals->tonight($this->household)->name);
    }

    /**
     * Emptying the box is the only gesture the parent screen offers for "we
     * don't know yet", so it has to mean that rather than storing a blank row.
     */
    public function test_a_blank_name_clears_the_night(): void
    {
        $meals = app(MealService::class);

        $meals->set($this->household, $this->mom, $this->today(), 'Tacos');
        $this->assertNull($meals->set($this->household, $this->mom, $this->today(), '   '));

        $this->assertDatabaseCount('meals', 0);
        $this->assertNull($meals->tonight($this->household));
    }

    public function test_tonight_and_tomorrow_are_read_separately(): void
    {
        $meals = app(MealService::class);

        $meals->set($this->household, $this->mom, $this->today(), 'Tacos');
        $meals->set($this->household, $this->mom, $this->today()->addDay(), 'Spaghetti');

        $this->assertSame('Tacos', $meals->tonight($this->household)->name);
        $this->assertSame('Spaghetti', $meals->tomorrow($this->household)->name);
    }

    public function test_the_week_is_seven_days_starting_today(): void
    {
        $week = app(MealService::class)->week($this->household);

        $this->assertCount(MealService::WEEK, $week);
        $this->assertTrue($week[0]['date']->isSameDay($this->today()));
        $this->assertTrue($week[6]['date']->isSameDay($this->today()->addDays(6)));
        $this->assertNull($week[0]['meal']);
    }

    /**
     * The last row of the week is the one a range query loses.
     *
     * `served_on` is a date column, but the date cast writes it back with a
     * time on it — so a plain whereBetween against a bare 'Y-m-d' upper bound
     * sorts the seventh day *after* the bound and silently drops it.
     */
    public function test_the_week_carries_the_meals_set_on_its_first_and_last_days(): void
    {
        $meals = app(MealService::class);

        $meals->set($this->household, $this->mom, $this->today(), 'Tacos');
        $meals->set($this->household, $this->mom, $this->today()->addDays(6), 'Roast');

        $week = $meals->week($this->household);

        $this->assertSame('Tacos', $week[0]['meal']?->name);
        $this->assertSame('Roast', $week[6]['meal']?->name);

        // And a day past the end of the week is not dragged in with it.
        $meals->set($this->household, $this->mom, $this->today()->addDays(7), 'Too far');
        $this->assertCount(MealService::WEEK, $meals->week($this->household));
        $this->assertNotContains('Too far', collect($meals->week($this->household))->map(fn (array $r) => $r['meal']?->name)->all());
    }

    public function test_another_households_dinner_is_never_read(): void
    {
        $other = Household::factory()->create();
        $theirParent = Profile::factory()->parent()->for($other)->create();

        app(MealService::class)->set($other, $theirParent, $this->today(), 'Their roast');

        $this->assertNull(app(MealService::class)->tonight($this->household));
    }

    /**
     * The household day rolls at 4am, so a kid still up at 1am is in last
     * night's day and "tonight's dinner" is still last night's. This is the
     * whole reason the service goes through HouseholdClock rather than now().
     */
    public function test_the_small_hours_still_belong_to_the_evening_before(): void
    {
        $this->household->update(['timezone' => 'UTC', 'day_boundary_hour' => 4]);

        Carbon::setTestNow(Carbon::parse('2026-09-15 20:00:00', 'UTC'));
        app(MealService::class)->set($this->household, $this->mom, $this->today(), 'Tacos');

        Carbon::setTestNow(Carbon::parse('2026-09-16 01:00:00', 'UTC'));
        $this->assertSame('Tacos', app(MealService::class)->tonight($this->household)->name);

        // And past the boundary it is a new day with nothing on it yet.
        Carbon::setTestNow(Carbon::parse('2026-09-16 09:00:00', 'UTC'));
        $this->assertNull(app(MealService::class)->tonight($this->household));

        Carbon::setTestNow();
    }

    /*
     * ------------------------------------------------------------------
     * The line the kids read
     * ------------------------------------------------------------------
     */

    public function test_the_feed_shows_tonights_dinner_to_a_kid(): void
    {
        app(MealService::class)->set($this->household, $this->mom, $this->today(), 'Tacos', 'eat at 5');

        Auth::guard('profile')->login($this->kid);

        Volt::test('family-feed')
            ->assertSee('Tonight')
            ->assertSee('Tacos')
            ->assertSee('eat at 5');
    }

    /**
     * The decision this file is really for. Every other row on that card is
     * behind "say how your day went first"; dinner is not one of the things
     * that rule protects.
     */
    public function test_dinner_is_readable_without_having_filed_a_feeling(): void
    {
        app(MealService::class)->set($this->household, $this->mom, $this->today(), 'Tacos');

        Auth::guard('profile')->login($this->kid);

        Volt::test('family-feed')
            ->assertSee('Tacos')
            // The gate is still up over the feelings beside it.
            ->assertSee("Say how your day went first and you'll see everyone else's.", false);
    }

    public function test_tomorrow_is_shown_once_it_is_set_and_not_before(): void
    {
        app(MealService::class)->set($this->household, $this->mom, $this->today(), 'Tacos');

        Auth::guard('profile')->login($this->kid);

        Volt::test('family-feed')->assertDontSee('Tomorrow');

        app(MealService::class)->set($this->household, $this->mom, $this->today()->addDay(), 'Spaghetti');

        Volt::test('family-feed')->assertSee('Tomorrow')->assertSee('Spaghetti');
    }

    /**
     * No "no dinner planned" line for the kids. They are not the person who can
     * fix it, and an app nagging on their behalf is noise on their screen.
     */
    public function test_an_unset_menu_says_nothing_at_all_to_a_kid(): void
    {
        Auth::guard('profile')->login($this->kid);

        Volt::test('family-feed')
            ->assertDontSee('Tonight')
            ->assertDontSee('No dinner set yet');
    }

    /** A grown-up is the person who can fix it, so they get the prompt. */
    public function test_an_unset_menu_offers_a_grown_up_the_menu_screen(): void
    {
        Auth::guard('profile')->login($this->mom);

        Volt::test('family-feed')
            ->assertSee('No dinner set yet')
            ->assertSee(route('parent.meals'), false);
    }

    public function test_a_kid_is_never_pointed_at_the_parent_screen(): void
    {
        app(MealService::class)->set($this->household, $this->mom, $this->today(), 'Tacos');

        Auth::guard('profile')->login($this->kid);

        Volt::test('family-feed')
            ->assertSee('Tacos')
            ->assertDontSee(route('parent.meals'), false);
    }

    public function test_another_households_dinner_never_reaches_this_feed(): void
    {
        $other = Household::factory()->create();
        $theirParent = Profile::factory()->parent()->for($other)->create();

        Meal::factory()->for($other)->create(['name' => 'Their roast', 'set_by_profile_id' => $theirParent->id]);

        Auth::guard('profile')->login($this->kid);

        Volt::test('family-feed')->assertDontSee('Their roast');
    }
}
