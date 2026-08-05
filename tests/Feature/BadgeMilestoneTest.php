<?php

namespace Tests\Feature;

use App\Enums\CompletionStatus;
use App\Enums\LedgerKind;
use App\Enums\PerkEffect;
use App\Enums\RedemptionStatus;
use App\Enums\SiblingOfferStatus;
use App\Enums\TradeAsset;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\DailyChest;
use App\Models\DailyQuest;
use App\Models\Household;
use App\Models\LedgerEntry;
use App\Models\OwnedPerk;
use App\Models\Profile;
use App\Models\Redemption;
use App\Models\SiblingOffer;
use App\Models\Spin;
use App\Models\StoreItem;
use App\Models\StreakRepair;
use App\Services\BadgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The second wave of achievements — lifetime volume, points, levels, and the
 * corners of the app the original set never reached.
 */
class BadgeMilestoneTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->kid = Profile::factory()->for($this->household)->create();
    }

    private function evaluate(): void
    {
        app(BadgeService::class)->evaluate($this->kid);
    }

    private function assertEarned(string $key): void
    {
        $this->assertTrue(
            $this->kid->badges()->where('key', $key)->exists(),
            "Expected the {$key} badge to be earned."
        );
    }

    private function assertNotEarned(string $key): void
    {
        $this->assertFalse(
            $this->kid->badges()->where('key', $key)->exists(),
            "Did not expect the {$key} badge to be earned."
        );
    }

    private function approve(int $times, ?Carbon $submittedAt = null, ?Chore $chore = null): void
    {
        $chore ??= Chore::factory()->for($this->household)->create();
        $submittedAt ??= now();

        foreach (range(1, $times) as $ignored) {
            ChoreCompletion::create([
                'chore_id' => $chore->id,
                'profile_id' => $this->kid->id,
                'status' => CompletionStatus::Approved,
                'points_awarded' => 10,
                'submitted_at' => $submittedAt,
                'decided_at' => $submittedAt,
            ]);
        }
    }

    public function test_chore_volume_badges_unlock_one_tier_at_a_time(): void
    {
        $this->approve(10);
        $this->evaluate();

        $this->assertEarned('chores_10');
        $this->assertNotEarned('chores_50');

        $this->approve(40);
        $this->evaluate();

        $this->assertEarned('chores_50');
        $this->assertNotEarned('chores_100');
    }

    public function test_quest_volume_badges_count_only_cleared_quests(): void
    {
        $chore = Chore::factory()->for($this->household)->create();

        foreach (range(1, 12) as $daysAgo) {
            DailyQuest::create([
                'household_id' => $this->household->id,
                'profile_id' => $this->kid->id,
                'chore_id' => $chore->id,
                'quest_date' => now()->subDays($daysAgo)->toDateString(),
                // Two of the twelve were handed out and never cleared.
                'completed_at' => $daysAgo <= 10 ? now()->subDays($daysAgo) : null,
            ]);
        }

        $this->evaluate();

        $this->assertEarned('quest_10');
        $this->assertNotEarned('quest_50');
    }

    public function test_earning_badges_read_the_earn_ledger_only(): void
    {
        // A parent top-up moves the balance without a day's work behind it, so
        // it must not buy a badge that's meant to say "I worked for this".
        LedgerEntry::create([
            'household_id' => $this->household->id,
            'profile_id' => $this->kid->id,
            'kind' => LedgerKind::Adjustment,
            'amount' => 2000,
            'description' => 'Parent top-up',
        ]);

        $this->evaluate();
        $this->assertNotEarned('earner_1000');

        LedgerEntry::create([
            'household_id' => $this->household->id,
            'profile_id' => $this->kid->id,
            'kind' => LedgerKind::Earn,
            'amount' => 1000,
            'description' => 'Chores',
        ]);

        $this->evaluate();
        $this->assertEarned('earner_1000');
        $this->assertNotEarned('earner_5000');
    }

    public function test_level_badges_track_the_xp_curve(): void
    {
        $this->kid->update(['xp' => Profile::XP_PER_LEVEL * 9]);

        $this->assertSame(10, $this->kid->level());

        $this->evaluate();

        $this->assertEarned('level_10');
        $this->assertNotEarned('level_25');
    }

    public function test_a_thirty_day_streak_earns_unstoppable(): void
    {
        $this->kid->update(['streak' => 30]);

        $this->evaluate();

        $this->assertEarned('streak_30');
    }

    public function test_overachiever_needs_eight_chores_on_one_day(): void
    {
        $this->approve(7, now()->subDay());
        $this->approve(7, now());

        $this->evaluate();
        $this->assertNotEarned('overachiever');

        $this->approve(1, now());

        $this->evaluate();
        $this->assertEarned('overachiever');
    }

    public function test_weekend_warrior_needs_both_halves_of_the_same_weekend(): void
    {
        // A Saturday and the Sunday of the *following* weekend is not a weekend
        // worked — the badge is about seeing one through.
        $this->approve(1, Carbon::parse('2026-03-07 12:00', $this->household->timezone));
        $this->approve(1, Carbon::parse('2026-03-15 12:00', $this->household->timezone));

        $this->evaluate();
        $this->assertNotEarned('weekend_warrior');

        $this->approve(1, Carbon::parse('2026-03-08 12:00', $this->household->timezone));

        $this->evaluate();
        $this->assertEarned('weekend_warrior');
    }

    public function test_all_rounder_needs_every_chore_on_the_board(): void
    {
        $chores = Chore::factory()->for($this->household)->count(3)->create();

        foreach ($chores->take(2) as $chore) {
            $this->approve(1, now(), $chore);
        }

        $this->evaluate();
        $this->assertNotEarned('all_rounder');

        $this->approve(1, now(), $chores->last());

        $this->evaluate();
        $this->assertEarned('all_rounder');
    }

    public function test_all_rounder_is_not_winnable_on_a_bare_board(): void
    {
        $chore = Chore::factory()->for($this->household)->create();
        $this->approve(1, now(), $chore);

        $this->evaluate();

        $this->assertNotEarned('all_rounder');
    }

    public function test_wheel_badges_count_spins_and_triples(): void
    {
        $chore = Chore::factory()->for($this->household)->create();

        foreach (range(1, 2) as $day) {
            Spin::create([
                'profile_id' => $this->kid->id,
                'spin_date' => now()->subDays($day)->toDateString(),
                'chore_id' => $chore->id,
                'multiplier' => 3,
            ]);
        }

        $this->evaluate();
        $this->assertEarned('wheel_winner');
        $this->assertNotEarned('triple_threat');
        $this->assertNotEarned('spin_25');

        Spin::create([
            'profile_id' => $this->kid->id,
            'spin_date' => now()->toDateString(),
            'chore_id' => $chore->id,
            'multiplier' => 3,
        ]);

        $this->evaluate();
        $this->assertEarned('triple_threat');
    }

    public function test_chest_badges_count_daily_chests(): void
    {
        foreach (range(1, 7) as $day) {
            DailyChest::create([
                'profile_id' => $this->kid->id,
                'chest_date' => now()->subDays($day)->toDateString(),
                'reward_kind' => 'tickets',
                'reward_amount' => 1,
                'quest_was_done' => false,
            ]);
        }

        $this->evaluate();

        $this->assertEarned('chest_7');
        $this->assertNotEarned('chest_30');
    }

    public function test_loot_shop_badges_split_a_first_reward_from_a_big_one(): void
    {
        $item = StoreItem::factory()->for($this->household)->create(['cost' => 100]);

        Redemption::create([
            'store_item_id' => $item->id,
            'profile_id' => $this->kid->id,
            'cost_snapshot' => 100,
            'status' => RedemptionStatus::Pending,
            'requested_at' => now(),
        ]);

        $this->evaluate();
        $this->assertEarned('first_reward');
        $this->assertNotEarned('big_ticket');

        Redemption::create([
            'store_item_id' => $item->id,
            'profile_id' => $this->kid->id,
            'cost_snapshot' => 500,
            'status' => RedemptionStatus::Pending,
            'requested_at' => now(),
        ]);

        $this->evaluate();
        $this->assertEarned('big_ticket');
    }

    public function test_trade_badges_count_settled_deals_from_either_side(): void
    {
        $sibling = Profile::factory()->for($this->household)->create();

        // An offer this kid received and accepted is as much their trade as one
        // they sent, and an offer left hanging isn't a trade at all.
        SiblingOffer::create([
            'household_id' => $this->household->id,
            'from_profile_id' => $sibling->id,
            'to_profile_id' => $this->kid->id,
            'give_asset' => TradeAsset::Points,
            'give_amount' => 50,
            'get_asset' => TradeAsset::Tickets,
            'get_amount' => 2,
            'status' => SiblingOfferStatus::Pending,
            'expires_at' => now()->addDay(),
        ]);

        $this->evaluate();
        $this->assertNotEarned('dealmaker');

        SiblingOffer::create([
            'household_id' => $this->household->id,
            'from_profile_id' => $sibling->id,
            'to_profile_id' => $this->kid->id,
            'give_asset' => TradeAsset::Points,
            'give_amount' => 50,
            'get_asset' => TradeAsset::Tickets,
            'get_amount' => 2,
            'status' => SiblingOfferStatus::Accepted,
            'expires_at' => now()->addDay(),
            'responded_at' => now(),
        ]);

        $this->evaluate();
        $this->assertEarned('dealmaker');
        $this->assertNotEarned('trade_10');
    }

    public function test_gadgeteer_counts_only_perks_actually_spent(): void
    {
        foreach (range(1, 5) as $index) {
            OwnedPerk::create([
                'profile_id' => $this->kid->id,
                'effect' => PerkEffect::WheelRespin,
                'source' => OwnedPerk::SOURCE_SHOP,
                'acquired_at' => now(),
                'consumed_at' => $index <= 4 ? now() : null,
            ]);
        }

        $this->evaluate();
        $this->assertNotEarned('gadgeteer');

        OwnedPerk::where('profile_id', $this->kid->id)->update(['consumed_at' => now()]);

        $this->evaluate();
        $this->assertEarned('gadgeteer');
    }

    public function test_comeback_kid_needs_a_repaired_streak(): void
    {
        $this->evaluate();
        $this->assertNotEarned('comeback_kid');

        StreakRepair::create(['profile_id' => $this->kid->id, 'repaired_date' => now()->subDay()->toDateString()]);

        $this->evaluate();
        $this->assertEarned('comeback_kid');
    }

    public function test_a_milestone_badge_pays_out_once_however_often_it_is_checked(): void
    {
        $this->approve(10);

        $service = app(BadgeService::class);
        $service->evaluate($this->kid);
        $xp = $this->kid->refresh()->xp;

        $service->evaluate($this->kid);
        $service->evaluate($this->kid);

        $this->assertSame($xp, $this->kid->refresh()->xp);
        $this->assertSame(1, $this->kid->badges()->where('key', 'chores_10')->count());
    }

    public function test_another_kids_activity_never_unlocks_this_kids_badges(): void
    {
        $sibling = Profile::factory()->for($this->household)->create();
        $chore = Chore::factory()->for($this->household)->create();

        foreach (range(1, 12) as $ignored) {
            ChoreCompletion::create([
                'chore_id' => $chore->id,
                'profile_id' => $sibling->id,
                'status' => CompletionStatus::Approved,
                'points_awarded' => 10,
                'submitted_at' => now(),
                'decided_at' => now(),
            ]);
        }

        $this->evaluate();

        $this->assertNotEarned('chores_10');
    }
}
