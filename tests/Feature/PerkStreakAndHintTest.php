<?php

namespace Tests\Feature;

use App\Enums\PerkEffect;
use App\Enums\TicketKind;
use App\Exceptions\PerkUnavailableException;
use App\Models\BonusPerk;
use App\Models\BonusTicketEntry;
use App\Models\Chore;
use App\Models\Household;
use App\Models\LedgerEntry;
use App\Models\MysteryHintPurchase;
use App\Models\Profile;
use App\Services\BonusShopService;
use App\Services\ChoreService;
use App\Services\PerkInventoryService;
use App\Services\StreakService;
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

    /** Buys a perk and immediately spends it — the old one-step behaviour. */
    private function buyAndUse(Profile $kid, PerkEffect $effect): string
    {
        app(BonusShopService::class)->purchase($kid, $this->perk($kid, $effect));

        return app(PerkInventoryService::class)->use($kid, $effect);
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

        // Two days on, one missed, then standing on the next day with today's
        // quest still untouched — the only window a restore is good for.
        $this->clearQuest($kid, $parent);
        Carbon::setTestNow(now()->addDay());
        $this->clearQuest($kid, $parent);

        Carbon::setTestNow(now()->addDays(2));

        // The gap killed the chain, and the cached count says so without
        // waiting for an approval to notice.
        app(StreakService::class)->syncStreak($kid->refresh());
        $this->assertSame(0, $kid->refresh()->streak);

        $this->buyAndUse($kid, PerkEffect::StreakRestore);

        // Yesterday now counts, reconnecting the run behind it.
        $this->assertSame(3, $kid->refresh()->streak);

        // Asserted off the ticket ledger rather than the balance: levelling up
        // and unlocking badges both mint tickets along the way, so the balance
        // moves for reasons that have nothing to do with the price paid.
        $this->assertSame(
            -$this->perk($kid, PerkEffect::StreakRestore)->cost,
            (int) BonusTicketEntry::where('profile_id', $kid->id)
                ->where('kind', TicketKind::Purchase)
                ->sum('amount'),
        );

        // And today's quest lands on top of the rescued chain.
        $this->clearQuest($kid, $parent);
        $this->assertSame(4, $kid->refresh()->streak);
    }

    public function test_a_restore_is_refused_once_todays_quest_is_cleared(): void
    {
        // The window closes when the new chain starts: buying the broken day
        // back at that point would splice a finished run onto a fresh one.
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        Chore::factory()->for($household)->create(['points' => 0, 'min_age' => 1]);

        $this->clearQuest($kid, $parent);
        Carbon::setTestNow(now()->addDay());
        $this->clearQuest($kid, $parent);

        Carbon::setTestNow(now()->addDays(2));
        $this->clearQuest($kid, $parent);

        $this->assertSame(1, $kid->refresh()->streak);

        $this->expectException(PerkUnavailableException::class);
        $this->expectExceptionMessage('Too late — today already counts');

        $this->buyAndUse($kid, PerkEffect::StreakRestore);
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

        // Buying is always allowed — holding one against a future slip is the
        // point. Using it is what gets refused while nothing is broken.
        app(BonusShopService::class)->purchase($kid, $this->perk($kid, PerkEffect::StreakRestore));

        $this->expectException(PerkUnavailableException::class);

        try {
            app(PerkInventoryService::class)->use($kid, PerkEffect::StreakRestore);
        } finally {
            $this->assertSame(1, app(PerkInventoryService::class)->countOf($kid, PerkEffect::StreakRestore));
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

        // The chest is what pays, so the milestone has to actually be collected
        // before there is anything for a repair to collect a second time.
        app(StreakService::class)->openStreakChest($kid);

        $paidForDayThree = LedgerEntry::where('profile_id', $kid->id)
            ->where('description', 'like', '%3-day streak bonus%')->count();
        $this->assertSame(1, $paidForDayThree);

        // Miss a day, come back, and buy the repair before touching today's
        // quest — the only order the restore is still allowed in.
        Carbon::setTestNow(now()->addDays(2));
        app(StreakService::class)->syncStreak($kid->refresh());
        $this->assertSame(0, $kid->refresh()->streak);

        $this->buyAndUse($kid, PerkEffect::StreakRestore);
        $this->clearQuest($kid, $parent);

        // Three cleared days, the bought-back one, and today.
        $this->assertSame(5, $kid->refresh()->streak);

        // Day 5 is a fresh milestone and is allowed to queue a chest. Opening
        // it must pay for day 5 and nothing else — the walk starts at the mark,
        // so day 3 is behind it and stays bought.
        $this->assertSame(['day' => 5, 'dollars' => 3], app(StreakService::class)->openStreakChest($kid));
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

        $outcome = $this->buyAndUse($kid, PerkEffect::MysteryHint);

        $this->assertStringContainsString('hungry ones', $outcome);
        $this->assertSame(10 - $this->perk($kid, PerkEffect::MysteryHint)->cost, $kid->refresh()->bonus_tickets);
    }

    public function test_a_hint_is_visible_only_to_the_kid_who_paid(): void
    {
        $household = Household::factory()->create();
        $buyer = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        $sibling = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        Chore::factory()->for($household)->create(['hint' => 'It lives under the sink.']);

        $this->buyAndUse($buyer, PerkEffect::MysteryHint);

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

        $this->buyAndUse($kid, PerkEffect::MysteryHint);

        app(BonusShopService::class)->purchase($kid, $this->perk($kid, PerkEffect::MysteryHint));

        $this->expectException(PerkUnavailableException::class);

        try {
            app(PerkInventoryService::class)->use($kid, PerkEffect::MysteryHint);
        } finally {
            // The second perk is kept for another day rather than wasted.
            $this->assertSame(1, app(PerkInventoryService::class)->countOf($kid, PerkEffect::MysteryHint));
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

        app(BonusShopService::class)->purchase($kid, $this->perk($kid, PerkEffect::MysteryHint));

        $this->expectException(PerkUnavailableException::class);

        try {
            app(PerkInventoryService::class)->use($kid, PerkEffect::MysteryHint);
        } finally {
            // A clue is worthless once the race is over, so it keeps for tomorrow.
            $this->assertSame(1, app(PerkInventoryService::class)->countOf($kid, PerkEffect::MysteryHint));
        }
    }

    public function test_the_quests_page_shows_a_purchased_hint(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);
        Chore::factory()->for($household)->create(['hint' => 'It lives under the sink.']);

        $this->buyAndUse($kid, PerkEffect::MysteryHint);

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

        $this->buyAndUse($buyer, PerkEffect::MysteryHint);

        Auth::guard('profile')->login($sibling);

        Volt::test('kid.quests')
            ->assertDontSee('Your Hint')
            ->assertDontSee('It lives under the sink.');
    }
}
