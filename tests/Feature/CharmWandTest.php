<?php

namespace Tests\Feature;

use App\Enums\PerkEffect;
use App\Models\BonusPerk;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Models\Spin;
use App\Services\BonusShopService;
use App\Services\ChoreService;
use App\Services\PerkInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The charm, watched rather than announced.
 *
 * A charm spends a ticket, picks five chores at random and pays half again on
 * each — all of it between two paints. Kids could not tell what they had
 * bought: a ticket went, the board came back with five violet marks on it, and
 * a toast said "find them!". So the board has a wand now
 * (resources/js/charm.js) that flies to each chore the charm landed on and
 * counts its payout up.
 *
 * The animation itself is not testable from here. What is, and what this holds,
 * is the seam it runs on: the server has to name the chores that were charmed
 * and say what each row paid *before* it — the new number is the only one that
 * survives the render, and a wand with nothing to count up from is a wand
 * pointing at an answer.
 */
class CharmWandTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();

        // Middle of a household day: a charm is keyed to that day, and either
        // side of the 4am rollover this would be testing the clock.
        $this->travelTo(Carbon::parse('2026-05-04 12:00', $this->household->timezone));

        $this->kid = Profile::factory()->for($this->household)->create(['bonus_tickets' => 50]);

        Auth::guard('profile')->login($this->kid);
    }

    private function charmPerk(): BonusPerk
    {
        return BonusPerk::where('household_id', $this->household->id)
            ->where('effect', PerkEffect::QuestCharm)
            ->firstOrFail();
    }

    /** One held, ready to cast. */
    private function holdOne(): void
    {
        app(BonusShopService::class)->purchase($this->kid, $this->charmPerk());
    }

    /**
     * The event carries one entry per chore the charm actually landed on, and
     * each one says what the row paid before as well as after.
     */
    public function test_casting_a_charm_names_every_chore_it_landed_on(): void
    {
        Chore::factory()->for($this->household)->count(8)->create(['points' => 200]);
        app(ChoreService::class)->forgetBoards();

        $this->holdOne();

        Volt::test('kid.quests')
            ->call('usePerk', PerkEffect::QuestCharm->value)
            ->assertDispatched('charm-cast', function (string $name, array $params) {
                $charmed = app(ChoreService::class)->charmedChoreIdsFor($this->kid->fresh());

                $this->assertCount(ChoreService::CHARM_CHORES, $params['chores']);
                $this->assertEqualsCanonicalizing(
                    $charmed,
                    array_column($params['chores'], 'id'),
                    'The wand has to fly to the rows that actually went charmed.',
                );

                foreach ($params['chores'] as $chore) {
                    // 200 points, +50%: the row climbs from 200 to 300 under
                    // the wand. Without `from` the animation has nowhere to
                    // count up from — the render only keeps the new number.
                    $this->assertSame(200, $chore['from']);
                    $this->assertSame(300, $chore['to']);
                    $this->assertSame('var(--fq-lime)', $chore['tint']);
                }

                return true;
            });
    }

    /**
     * A charm cast over a chore the wheel already boosted climbs from the
     * boosted number, in the boost's own colour.
     *
     * The charm rides on top of the multiplier rather than inside it — exactly
     * as ChoreService::claim() pays it — so a 3x row goes 600 to 700, not 200
     * to 300 and not 600 to 900. And a row that flashed lime on its way to
     * violet would be telling a kid their boost had gone.
     */
    public function test_a_boosted_row_climbs_from_its_boosted_payout(): void
    {
        $chore = Chore::factory()->for($this->household)->create(['points' => 200]);
        app(ChoreService::class)->forgetBoards();

        Spin::create([
            'profile_id' => $this->kid->id,
            'chore_id' => $chore->id,
            'multiplier' => 3,
            'spin_date' => now()->toDateString(),
        ]);

        $this->holdOne();

        Volt::test('kid.quests')
            ->call('usePerk', PerkEffect::QuestCharm->value)
            ->assertDispatched('charm-cast', function (string $name, array $params) use ($chore) {
                $entry = collect($params['chores'])->firstWhere('id', $chore->id);

                $this->assertNotNull($entry, 'The only chore on the board has to be the charmed one.');
                $this->assertSame(600, $entry['from']);
                $this->assertSame(700, $entry['to']);
                $this->assertSame('var(--fq-gold)', $entry['tint']);

                return true;
            });
    }

    /** The rate goes with it, because the row counts in dollars as well as points. */
    public function test_the_event_carries_the_households_own_rate(): void
    {
        Chore::factory()->for($this->household)->count(3)->create(['points' => 200]);
        app(ChoreService::class)->forgetBoards();

        $this->holdOne();

        Volt::test('kid.quests')
            ->call('usePerk', PerkEffect::QuestCharm->value)
            ->assertDispatched('charm-cast', function (string $name, array $params) {
                $this->assertSame($this->household->points_per_dollar, $params['rate']);

                return true;
            });
    }

    /**
     * The page stops telling a kid to go and find what it has just shown them.
     *
     * The shop keeps that wording — it has no board to fly a wand over, so
     * "find them" is still the only thing it can honestly say.
     */
    public function test_the_board_says_charmed_and_the_shop_says_find_them(): void
    {
        Chore::factory()->for($this->household)->count(8)->create();
        app(ChoreService::class)->forgetBoards();

        $this->holdOne();

        Volt::test('kid.quests')
            ->call('usePerk', PerkEffect::QuestCharm->value)
            ->assertDispatched('charm-cast', function (string $name, array $params) {
                $this->assertSame(ChoreService::CHARM_CHORES.' chores charmed!', $params['message']);

                return true;
            })
            // The toast is thrown by the wand once it has finished the trip,
            // not by the server on top of it.
            ->assertNotDispatched('celebrate');

        $this->holdOne();

        Volt::test('kid.bonus')
            ->call('usePerk', PerkEffect::QuestCharm->value)
            ->assertDispatched('celebrate', fn (string $name, array $params) => str_contains($params['message'], 'find them'));
    }

    /**
     * A charm that lands on nothing is refused and keeps the ticket, and there
     * is no wand for a board with nothing on it.
     */
    public function test_a_refused_charm_flies_no_wand(): void
    {
        $this->holdOne();

        Volt::test('kid.quests')
            ->call('usePerk', PerkEffect::QuestCharm->value)
            ->assertNotDispatched('charm-cast')
            ->assertSet('perkMessage', 'Nothing left on the board to charm');

        $this->assertSame(1, app(PerkInventoryService::class)->countOf($this->kid->fresh(), PerkEffect::QuestCharm));
    }

    /**
     * The seam the animation actually runs on.
     *
     * charm.js finds its rows and its numbers by four data attributes. Nothing
     * else on either side references them, so a rename in the Blade would take
     * the wand off the board silently — it would fly to no rows, decide there
     * was nothing to show, and fall back to the toast. That is the failure
     * this catches, since it looks exactly like the behaviour we replaced.
     */
    public function test_the_board_renders_every_hook_the_wand_reads(): void
    {
        $chore = Chore::factory()->for($this->household)->create();
        app(ChoreService::class)->forgetBoards();

        $wand = file_get_contents(resource_path('js/charm.js'));

        foreach (['data-chore', 'data-chore-money', 'data-chore-pts', 'data-chore-payout'] as $hook) {
            $this->assertStringContainsString($hook, $wand, "charm.js should still read [{$hook}].");
        }

        $html = Volt::test('kid.quests')->html();

        $this->assertStringContainsString('data-chore="'.$chore->id.'"', $html);
        $this->assertStringContainsString('data-chore-money', $html);
        $this->assertStringContainsString('data-chore-pts', $html);
        $this->assertStringContainsString('data-chore-payout', $html);

        // The charm mark is the fifth: hidden by the wand on arrival and
        // popped back in, so it is only rendered on a row already charmed.
        $this->assertStringContainsString('data-charm-mark', $wand);
        $this->assertStringNotContainsString('data-charm-mark', $html, 'Nothing is charmed yet.');

        app(ChoreService::class)->charmBoard($this->kid);

        $this->assertStringContainsString('data-charm-mark', Volt::test('kid.quests')->html());
    }
}
