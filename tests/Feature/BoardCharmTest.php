<?php

namespace Tests\Feature;

use App\Enums\ChoreCadence;
use App\Enums\CompletionStatus;
use App\Enums\PerkEffect;
use App\Models\CharmedChore;
use App\Models\Chore;
use App\Models\Household;
use App\Models\OwnedPerk;
use App\Models\Profile;
use App\Services\ChoreService;
use App\Services\PerkInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Quest Charm, after the daily quest it was named for went away.
 *
 * It used to be a bet on a chest: cast before the lid came up, it decided how
 * many of three dealt cards paid a bonus. There are no cards, so it is aimed at
 * the board instead — five random chores this kid can still claim start paying
 * half again, for this kid alone, until the household day rolls over.
 *
 * Random is the mechanic, not an implementation detail. A kid choosing which
 * five chores pay more is not gambling, they are giving themselves a pay rise
 * on the five they were going to do anyway.
 */
class BoardCharmTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();

        // Pinned to the middle of a household day: a charm is keyed to that
        // day, and a test that starts either side of the 4am rollover would be
        // asserting about the clock rather than the charm.
        $this->travelTo(Carbon::parse('2026-05-04 12:00', $this->household->timezone));

        $this->kid = Profile::factory()->for($this->household)->create();
    }

    private function service(): ChoreService
    {
        return app(ChoreService::class);
    }

    public function test_it_lights_up_five_chores(): void
    {
        Chore::factory()->for($this->household)->count(12)->create();

        $charmed = $this->service()->charmBoard($this->kid);

        $this->assertCount(ChoreService::CHARM_CHORES, $charmed);
        $this->assertCount(ChoreService::CHARM_CHORES, $this->service()->charmedChoreIdsFor($this->kid));
    }

    /** A board smaller than the charm is taken whole rather than padded. */
    public function test_a_short_board_is_charmed_entirely(): void
    {
        Chore::factory()->for($this->household)->count(3)->create();

        $this->assertCount(3, $this->service()->charmBoard($this->kid));
    }

    public function test_a_charmed_chore_pays_half_again(): void
    {
        $chore = Chore::factory()->for($this->household)->create(['points' => 100]);

        $this->service()->charmBoard($this->kid);

        $this->assertSame(150, $this->service()->claim($this->kid, $chore)->points_awarded);
    }

    /**
     * The hole this closes. Unlimited is the cadence with no cooldown, so
     * nothing but this stops a charmed chore paying its bonus on every tap —
     * one ticket for as many bonuses as a kid can be bothered to submit.
     */
    public function test_an_unlimited_chore_pays_the_charm_once_a_day(): void
    {
        $chore = Chore::factory()->for($this->household)->create([
            'cadence' => ChoreCadence::Unlimited,
            'points' => 100,
        ]);

        $this->service()->charmBoard($this->kid);

        $paid = collect(range(1, 4))
            ->map(fn () => $this->service()->claim($this->kid, $chore)->points_awarded)
            ->all();

        $this->assertSame([150, 100, 100, 100], $paid);
    }

    /**
     * A claim a parent sent back earned nothing, so redoing the work is still
     * owed the bonus the board promised the first time.
     */
    public function test_a_sent_back_claim_does_not_spend_the_charm(): void
    {
        $parent = Profile::factory()->parent()->for($this->household)->create();
        $chore = Chore::factory()->for($this->household)->create([
            'cadence' => ChoreCadence::Unlimited,
            'points' => 100,
        ]);

        $this->service()->charmBoard($this->kid);

        $first = $this->service()->claim($this->kid, $chore);
        $this->service()->sendBack($first, $parent);

        $this->assertSame(150, $this->service()->claim($this->kid, $chore)->points_awarded);
    }

    /** A charm cast after an ordinary claim still pays on the next one. */
    public function test_a_claim_before_the_charm_does_not_spend_it(): void
    {
        $chore = Chore::factory()->for($this->household)->create([
            'cadence' => ChoreCadence::Unlimited,
            'points' => 100,
        ]);

        $this->assertSame(100, $this->service()->claim($this->kid, $chore)->points_awarded);

        $this->service()->charmBoard($this->kid);

        $this->assertSame(150, $this->service()->claim($this->kid, $chore)->points_awarded);
    }

    /** What was paid is frozen on the row, like the Help Wanted flag beside it. */
    public function test_the_bonus_is_recorded_on_the_completion(): void
    {
        $chore = Chore::factory()->for($this->household)->create(['points' => 200]);

        $this->service()->charmBoard($this->kid);

        $this->assertSame(100, $this->service()->claim($this->kid, $chore)->charm_bonus);
    }

    /** One kid's ticket does not light up their brother's board. */
    public function test_a_charm_is_for_the_kid_who_cast_it(): void
    {
        $sibling = Profile::factory()->for($this->household)->create();
        $chore = Chore::factory()->for($this->household)->create(['points' => 100]);

        $this->service()->charmBoard($this->kid);

        $this->assertSame([], $this->service()->charmedChoreIdsFor($sibling));
        $this->assertSame(100, $this->service()->claim($sibling, $chore)->points_awarded);
    }

    public function test_it_lapses_at_the_household_rollover(): void
    {
        $chore = Chore::factory()->for($this->household)->create(['points' => 100]);

        $this->service()->charmBoard($this->kid);

        $this->travelTo(Carbon::parse('2026-05-05 12:00', $this->household->timezone));

        $this->assertSame([], app(ChoreService::class)->charmedChoreIdsFor($this->kid));
        $this->assertSame(100, app(ChoreService::class)->claim($this->kid, $chore)->points_awarded);
    }

    /** A chore a sibling is holding is not worth a ticket. */
    public function test_it_never_lands_on_a_chore_nobody_can_claim(): void
    {
        $sibling = Profile::factory()->for($this->household)->create();

        $taken = Chore::factory()->for($this->household)->create(['cadence' => ChoreCadence::Daily]);
        $free = Chore::factory()->for($this->household)->create(['cadence' => ChoreCadence::Daily]);

        $this->service()->claim($sibling, $taken);

        $this->service()->charmBoard($this->kid);
        $charmed = $this->service()->charmedChoreIdsFor($this->kid);

        $this->assertContains($free->id, $charmed);
        $this->assertNotContains($taken->id, $charmed);
    }

    /** A second charm widens the spread rather than doubling up. */
    public function test_a_second_charm_lights_up_different_chores(): void
    {
        Chore::factory()->for($this->household)->count(12)->create();

        $service = $this->service();
        $first = $service->charmBoard($this->kid)->pluck('id');
        $second = $service->charmBoard($this->kid)->pluck('id');

        $this->assertCount(ChoreService::CHARM_CHORES, $second);
        $this->assertEmpty($first->intersect($second), 'A second charm must not re-charm a lit row.');
        $this->assertCount(ChoreService::CHARM_CHORES * 2, $service->charmedChoreIdsFor($this->kid));
    }

    /** Casting one twice over is a no-op, not a unique-index 500. */
    public function test_charming_a_chore_that_is_already_charmed_never_throws(): void
    {
        $chore = Chore::factory()->for($this->household)->create();

        CharmedChore::create([
            'profile_id' => $this->kid->id,
            'chore_id' => $chore->id,
            'charm_date' => now(),
        ]);

        // Nothing left to light up, so the charm refuses rather than colliding.
        $this->assertTrue($this->service()->charmBoard($this->kid)->isEmpty());
        $this->assertSame(1, CharmedChore::where('profile_id', $this->kid->id)->count());
    }

    public function test_the_perk_refuses_when_there_is_nothing_left_to_charm(): void
    {
        $chore = Chore::factory()->for($this->household)->create();

        CharmedChore::create([
            'profile_id' => $this->kid->id,
            'chore_id' => $chore->id,
            'charm_date' => now(),
        ]);

        $perks = app(PerkInventoryService::class);
        $perks->grant($this->kid, PerkEffect::QuestCharm, OwnedPerk::SOURCE_GIFT);

        $this->assertSame('Nothing left on the board to charm', $perks->blockedReason($this->kid, PerkEffect::QuestCharm));
        // A perk that cannot be applied stays in the pocket.
        $this->assertSame(1, $perks->countOf($this->kid, PerkEffect::QuestCharm));
    }

    /**
     * The board is asked for three or four times on a single render — the page,
     * the adding-up card, the charm's blocked reason, Home's Work row — and each
     * walk costs a claimant query per chore.
     */
    public function test_the_board_is_only_walked_once_per_request(): void
    {
        Chore::factory()->for($this->household)->count(10)->create();

        $service = $this->service();
        $service->boardFor($this->kid);

        DB::enableQueryLog();
        $service->boardFor($this->kid);
        $repeat = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $repeat, 'A second boardFor() in the same request must cost nothing.');
    }

    /** But a claim has to be visible to the next read of it. */
    public function test_a_claim_invalidates_the_memo(): void
    {
        $chore = Chore::factory()->for($this->household)->create(['cadence' => ChoreCadence::Daily]);

        $service = $this->service();

        $this->assertSame('ready', $service->boardFor($this->kid)->firstWhere('chore.id', $chore->id)['state']);

        $service->claim($this->kid, $chore);

        $this->assertSame('pending', $service->boardFor($this->kid)->firstWhere('chore.id', $chore->id)['state']);
    }

    /** And so does a charm — the rows it just lit have to show as lit. */
    public function test_a_charm_invalidates_the_memo(): void
    {
        $chore = Chore::factory()->for($this->household)->create();

        $service = $this->service();
        $service->boardFor($this->kid);
        $service->charmBoard($this->kid);

        $entry = $service->boardFor($this->kid)->firstWhere('chore.id', $chore->id);

        $this->assertTrue($entry['charmed']);
        $this->assertSame(50, $entry['charmBonus']);
    }

    /** An approval changes what every sibling's board says, too. */
    public function test_an_approval_invalidates_the_memo(): void
    {
        $parent = Profile::factory()->parent()->for($this->household)->create();
        $sibling = Profile::factory()->for($this->household)->create();
        $chore = Chore::factory()->for($this->household)->create(['cadence' => ChoreCadence::Daily]);

        $service = $this->service();
        $completion = $service->claim($this->kid, $chore);

        $this->assertSame('done', $service->boardFor($sibling)->firstWhere('chore.id', $chore->id)['state']);

        $service->approve($completion, $parent);

        $this->assertSame(
            CompletionStatus::Approved,
            $completion->refresh()->status,
            'The approval itself has to land before the board can be re-read.',
        );
        $this->assertSame('done', $service->boardFor($sibling)->firstWhere('chore.id', $chore->id)['state']);
    }
}
