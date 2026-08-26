<?php

namespace Tests\Feature;

use App\Enums\TicketKind;
use App\Models\BonusTicketEntry;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Monster;
use App\Models\Profile;
use App\Services\MonsterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * What a household gets for putting a monster down, beyond the reward itself.
 *
 * Three payouts that stack: one ticket for living here, two for the last blow,
 * two for the biggest share of the work. They reward different things — the
 * final hit is timing, the biggest share is a fortnight of chores — which is
 * why one kid can take all five.
 */
class MonsterKillRewardTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        Chore::factory()->for($this->household)->create(['points' => 100]);
    }

    private function arena(): MonsterService
    {
        return app(MonsterService::class);
    }

    private function kid(string $name): Profile
    {
        return Profile::factory()->for($this->household)->create(['name' => $name]);
    }

    private function spawn(int $health = 1000): Monster
    {
        return $this->arena()->spawn($this->household, 'Ice cream outing', $health);
    }

    private function ticketsFor(Profile $kid): int
    {
        return $kid->fresh()->bonus_tickets;
    }

    public function test_everyone_in_the_house_gets_a_ticket(): void
    {
        $nova = $this->kid('Nova');
        $pip = $this->kid('Pip');
        // Away all week and never touched it — still lives here.
        $wren = $this->kid('Wren');

        $monster = $this->spawn(100);
        $this->arena()->land($monster, 60, $nova);
        $this->arena()->land($monster, 40, $pip);
        $this->arena()->settle($monster, $pip);

        $this->assertSame(MonsterService::TICKETS_FOR_EVERYONE, $this->ticketsFor($wren));
    }

    public function test_the_finisher_takes_two_more(): void
    {
        $nova = $this->kid('Nova');
        $pip = $this->kid('Pip');

        $monster = $this->spawn(100);
        // Nova has the most damage, Pip lands the last hit.
        $this->arena()->land($monster, 90, $nova);
        $this->arena()->land($monster, 10, $pip);
        $this->arena()->settle($monster, $pip);

        $this->assertSame(
            MonsterService::TICKETS_FOR_EVERYONE + MonsterService::TICKETS_FOR_FINISHER,
            $this->ticketsFor($pip),
        );
    }

    public function test_the_biggest_share_takes_two_more(): void
    {
        $nova = $this->kid('Nova');
        $pip = $this->kid('Pip');

        $monster = $this->spawn(100);
        $this->arena()->land($monster, 90, $nova);
        $this->arena()->land($monster, 10, $pip);
        $this->arena()->settle($monster, $pip);

        $this->assertSame(
            MonsterService::TICKETS_FOR_EVERYONE + MonsterService::TICKETS_FOR_TOP_DAMAGE,
            $this->ticketsFor($nova),
        );
    }

    public function test_one_kid_doing_both_takes_all_five(): void
    {
        $nova = $this->kid('Nova');
        $this->kid('Pip');

        $monster = $this->spawn(100);
        $this->arena()->land($monster, 100, $nova);
        $this->arena()->settle($monster, $nova);

        $this->assertSame(
            MonsterService::TICKETS_FOR_EVERYONE
                + MonsterService::TICKETS_FOR_FINISHER
                + MonsterService::TICKETS_FOR_TOP_DAMAGE,
            $this->ticketsFor($nova),
        );
    }

    public function test_a_tie_for_most_damage_pays_both(): void
    {
        $nova = $this->kid('Nova');
        $pip = $this->kid('Pip');

        $monster = $this->spawn(100);
        $this->arena()->land($monster, 50, $nova);
        $this->arena()->land($monster, 50, $pip);
        $this->arena()->settle($monster, $pip);

        // A whole ticket doesn't split, and the board already shares the crown
        // on a tie.
        $this->assertSame(
            MonsterService::TICKETS_FOR_EVERYONE + MonsterService::TICKETS_FOR_TOP_DAMAGE,
            $this->ticketsFor($nova),
        );
    }

    public function test_a_monster_finished_off_by_a_parents_nudge_pays_nobody_the_bonuses(): void
    {
        $nova = $this->kid('Nova');

        $monster = $this->spawn(100);
        // Nothing anyone did — the whole bar is a hand adjustment.
        $this->arena()->adjust($monster, 100);
        $this->arena()->settle($monster);

        $this->assertSame(MonsterService::TICKETS_FOR_EVERYONE, $this->ticketsFor($nova));
    }

    public function test_the_payout_is_recorded_against_the_monster(): void
    {
        $nova = $this->kid('Nova');

        $monster = $this->spawn(100);
        $this->arena()->land($monster, 100, $nova);
        $this->arena()->settle($monster, $nova);

        $entry = BonusTicketEntry::where('profile_id', $nova->id)
            ->where('kind', TicketKind::BossDefeat)
            ->sole();

        $this->assertSame(5, $entry->amount);
        $this->assertSame($monster->id, $entry->related_id);
        $this->assertStringContainsString('defeated', $entry->description);
    }

    public function test_a_kill_pays_out_once(): void
    {
        $nova = $this->kid('Nova');

        $monster = $this->spawn(100);
        $this->arena()->land($monster, 100, $nova);

        $this->arena()->settle($monster, $nova);
        $this->arena()->settle($monster->fresh(), $nova);

        $this->assertSame(5, $this->ticketsFor($nova));
    }

    public function test_each_monster_beaten_pays_again(): void
    {
        $nova = $this->kid('Nova');

        // One after the other now rather than in a cascade — the next one can
        // only be stood up once the first is off the board.
        foreach (['Ice cream', 'Pizza night'] as $reward) {
            $monster = $this->arena()->spawn($this->household, $reward, 100);
            $this->arena()->land($monster, 100, $nova);
            $this->arena()->settle($monster, $nova);
        }

        $this->assertSame(10, $this->ticketsFor($nova));
    }

    public function test_the_card_tells_the_kid_what_they_got_and_why(): void
    {
        $nova = $this->kid('Nova');
        $pip = $this->kid('Pip');

        $monster = $this->spawn(100);
        $this->arena()->land($monster, 90, $nova);
        $this->arena()->land($monster, 10, $pip);
        $this->arena()->settle($monster, $pip);

        Auth::guard('profile')->login($nova->fresh());

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('+3 tickets', false)
            ->assertSee('most damage', false)
            ->assertDontSee('final blow · most damage', false);
    }

    public function test_the_card_names_both_bonuses_when_one_kid_earned_both(): void
    {
        $nova = $this->kid('Nova');

        $monster = $this->spawn(100);
        $this->arena()->land($monster, 100, $nova);
        $this->arena()->settle($monster, $nova);

        Auth::guard('profile')->login($nova->fresh());

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('+5 tickets', false)
            ->assertSee('final blow · most damage', false);
    }

    public function test_a_sibling_who_did_nothing_is_told_what_their_ticket_is_for(): void
    {
        $nova = $this->kid('Nova');
        $wren = $this->kid('Wren');

        $monster = $this->spawn(100);
        $this->arena()->land($monster, 100, $nova);
        $this->arena()->settle($monster, $nova);

        Auth::guard('profile')->login($wren->fresh());

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('+1 ticket', false)
            ->assertSee('Ice cream outing', false)
            ->assertDontSee('most damage', false);
    }

    public function test_the_balance_and_its_entries_stay_in_step(): void
    {
        $nova = $this->kid('Nova');
        $pip = $this->kid('Pip');

        $monster = $this->spawn(100);
        $this->arena()->land($monster, 100, $nova);
        $this->arena()->settle($monster, $nova);

        foreach ([$nova, $pip] as $kid) {
            $this->assertSame(
                (int) BonusTicketEntry::where('profile_id', $kid->id)->sum('amount'),
                $this->ticketsFor($kid),
            );
        }
    }
}
