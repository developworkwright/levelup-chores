<?php

namespace Tests\Feature;

use App\Enums\ChoreCadence;
use App\Enums\ChoreCategory;
use App\Enums\ChoreEffort;
use App\Enums\ChoreIcon;
use App\Enums\CompletionStatus;
use App\Enums\PriceBand;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The side-quest board's browse controls: price bands and category chips.
 *
 * A six-year-old asks a parent for "a $2 job" over and over, and the board
 * could not answer him — no ordering control at all, and the one filter it had
 * was a typed search, unusable by exactly the kid who needs it most.
 *
 * The filtered list is read off `viewData('board')` rather than asserted
 * against the rendered page: the adding-up card below the list names two
 * chores of its own, deliberately ignoring the band and the chip, so an
 * `assertDontSee` on a chore name would be testing the adder by accident.
 */
class QuestBoardFilterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Boards exclude every card in today's quest hand, so fixtures need one
     * quest-eligible chore to absorb the deal. The hinted decoy absorbs the
     * mystery draw the same way — hinted chores win it outright, and an
     * unpinned pick gets named on the page once found, which flakes any
     * assertDontSee on a chore name.
     */
    private function household(): Household
    {
        $household = Household::factory()->create();

        Chore::factory()->for($household)->create([
            'name' => 'The quest',
            'quest_eligible' => true,
        ]);

        Chore::factory()->for($household)->create([
            'name' => 'The decoy',
            'quest_eligible' => false,
            'hint' => 'Somewhere warm',
        ]);

        return $household;
    }

    /** @param  array<string, mixed>  $attributes */
    private function chore(Household $household, string $name, int $points, array $attributes = []): Chore
    {
        return Chore::factory()->for($household)->create($attributes + [
            'name' => $name,
            'points' => $points,
            'quest_eligible' => false,
        ]);
    }

    private function loginKid(Household $household): Profile
    {
        $kid = Profile::factory()->for($household)->create(['name' => 'Sam']);
        Auth::guard('profile')->login($kid);

        return $kid;
    }

    /** @return array<int, string> */
    private function shown(mixed $page): array
    {
        return $page->viewData('board')
            ->map(fn (array $entry) => $entry['chore']->name)
            ->all();
    }

    public function test_a_band_narrows_the_board_to_its_own_price_shelf(): void
    {
        $household = $this->household();
        $this->chore($household, 'Brush your teeth', 100);
        $this->chore($household, 'Sweep the kitchen', 250);
        $this->chore($household, 'Mow the lawn', 800);
        $this->loginKid($household);

        $page = Volt::test('kid.quests')->set('band', PriceBand::ARealJob->value);

        $this->assertSame(['Sweep the kitchen'], $this->shown($page));
    }

    public function test_tapping_the_live_band_clears_it(): void
    {
        // The control is its own off switch, so there is no separate "All" chip
        // to explain.
        $household = $this->household();
        $this->chore($household, 'Brush your teeth', 100);
        $this->loginKid($household);

        $page = Volt::test('kid.quests')
            ->call('pickBand', PriceBand::ARealJob->value)
            ->assertSet('band', PriceBand::ARealJob->value)
            ->call('pickBand', PriceBand::ARealJob->value)
            ->assertSet('band', null);

        $this->assertContains('Brush your teeth', $this->shown($page));
    }

    public function test_the_bands_are_read_in_the_households_own_money(): void
    {
        // A "$2–5" button that means $2 to $5 whatever the household rates a
        // point at. At 50 points to the dollar, 250 points is $5 and 125 is
        // $2.50 — so the shelf holds the *cheaper* of the two.
        $household = $this->household();
        $household->update(['points_per_dollar' => 50]);
        $this->chore($household, 'Sweep the kitchen', 250);
        $this->chore($household, 'Wipe the table', 125);
        $this->loginKid($household);

        $shown = $this->shown(Volt::test('kid.quests')->set('band', PriceBand::ARealJob->value));

        $this->assertContains('Wipe the table', $shown);
        $this->assertNotContains('Sweep the kitchen', $shown);
    }

    public function test_the_top_band_renders_even_when_it_is_empty(): void
    {
        // An empty band that sometimes fills is a promise. Hiding it costs the
        // eldest kid the one place he goes looking for a big one-time job.
        $household = $this->household();
        $this->chore($household, 'Brush your teeth', 100);
        $this->loginKid($household);

        Volt::test('kid.quests')
            ->assertSee('$10+')
            ->assertSee('Rare ones');
    }

    public function test_the_count_reads_open_until_something_narrows_it(): void
    {
        $household = $this->household();
        $this->chore($household, 'Brush your teeth', 100);
        $this->chore($household, 'Sweep the kitchen', 250);
        $this->loginKid($household);

        Volt::test('kid.quests')
            ->assertSee('3 open')
            ->set('band', PriceBand::ARealJob->value)
            ->assertSee('1 of 3');
    }

    public function test_a_category_chip_narrows_the_board_to_its_kind(): void
    {
        $household = $this->household();
        $this->chore($household, 'Load the dishwasher', 175, ['category' => ChoreCategory::Kitchen]);
        $this->chore($household, 'Fold the washing', 300, ['category' => ChoreCategory::Laundry]);
        $this->loginKid($household);

        $page = Volt::test('kid.quests')->set('category', ChoreCategory::Kitchen->value);

        $this->assertSame(['Load the dishwasher'], $this->shown($page));
    }

    public function test_the_category_owes_nothing_to_the_chores_face(): void
    {
        // The icon is a card face a pre-reader picks the chore by, and the
        // column is uncast so a parent can paste any class they like. Letting
        // it decide the category meant choosing a nicer picture moved the chore
        // to another chip — so nothing reads the icon here any more.
        $household = $this->household();
        $this->chore($household, 'Scrub the bath', 300, [
            'icon' => ChoreIcon::Dishes->faClass(),
            'category' => ChoreCategory::Cleaning,
        ]);
        $this->loginKid($household);

        $page = Volt::test('kid.quests')->set('category', ChoreCategory::Cleaning->value);

        $this->assertSame(['Scrub the bath'], $this->shown($page));
    }

    public function test_a_chore_nobody_has_filed_collects_under_other(): void
    {
        // Never a silent disappearance: picking a chip must not be able to hide
        // a chore from every chip at once.
        $household = $this->household();
        $unfiled = $this->chore($household, 'Polish the trophies', 200, ['icon' => 'fa-solid fa-rocket']);
        $this->loginKid($household);

        $this->assertNull($unfiled->category);
        $this->assertSame(ChoreCategory::Other, ChoreCategory::forChore($unfiled));

        $page = Volt::test('kid.quests')->set('category', ChoreCategory::Other->value);

        $this->assertContains('Polish the trophies', $this->shown($page));
    }

    public function test_a_chip_is_only_offered_when_something_is_behind_it(): void
    {
        // A chip that leads nowhere is worse than no chip. Muscle in particular
        // is empty on every board until a parent flags a chore.
        $household = $this->household();
        $this->chore($household, 'Load the dishwasher', 175, ['category' => ChoreCategory::Kitchen]);
        $this->loginKid($household);

        $chips = Volt::test('kid.quests')->viewData('chips')->pluck('id')->all();

        $this->assertContains(ChoreCategory::Kitchen->value, $chips);
        $this->assertNotContains(ChoreCategory::Garden->value, $chips);
        $this->assertNotContains('muscle', $chips);
        $this->assertNotContains('done', $chips);
    }

    public function test_the_muscle_chip_collects_what_a_parent_flagged(): void
    {
        // The one axis nothing guesses at: scrubbing a bathroom is hard work
        // behind an indoor icon, and a wrong guess sends a six-year-old at a
        // job he can't finish.
        $household = $this->household();
        $this->chore($household, 'Weed whack the fence', 1000, ['effort' => ChoreEffort::Heavy]);
        $this->chore($household, 'Brush your teeth', 100);
        $this->loginKid($household);

        $page = Volt::test('kid.quests')->set('category', 'muscle');

        $this->assertSame(['Weed whack the fence'], $this->shown($page));
    }

    public function test_outside_is_a_category_a_parent_picks_not_a_guess(): void
    {
        // It used to be derived — lawn, bins, car, plants, windows and post
        // were "outdoor by name" — and it sat beside the categories as its own
        // switch, so a chore could be Outside *and* Garden. As a category it is
        // exclusive, which is the point: a parent decides which one this board
        // wants to browse a job by.
        $household = $this->household();
        $this->chore($household, 'Weed whack the fence', 1000, ['category' => ChoreCategory::Outside]);
        $this->chore($household, 'Mow the lawn', 800, [
            'icon' => ChoreIcon::Lawn->faClass(),
            'category' => ChoreCategory::Garden,
        ]);
        $this->loginKid($household);

        $page = Volt::test('kid.quests')->set('category', ChoreCategory::Outside->value);

        $this->assertSame(['Weed whack the fence'], $this->shown($page));
    }

    public function test_done_before_counts_approved_work_only(): void
    {
        // A pending claim is work a parent hasn't looked at yet, and a chip
        // promising "you've done this one" should mean somebody agreed.
        $household = $this->household();
        $approved = $this->chore($household, 'Sweep the porch', 250, ['cadence' => ChoreCadence::Unlimited]);
        $pending = $this->chore($household, 'Wipe the table', 200, ['cadence' => ChoreCadence::Unlimited]);
        $kid = $this->loginKid($household);

        ChoreCompletion::create([
            'chore_id' => $approved->id,
            'profile_id' => $kid->id,
            'status' => CompletionStatus::Approved,
            'points_awarded' => 250,
            'submitted_at' => now()->subDays(3),
        ]);

        ChoreCompletion::create([
            'chore_id' => $pending->id,
            'profile_id' => $kid->id,
            'status' => CompletionStatus::Pending,
            'points_awarded' => 200,
            'submitted_at' => now()->subDays(3),
        ]);

        $page = Volt::test('kid.quests')->set('category', 'done');

        $this->assertSame(['Sweep the porch'], $this->shown($page));
    }

    public function test_a_sibling_doing_it_is_not_this_kid_having_done_it_before(): void
    {
        $household = $this->household();
        $chore = $this->chore($household, 'Sweep the porch', 250, ['cadence' => ChoreCadence::Unlimited]);
        $sibling = Profile::factory()->for($household)->create(['name' => 'Nova']);
        $this->loginKid($household);

        ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $sibling->id,
            'status' => CompletionStatus::Approved,
            'points_awarded' => 250,
            'submitted_at' => now()->subDays(3),
        ]);

        $chips = Volt::test('kid.quests')->viewData('chips')->pluck('id')->all();

        $this->assertNotContains('done', $chips);
    }

    public function test_a_band_and_a_chip_narrow_together(): void
    {
        $household = $this->household();
        $this->chore($household, 'Mow the lawn', 800, ['category' => ChoreCategory::Garden]);
        $this->chore($household, 'Water the plants', 150, ['category' => ChoreCategory::Garden]);
        $this->chore($household, 'Vacuum upstairs', 800, ['category' => ChoreCategory::Cleaning]);
        $this->loginKid($household);

        $page = Volt::test('kid.quests')
            ->set('band', PriceBand::HalfADay->value)
            ->set('category', ChoreCategory::Garden->value);

        $this->assertSame(['Mow the lawn'], $this->shown($page));
    }

    public function test_an_empty_combination_points_at_the_adder(): void
    {
        $household = $this->household();
        $this->chore($household, 'Brush your teeth', 100);
        $this->loginKid($household);

        Volt::test('kid.quests')
            ->set('band', PriceBand::RareOnes->value)
            ->assertSee('Nothing on the board matches that right now.')
            ->assertSee('Try a different amount, or add two smaller jobs together below.');
    }

    public function test_a_row_shows_the_money_and_the_points(): void
    {
        // He thinks in dollars; the points are what the rest of the app counts
        // in, so both are on the row — and the money always to two places.
        $household = $this->household();
        $this->chore($household, 'Sweep the kitchen', 250);
        $this->loginKid($household);

        Volt::test('kid.quests')
            ->assertSee('$2.50')
            ->assertSee('250 PTS');
    }

    public function test_a_row_says_what_tapping_it_does(): void
    {
        // The row *is* the button, which is the design — but a row that claims
        // a chore with no sign of it is a trap. The tick that carries it is a
        // picture, so the words live in the row's title and in sr-only text
        // rather than nowhere at all.
        $household = $this->household();
        $this->chore($household, 'Sweep the kitchen', 250);
        $this->loginKid($household);

        Volt::test('kid.quests')->assertSee('Mark it done');
    }

    public function test_a_light_chore_says_so_as_readily_as_a_hard_one(): void
    {
        // The Muscle chip collects Heavy alone, but a chore a parent has
        // deliberately called easy is worth saying on the row — it answers "is
        // this a big one?", which is what the effort control is for. This read
        // Heavy only at first, so setting a chore to easy going showed nothing
        // anywhere on the board.
        $household = $this->household();
        $this->chore($household, 'Wipe the table', 150, ['effort' => ChoreEffort::Light]);
        $this->loginKid($household);

        Volt::test('kid.quests')->assertSeeInOrder(['Wipe the table', 'Easy']);
    }

    public function test_a_row_tags_what_a_kid_browses_by(): void
    {
        // Cadence, then the flags. The category is deliberately *not* a tag:
        // repeating the chip you just filtered by on every row it returned is
        // noise, and the face already carries it.
        $household = $this->household();
        $this->chore($household, 'Mow the lawn', 800, [
            'category' => ChoreCategory::Garden,
            'effort' => ChoreEffort::Heavy,
            'cadence' => ChoreCadence::Weekly,
        ]);
        $this->loginKid($household);

        Volt::test('kid.quests')
            ->assertSeeInOrder(['Mow the lawn', 'Once a week', 'Muscle']);
    }

    public function test_the_filters_reset_on_arrival(): void
    {
        // Transient, like the search beside them. A board half-taken looks very
        // different an hour later, so this defaults back to showing everything.
        $household = $this->household();
        $this->chore($household, 'Brush your teeth', 100);
        $this->loginKid($household);

        Volt::test('kid.quests')
            ->assertSet('band', null)
            ->assertSet('category', null);
    }
}
