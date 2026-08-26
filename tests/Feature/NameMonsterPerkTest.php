<?php

namespace Tests\Feature;

use App\Enums\PerkEffect;
use App\Exceptions\PerkUnavailableException;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Monster;
use App\Models\Profile;
use App\Services\MonsterService;
use App\Services\PerkInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Naming a monster: the one perk that asks the kid to say something as well as
 * tap, and the only one whose result the whole household has to look at for a
 * fortnight.
 */
class NameMonsterPerkTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create(['require_quest_first' => false]);
        $this->kid = Profile::factory()->for($this->household)->create(['name' => 'Nova']);
        Chore::factory()->for($this->household)->create(['points' => 100]);

        Auth::guard('profile')->login($this->kid);
    }

    private function arena(): MonsterService
    {
        return app(MonsterService::class);
    }

    private function perks(): PerkInventoryService
    {
        return app(PerkInventoryService::class);
    }

    private function ownAName(): void
    {
        $this->perks()->grant($this->kid, PerkEffect::NameMonster, 'shop');
    }

    private function spawn(): Monster
    {
        return $this->arena()->spawn($this->household, 'Ice cream', 500);
    }

    public function test_a_kid_names_a_monster_and_everyone_sees_it(): void
    {
        $monster = $this->spawn();
        $this->ownAName();

        $this->perks()->use($this->kid, PerkEffect::NameMonster, [
            'name' => 'Barry',
        ]);

        $this->assertSame('Barry', $monster->fresh()->nickname);
        $this->assertSame('Barry', $monster->fresh()->displayName());
        $this->assertSame('Barry', $this->arena()->stateFor($monster->fresh())['name']);
    }

    public function test_the_artwork_and_the_flavour_stay_the_monsters_own(): void
    {
        $monster = $this->spawn();
        $this->ownAName();

        $this->perks()->use($this->kid, PerkEffect::NameMonster, [
            'name' => 'Barry',
        ]);

        $state = $this->arena()->stateFor($monster->fresh());

        // A name, not a costume: it is still the same drawing with the same
        // line about where it lives.
        $this->assertSame($monster->skin, $state['skin']);
        $this->assertSame($monster->skin->tagline(), $state['tagline']);
    }

    public function test_an_empty_name_is_refused_and_the_perk_is_kept(): void
    {
        $monster = $this->spawn();
        $this->ownAName();

        try {
            $this->perks()->use($this->kid, PerkEffect::NameMonster, [
                'name' => '   ',
            ]);
            $this->fail('A blank name should be refused.');
        } catch (PerkUnavailableException $e) {
            $this->assertSame('Give it a name first.', $e->getMessage());
        }

        $this->assertNull($monster->fresh()->nickname);
        $this->assertCount(1, $this->perks()->unusedFor($this->kid->fresh()));
    }

    public function test_a_long_name_is_cut_to_fit(): void
    {
        $monster = $this->spawn();
        $this->ownAName();

        $this->perks()->use($this->kid, PerkEffect::NameMonster, [
            'name' => str_repeat('a', 80),
        ]);

        $this->assertSame(MonsterService::NICKNAME_LIMIT, mb_strlen($monster->fresh()->nickname));
    }

    public function test_a_monster_that_already_has_a_name_keeps_it(): void
    {
        $monster = $this->spawn();
        $this->ownAName();
        $this->ownAName();

        $this->perks()->use($this->kid, PerkEffect::NameMonster, [
            'name' => 'Barry',
        ]);

        // First come, first served — a sibling can't paint over it an hour later.
        $this->expectException(PerkUnavailableException::class);

        $this->perks()->use($this->kid->fresh(), PerkEffect::NameMonster, [
            'name' => 'Susan',
        ]);
    }

    public function test_the_perk_is_blocked_when_there_is_nothing_left_to_name(): void
    {
        $this->assertSame(
            'Nothing left to name',
            $this->perks()->blockedReason($this->kid, PerkEffect::NameMonster),
        );

        $monster = $this->spawn();
        $this->assertNull($this->perks()->blockedReason($this->kid, PerkEffect::NameMonster));

        $this->arena()->nameMonster($this->household, 'Barry');
        $this->assertSame(
            'Nothing left to name',
            $this->perks()->blockedReason($this->kid->fresh(), PerkEffect::NameMonster),
        );
    }

    /**
     * The perk no longer takes a monster id — it names whatever this household
     * is fighting — so reaching another family's arena is structurally out of
     * reach rather than merely refused. This holds the line anyway.
     */
    public function test_naming_cannot_reach_another_households_monster(): void
    {
        $elsewhere = Household::factory()->create();
        $theirs = $this->arena()->spawn($elsewhere, 'Not ours', 500);

        $this->ownAName();

        // Nothing standing here, and a monster standing over there.
        $this->expectException(PerkUnavailableException::class);

        $this->perks()->use($this->kid, PerkEffect::NameMonster, ['name' => 'Barry']);

        $this->assertNull($theirs->fresh()->nickname);
    }

    public function test_the_name_rides_on_the_kill_card_rather_than_the_skin(): void
    {
        $monster = $this->arena()->spawn($this->household, 'Ice cream outing', 100);
        $this->arena()->nameMonster($this->household, 'Barry');

        $this->arena()->land($monster->fresh(), 100, $this->kid);
        $this->arena()->settle($monster->fresh(), $this->kid);

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.quests')
            ->assertOk()
            ->assertSee('Barry is down!', false);
    }

    public function test_the_form_opens_on_the_tap_and_spends_nothing_yet(): void
    {
        $monster = $this->spawn();
        $this->ownAName();

        Volt::test('kid.bonus')
            ->call('usePerk', PerkEffect::NameMonster->value)
            ->assertSee('What should '.$monster->skin->label().' be called?')
            ->assertSet('monsterName', '');

        $this->assertCount(1, $this->perks()->unusedFor($this->kid->fresh()));
    }

    public function test_naming_from_the_page_spends_the_perk(): void
    {
        $monster = $this->spawn();
        $this->ownAName();

        Volt::test('kid.bonus')
            ->call('usePerk', PerkEffect::NameMonster->value)
            ->set('monsterName', 'Barry')
            ->call('nameMonster')
            ->assertSet('naming', false);

        $this->assertSame('Barry', $monster->fresh()->nickname);
        $this->assertCount(0, $this->perks()->unusedFor($this->kid->fresh()));
    }

    public function test_naming_celebrates_with_confetti_rather_than_coins(): void
    {
        $monster = $this->spawn();
        $this->ownAName();

        // Money is the toast's default, and nothing is bought at the moment a
        // perk is *used* — the tickets went days ago. Coins raining down for
        // naming a monster is the app cheering about a transaction that never
        // happened.
        Volt::test('kid.bonus')
            ->call('usePerk', PerkEffect::NameMonster->value)
            ->set('monsterName', 'Barry')
            ->call('nameMonster')
            ->assertDispatched('celebrate', style: 'confetti');
    }

    public function test_no_perk_celebrates_with_money(): void
    {
        foreach (PerkEffect::cases() as $effect) {
            $this->assertNotSame('money', $effect->celebrationStyle(), $effect->value.' still rains coins.');
        }
    }

    public function test_every_page_that_uses_a_perk_asks_for_its_style(): void
    {
        // Three pages spend perks — the shop, the quest board and Home, which
        // holds the wheel and the quest hero — and the toast's own default is
        // money. A page that forgets to pass a style doesn't fail, it just
        // quietly rains coins, which is exactly how the board kept doing it
        // after the shop was fixed.
        foreach (['bonus', 'quests', 'home'] as $page) {
            $source = file_get_contents(resource_path("views/pages/kid/{$page}.blade.php"));

            $this->assertStringContainsString(
                'celebrationStyle()',
                $source,
                "The {$page} page spends perks without asking for a celebration style.",
            );
        }
    }

    public function test_backing_out_of_the_form_keeps_the_perk(): void
    {
        $this->spawn();
        $this->ownAName();

        Volt::test('kid.bonus')
            ->call('usePerk', PerkEffect::NameMonster->value)
            ->call('cancelNaming')
            ->assertSet('naming', false)
            ->assertDontSee('What should it be called?');

        $this->assertCount(1, $this->perks()->unusedFor($this->kid->fresh()));
    }

    public function test_a_parent_can_take_a_name_back_off(): void
    {
        $monster = $this->spawn();
        $this->arena()->nameMonster($this->household, 'Barry');

        $parent = Profile::factory()->parent()->for($this->household)->create();
        Auth::guard('profile')->login($parent);

        Volt::test('parent.monsters')
            ->assertSee('Barry')
            ->assertSee('Take the name off')
            ->call('clearNickname');

        $this->assertNull($monster->fresh()->nickname);
        $this->assertSame($monster->skin->label(), $monster->fresh()->displayName());
    }

    public function test_the_next_monster_starts_unnamed(): void
    {
        $monster = $this->arena()->spawn($this->household, 'Ice cream', 100);
        $this->arena()->nameMonster($this->household, 'Barry');

        $this->arena()->land($monster->fresh(), 100, $this->kid);
        $this->arena()->settle($monster->fresh(), $this->kid);

        $next = $this->arena()->spawn($this->household, 'Ice cream again', 100);

        // The name belonged to the monster, not the arena — and the beaten one
        // keeps it on the shelf.
        $this->assertNull($next->nickname);
        $this->assertSame('Barry', $monster->fresh()->displayName());
    }
}
