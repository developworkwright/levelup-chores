<?php

namespace Tests\Feature;

use App\Enums\PerkEffect;
use App\Exceptions\PerkUnavailableException;
use App\Models\BonusPerk;
use App\Models\Chore;
use App\Models\Household;
use App\Models\LedgerEntry;
use App\Models\MysteryHintPurchase;
use App\Models\Profile;
use App\Services\BonusShopService;
use App\Services\ChoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PerkStreakAndHintTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The catalogue row backing an effect for this kid's household. */
    private function perk(Profile $kid, PerkEffect $effect): BonusPerk
    {
        return BonusPerk::where('household_id', $kid->household_id)
            ->where('effect', $effect)
            ->firstOrFail();
    }

    /** Clears the day's quest end to end so it counts toward the streak. */
    private function clearQuest(Profile $kid, Profile $parent): void
    {
        $chores = app(ChoreService::class);
        $quest = $chores->claimQuest($kid);

        $completion = $kid->choreCompletions()
            ->where('chore_id', $quest->chore_id)
            ->latest('id')
            ->firstOrFail();

        $chores->approve($completion, $parent);
    }

    public function test_restoring_a_streak_buys_back_the_missed_day(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        Chore::factory()->for($household)->create(['points' => 0, 'min_age' => 1]);

        // Two days on, one missed, then today.
        $this->clearQuest($kid, $parent);
        Carbon::setTestNow(now()->addDay());
        $this->clearQuest($kid, $parent);

        Carbon::setTestNow(now()->addDays(2));
        $this->clearQuest($kid, $parent);

        // The gap reset the chain to just today.
        $this->assertSame(1, $kid->refresh()->streak);

        // Captured here, not assumed — clearing quests mints tickets of its
        // own through levelling and badges.
        $before = $kid->bonus_tickets;

        app(BonusShopService::class)->purchase($kid, $this->perk($kid, PerkEffect::StreakRestore));

        // Yesterday now counts, reconnecting the run.
        $this->assertSame(4, $kid->refresh()->streak);
        $this->assertSame($before - $this->perk($kid, PerkEffect::StreakRestore)->cost, $kid->bonus_tickets);
    }

    public function test_restoring_is_refused_when_no_streak_is_broken(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        Chore::factory()->for($household)->create(['points' => 0, 'min_age' => 1]);

        $this->clearQuest($kid, $parent);
        Carbon::setTestNow(now()->addDay());
        $this->clearQuest($kid, $parent);

        $before = $kid->refresh()->bonus_tickets;

        $this->expectException(PerkUnavailableException::class);

        try {
            app(BonusShopService::class)->purchase($kid, $this->perk($kid, PerkEffect::StreakRestore));
        } finally {
            $this->assertSame($before, $kid->refresh()->bonus_tickets);
        }
    }

    public function test_repairing_never_pays_a_milestone_bonus_twice(): void
    {
        // The exploit this guards: reach a milestone, let the streak lapse so
        // it recomputes down, then repair it and collect the bonus again.
        $household = Household::factory()->create(['points_per_dollar' => 100]);
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 20]);
        Chore::factory()->for($household)->create(['points' => 0, 'min_age' => 1]);

        for ($day = 0; $day < 3; $day++) {
            if ($day > 0) {
                Carbon::setTestNow(now()->addDay());
            }
            $this->clearQuest($kid, $parent);
        }

        $this->assertSame(3, $kid->refresh()->streak);
        $paidForDayThree = LedgerEntry::where('profile_id', $kid->id)
            ->where('description', 'like', '%3-day streak bonus%')->count();
        $this->assertSame(1, $paidForDayThree);

        // Miss a day, come back, then buy the repair.
        Carbon::setTestNow(now()->addDays(2));
        $this->clearQuest($kid, $parent);
        app(BonusShopService::class)->purchase($kid, $this->perk($kid, PerkEffect::StreakRestore));

        $this->assertSame(1, LedgerEntry::where('profile_id', $kid->id)
            ->where('description', 'like', '%3-day streak bonus%')->count());
    }

    public function test_buying_a_hint_reveals_the_parents_clue(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        Chore::factory()->for($household)->create([
            'name' => 'Feed animals',
            'hint' => 'The hungry ones cannot ask for it themselves.',
        ]);

        $outcome = app(BonusShopService::class)->purchase($kid, $this->perk($kid, PerkEffect::MysteryHint));

        $this->assertStringContainsString('hungry ones', $outcome);
        $this->assertSame(10 - $this->perk($kid, PerkEffect::MysteryHint)->cost, $kid->refresh()->bonus_tickets);
    }

    public function test_a_hint_is_visible_only_to_the_kid_who_paid(): void
    {
        $household = Household::factory()->create();
        $buyer = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        $sibling = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        Chore::factory()->for($household)->create(['hint' => 'It lives under the sink.']);

        app(BonusShopService::class)->purchase($buyer, $this->perk($buyer, PerkEffect::MysteryHint));

        $chores = app(ChoreService::class);
        $this->assertNotNull($chores->mysteryHintFor($buyer));
        // One sibling paying must not clue in the rest, or the race stops being fair.
        $this->assertNull($chores->mysteryHintFor($sibling));
    }

    public function test_a_hint_cannot_be_bought_twice_in_a_day(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 20]);
        Chore::factory()->for($household)->create(['hint' => 'A clue.']);

        $shop = app(BonusShopService::class);
        $shop->purchase($kid, $this->perk($kid, PerkEffect::MysteryHint));
        $afterFirst = $kid->refresh()->bonus_tickets;

        $this->expectException(PerkUnavailableException::class);

        try {
            $shop->purchase($kid, $this->perk($kid, PerkEffect::MysteryHint));
        } finally {
            $this->assertSame($afterFirst, $kid->refresh()->bonus_tickets);
            $this->assertSame(1, MysteryHintPurchase::where('profile_id', $kid->id)->count());
        }
    }

    public function test_a_hint_is_refused_once_the_mystery_is_found(): void
    {
        $household = Household::factory()->create();
        $finder = Profile::factory()->for($household)->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        $chore = Chore::factory()->for($household)->create(['hint' => 'A clue.']);

        app(ChoreService::class)->claim($finder, $chore);

        $this->expectException(PerkUnavailableException::class);

        try {
            app(BonusShopService::class)->purchase($kid, $this->perk($kid, PerkEffect::MysteryHint));
        } finally {
            $this->assertSame(10, $kid->refresh()->bonus_tickets);
        }
    }

    public function test_the_quests_page_shows_a_purchased_hint(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        Chore::factory()->for($household)->create(['hint' => 'It lives under the sink.']);

        app(BonusShopService::class)->purchase($kid, $this->perk($kid, PerkEffect::MysteryHint));

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->assertSee('Your Hint')
            ->assertSee('It lives under the sink.');
    }

    public function test_the_quests_page_hides_the_hint_from_a_kid_who_did_not_buy_it(): void
    {
        $household = Household::factory()->create();
        $buyer = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        $sibling = Profile::factory()->for($household)->create();
        Chore::factory()->for($household)->create(['hint' => 'It lives under the sink.']);

        app(BonusShopService::class)->purchase($buyer, $this->perk($buyer, PerkEffect::MysteryHint));

        Auth::guard('profile')->login($sibling);

        Volt::test('kid.quests')
            ->assertDontSee('Your Hint')
            ->assertDontSee('It lives under the sink.');
    }
}
