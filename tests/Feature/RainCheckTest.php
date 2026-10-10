<?php

namespace Tests\Feature;

use App\Enums\PerkEffect;
use App\Exceptions\PerkUnavailableException;
use App\Models\Chore;
use App\Models\Household;
use App\Models\OwnedPerk;
use App\Models\Profile;
use App\Models\RainCheck;
use App\Models\Spin;
use App\Services\ChoreService;
use App\Services\PerkInventoryService;
use App\Services\SpinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use RuntimeException;
use Tests\TestCase;

/**
 * The Rain Check: a boost landed on something that can't be done right now —
 * pulling weeds at 9pm — saved for tomorrow instead of lost. The kid can still
 * respin for another boost tonight; there is only ever one rain check a day.
 */
class RainCheckTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();

        // Evening, which is when this perk gets bought — and far enough from
        // midnight that a day's travel never lands on the rollover.
        $this->travelTo(Carbon::parse('2026-05-01 20:00', $this->household->timezone));

        $this->kid = Profile::factory()->for($this->household)->create();

        Chore::factory()->for($this->household)->count(6)->create();
    }

    private function bank(): RainCheck
    {
        $this->inventory()->grant($this->kid, PerkEffect::RainCheck, OwnedPerk::SOURCE_SHOP);
        $this->inventory()->use($this->kid->refresh(), PerkEffect::RainCheck);

        return app(SpinService::class)->rainCheckForTomorrow($this->kid);
    }

    private function respin(): Spin
    {
        $this->inventory()->grant($this->kid, PerkEffect::WheelRespin, OwnedPerk::SOURCE_SHOP);
        $this->inventory()->use($this->kid->refresh(), PerkEffect::WheelRespin);

        return app(SpinService::class)->spin($this->kid->refresh());
    }

    private function inventory(): PerkInventoryService
    {
        return app(PerkInventoryService::class);
    }

    public function test_a_banked_boost_pays_nothing_today(): void
    {
        $spin = app(SpinService::class)->spin($this->kid);
        $this->bank();
        $spins = app(SpinService::class);

        $this->assertTrue($spins->isBanked($spin));
        $this->assertSame(1, $spins->multiplierFor($this->kid, $spin->chore));
        $this->assertSame([], $spins->boostsFor($this->kid));

        // Banking alone is not a respin: the wheel is still spent until one is used.
        $this->expectException(RuntimeException::class);
        $spins->spin($this->kid->refresh());
    }

    public function test_a_banked_boost_pays_tomorrow_when_the_chore_is_claimed(): void
    {
        $spin = app(SpinService::class)->spin($this->kid);
        $this->bank();

        $this->travel(1)->days();

        $this->assertSame($spin->multiplier, app(SpinService::class)->multiplierFor($this->kid, $spin->chore));

        $completion = app(ChoreService::class)->claim($this->kid, $spin->chore);

        $this->assertSame($spin->chore->points * $spin->multiplier, $completion->points_awarded);
    }

    public function test_a_banked_boost_is_gone_the_day_after_tomorrow(): void
    {
        $spin = app(SpinService::class)->spin($this->kid);
        $this->bank();

        $this->travel(2)->days();

        $this->assertSame(1, app(SpinService::class)->multiplierFor($this->kid, $spin->chore));
        $this->assertNull(app(SpinService::class)->rainCheckForToday($this->kid));
    }

    /**
     * The whole point of the rule: weeds at 9pm go to tomorrow, and a respin
     * still finds a boost the kid can do tonight.
     */
    public function test_a_respin_after_banking_keeps_the_rain_check_and_pays_the_new_boost_today(): void
    {
        $first = app(SpinService::class)->spin($this->kid);
        $this->bank();

        $second = $this->respin();
        $spins = app(SpinService::class);

        $this->assertSame($first->chore_id, $spins->rainCheckForTomorrow($this->kid)->chore_id);
        $this->assertSame([$second->chore_id => $second->multiplier], $spins->boostsFor($this->kid));
    }

    /** One rain check a day: banking the respun boost takes over from the first. */
    public function test_banking_again_replaces_tomorrows_rain_check(): void
    {
        app(SpinService::class)->spin($this->kid);
        $this->bank();

        $second = $this->respin();
        $replaced = $this->bank();

        $this->assertSame(1, RainCheck::where('profile_id', $this->kid->id)->count());
        $this->assertSame($second->chore_id, $replaced->chore_id);
        $this->assertSame($second->multiplier, $replaced->multiplier);

        $this->travel(1)->days();

        $this->assertSame([$second->chore_id => $second->multiplier], app(SpinService::class)->boostsFor($this->kid));
    }

    /**
     * Tomorrow still gets its own spin, so the saved boost is on top of the
     * day rather than instead of it — but never on the same chore, where the
     * two would not stack and the spin would win nothing.
     */
    public function test_tomorrows_wheel_leaves_the_banked_chore_off(): void
    {
        $spin = app(SpinService::class)->spin($this->kid);
        $this->bank();

        $this->travel(1)->days();

        $wheel = app(SpinService::class)->eligibleChoresFor($this->kid);

        $this->assertFalse($wheel->contains('id', $spin->chore_id));
        $this->assertCount(5, $wheel);
    }

    /** A pet can bat tomorrow's boost onto the banked chore; the bigger one wins. */
    public function test_two_boosts_on_one_chore_do_not_multiply(): void
    {
        $banked = app(SpinService::class)->spin($this->kid);
        $this->bank();

        $this->travel(1)->days();

        $spins = app(SpinService::class);
        $today = $spins->spin($this->kid->refresh());
        $spins->moveBoostTo($today, $banked->chore);

        $this->assertSame(
            max($banked->multiplier, $today->multiplier),
            $spins->multiplierFor($this->kid, $banked->chore),
        );
    }

    public function test_it_cannot_be_used_before_spinning(): void
    {
        $this->inventory()->grant($this->kid, PerkEffect::RainCheck, OwnedPerk::SOURCE_SHOP);

        $this->assertSame('Spin the wheel first', $this->inventory()->blockedReason($this->kid, PerkEffect::RainCheck));
    }

    /** The same spin can't be banked twice, and the second perk stays in the pocket. */
    public function test_a_spin_already_banked_cannot_be_banked_again(): void
    {
        app(SpinService::class)->spin($this->kid);
        $this->bank();
        $this->inventory()->grant($this->kid, PerkEffect::RainCheck, OwnedPerk::SOURCE_SHOP);

        $this->assertSame('Already saved for tomorrow', $this->inventory()->blockedReason($this->kid, PerkEffect::RainCheck));

        try {
            $this->inventory()->use($this->kid, PerkEffect::RainCheck);
            $this->fail('A second rain check on one spin should have been refused.');
        } catch (PerkUnavailableException) {
            $this->assertSame(1, $this->inventory()->countOf($this->kid, PerkEffect::RainCheck));
        }
    }

    /** Handed in at the boost already, so banking it would pay it twice. */
    public function test_a_boost_already_cashed_cannot_be_banked(): void
    {
        $spin = app(SpinService::class)->spin($this->kid);
        app(ChoreService::class)->claim($this->kid, $spin->chore);

        $this->inventory()->grant($this->kid, PerkEffect::RainCheck, OwnedPerk::SOURCE_SHOP);

        $this->assertSame('You already did this one', $this->inventory()->blockedReason($this->kid->refresh(), PerkEffect::RainCheck));
    }

    /** A sibling taking the chore today is one of the best reasons to save the boost. */
    public function test_a_boost_a_sibling_beat_them_to_can_still_be_banked(): void
    {
        $spin = app(SpinService::class)->spin($this->kid);
        $sibling = Profile::factory()->for($this->household)->create();
        app(ChoreService::class)->claim($sibling, $spin->chore);

        $this->inventory()->grant($this->kid, PerkEffect::RainCheck, OwnedPerk::SOURCE_SHOP);

        $this->assertNull($this->inventory()->blockedReason($this->kid->refresh(), PerkEffect::RainCheck));
    }

    public function test_a_banked_spin_can_still_be_respun(): void
    {
        app(SpinService::class)->spin($this->kid);
        $this->bank();
        $this->inventory()->grant($this->kid, PerkEffect::WheelRespin, OwnedPerk::SOURCE_SHOP);

        $this->assertNull($this->inventory()->blockedReason($this->kid, PerkEffect::WheelRespin));
    }

    /** A parent freeing the wheel doesn't take back a boost the kid already saved. */
    public function test_a_parent_reset_leaves_the_rain_check_alone(): void
    {
        $spin = app(SpinService::class)->spin($this->kid);
        $this->bank();

        app(SpinService::class)->resetByParent($this->kid);

        $this->assertFalse(app(SpinService::class)->hasSpunToday($this->kid));
        $this->assertSame($spin->chore_id, app(SpinService::class)->rainCheckForTomorrow($this->kid)->chore_id);
    }

    public function test_the_quests_page_saves_the_boost_and_shows_it_tomorrow(): void
    {
        Auth::guard('profile')->login($this->kid);
        $this->kid->update(['bonus_tickets' => 5]);

        Volt::test('kid.quests')
            ->call('spin')
            ->call('finishSpin')
            ->assertSee('Keep this boost for tomorrow instead')
            ->call('buyBonusItem', PerkEffect::RainCheck->value)
            ->call('usePerk', PerkEffect::RainCheck->value)
            ->assertSee('saved for tomorrow')
            ->assertSee('Saved for tomorrow');

        $saved = app(SpinService::class)->rainCheckForTomorrow($this->kid);

        $this->travel(1)->days();

        Volt::test('kid.quests')
            ->assertSee('Rain check')
            ->assertSee($saved->chore->name)
            ->call('claimBoostedChore', true);

        $this->assertSame(
            $saved->chore->points * $saved->multiplier,
            $this->kid->choreCompletions()->latest('id')->first()->points_awarded,
        );
    }

    /** After a respin the saved boost is still on show, and the offer says it will swap. */
    public function test_the_quests_page_offers_to_swap_after_a_respin(): void
    {
        Auth::guard('profile')->login($this->kid);
        $this->kid->update(['bonus_tickets' => 20]);

        Volt::test('kid.quests')
            ->call('spin')
            ->call('finishSpin')
            ->call('buyBonusItem', PerkEffect::RainCheck->value)
            ->call('usePerk', PerkEffect::RainCheck->value)
            ->call('buyBonusItem', PerkEffect::WheelRespin->value)
            ->call('usePerk', PerkEffect::WheelRespin->value)
            ->call('spin')
            ->call('finishSpin')
            ->assertSee('saved for tomorrow')
            ->assertSee('Swap tomorrow’s rain check for this boost');
    }
}
