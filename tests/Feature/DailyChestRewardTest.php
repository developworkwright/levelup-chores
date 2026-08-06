<?php

namespace Tests\Feature;

use App\Enums\TicketKind;
use App\Models\BonusPerk;
use App\Models\BonusTicketEntry;
use App\Models\Chore;
use App\Models\DailyChest;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * What the daily chest pays, and when it lands. The prize is drawn with
 * random_int, which can't be seeded — so where a specific outcome is needed
 * these open a chest a day until it turns up.
 */
class DailyChestRewardTest extends TestCase
{
    /** Safety bound on the roll-until loop; tickets are a ~60% outcome. */
    private const MAX_DAYS = 40;

    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    private int $ticketsBeforeWin = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->kid = Profile::factory()->for($this->household)->create(['bonus_tickets' => 0]);
        Chore::factory()->for($this->household)->create();

        $this->travelTo(Carbon::parse('2026-03-01 12:00', $this->household->timezone));

        // With no perk to hand out, the chest's perk outcome falls back to
        // tickets — so every roll is either tickets, points or XP, and the
        // loop below only has to skip two of the three.
        BonusPerk::where('household_id', $this->household->id)->update(['enabled' => false]);
    }

    public function test_a_ticket_chest_credits_the_ticket_balance(): void
    {
        $chest = $this->openUntilTickets();

        $this->assertSame($this->ticketsBeforeWin + $chest->reward_amount, $this->kid->refresh()->bonus_tickets);
    }

    public function test_a_ticket_chest_writes_a_matching_ledger_entry(): void
    {
        // profiles.bonus_tickets is a cache over bonus_ticket_entries, so a
        // credit that skipped the entry would drift the two apart.
        $chest = $this->openUntilTickets();

        $entry = BonusTicketEntry::where('profile_id', $this->kid->id)
            ->where('description', 'Daily chest')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(TicketKind::Adjustment, $entry->kind);
        $this->assertSame($chest->reward_amount, $entry->amount);
    }

    public function test_the_reward_is_banked_by_the_time_the_prize_is_revealed(): void
    {
        // The reveal is an animation over a reward the server already paid, so
        // the header re-renders with the new number in the same round trip.
        // Worth pinning: "the count didn't move when I opened it" reads as a
        // bug and is really the balance having moved a beat early.
        Auth::guard('profile')->login($this->kid);

        $page = Volt::test('kid.quests')->call('openDailyChest');

        $chest = DailyChest::where('profile_id', $this->kid->id)->firstOrFail();

        $this->assertNotNull($page->get('dailyChestPrize'));
        $this->assertSame(app(ChestService::class)->describe($chest), $page->get('dailyChestPrize'));

        // Whatever it rolled, the page is showing the balance the open left
        // behind rather than the one it started the request with.
        $page->assertSee((string) $this->kid->refresh()->bonus_tickets);
    }

    public function test_only_one_chest_a_day_pays_out(): void
    {
        app(ChestService::class)->open($this->kid);
        $banked = $this->kid->refresh()->bonus_tickets;

        $this->assertNull(app(ChestService::class)->open($this->kid));
        $this->assertSame($banked, $this->kid->refresh()->bonus_tickets);
    }

    /**
     * Opens one chest a day until the roll lands on tickets, recording the
     * balance the winning open started from. Read rather than assumed to be
     * zero, because a week of opening chests unlocks a badge, and a badge
     * mints a ticket of its own.
     */
    private function openUntilTickets(): DailyChest
    {
        foreach (range(1, self::MAX_DAYS) as $ignored) {
            $this->ticketsBeforeWin = $this->kid->refresh()->bonus_tickets;
            $chest = app(ChestService::class)->open($this->kid);

            if ($chest?->reward_kind === ChestService::KIND_TICKETS) {
                return $chest;
            }

            $this->travel(1)->days();
        }

        $this->fail('The chest never rolled tickets in '.self::MAX_DAYS.' days.');
    }
}
