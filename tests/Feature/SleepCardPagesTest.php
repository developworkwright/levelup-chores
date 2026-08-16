<?php

namespace Tests\Feature;

use App\Enums\Constellation;
use App\Enums\SleepOutcome;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Models\SleepNight;
use App\Services\SleepService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SleepCardPagesTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create(['sleep_card_enabled' => true]);
        $this->kid = Profile::factory()->for($this->household)->create([
            'name' => 'Ziggy',
            'sleep_card_enabled' => true,
        ]);
        Chore::factory()->for($this->household)->create();

        $this->travelTo(Carbon::parse('2026-05-01 09:00', $this->household->timezone));
    }

    private function loginKid(): void
    {
        Auth::guard('profile')->login($this->kid);
    }

    public function test_the_card_is_absent_for_a_kid_it_is_not_switched_on_for(): void
    {
        $this->kid->update(['sleep_card_enabled' => false]);
        $this->loginKid();

        // Every other kid's page must look exactly as it did before this
        // feature existed.
        Volt::test('kid.quests')
            ->assertOk()
            ->assertDontSee('How did last night go?')
            ->assertDontSee('Last Night');
    }

    public function test_the_card_is_absent_when_the_household_switch_is_off(): void
    {
        $this->household->update(['sleep_card_enabled' => false]);
        $this->loginKid();

        Volt::test('kid.quests')
            ->assertOk()
            ->assertDontSee('How did last night go?');
    }

    public function test_the_card_asks_and_a_star_lands(): void
    {
        $this->loginKid();

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('How did last night go?')
            ->assertSee(Constellation::LittleBear->label())
            ->call('answerSleep', SleepOutcome::OwnBed->value)
            ->assertSee('That is a star.');

        $this->assertSame(1, $this->kid->fresh()->sleep_nights);
    }

    public function test_a_hard_night_is_answered_warmly_and_costs_nothing(): void
    {
        app(SleepService::class)->record($this->kid, SleepOutcome::OwnBed);
        $this->travel(1)->days();

        $this->loginKid();

        Volt::test('kid.quests')
            ->call('answerSleep', SleepOutcome::Rough->value)
            ->assertSee('Nothing lost.');

        $fresh = $this->kid->fresh();

        $this->assertSame(1, $fresh->sleep_nights);
        $this->assertSame(0, $fresh->sleep_run);
        $this->assertSame(1, $fresh->sleep_best_run);
    }

    public function test_answering_twice_does_not_light_two_stars(): void
    {
        $this->loginKid();

        // The card is on the page a kid lands on, and a double tap is the most
        // likely thing a six-year-old will do to it.
        Volt::test('kid.quests')
            ->call('answerSleep', SleepOutcome::OwnBed->value)
            ->call('answerSleep', SleepOutcome::OwnBed->value)
            ->assertOk();

        $this->assertSame(1, $this->kid->fresh()->sleep_nights);
        $this->assertSame(1, SleepNight::where('profile_id', $this->kid->id)->count());
    }

    public function test_a_nonsense_answer_is_ignored_rather_than_thrown(): void
    {
        $this->loginKid();

        Volt::test('kid.quests')
            ->call('answerSleep', 'slept-on-the-roof')
            ->assertOk();

        $this->assertSame(0, SleepNight::where('profile_id', $this->kid->id)->count());
    }

    public function test_the_night_chest_shows_and_opens(): void
    {
        $service = app(SleepService::class);

        for ($i = 0; $i < 3; $i++) {
            $service->record($this->kid->refresh(), SleepOutcome::OwnBed);
            $this->travel(1)->days();
        }

        $this->loginKid();

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('3 nights in a row — tap to open')
            ->call('openSleepChest')
            ->assertDontSee('tap to open');

        $this->assertNull($this->kid->fresh()->pending_sleep_chest);
    }

    // --- Parent console ----------------------------------------------------

    private function loginParent(): Profile
    {
        $parent = Profile::factory()->for($this->household)->parent()->create();
        Auth::guard('profile')->login($parent);

        return $parent;
    }

    public function test_a_parent_can_switch_the_card_on_for_one_kid(): void
    {
        $this->kid->update(['sleep_card_enabled' => false]);
        $this->loginParent();

        Volt::test('parent.kids')
            ->assertOk()
            ->assertSee('Own Bed Card')
            ->call('toggleSleepCard', $this->kid->id);

        $this->assertTrue($this->kid->fresh()->sleep_card_enabled);
    }

    public function test_the_per_kid_switch_is_hidden_until_the_household_one_is_on(): void
    {
        $this->household->update(['sleep_card_enabled' => false]);
        $this->loginParent();

        // The household block still explains the feature; what disappears is
        // the per-kid row, which would be meaningless with the family off.
        Volt::test('parent.kids')
            ->assertOk()
            ->assertDontSee('Now switch it on for whoever needs it');
    }

    public function test_a_parent_can_taper_the_payout_from_the_console(): void
    {
        $this->loginParent();

        Volt::test('parent.kids')
            ->assertOk()
            ->assertSee('Per constellation')
            ->call('adjustConstellationPayout', -SleepService::PAYOUT_STEP);

        $this->assertSame(450, $this->household->fresh()->sleep_constellation_points);
    }

    public function test_the_payout_cannot_be_tapered_below_nothing(): void
    {
        app(SleepService::class)->setConstellationPoints($this->household, 0);
        $this->loginParent();

        Volt::test('parent.kids')
            // The dial reads "nothing" rather than 0 — the end of a taper is a
            // state, not a broken number.
            ->assertSee('nothing')
            ->call('adjustConstellationPayout', -SleepService::PAYOUT_STEP);

        $this->assertSame(0, $this->household->fresh()->sleep_constellation_points);
    }

    public function test_a_parent_can_correct_the_numbers_from_the_console(): void
    {
        app(SleepService::class)->adjust($this->kid, nights: 5, run: 5);
        $this->loginParent();

        Volt::test('parent.kids')
            ->call('adjustSleep', $this->kid->id, -1, 0)
            ->call('adjustSleep', $this->kid->id, 0, -1);

        $fresh = $this->kid->fresh();

        $this->assertSame(4, $fresh->sleep_nights);
        $this->assertSame(4, $fresh->sleep_run);
    }

    public function test_a_parent_cannot_reach_another_households_kid(): void
    {
        $stranger = Profile::factory()->for(Household::factory()->create())->create([
            'sleep_card_enabled' => false,
        ]);

        $this->loginParent();

        Volt::test('parent.kids')->call('toggleSleepCard', $stranger->id);

        $this->assertFalse($stranger->fresh()->sleep_card_enabled);
    }
}
