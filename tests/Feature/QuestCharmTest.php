<?php

namespace Tests\Feature;

use App\Enums\PerkEffect;
use App\Enums\QuestCharmEffect;
use App\Exceptions\PerkUnavailableException;
use App\Models\BonusPerk;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Household;
use App\Models\OwnedPerk;
use App\Models\Profile;
use App\Services\ChoreService;
use App\Services\PerkInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The Quest Charm: a bet placed on a chest before it opens, which resolves
 * twice — once on the cards, once at hand-in.
 *
 * @see QuestCharmEffect
 */
class QuestCharmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::now()->startOfDay()->addHours(12));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): ChoreService
    {
        return app(ChoreService::class);
    }

    /**
     * @param  array<int, int>  $points
     * @return array{0: Household, 1: Profile}
     */
    private function householdWithChores(array $points): array
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['age' => 10]);

        foreach ($points as $i => $value) {
            Chore::factory()->for($household)->create([
                'name' => "Chore {$i}",
                'points' => $value,
                'min_age' => null,
                'quest_eligible' => true,
            ]);
        }

        return [$household, $kid];
    }

    /** Pins the hand roll, which is otherwise weighted chance. */
    private function charmWith(Profile $kid, QuestCharmEffect $effect): void
    {
        $this->service()->charmQuest($kid);
        $this->service()->questFor($kid)->forceFill(['charm_effect' => $effect])->save();
    }

    public function test_the_charm_is_in_the_shop_for_every_household(): void
    {
        $household = Household::factory()->create();

        $this->assertTrue(
            BonusPerk::where('household_id', $household->id)
                ->where('effect', PerkEffect::QuestCharm)
                ->exists(),
        );
    }

    public function test_a_charm_can_only_be_cast_on_a_chest_that_is_still_shut(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);
        $inventory = app(PerkInventoryService::class);

        $this->assertNull($inventory->blockedReason($kid, PerkEffect::QuestCharm));

        $this->service()->dealQuestHand($kid);

        // Charming cards you have already read isn't a gamble, it's shopping.
        $this->assertSame(
            'Charm the chest before you open it',
            $inventory->blockedReason($kid, PerkEffect::QuestCharm),
        );
    }

    public function test_a_second_charm_is_refused(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);

        $this->assertNotNull($this->service()->charmQuest($kid));
        $this->assertNull($this->service()->charmQuest($kid));

        $this->assertSame(
            "Today's quest is already charmed",
            app(PerkInventoryService::class)->blockedReason($kid, PerkEffect::QuestCharm),
        );
    }

    public function test_using_the_perk_charms_the_chest_and_keeps_the_outcome_quiet(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);
        app(PerkInventoryService::class)->grant($kid, PerkEffect::QuestCharm, OwnedPerk::SOURCE_SHOP);

        $outcome = app(PerkInventoryService::class)->use($kid, PerkEffect::QuestCharm);

        $this->assertTrue($this->service()->questFor($kid)->isCharmed());
        // What it did isn't decided until the lid comes up, so the message must
        // not promise one — that would spend the reveal a beat early.
        $this->assertSame('The chest is charmed — open it and see.', $outcome);
        $this->assertNull($this->service()->questFor($kid)->charm_effect);
    }

    public function test_a_perk_that_cannot_be_applied_stays_in_the_pocket(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);
        app(PerkInventoryService::class)->grant($kid, PerkEffect::QuestCharm, OwnedPerk::SOURCE_SHOP);

        $this->service()->dealQuestHand($kid);

        try {
            app(PerkInventoryService::class)->use($kid, PerkEffect::QuestCharm);
            $this->fail('Expected the charm to be refused on an open chest.');
        } catch (PerkUnavailableException) {
            // Expected.
        }

        $this->assertSame(1, app(PerkInventoryService::class)->countOf($kid, PerkEffect::QuestCharm));
    }

    public function test_the_effect_is_rolled_when_the_chest_opens_not_when_the_charm_is_cast(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);

        $this->service()->charmQuest($kid);
        $this->assertNull($this->service()->questFor($kid)->charm_effect);

        $this->service()->dealQuestHand($kid);
        $this->assertNotNull($this->service()->questFor($kid)->charm_effect);
    }

    public function test_a_second_card_charm_makes_the_top_two_bold(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);
        $this->charmWith($kid, QuestCharmEffect::SecondCard);

        $hand = $this->service()->offeredChoresFor($kid);
        $bonuses = $this->service()->cardBonusesFor($kid);

        $this->assertCount(2, $bonuses);
        $this->assertSame(500, $bonuses[$hand->last()->id]);
        $this->assertSame(50, $bonuses[$hand[1]->id]);
        $this->assertArrayNotHasKey($hand->first()->id, $bonuses);
    }

    public function test_an_all_cards_charm_makes_every_card_bold(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);
        $this->charmWith($kid, QuestCharmEffect::AllCards);

        $this->assertCount(3, $this->service()->cardBonusesFor($kid));
    }

    public function test_a_doubled_charm_doubles_the_one_bold_card_rather_than_widening_the_hand(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);
        $this->charmWith($kid, QuestCharmEffect::DoubledBonus);

        $hand = $this->service()->offeredChoresFor($kid);
        $bonuses = $this->service()->cardBonusesFor($kid);

        $this->assertCount(1, $bonuses);
        $this->assertSame(1000, $bonuses[$hand->last()->id]);
    }

    public function test_an_unchanged_charm_leaves_the_hand_exactly_as_it_was(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);
        $this->charmWith($kid, QuestCharmEffect::Unchanged);

        $hand = $this->service()->offeredChoresFor($kid);

        $this->assertSame([$hand->last()->id => 500], $this->service()->cardBonusesFor($kid));
    }

    public function test_a_charm_overrides_the_no_bold_card_rule_on_a_flat_hand(): void
    {
        // Normally a hand whose cards all pay the same has no bold card at all.
        // The tickets were spent, so an arbitrary bold card beats a fizzle.
        [, $kid] = $this->householdWithChores([50, 50, 50]);

        $this->assertSame([], $this->service()->cardBonusesFor($kid));

        $this->charmWith($kid, QuestCharmEffect::AllCards);

        $this->assertCount(3, $this->service()->cardBonusesFor($kid));
    }

    public function test_the_payout_roll_is_settled_once_at_hand_in(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);
        $this->charmWith($kid, QuestCharmEffect::Unchanged);

        $plain = $this->service()->offeredChoresFor($kid)->first();
        $this->service()->chooseQuest($kid, $plain->id);

        // Not rolled until the quest is handed in.
        $this->assertNull($this->service()->questFor($kid)->charm_payout_percent);

        $this->service()->claimQuest($kid);

        $settled = $this->service()->questFor($kid)->charm_payout_percent;

        $this->assertNotNull($settled);
        $this->assertContains($settled, [0, ChoreService::CHARM_PAYOUT_PERCENT]);

        // Asking again must not re-roll it — the number a kid was shown has to
        // be the number that reached the ledger.
        $this->service()->questFor($kid);
        $this->assertSame($settled, $this->service()->questFor($kid)->charm_payout_percent);
    }

    public function test_a_charm_payout_is_paid_on_top_of_the_cards_own_bonus(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);
        $this->charmWith($kid, QuestCharmEffect::Unchanged);

        $bold = $this->service()->offeredChoresFor($kid)->last();
        $this->service()->chooseQuest($kid, $bold->id);
        $this->service()->claimQuest($kid);

        $paid = ChoreCompletion::where('profile_id', $kid->id)->latest('id')->first()->points_awarded;
        $payout = $this->service()->questFor($kid)->charm_payout_percent;

        // 1000 base + 500 bold, plus 250 if the charm's second roll landed.
        $this->assertSame($payout > 0 ? 1750 : 1500, $paid);
    }

    public function test_an_uncharmed_quest_never_pays_a_charm_bonus(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);

        $plain = $this->service()->offeredChoresFor($kid)->first();
        $this->service()->chooseQuest($kid, $plain->id);
        $this->service()->claimQuest($kid);

        $this->assertNull($this->service()->questFor($kid)->charm_payout_percent);
        $this->assertSame(0, $this->service()->charmPayoutFor($kid));
        $this->assertSame(10, ChoreCompletion::where('profile_id', $kid->id)->latest('id')->first()->points_awarded);
    }

    public function test_a_charm_survives_a_re_deal_but_its_effect_is_not_rolled_again(): void
    {
        [, $kid] = $this->householdWithChores([10, 50, 100, 500, 1000, 2000]);
        $this->charmWith($kid, QuestCharmEffect::AllCards);
        $this->service()->dealQuestHand($kid);

        $this->service()->rerollQuest($kid);

        $quest = $this->service()->questFor($kid);

        // Paid for, so it carries over — but a kid holding rerolls must not be
        // able to spin the charm until it comes up "every card bold".
        $this->assertTrue($quest->isCharmed());
        $this->assertSame(QuestCharmEffect::AllCards, $quest->charm_effect);
        $this->assertNull($quest->dealt_at);
    }

    public function test_the_quests_page_offers_to_sell_a_charm_when_the_kid_holds_none(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);
        $kid->update(['bonus_tickets' => 10]);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->assertSee('Buy a Quest Charm')
            ->assertDontSee('Use Quest Charm');
    }

    public function test_holding_a_charm_swaps_the_buy_button_for_the_use_button(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);
        $kid->update(['bonus_tickets' => 10]);
        app(PerkInventoryService::class)->grant($kid, PerkEffect::QuestCharm, OwnedPerk::SOURCE_SHOP);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->assertSee('Use Quest Charm')
            ->assertDontSee('Buy a Quest Charm');
    }

    public function test_buying_a_charm_from_the_quests_page_spends_tickets_and_stocks_the_pocket(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);
        $cost = BonusPerk::where('household_id', $kid->household_id)
            ->where('effect', PerkEffect::QuestCharm)
            ->value('cost');

        $kid->update(['bonus_tickets' => $cost + 2]);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')->call('buyQuestCharm');

        $this->assertSame(1, app(PerkInventoryService::class)->countOf($kid, PerkEffect::QuestCharm));
        $this->assertSame(2, $kid->refresh()->bonus_tickets);
    }

    public function test_a_kid_short_on_tickets_is_told_how_many_they_need(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);
        $cost = BonusPerk::where('household_id', $kid->household_id)
            ->where('effect', PerkEffect::QuestCharm)
            ->value('cost');

        $kid->update(['bonus_tickets' => $cost - 1]);

        Auth::guard('profile')->login($kid);

        // A disabled button with no reason on it is the thing this avoids.
        Volt::test('kid.quests')
            ->assertSee('Buy a Quest Charm')
            ->assertSee('1 more');
    }

    public function test_the_buy_button_goes_once_the_chest_is_open(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);
        $kid->update(['bonus_tickets' => 10]);

        Auth::guard('profile')->login($kid);

        $this->service()->dealQuestHand($kid);

        // Nothing to charm any more, so offering to sell one here would be
        // selling a kid something they can't use on the thing in front of them.
        Volt::test('kid.quests')->assertDontSee('Buy a Quest Charm');
    }

    public function test_a_charm_a_parent_switched_off_is_never_offered(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);
        $kid->update(['bonus_tickets' => 10]);

        BonusPerk::where('household_id', $kid->household_id)
            ->where('effect', PerkEffect::QuestCharm)
            ->update(['enabled' => false]);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->assertDontSee('Buy a Quest Charm')
            // And the action itself refuses, for the stale tab that still has
            // the button on it.
            ->call('buyQuestCharm');

        $this->assertSame(0, app(PerkInventoryService::class)->countOf($kid, PerkEffect::QuestCharm));
        $this->assertSame(10, $kid->refresh()->bonus_tickets);
    }

    public function test_holding_an_unused_charm_stops_the_chest_to_ask_about_it(): void
    {
        // Buying a charm changes one button's label and nothing else, which is
        // very easy to read as "bought it, so it's on". This is the stop that
        // catches that before the chest opens and the chance is gone.
        [, $kid] = $this->householdWithChores([10, 100, 1000]);
        app(PerkInventoryService::class)->grant($kid, PerkEffect::QuestCharm, OwnedPerk::SOURCE_SHOP);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->assertSee('You have a charm')
            ->assertSee('Charm it first')
            ->assertSee('Open without it');
    }

    public function test_a_kid_with_no_charm_opens_the_chest_without_being_asked(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->assertDontSee('Charm it first')
            ->assertDontSee('Open without it');
    }

    public function test_an_already_charmed_chest_is_not_asked_about_again(): void
    {
        [, $kid] = $this->householdWithChores([10, 100, 1000]);
        app(PerkInventoryService::class)->grant($kid, PerkEffect::QuestCharm, OwnedPerk::SOURCE_SHOP);
        $this->service()->charmQuest($kid);

        Auth::guard('profile')->login($kid);

        // They hold a second charm, but this quest already has one on it and a
        // second is refused — so there is nothing to stop them for.
        Volt::test('kid.quests')->assertDontSee('Charm it first');
    }

    public function test_a_charm_cast_after_the_chest_opened_is_refused_and_kept(): void
    {
        // The window the <x-chest> `chest-opening` event closes client-side.
        // The server has to hold the same line for the tap that got through it,
        // or whether the charm lands depends on where in a 2.6s animation the
        // kid pressed the button.
        [, $kid] = $this->householdWithChores([10, 100, 1000]);
        app(PerkInventoryService::class)->grant($kid, PerkEffect::QuestCharm, OwnedPerk::SOURCE_SHOP);

        Auth::guard('profile')->login($kid);

        $component = Volt::test('kid.quests')->call('dealHand');
        $component->call('usePerk', PerkEffect::QuestCharm->value)
            ->assertSee('Charm the chest before you open it');

        $this->assertFalse($this->service()->questFor($kid)->isCharmed());
        $this->assertSame(1, app(PerkInventoryService::class)->countOf($kid, PerkEffect::QuestCharm));
    }

    public function test_every_roll_outcome_is_reachable_and_weighted(): void
    {
        $seen = [];

        for ($i = 0; $i < 400; $i++) {
            $seen[QuestCharmEffect::roll()->value] = ($seen[QuestCharmEffect::roll()->value] ?? 0) + 1;
        }

        foreach (QuestCharmEffect::cases() as $case) {
            $this->assertArrayHasKey($case->value, $seen, "{$case->value} never came up in 400 rolls.");
        }

        // The cheap, generous-looking outcomes are the common ones on purpose —
        // see the enum docblock for why that is the right way round.
        $this->assertGreaterThan(
            QuestCharmEffect::Unchanged->weight(),
            QuestCharmEffect::SecondCard->weight(),
        );
    }
}
