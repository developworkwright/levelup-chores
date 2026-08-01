<?php

namespace Tests\Feature;

use App\Models\BonusPerk;
use App\Models\Chore;
use App\Models\DailyChest;
use App\Models\Household;
use App\Models\OwnedPerk;
use App\Models\Profile;
use App\Services\ChestService;
use App\Services\ChoreService;
use App\Services\PerkInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DailyChestTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function chests(): ChestService
    {
        return app(ChestService::class);
    }

    private function kid(array $attributes = []): Profile
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->count(3)->create();

        return Profile::factory()->for($household)->create($attributes);
    }

    public function test_a_chest_is_available_and_pays_out_something(): void
    {
        $kid = $this->kid();

        $this->assertTrue($this->chests()->isAvailable($kid));

        $chest = $this->chests()->open($kid);

        $this->assertNotNull($chest);
        $this->assertContains($chest->reward_kind, [
            ChestService::KIND_TICKETS,
            ChestService::KIND_PERK,
            ChestService::KIND_POINTS,
            ChestService::KIND_XP,
        ]);
    }

    public function test_only_one_chest_a_day(): void
    {
        $kid = $this->kid();

        $this->chests()->open($kid);

        $this->assertFalse($this->chests()->isAvailable($kid->refresh()));
        $this->assertNull($this->chests()->open($kid));
        $this->assertSame(1, DailyChest::where('profile_id', $kid->id)->count());
    }

    public function test_a_new_chest_arrives_the_next_day(): void
    {
        $kid = $this->kid();

        $this->chests()->open($kid);

        Carbon::setTestNow(now()->addDay());

        $this->assertTrue($this->chests()->isAvailable($kid->refresh()));
        $this->assertNotNull($this->chests()->open($kid));
        $this->assertSame(2, DailyChest::where('profile_id', $kid->id)->count());
    }

    public function test_a_pending_streak_chest_takes_precedence(): void
    {
        // One chest a day — a milestone day is the streak chest's, not this one's.
        $kid = $this->kid(['pending_streak_chest' => 3]);

        $this->assertFalse($this->chests()->isAvailable($kid));
        $this->assertNull($this->chests()->open($kid));
    }

    public function test_the_chest_records_whether_the_quest_was_done(): void
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->count(3)->create();
        $kid = Profile::factory()->for($household)->create();

        app(ChoreService::class)->claimQuest($kid);

        $chest = $this->chests()->open($kid);

        // Clearing the quest doesn't unlock the chest, it improves the roll —
        // so the flag has to be captured to make that auditable.
        $this->assertTrue($chest->quest_was_done);
    }

    public function test_a_ticket_reward_lands_in_the_balance(): void
    {
        $kid = $this->kid(['bonus_tickets' => 0]);

        // Force the outcome by leaving no other reward possible is not doable
        // through the public API, so drive it until tickets come up.
        do {
            DailyChest::where('profile_id', $kid->id)->delete();
            $chest = $this->chests()->open($kid->refresh());
        } while ($chest->reward_kind !== ChestService::KIND_TICKETS);

        $this->assertSame($chest->reward_amount, $kid->refresh()->bonus_tickets);
    }

    public function test_a_perk_reward_lands_in_the_inventory(): void
    {
        $kid = $this->kid();

        do {
            DailyChest::where('profile_id', $kid->id)->delete();
            OwnedPerk::where('profile_id', $kid->id)->delete();
            $chest = $this->chests()->open($kid->refresh());
        } while ($chest->reward_kind !== ChestService::KIND_PERK);

        $owned = OwnedPerk::where('profile_id', $kid->id)->firstOrFail();

        $this->assertSame($chest->reward_effect, $owned->effect);
        $this->assertSame(OwnedPerk::SOURCE_CHEST, $owned->source);
        $this->assertSame(1, app(PerkInventoryService::class)->countOf($kid, $owned->effect));
    }

    public function test_it_falls_back_to_tickets_when_every_perk_is_disabled(): void
    {
        $kid = $this->kid(['bonus_tickets' => 0]);
        BonusPerk::where('household_id', $kid->household_id)->update(['enabled' => false]);

        // With nothing to grant, a perk roll must still pay out rather than
        // handing over an empty chest.
        for ($i = 0; $i < 25; $i++) {
            DailyChest::where('profile_id', $kid->id)->delete();
            $chest = $this->chests()->open($kid->refresh());

            $this->assertNotSame(ChestService::KIND_PERK, $chest->reward_kind);
        }

        $this->assertSame(0, OwnedPerk::where('profile_id', $kid->id)->count());
    }

    public function test_the_quests_page_offers_and_opens_the_chest(): void
    {
        $kid = $this->kid();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->assertSee('Daily Chest')
            ->call('openDailyChest')
            ->assertSuccessful();

        $this->assertSame(1, DailyChest::where('profile_id', $kid->id)->count());
    }

    public function test_the_milestone_track_survives_alongside_the_chest(): void
    {
        // The chest is its own block, not an alternative to the streak panel —
        // the milestone track is useful every day.
        $kid = $this->kid(['streak' => 1]);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->assertSee('Daily Chest')
            ->assertSee('D3 · 100', false);
    }

    public function test_the_quests_page_hides_the_chest_once_opened(): void
    {
        $kid = $this->kid();

        $this->chests()->open($kid);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')->assertDontSee('Daily Chest');
    }
}
