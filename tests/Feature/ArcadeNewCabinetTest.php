<?php

namespace Tests\Feature;

use App\Enums\ArcadeGame;
use App\Models\Household;
use App\Models\Profile;
use App\Notifications\ArcadeCabinetAdded;
use App\Services\ArcadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Telling the kids a cabinet has arrived.
 *
 * Nothing about the arcade ever comes looking for anybody — a chore nags, a
 * sibling's swap arrives, a monster loses health, and the arcade just sits
 * there being a thing you have to remember. So a game added quietly is a game
 * nobody plays, and this is the two halves of fixing that: a flash in the app
 * for the kid who opens it, and a push for the kid who doesn't.
 */
class ArcadeNewCabinetTest extends TestCase
{
    use RefreshDatabase;

    private function arcade(): ArcadeService
    {
        return app(ArcadeService::class);
    }

    /** A kid who was last in the arcade before the second cabinet went in. */
    private function returningKid(string $name = 'Nova', ?Household $household = null): Profile
    {
        return Profile::factory()
            ->for($household ?? Household::factory())
            ->create([
                'name' => $name,
                'arcade_seen_at' => ArcadeGame::StackTheMess->releasedOn(),
            ]);
    }

    public function test_a_cabinet_added_since_a_kid_last_looked_is_new_to_them(): void
    {
        $kid = $this->returningKid();

        $new = $this->arcade()->newCabinetsFor($kid);

        $this->assertEquals([ArcadeGame::WindyWalkies], $new->all());
        $this->assertSame(1, $this->arcade()->newCountFor($kid));
    }

    public function test_a_kid_who_has_never_been_finds_every_cabinet_new(): void
    {
        // Null is a profile that has never opened the arcade, and the same
        // reading `loot_seen_at` gets: all of it is news to them.
        $kid = Profile::factory()->for(Household::factory())->create(['arcade_seen_at' => null]);

        $this->assertSame(count(ArcadeGame::cases()), $this->arcade()->newCountFor($kid));
    }

    public function test_the_newest_unseen_cabinet_is_the_one_that_gets_opened(): void
    {
        // Two unseen games should land a kid on the one that just arrived, not
        // the one that arrived first.
        $kid = Profile::factory()->for(Household::factory())->create(['arcade_seen_at' => null]);

        Auth::guard('profile')->login($kid);

        Volt::test('arcade')->assertSet('game', ArcadeGame::newest());
    }

    public function test_the_flash_survives_the_visit_it_was_meant_for(): void
    {
        /*
         * The marker is stamped on mount, so a page reading it live would clear
         * the flash before the kid it was for had looked up. The snapshot taken
         * a line earlier is what holds it for the visit — the same ordering the
         * Loot Shop's "new" chips need.
         */
        $kid = $this->returningKid();

        Auth::guard('profile')->login($kid);

        Volt::test('arcade')
            ->assertSet('newCabinets', [ArcadeGame::WindyWalkies->value])
            ->assertSee('New');

        // And stamped, so the next visit is quiet.
        $this->assertNotNull($kid->fresh()->arcade_seen_at);
        $this->assertSame(0, $this->arcade()->newCountFor($kid->fresh()));
    }

    public function test_the_flash_is_gone_by_the_next_visit(): void
    {
        $kid = $this->returningKid();

        Auth::guard('profile')->login($kid);

        Volt::test('arcade');
        Volt::test('arcade')->assertSet('newCabinets', []);
    }

    public function test_a_new_cabinet_is_pushed_to_every_kid_in_the_house(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $kids = collect(['Nova', 'Rook'])->map(fn (string $name) => $this->returningKid($name, $household));
        $parent = Profile::factory()->for($household)->parent()->create(['name' => 'Dad']);

        $told = $this->arcade()->announceNewCabinet($household, ArcadeGame::WindyWalkies);

        $this->assertSame(2, $told);

        foreach ($kids as $kid) {
            Notification::assertSentTo($kid, ArcadeCabinetAdded::class);
        }

        // Grown-ups put the game there and can see the switcher on their own
        // console; a push telling them about it would be telling them nothing.
        Notification::assertNotSentTo($parent, ArcadeCabinetAdded::class);
    }

    public function test_one_houses_announcement_does_not_reach_another(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $mine = $this->returningKid('Nova', $household);
        $theirs = $this->returningKid('Rook');

        $this->arcade()->announceNewCabinet($household, ArcadeGame::WindyWalkies);

        Notification::assertSentTo($mine, ArcadeCabinetAdded::class);
        Notification::assertNotSentTo($theirs, ArcadeCabinetAdded::class);
    }

    public function test_the_announce_command_defaults_to_the_newest_cabinet(): void
    {
        Notification::fake();

        $household = Household::factory()->create(['name' => 'Wright']);
        $kid = $this->returningKid('Nova', $household);

        $this->artisan('arcade:announce')
            ->expectsOutputToContain(ArcadeGame::newest()->label())
            ->assertSuccessful();

        Notification::assertSentTo($kid, ArcadeCabinetAdded::class);
    }

    public function test_the_announce_command_sends_nothing_on_a_dry_run(): void
    {
        Notification::fake();

        $household = Household::factory()->create(['name' => 'Wright']);
        $this->returningKid('Nova', $household);

        $this->artisan('arcade:announce --dry-run')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_announce_command_refuses_a_cabinet_that_does_not_exist(): void
    {
        Notification::fake();

        $this->artisan('arcade:announce not_a_game')->assertFailed();

        Notification::assertNothingSent();
    }

    public function test_every_cabinet_declares_when_it_went_in(): void
    {
        // The whole announcement hangs off these dates, and a game added
        // without one would be new to nobody, silently and forever.
        $seen = [];

        foreach (ArcadeGame::cases() as $game) {
            $seen[] = $game->releasedOn()->toDateString();
        }

        $this->assertSame($seen, array_unique($seen), 'Two cabinets claim the same release date.');
        $this->assertSame(ArcadeGame::WindyWalkies, ArcadeGame::newest());
    }
}
