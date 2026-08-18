<?php

namespace Tests\Feature;

use App\Enums\TicketKind;
use App\Exceptions\InsufficientTicketsException;
use App\Models\Badge;
use App\Models\BonusTicketEntry;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\BadgeService;
use App\Services\ChoreService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BonusTicketTest extends TestCase
{
    use RefreshDatabase;

    private function tickets(): TicketService
    {
        return app(TicketService::class);
    }

    public function test_crossing_a_level_mints_a_ticket(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create([
            'xp' => Profile::XP_PER_LEVEL,
            'bonus_tickets' => 0,
            'tickets_granted_through_level' => 1,
        ]);

        $this->tickets()->syncLevelTickets($kid);

        $this->assertSame(TicketService::PER_LEVEL, $kid->refresh()->bonus_tickets);
        $this->assertSame(2, $kid->tickets_granted_through_level);
    }

    public function test_jumping_several_levels_pays_for_each(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create([
            'xp' => Profile::XP_PER_LEVEL * 4,
            'bonus_tickets' => 0,
            'tickets_granted_through_level' => 1,
        ]);

        $this->tickets()->syncLevelTickets($kid);

        // Four levels, and the fourth of them lands on 5 — the first rank
        // boundary — which pays its own bonus on top. See LevelRankTest.
        $this->assertSame(
            4 * TicketService::PER_LEVEL + TicketService::PER_RANK,
            $kid->refresh()->bonus_tickets,
        );
    }

    public function test_syncing_repeatedly_does_not_re_mint(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create([
            'xp' => Profile::XP_PER_LEVEL * 2,
            'bonus_tickets' => 0,
            'tickets_granted_through_level' => 1,
        ]);

        $service = $this->tickets();
        $service->syncLevelTickets($kid);
        $after = $kid->refresh()->bonus_tickets;

        $service->syncLevelTickets($kid);
        $service->syncLevelTickets($kid);

        $this->assertSame($after, $kid->refresh()->bonus_tickets);
    }

    public function test_losing_xp_and_regaining_it_never_re_mints(): void
    {
        // quest:reset-today claws back 25 XP per undone approval, so a kid can
        // genuinely drop below a threshold. The high-water mark is what stops
        // them farming the same level twice.
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create([
            'xp' => Profile::XP_PER_LEVEL,
            'bonus_tickets' => 0,
            'tickets_granted_through_level' => 1,
        ]);

        $service = $this->tickets();
        $service->syncLevelTickets($kid);
        $earned = $kid->refresh()->bonus_tickets;

        $kid->xp = Profile::XP_PER_LEVEL - 50;
        $kid->save();
        $service->syncLevelTickets($kid);

        $kid->xp = Profile::XP_PER_LEVEL;
        $kid->save();
        $service->syncLevelTickets($kid);

        $this->assertSame($earned, $kid->refresh()->bonus_tickets);
    }

    public function test_earning_a_badge_mints_a_ticket(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create([
            'xp' => 0,
            'streak' => 3,
            'bonus_tickets' => 0,
            'tickets_granted_through_level' => 1,
        ]);
        Chore::factory()->for($household)->create();

        app(BadgeService::class)->evaluate($kid);

        $this->assertGreaterThanOrEqual(TicketService::PER_BADGE, $kid->refresh()->bonus_tickets);
        $this->assertSame(1, BonusTicketEntry::where('profile_id', $kid->id)
            ->where('kind', TicketKind::Badge)->count());
    }

    public function test_a_badge_mints_its_ticket_only_once(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create([
            'xp' => 0,
            'streak' => 3,
            'bonus_tickets' => 0,
            'tickets_granted_through_level' => 1,
        ]);
        Chore::factory()->for($household)->create();

        $service = app(BadgeService::class);
        $service->evaluate($kid);
        $after = $kid->refresh()->bonus_tickets;

        $service->evaluate($kid);
        $service->evaluate($kid);

        $this->assertSame($after, $kid->refresh()->bonus_tickets);
    }

    public function test_the_balance_always_matches_the_entries(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create([
            'bonus_tickets' => 0,
            'tickets_granted_through_level' => 1,
        ]);
        $chore = Chore::factory()->for($household)->create(['min_age' => 1]);

        $chores = app(ChoreService::class);

        for ($i = 0; $i < 12; $i++) {
            $completion = $chores->claim($kid, $chore);
            $chores->approve($completion, $parent);
        }

        $kid->refresh();

        // Same invariant the points ledger holds: the cached column can never
        // drift from the sum of its entries.
        $this->assertSame(
            (int) BonusTicketEntry::where('profile_id', $kid->id)->sum('amount'),
            $kid->bonus_tickets,
        );
        $this->assertGreaterThan(0, $kid->bonus_tickets);
    }

    public function test_spending_deducts_and_records_a_purchase(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 10]);

        $this->tickets()->spend($kid, 3, 'Wheel respin');

        $this->assertSame(7, $kid->refresh()->bonus_tickets);
        $this->assertSame(-3, (int) BonusTicketEntry::where('profile_id', $kid->id)
            ->where('kind', TicketKind::Purchase)->value('amount'));
    }

    public function test_spending_more_than_the_balance_is_refused(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 2]);

        $this->expectException(InsufficientTicketsException::class);

        try {
            $this->tickets()->spend($kid, 5, 'Mystery hint');
        } finally {
            $this->assertSame(2, $kid->refresh()->bonus_tickets);
            $this->assertSame(0, BonusTicketEntry::where('profile_id', $kid->id)->count());
        }
    }

    public function test_spending_never_touches_xp_or_level(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create([
            'xp' => Profile::XP_PER_LEVEL * 3,
            'bonus_tickets' => 10,
        ]);

        $levelBefore = $kid->level();

        $this->tickets()->spend($kid, 6, 'Mystery hint');
        $kid->refresh();

        // The whole point of the design: tickets are minted by XP, never from
        // it, so progress is permanent no matter how much gets spent.
        $this->assertSame(Profile::XP_PER_LEVEL * 3, $kid->xp);
        $this->assertSame($levelBefore, $kid->level());
    }

    public function test_a_badge_ticket_links_back_to_the_badge(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 0]);
        $badge = Badge::where('key', 'wheel_winner')->firstOrFail();

        $this->tickets()->awardForBadge($kid, $badge);

        $entry = BonusTicketEntry::where('profile_id', $kid->id)->firstOrFail();
        $this->assertTrue($entry->related->is($badge));
    }
}
