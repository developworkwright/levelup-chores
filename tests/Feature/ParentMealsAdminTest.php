<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Meal;
use App\Models\Profile;
use App\Services\HouseholdClock;
use App\Services\MealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The menu screen — seven nights, typed in and saved on blur.
 *
 * There is no Save button, which is the decision most of these pin: every box
 * writes when it loses focus, so a week filled in halfway is a week half
 * filled in rather than a form nobody submitted.
 */
class ParentMealsAdminTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $mom;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->mom = Profile::factory()->parent()->for($this->household)->create(['name' => 'Mom']);
        $this->kid = Profile::factory()->for($this->household)->create(['name' => 'Raylan']);
    }

    private function today(): string
    {
        return HouseholdClock::for($this->household)->today()->toDateString();
    }

    public function test_a_parent_sets_a_dinner(): void
    {
        Auth::guard('profile')->login($this->mom);

        Volt::test('parent.meals')
            ->set('names.'.$this->today(), 'Tacos')
            ->set('notes.'.$this->today(), 'eat at 5')
            ->call('save', $this->today());

        $meal = app(MealService::class)->tonight($this->household);

        $this->assertSame('Tacos', $meal->name);
        $this->assertSame('eat at 5', $meal->note);
        $this->assertSame((int) $this->mom->id, (int) $meal->set_by_profile_id);
    }

    public function test_a_parent_renames_a_dinner(): void
    {
        app(MealService::class)->set($this->household, $this->mom, HouseholdClock::for($this->household)->today(), 'Tacos');

        Auth::guard('profile')->login($this->mom);

        Volt::test('parent.meals')
            ->set('names.'.$this->today(), 'Spaghetti')
            ->call('save', $this->today());

        $this->assertSame('Spaghetti', app(MealService::class)->tonight($this->household)->name);
        $this->assertDatabaseCount('meals', 1);
    }

    public function test_clearing_a_night_removes_the_row(): void
    {
        app(MealService::class)->set($this->household, $this->mom, HouseholdClock::for($this->household)->today(), 'Tacos');

        Auth::guard('profile')->login($this->mom);

        Volt::test('parent.meals')->call('clearDay', $this->today());

        $this->assertDatabaseCount('meals', 0);
    }

    public function test_the_week_is_drawn_with_tonight_at_the_top(): void
    {
        app(MealService::class)->set($this->household, $this->mom, HouseholdClock::for($this->household)->today(), 'Tacos');

        Auth::guard('profile')->login($this->mom);

        Volt::test('parent.meals')
            ->assertSee('Tonight')
            ->assertSee('Tacos')
            ->assertSee(HouseholdClock::for($this->household)->today()->addDays(6)->format('D j M'));
    }

    public function test_a_kid_cannot_open_the_menu_screen(): void
    {
        Auth::guard('profile')->login($this->kid);

        $this->get(route('parent.meals'))->assertForbidden();
    }

    /**
     * The cross-household negative every mutating screen in this app carries.
     * A date is the only thing identifying a row here, and every household has
     * the same dates — so the scope has to come from the logged-in profile and
     * nowhere else.
     */
    public function test_a_parent_cannot_set_another_households_dinner(): void
    {
        $other = Household::factory()->create();
        $theirParent = Profile::factory()->parent()->for($other)->create();

        app(MealService::class)->set($other, $theirParent, HouseholdClock::for($other)->today(), 'Their roast');

        Auth::guard('profile')->login($this->mom);

        Volt::test('parent.meals')
            ->set('names.'.$this->today(), 'Tacos')
            ->call('save', $this->today());

        // Their night is untouched, and ours is a row of our own.
        $this->assertSame('Their roast', app(MealService::class)->tonight($other)->name);
        $this->assertSame('Tacos', app(MealService::class)->tonight($this->household)->name);
        $this->assertDatabaseCount('meals', 2);
    }

    /**
     * The screen edits seven nights and only seven. A hand-sent date outside
     * that window writes nothing — otherwise the page is an open door onto
     * every date there has ever been.
     */
    public function test_a_day_outside_the_week_on_screen_is_refused(): void
    {
        Auth::guard('profile')->login($this->mom);

        $faraway = HouseholdClock::for($this->household)->today()->addYear()->toDateString();

        Volt::test('parent.meals')
            ->set('names.'.$faraway, 'Next year')
            ->call('save', $faraway);

        $this->assertDatabaseCount('meals', 0);
    }

    public function test_a_date_that_is_not_a_date_writes_nothing(): void
    {
        Auth::guard('profile')->login($this->mom);

        Volt::test('parent.meals')->call('save', 'not-a-date');

        $this->assertDatabaseCount('meals', 0);
    }

    public function test_another_households_menu_is_never_drawn(): void
    {
        $other = Household::factory()->create();

        Meal::factory()->for($other)->create(['name' => 'Their roast']);

        Auth::guard('profile')->login($this->mom);

        Volt::test('parent.meals')->assertDontSee('Their roast');
    }

    public function test_a_name_longer_than_the_cap_is_trimmed_rather_than_refused(): void
    {
        Auth::guard('profile')->login($this->mom);

        Volt::test('parent.meals')
            ->set('names.'.$this->today(), str_repeat('a', MealService::MAX_NAME + 50))
            ->call('save', $this->today());

        $this->assertSame(
            MealService::MAX_NAME,
            mb_strlen(app(MealService::class)->tonight($this->household)->name),
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
