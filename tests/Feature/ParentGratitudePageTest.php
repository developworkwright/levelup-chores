<?php

namespace Tests\Feature;

use App\Models\GratitudeEntry;
use App\Models\Household;
use App\Models\Profile;
use App\Services\GratitudeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The parent Gratitude page: every list the kids have written, not just the few
 * Activity shows.
 */
class ParentGratitudePageTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $parent;

    private Profile $raylan;

    private Profile $westin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->travelTo(Carbon::parse('2026-05-04 12:00', $this->household->timezone));

        $this->parent = Profile::factory()->for($this->household)->parent()->create(['name' => 'Mom']);
        $this->raylan = Profile::factory()->for($this->household)->create(['name' => 'Raylan']);
        $this->westin = Profile::factory()->for($this->household)->create(['name' => 'Westin']);
    }

    private function entry(Profile $kid, int $daysAgo, string $first, bool $shared = true): GratitudeEntry
    {
        return GratitudeEntry::factory()->for($this->household)->for($kid)->daysAgo($daysAgo)
            ->create(['items' => [$first, 'Second', 'Third'], 'shared' => $shared]);
    }

    public function test_it_reads_every_entry_newest_first_and_pages_rather_than_capping(): void
    {
        // Well past both Activity's eight and a page of twenty.
        foreach (range(1, 25) as $daysAgo) {
            $this->entry($this->raylan, $daysAgo, "Day {$daysAgo} first");
        }

        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.gratitude')
            ->assertOk()
            ->assertSeeInOrder(['Day 1 first', 'Day 2 first', 'Day 20 first'])
            ->assertDontSee('Day 21 first')
            ->call('nextPage')
            ->assertSee('Day 25 first');
    }

    public function test_it_filters_to_one_kid_and_back(): void
    {
        $this->entry($this->raylan, 1, 'Raylan thing');
        $this->entry($this->westin, 2, 'Westin thing');

        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.gratitude')
            ->assertSee('Raylan thing')
            ->assertSee('Westin thing')
            ->call('showKid', $this->westin->id)
            ->assertSee('Westin thing')
            ->assertDontSee('Raylan thing')
            ->call('showKid', null)
            ->assertSee('Raylan thing');
    }

    public function test_a_kid_from_another_house_is_not_a_filter(): void
    {
        $stranger = Profile::factory()->create();

        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.gratitude')
            ->call('showKid', $stranger->id)
            ->assertSet('kidId', null);
    }

    public function test_it_never_shows_another_households_lists(): void
    {
        $this->entry($this->raylan, 1, 'Ours');
        app(GratitudeService::class)->record(Profile::factory()->create(), ['Not', 'Your', 'Household']);

        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.gratitude')
            ->assertSee('Ours')
            ->assertDontSee('Your');
    }

    /** The opt-out is about the siblings, and the page says which ones used it. */
    public function test_a_list_kept_from_the_house_is_labelled(): void
    {
        $this->entry($this->raylan, 1, 'Just for me', shared: false);

        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.gratitude')
            ->assertSee('Just for me')
            ->assertSee('Kept from the house');
    }

    public function test_it_is_for_the_grown_ups_and_in_their_menu(): void
    {
        $this->actingAs($this->raylan, 'profile')->get(route('parent.gratitude'))->assertForbidden();

        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.home')->assertSee(route('parent.gratitude'), false);
        Volt::test('parent.activity')->assertSee(route('parent.gratitude'), false);
    }
}
