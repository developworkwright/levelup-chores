<?php

namespace Tests\Feature;

use App\Enums\Rank;
use App\Enums\TicketKind;
use App\Exceptions\LevelTooLowException;
use App\Models\BonusTicketEntry;
use App\Models\Household;
use App\Models\Profile;
use App\Models\StoreItem;
use App\Services\StoreService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LevelRankTest extends TestCase
{
    use RefreshDatabase;

    private function kid(array $attributes = []): Profile
    {
        return Profile::factory()->for(Household::factory()->create())->create($attributes);
    }

    public function test_the_first_band_still_costs_two_hundred_a_level(): void
    {
        $this->assertSame(1, Profile::levelForXp(0));
        $this->assertSame(2, Profile::levelForXp(200));
        $this->assertSame(10, Profile::levelForXp(1800));
        $this->assertSame(1800, Profile::xpToReachLevel(10));
    }

    public function test_levels_get_dearer_at_eleven_and_twenty_one(): void
    {
        $this->assertSame(200, Profile::xpToClearLevel(10));
        $this->assertSame(350, Profile::xpToClearLevel(11));
        $this->assertSame(350, Profile::xpToClearLevel(20));
        $this->assertSame(500, Profile::xpToClearLevel(21));

        // 2000 reaches 11, then five levels at 350 to reach 16.
        $this->assertSame(2000, Profile::xpToReachLevel(11));
        $this->assertSame(3750, Profile::xpToReachLevel(16));
        $this->assertSame(5500, Profile::xpToReachLevel(21));
    }

    public function test_a_level_boundary_is_the_exact_xp_that_reaches_it(): void
    {
        foreach ([2, 10, 11, 20, 21, 26] as $level) {
            $reach = Profile::xpToReachLevel($level);

            $this->assertSame($level, Profile::levelForXp($reach));
            $this->assertSame($level - 1, Profile::levelForXp($reach - 1));
        }
    }

    public function test_the_bar_measures_progress_through_the_current_band(): void
    {
        // Halfway through level 11, which costs 350.
        $kid = $this->kid(['xp' => Profile::xpToReachLevel(11) + 175]);

        $this->assertSame(11, $kid->level());
        $this->assertSame(50.0, $kid->xpBarPercent());
    }

    public function test_the_bar_is_empty_at_the_start_of_a_level(): void
    {
        $kid = $this->kid(['xp' => Profile::xpToReachLevel(21)]);

        $this->assertSame(21, $kid->level());
        $this->assertSame(0.0, $kid->xpBarPercent());
    }

    public function test_rank_is_read_off_the_level(): void
    {
        $this->assertSame(Rank::Prowler, Rank::fromLevel(1));
        $this->assertSame(Rank::Prowler, Rank::fromLevel(4));
        $this->assertSame(Rank::Nightblade, Rank::fromLevel(5));
        $this->assertSame(Rank::Bonebreaker, Rank::fromLevel(10));
        $this->assertSame(Rank::Voidreaver, Rank::fromLevel(29));
        $this->assertSame(Rank::Hellfang, Rank::fromLevel(30));
        $this->assertSame(Rank::Soulreaper, Rank::fromLevel(40));
        $this->assertSame(Rank::Deathless, Rank::fromLevel(94));
        $this->assertSame(Rank::Doomlord, Rank::fromLevel(95));
    }

    public function test_the_ladder_runs_past_the_kid_who_is_furthest_along(): void
    {
        // The whole reason the ladder was extended: a kid already past 40 has
        // to have somewhere left to climb.
        $this->assertNotSame(Rank::Doomlord, Rank::fromLevel(40));
        $this->assertGreaterThan(0, Rank::countBetween(40, 95));
    }

    public function test_every_fifth_level_changes_the_title(): void
    {
        for ($level = 1; $level < 95; $level++) {
            $changed = Rank::fromLevel($level) !== Rank::fromLevel($level + 1);

            $this->assertSame(
                ($level + 1) % Rank::LEVELS_PER_RANK === 0,
                $changed,
                "Level {$level} to ".($level + 1).' got the rank change wrong.',
            );
        }
    }

    public function test_the_cases_are_declared_in_climbing_order(): void
    {
        // next() walks cases() rather than sorting them, so a rank inserted in
        // the wrong place would quietly break every "next rank at" label.
        $levels = array_map(fn (Rank $rank) => $rank->minLevel(), Rank::cases());
        $sorted = $levels;
        sort($sorted);

        $this->assertSame($sorted, $levels);
        $this->assertSame($levels, array_unique($levels));
    }

    public function test_the_top_rank_holds_past_its_own_boundary(): void
    {
        $this->assertSame(Rank::Doomlord, Rank::fromLevel(150));
        $this->assertNull(Rank::Doomlord->next());
        $this->assertNull(Rank::Doomlord->nextLevel());
        $this->assertSame(10, Rank::Nightblade->nextLevel());
        $this->assertSame(95, Rank::Deathless->nextLevel());
    }

    public function test_crossing_a_rank_pays_on_top_of_the_level(): void
    {
        $kid = $this->kid([
            'xp' => Profile::xpToReachLevel(5),
            'bonus_tickets' => 0,
            'tickets_granted_through_level' => 4,
        ]);

        app(TicketService::class)->syncLevelTickets($kid);

        $this->assertSame(
            TicketService::PER_LEVEL + TicketService::PER_RANK,
            $kid->refresh()->bonus_tickets,
        );

        $this->assertDatabaseHas('bonus_ticket_entries', [
            'profile_id' => $kid->id,
            'kind' => TicketKind::RankUp->value,
            'amount' => TicketService::PER_RANK,
            'description' => 'Became a Nightblade',
        ]);
    }

    public function test_an_ordinary_level_pays_no_rank_bonus(): void
    {
        $kid = $this->kid([
            'xp' => Profile::xpToReachLevel(4),
            'bonus_tickets' => 0,
            'tickets_granted_through_level' => 3,
        ]);

        app(TicketService::class)->syncLevelTickets($kid);

        $this->assertSame(TicketService::PER_LEVEL, $kid->refresh()->bonus_tickets);
        $this->assertDatabaseMissing('bonus_ticket_entries', [
            'profile_id' => $kid->id,
            'kind' => TicketKind::RankUp->value,
        ]);
    }

    public function test_a_jump_across_two_ranks_pays_for_both(): void
    {
        $kid = $this->kid([
            'xp' => Profile::xpToReachLevel(12),
            'bonus_tickets' => 0,
            'tickets_granted_through_level' => 4,
        ]);

        app(TicketService::class)->syncLevelTickets($kid);

        // Nightblade at 5 and Bonebreaker at 10.
        $this->assertSame(2 * TicketService::PER_RANK, (int) BonusTicketEntry::where('profile_id', $kid->id)
            ->where('kind', TicketKind::RankUp)
            ->sum('amount'));
    }

    public function test_the_rank_bonus_is_not_paid_twice(): void
    {
        $kid = $this->kid([
            'xp' => Profile::xpToReachLevel(5),
            'bonus_tickets' => 0,
            'tickets_granted_through_level' => 4,
        ]);

        app(TicketService::class)->syncLevelTickets($kid);
        app(TicketService::class)->syncLevelTickets($kid->refresh());

        $this->assertSame(1, BonusTicketEntry::where('profile_id', $kid->id)
            ->where('kind', TicketKind::RankUp)
            ->count());
    }

    public function test_xp_falling_back_below_a_rank_does_not_re_pay_it(): void
    {
        $kid = $this->kid([
            'xp' => Profile::xpToReachLevel(5),
            'bonus_tickets' => 0,
            'tickets_granted_through_level' => 4,
        ]);

        app(TicketService::class)->syncLevelTickets($kid);

        // A clawback drops the kid back under the boundary, then they climb it
        // again — the high-water mark has to swallow the second crossing.
        $kid->update(['xp' => Profile::xpToReachLevel(4)]);
        app(TicketService::class)->syncLevelTickets($kid);
        $kid->update(['xp' => Profile::xpToReachLevel(5)]);
        app(TicketService::class)->syncLevelTickets($kid);

        $this->assertSame(1, BonusTicketEntry::where('profile_id', $kid->id)
            ->where('kind', TicketKind::RankUp)
            ->count());
    }

    public function test_a_reward_below_the_gate_cannot_be_redeemed(): void
    {
        $kid = $this->kid(['xp' => 0, 'points' => 5000]);
        $item = StoreItem::factory()
            ->for($kid->household)
            ->lockedUntilLevel(20)
            ->create(['cost' => 100]);

        try {
            app(StoreService::class)->redeem($kid, $item);
            $this->fail('A locked reward was redeemed.');
        } catch (LevelTooLowException $e) {
            $this->assertSame(20, $e->requiredLevel);
        }

        $this->assertSame(5000, $kid->refresh()->points);
        $this->assertDatabaseCount('redemptions', 0);
    }

    public function test_the_gate_refuses_before_it_mentions_points(): void
    {
        // Short of both. The level is the one the kid is told about, because
        // saving up for something they still can't have is the worse advice.
        $kid = $this->kid(['xp' => 0, 'points' => 10]);
        $item = StoreItem::factory()
            ->for($kid->household)
            ->lockedUntilLevel(15)
            ->create(['cost' => 100]);

        $this->expectException(LevelTooLowException::class);

        app(StoreService::class)->redeem($kid, $item);
    }

    public function test_reaching_the_gate_opens_the_reward(): void
    {
        $kid = $this->kid([
            'xp' => Profile::xpToReachLevel(20),
            'points' => 5000,
        ]);
        $item = StoreItem::factory()
            ->for($kid->household)
            ->lockedUntilLevel(20)
            ->create(['cost' => 100]);

        $this->assertFalse($item->isLockedFor($kid));

        app(StoreService::class)->redeem($kid, $item);

        $this->assertSame(4900, $kid->refresh()->points);
        $this->assertDatabaseCount('redemptions', 1);
    }

    public function test_an_ungated_reward_is_open_at_level_one(): void
    {
        $kid = $this->kid(['xp' => 0, 'points' => 200]);
        $item = StoreItem::factory()->for($kid->household)->create(['cost' => 100]);

        $this->assertFalse($item->isLockedFor($kid));

        app(StoreService::class)->redeem($kid, $item);

        $this->assertDatabaseCount('redemptions', 1);
    }
}
